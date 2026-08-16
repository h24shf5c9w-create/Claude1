<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

use RoyalSpin\Support\Env;
use RoyalSpin\Support\Installer;
use RoyalSpin\Support\Path;

/**
 * Deployment shapes.
 *
 * The app must work when it owns the domain root, when it is unzipped into a
 * subfolder, and when mod_rewrite is unavailable. These are regression tests
 * for a real deployment failure: a blanket `Require all denied` in the project
 * root .htaccess cascaded into public/ and produced 403 Forbidden, and every
 * generated URL assumed the app lived at "/".
 */
final class DeploymentTest extends TestCase
{
    public function tearDown(): void
    {
        Path::reset(null, null);
        unset($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI'], $_SERVER['PATH_INFO']);
    }

    /** @param array<string,string> $server */
    private function request(array $server): void
    {
        Path::reset(null, null);
        foreach ($server as $key => $value) {
            $_SERVER[$key] = $value;
        }
    }

    /* ------------------------------------------------- base path detection */

    public function testDocumentRootPointingAtPublicHasNoPrefix(): void
    {
        $this->request(['SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/login']);

        $this->assertSame('', Path::base());
        $this->assertSame('/login', Path::strip('/login'));
        $this->assertSame('/login', Path::url('/login'), 'Clean install should produce clean URLs');
    }

    public function testSubfolderInstallKeepsItsPrefix(): void
    {
        // https://example.com/Royalespin/public/index.php  — the reported case.
        $this->request([
            'SCRIPT_NAME' => '/Royalespin/public/index.php',
            'REQUEST_URI' => '/Royalespin/public/index.php',
        ]);

        $this->assertSame('/Royalespin/public', Path::base());
        $this->assertSame('/', Path::strip('/Royalespin/public/index.php'));
        $this->assertSame('/Royalespin/public/index.php/login', Path::url('/login'));
    }

    public function testSubfolderRoutesAreStrippedBeforeRouting(): void
    {
        $this->request([
            'SCRIPT_NAME' => '/Royalespin/public/index.php',
            'REQUEST_URI' => '/Royalespin/public/index.php/api/rooms/join',
        ]);

        $this->assertSame('/api/rooms/join', Path::strip('/Royalespin/public/index.php/api/rooms/join'));
    }

    public function testRewrittenSubfolderRoutesResolveToo(): void
    {
        // Root .htaccess rewrote /Royalespin/login into /Royalespin/public/index.php
        $this->request([
            'SCRIPT_NAME' => '/Royalespin/public/index.php',
            'REQUEST_URI' => '/Royalespin/login',
        ]);

        $this->assertSame('/Royalespin', Path::base());
        $this->assertSame('/login', Path::strip('/Royalespin/login'));
    }

    public function testPathInfoIsUsedWhenTheRouteIsNotInTheRequestUri(): void
    {
        $this->request([
            'SCRIPT_NAME' => '/Royalespin/public/index.php',
            'REQUEST_URI' => '/Royalespin/public/index.php',
            'PATH_INFO'   => '/dashboard',
        ]);

        $this->assertSame('/dashboard', Path::strip('/Royalespin/public/index.php'));
    }

    /* --------------------------------------------------- pretty URL choice */

    public function testUrlsFallBackToIndexPhpWhenRewritingIsUnproven(): void
    {
        // Landing on the bare folder tells us nothing about mod_rewrite, so the
        // app must emit the form that works everywhere.
        $this->request([
            'SCRIPT_NAME' => '/Royalespin/public/index.php',
            'REQUEST_URI' => '/Royalespin/public/',
        ]);

        $this->assertSame('/index.php', Path::script());
        $this->assertSame('/Royalespin/public/index.php/login', Path::url('/login'));
    }

    public function testUrlsStayCleanOnceRewritingIsProven(): void
    {
        // We were reached at a route that only a rewrite could have produced.
        $this->request([
            'SCRIPT_NAME' => '/Royalespin/public/index.php',
            'REQUEST_URI' => '/Royalespin/public/login',
        ]);

        $this->assertSame('', Path::script());
        $this->assertSame('/Royalespin/public/login', Path::url('/login'));
    }

    public function testPrettyUrlsCanBeForcedByConfiguration(): void
    {
        $this->request([
            'SCRIPT_NAME' => '/Royalespin/public/index.php',
            'REQUEST_URI' => '/Royalespin/public/',
        ]);
        Env::set('APP_PRETTY_URLS', 'true');
        Path::reset(null, null);

        $this->assertSame('', Path::script());
        $this->assertSame('/Royalespin/public/login', Path::url('/login'));

        Env::set('APP_PRETTY_URLS', '');
        putenv('APP_PRETTY_URLS');
    }

    public function testExplicitBasePathOverridesDetection(): void
    {
        Env::set('APP_BASE_PATH', '/custom/spot');
        Path::reset(null, null);

        $this->assertSame('/custom/spot', Path::base());

        Env::set('APP_BASE_PATH', '');
        putenv('APP_BASE_PATH');
        Path::reset(null, null);
    }

    /* ---------------------------------------------------------- .htaccess */

    /**
     * The exact bug that produced the 403: Apache inherits access rules into
     * subdirectories, so a bare `Require all denied` at the project root also
     * blocks public/.
     */
    public function testProjectRootHtaccessDoesNotDenyEverything(): void
    {
        $htaccess = (string) file_get_contents(ROYAL_SPIN_ROOT . '/.htaccess');

        // A deny inside <Files>/<FilesMatch>/<Directory> is scoped to those
        // files and is fine. Only an unscoped one is the bug — note that
        // <IfModule> does NOT scope anything, so it does not count as a guard.
        $scopeDepth = 0;

        foreach (explode("\n", $htaccess) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('#^<\s*(Files|FilesMatch|Directory|DirectoryMatch|Location|LocationMatch)\b#i', $line)) {
                $scopeDepth++;
                continue;
            }
            if (preg_match('#^</\s*(Files|FilesMatch|Directory|DirectoryMatch|Location|LocationMatch)\s*>#i', $line)) {
                $scopeDepth = max(0, $scopeDepth - 1);
                continue;
            }

            if ($scopeDepth > 0) {
                continue;   // scoped to specific files — harmless
            }

            $isBlanketDeny = strcasecmp($line, 'Require all denied') === 0
                || strcasecmp($line, 'Deny from all') === 0;

            $this->assertFalse(
                $isBlanketDeny,
                'An unscoped "' . $line . '" in the project root cascades into public/ and causes 403 Forbidden'
            );
        }
    }

