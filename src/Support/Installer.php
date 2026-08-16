<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

use RoyalSpin\Game\UpgradeCatalog;
use Throwable;

/**
 * Zero-configuration self-install.
 *
 * The goal: unzip and open the URL. Nothing else.
 *
 *  - No .env required. If one exists it still wins for every setting.
 *  - No database server required. The default driver is SQLite, which is a
 *    single file inside storage/ — nothing to install, nothing to configure.
 *  - No migration command required. The first request that finds an empty
 *    database creates the schema and seeds the upgrade catalogue.
 *  - No APP_KEY to paste. One is generated and persisted on first boot.
 *
 * Everything here is idempotent and, after the first request, costs one
 * `is_file()` check.
 */
final class Installer
{
    private static bool $ensured = false;

    public static function storagePath(string $file = ''): string
    {
        $directory = (string) Env::get('APP_STORAGE', ROYAL_SPIN_ROOT . '/storage');
        return $file === '' ? $directory : $directory . '/' . ltrim($file, '/');
    }

    /**
     * Make sure the app can run. Returns null when ready, or a human-readable
     * problem description when the environment cannot support it.
     */
    public static function ensure(): ?string
    {
        if (self::$ensured) {
            return null;
        }

        $storage = self::storagePath();
        if (!is_dir($storage) && !@mkdir($storage, 0775, true) && !is_dir($storage)) {
            return 'The storage folder could not be created at ' . $storage
                . '. Give the web server write permission to the project folder (chmod 755, or 775 on some hosts).';
        }
        if (!is_writable($storage)) {
            return 'The storage folder at ' . $storage . ' is not writable by the web server. '
                . 'Set its permissions to 775 (or 777 on restrictive shared hosting).';
        }

        self::protectStorage($storage);

        $driver = strtolower((string) Env::get('DB_DRIVER', 'sqlite'));
        if ($driver === 'sqlite' && !in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            return 'PHP is missing the pdo_sqlite extension, which the zero-setup mode needs. '
                . 'Enable pdo_sqlite in your hosting control panel, or configure MySQL in .env.';
        }

        // The marker lets later requests skip the schema probe entirely.
        $marker = self::storagePath('installed-' . self::schemaFingerprint() . '.lock');
        if (is_file($marker)) {
            self::$ensured = true;
            return null;
        }

        try {
            self::install();
        } catch (Throwable $exception) {
            error_log('[royal-spin] install failed: ' . $exception->getMessage());
            return 'The database could not be prepared: ' . $exception->getMessage();
        }

        @file_put_contents($marker, gmdate('c'));
        self::$ensured = true;
        return null;
    }

    /** Create any missing tables and refresh the seed data. */
    public static function install(): void
    {
        $driver = Db::isMysql() ? 'mysql' : 'sqlite';
        $schema = ROYAL_SPIN_ROOT . '/database/schema.' . $driver . '.sql';

        $sql = file_get_contents($schema);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read ' . basename($schema));
        }

        // Every statement is CREATE TABLE IF NOT EXISTS, so running this on an
        // existing installation is a no-op.
        foreach (self::statements($sql) as $statement) {
            Db::run($statement);
        }

        self::seedUpgrades();
    }

    /** Mirror config/upgrades.php into the `upgrades` table. */
    public static function seedUpgrades(): int
    {
        $now   = Db::now();
        $count = 0;

        foreach (UpgradeCatalog::all() as $key => $upgrade) {
            $row = [
                'name'        => (string) $upgrade['name'],
                'description' => (string) $upgrade['description'],
                'category'    => (string) $upgrade['category'],
                'rarity'      => (string) $upgrade['rarity'],
                'icon'        => (string) $upgrade['icon'],
                'max_level'   => (int) $upgrade['max_level'],
                'base_cost'   => (int) $upgrade['base_cost'],
                'cost_growth' => (float) $upgrade['cost_growth'],
                'effects'     => json_encode($upgrade['effects'], JSON_UNESCAPED_UNICODE) ?: '[]',
                'updated_at'  => $now,
            ];

            $exists = Db::first('SELECT upgrade_key FROM upgrades WHERE upgrade_key = :key', ['key' => $key]);
            if ($exists === null) {
                Db::insert('upgrades', array_merge(['upgrade_key' => (string) $key], $row));
            } else {
                Db::update('upgrades', $row, ['upgrade_key' => (string) $key]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * A persistent random key for session fingerprinting. Generated once and
     * stored in storage/ so the operator never has to create one.
     */
    public static function applicationKey(): string
    {
        $configured = Env::get('APP_KEY');
        if ($configured !== null && $configured !== '' && $configured !== 'change-me-to-a-long-random-string') {
            return $configured;
        }

        $file = self::storagePath('app.key');
        if (is_file($file)) {
            $key = trim((string) file_get_contents($file));
            if ($key !== '') {
                return $key;
            }
        }

        $key = bin2hex(random_bytes(32));
        @file_put_contents($file, $key);
        @chmod($file, 0600);
        return $key;
    }

    /**
     * Belt and braces: the SQLite file lives under storage/, which should never
     * be web-reachable. If storage/ happens to sit inside the document root on
     * a misconfigured host, this .htaccess still blocks it.
     */
    private static function protectStorage(string $storage): void
    {
        $htaccess = $storage . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# Never serve anything from here.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n"
            );
        }
        $index = $storage . '/index.html';
        if (!is_file($index)) {
            @file_put_contents($index, '');
        }
    }

    /** Changes when the schema or the upgrade catalogue changes. */
    private static function schemaFingerprint(): string
    {
        $parts = [
            (string) @filemtime(ROYAL_SPIN_ROOT . '/database/schema.sqlite.sql'),
            (string) @filemtime(ROYAL_SPIN_ROOT . '/database/schema.mysql.sql'),
            (string) @filemtime(ROYAL_SPIN_ROOT . '/config/upgrades.php'),
            Db::driver(),
        ];
        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    /** @return list<string> */
    public static function statements(string $sql): array
    {
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }

        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }
        return $statements;
    }
}
