<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

/**
 * Loads and caches the files in /config. Supports dot notation lookups and
 * (for tests / the simulator) temporary overrides.
 */
final class Config
{
    /** @var array<string,array<string,mixed>> */
    private static array $files = [];

    /** @var array<string,mixed> */
    private static array $overrides = [];

    /** @return array<string,mixed> */
    public static function file(string $name): array
    {
        if (!isset(self::$files[$name])) {
            $path = ROYAL_SPIN_ROOT . '/config/' . $name . '.php';
            if (!is_file($path)) {
                throw new \RuntimeException("Missing config file: {$name}.php");
            }
            /** @var array<string,mixed> $loaded */
            $loaded              = require $path;
            self::$files[$name]  = $loaded;
        }
        return self::$files[$name];
    }

    /** `Config::get('game.target_coins')` */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) {
            return self::$overrides[$key];
        }

        $segments = explode('.', $key);
        $file     = array_shift($segments);
        $value    = self::file($file);

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $value = self::get($key, $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    /** @return array<string,mixed> */
    public static function array(string $key): array
    {
        $value = self::get($key, []);
        return is_array($value) ? $value : [];
    }

    /** Test/simulation hook — override a single dotted key. */
    public static function override(string $key, mixed $value): void
    {
        self::$overrides[$key] = $value;
    }

    public static function clearOverrides(): void
    {
        self::$overrides = [];
        self::$files     = [];
    }
}
