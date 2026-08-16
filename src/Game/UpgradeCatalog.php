<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use RoyalSpin\Support\Config;

/**
 * Read-only access to config/upgrades.php plus cost/eligibility rules.
 */
final class UpgradeCatalog
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        if (self::$cache === null) {
            /** @var array<string,array<string,mixed>> $catalog */
            $catalog     = Config::file('upgrades');
            self::$cache = $catalog;
        }
        return self::$cache;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<string,mixed>|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function maxLevel(string $key): int
    {
        $upgrade = self::get($key);
        return $upgrade === null ? 0 : (int) ($upgrade['max_level'] ?? 1);
    }

    /**
     * Cost of moving from `$currentLevel` to `$currentLevel + 1`.
     * Level 1 costs `base_cost`, each further level multiplies by `cost_growth`.
     */
    public static function costForLevel(string $key, int $nextLevel): int
    {
        $upgrade = self::get($key);
        if ($upgrade === null || $nextLevel < 1) {
            return 0;
        }
        $base   = (float) ($upgrade['base_cost'] ?? 0);
        $growth = (float) ($upgrade['cost_growth'] ?? 1.0);
        $cost   = $base * ($growth ** ($nextLevel - 1)) * Config::float('game.upgrade_cost_scale', 1.0);

        // Round to a readable multiple of 5 so shop prices never look random.
        return (int) (max(5, round($cost / 5)) * 5);
    }

    /**
     * Can this player buy the next level of `$key` right now?
     *
     * @param array<string,int> $ownedLevels upgrade_key => level
     * @return array{ok:bool, reason:?string, next_level:int, cost:int}
     */
    public static function purchaseCheck(string $key, array $ownedLevels): array
    {
        $upgrade = self::get($key);
        if ($upgrade === null) {
            return ['ok' => false, 'reason' => 'Unknown upgrade.', 'next_level' => 0, 'cost' => 0];
        }

        $current   = $ownedLevels[$key] ?? 0;
        $nextLevel = $current + 1;
        $maxLevel  = (int) ($upgrade['max_level'] ?? 1);

        if ($current >= $maxLevel) {
            return ['ok' => false, 'reason' => 'Already at maximum level.', 'next_level' => $current, 'cost' => 0];
        }

        /** @var array<string,int> $requires */
        $requires = $upgrade['requires'] ?? [];
        foreach ($requires as $requiredKey => $requiredLevel) {
            if (($ownedLevels[$requiredKey] ?? 0) < (int) $requiredLevel) {
                $name = self::get((string) $requiredKey)['name'] ?? $requiredKey;
                return [
                    'ok'         => false,
                    'reason'     => "Requires {$name}.",
                    'next_level' => $nextLevel,
                    'cost'       => self::costForLevel($key, $nextLevel),
                ];
            }
        }

        return [
            'ok'         => true,
            'reason'     => null,
            'next_level' => $nextLevel,
            'cost'       => self::costForLevel($key, $nextLevel),
        ];
    }

    /**
     * Keys that could still be offered to this player (not maxed, requirements met).
     *
     * @param array<string,int> $ownedLevels
     * @return list<string>
     */
    public static function purchasableKeys(array $ownedLevels): array
    {
        $keys = [];
        foreach (array_keys(self::all()) as $key) {
            if (self::purchaseCheck($key, $ownedLevels)['ok']) {
                $keys[] = $key;
            }
        }
        return $keys;
    }

    /**
     * Presentation payload for one shop card / owned badge.
     *
     * @param array<string,int> $ownedLevels
     * @return array<string,mixed>
     */
    public static function describe(string $key, array $ownedLevels): array
    {
        $upgrade = self::get($key) ?? [];
        $current = $ownedLevels[$key] ?? 0;
        $check   = self::purchaseCheck($key, $ownedLevels);

        return [
            'key'           => $key,
            'name'          => (string) ($upgrade['name'] ?? $key),
            'icon'          => (string) ($upgrade['icon'] ?? 'sparkle'),
            'category'      => (string) ($upgrade['category'] ?? 'special'),
            'category_label'=> self::categoryLabel((string) ($upgrade['category'] ?? 'special')),
            'rarity'        => (string) ($upgrade['rarity'] ?? 'common'),
            'description'   => (string) ($upgrade['description'] ?? ''),
            'current_level' => $current,
            'next_level'    => $check['next_level'],
            'max_level'     => (int) ($upgrade['max_level'] ?? 1),
            'cost'          => $check['cost'],
            'available'     => $check['ok'],
            'reason'        => $check['reason'],
            'reel'          => self::reelFor((string) ($upgrade['category'] ?? '')),
        ];
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'dice'      => 'Dice',
            'all_reels' => 'All Reels',
            'reel1'     => 'Reel 1',
            'reel2'     => 'Reel 2',
            'reel3'     => 'Reel 3',
            'symbols'   => 'Symbols',
            'payout'    => 'Payout',
            default     => 'Special',
        };
    }

    public static function reelFor(string $category): ?int
    {
        return match ($category) {
            'reel1' => 1,
            'reel2' => 2,
            'reel3' => 3,
            default => null,
        };
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return ['dice', 'all_reels', 'reel1', 'reel2', 'reel3', 'symbols', 'payout', 'special'];
    }
}
