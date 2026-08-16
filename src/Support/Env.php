<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

/**
 * Minimal .env loader. Values already present in the real environment win, so
 * container/systemd configuration always overrides the file.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded  = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key   = trim($parts[0]);
            $value = trim($parts[1]);
            if (strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        return self::$values[$key] ?? $default;
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /** Debug/dev mode gates the balance overlay and verbose errors. Never enable in production. */
    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false) || self::get('APP_ENV', 'production') === 'local';
    }

    /** Test helper — lets the suite point at an in-memory/temporary database. */
    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
        putenv($key . '=' . $value);
    }
}
