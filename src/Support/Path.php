<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

/**
 * Base-path handling.
 *
 * The app does not assume it owns the domain root. It works in all three
 * common layouts:
 *
 *   1. DocumentRoot -> public/            https://example.com/login
 *   2. Subfolder, root .htaccess rewrite  https://example.com/RoyalSpin/login
 *   3. Subfolder, public/ in the URL      https://example.com/RoyalSpin/public/login
 *
 * Every generated link, form action, asset URL, redirect and fetch() call goes
 * through here, so the same upload works in any of them. Set APP_BASE_PATH in
 * .env to pin it explicitly if the detection ever guesses wrong.
 */
final class Path
{
    private static ?string $base   = null;
    private static ?string $script = null;

    /** Base prefix without a trailing slash. '' when the app owns the root. */
    public static function base(): string
    {
        if (self::$base !== null) {
            return self::$base;
        }

        $explicit = Env::get('APP_BASE_PATH');
        if ($explicit !== null && trim($explicit) !== '') {
            $trimmed = '/' . trim(trim($explicit), '/');
            return self::$base = ($trimmed === '/' ? '' : $trimmed);
        }

        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '') {
            return self::$base = '';
        }

        $directory = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $uri       = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uri       = is_string($uri) && $uri !== '' ? $uri : '/';

        // Layout 1 and 3: the request path starts with the script's directory.
        if ($directory !== '' && str_starts_with($uri, $directory)) {
            return self::$base = $directory;
        }

        // Layout 2: index.php lives in <base>/public but the browser asked for
        // <base>/something — the root .htaccess rewrote it.
        if (str_ends_with($directory, '/public')) {
            $parent = substr($directory, 0, -strlen('/public'));
            if ($parent === '' || str_starts_with($uri, $parent)) {
                return self::$base = $parent;
            }
        }

        return self::$base = ($directory === '/' ? '' : $directory);
    }

    /**
     * The bit that goes between the base path and the route.
     *
     * Pretty URLs need mod_rewrite (and AllowOverride All). Plenty of shared
     * hosting has one or the other switched off, so the app does not assume it:
     * it generates whatever form demonstrably works for *this* visitor.
     *
     *   - Request arrived as ".../login"          -> rewrite works  -> ''
     *   - Request arrived as ".../index.php/..."  -> explicit mode  -> '/index.php'
     *   - Request was the bare folder ".../"      -> unproven       -> '/index.php'
     *
     * The unproven case defaults to the form that works everywhere, so a fresh
     * install can never hand out links that 404. Set APP_PRETTY_URLS=true to
     * force clean URLs once you know rewriting is available.
     */
    public static function script(): string
    {
        if (self::$script !== null) {
            return self::$script;
        }

        $forced = Env::get('APP_PRETTY_URLS');
        if ($forced !== null && $forced !== '') {
            return self::$script = Env::bool('APP_PRETTY_URLS', false) ? '' : '/index.php';
        }

        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $uri = is_string($uri) && $uri !== '' ? $uri : '/';

        $remainder = $uri;
        $base      = self::base();
        if ($base !== '' && str_starts_with($remainder, $base)) {
            $remainder = substr($remainder, strlen($base));
        }
        $remainder = trim($remainder, '/');

        if ($remainder === '' || str_starts_with($remainder, 'index.php')) {
            return self::$script = '/index.php';
        }

        // We got here through a rewritten route, so rewriting demonstrably works.
        return self::$script = '';
    }

    /** Turn an app-absolute path ('/login') into a browser URL. */
    public static function url(string $path = '/'): string
    {
        $path = '/' . ltrim($path, '/');
        $url  = self::base() . self::script() . ($path === '/' ? '' : $path);
        return $url === '' ? '/' : $url;
    }

    /** Strip the base prefix (and any "/index.php") from an incoming path. */
    public static function strip(string $path): string
    {
        $base = self::base();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        // Explicit front-controller form: "/index.php/login" -> "/login".
        $trimmed = ltrim($path, '/');
        if (str_starts_with($trimmed, 'index.php')) {
            $path = substr($trimmed, strlen('index.php'));
        }

        // PATH_INFO is what Apache/nginx hand us for ".../index.php/login" when
        // the path was not visible in REQUEST_URI.
        if (trim($path, '/') === '' && !empty($_SERVER['PATH_INFO'])) {
            $path = (string) $_SERVER['PATH_INFO'];
        }

        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /** Test/CLI hook. */
    public static function reset(?string $base = null, ?string $script = null): void
    {
        self::$base   = $base;
        self::$script = $script;
    }
}
