<?php

declare(strict_types=1);

/**
 * A handful of globals used by the templates. Keeping escaping this short is
 * what makes it realistic to escape *everything*.
 */

if (!function_exists('e')) {
    /** HTML-escape. Every dynamic value in a template goes through this. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('json_attr')) {
    /**
     * Embed data for the frontend in a `data-` attribute. Safe against `</script>`
     * breakouts because it never enters a script context.
     *
     * @param array<string,mixed> $data
     */
    function json_attr(array $data): string
    {
        return e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}

if (!function_exists('url')) {
    /**
     * App-absolute path -> browser URL, honouring the install's base path.
     * Use this for every link, form action and redirect: `url('/dashboard')`.
     */
    function url(string $path = '/'): string
    {
        return \RoyalSpin\Support\Path::url($path);
    }
}

if (!function_exists('asset')) {
    /**
     * Cache-busted asset URL.
     *
     * Static files are served straight off disk, so they use the base path only
     * — never the "/index.php" front-controller prefix that routes may carry.
     */
    function asset(string $path): string
    {
        $file    = ROYAL_SPIN_ROOT . '/public/' . ltrim($path, '/');
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return \RoyalSpin\Support\Path::base() . '/' . ltrim($path, '/') . '?v=' . $version;
    }
}

if (!function_exists('coins')) {
    /** Format a coin amount with thousands separators. */
    function coins(int|float $amount): string
    {
        return number_format((float) $amount, 0, '.', ',');
    }
}