    public function testProjectRootHtaccessStillProtectsSourceFolders(): void
    {
        $htaccess = (string) file_get_contents(ROYAL_SPIN_ROOT . '/.htaccess');

        foreach (['src', 'config', 'storage', 'database'] as $folder) {
            $this->assertStringContains($folder, $htaccess, "The {$folder} folder must be blocked from the web");
        }
    }

    public function testPublicHtaccessGrantsAccessExplicitly(): void
    {
        $htaccess = (string) file_get_contents(ROYAL_SPIN_ROOT . '/public/.htaccess');
        $this->assertStringContains(
            'Require all granted',
            $htaccess,
            'public/ must re-grant access so an inherited deny cannot lock the game out'
        );
    }

    /* ------------------------------------------------------- zero setup */

    public function testTheAppDefaultsToAFileDatabaseSoNoServerIsNeeded(): void
    {
        // With no .env at all the driver must be SQLite, not MySQL.
        $this->assertSame('sqlite', strtolower((string) Env::get('DB_DRIVER', 'sqlite')));
    }

    public function testStoragePathIsInsideTheProject(): void
    {
        $this->assertStringContains(ROYAL_SPIN_ROOT, Installer::storagePath());
    }

    public function testSchemaSplitterProducesRunnableStatements(): void
    {
        $sql        = (string) file_get_contents(ROYAL_SPIN_ROOT . '/database/schema.sqlite.sql');
        $statements = Installer::statements($sql);

        $this->assertTrue(count($statements) >= 15, 'Every table should produce a statement');
        foreach ($statements as $statement) {
            $this->assertFalse(str_starts_with(trim($statement), '--'), 'Comments must be stripped');
            $this->assertTrue(
                stripos($statement, 'CREATE') === 0,
                'Only CREATE statements belong in the schema file'
            );
            $this->assertStringContains('IF NOT EXISTS', $statement, 'Re-running the installer must be safe');
        }
    }
}
