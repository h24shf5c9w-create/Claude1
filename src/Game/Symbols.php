<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use RoyalSpin\Support\Config;

/**
 * The ten reel symbols, ordered weakest to strongest.
 */
final class Symbols
{
    public const WILD = 'wild';

    /** @return array<string,array{name:string,weight:float,pair:int,triple:int,tier:int}> */
    public static function all(): array
    {
        /** @var array<string,array{name:string,weight:float,pair:int,triple:int,tier:int}> $symbols */
        $symbols = Config::array('game.symbols');
        return $symbols;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function exists(string $symbol): bool
    {
        return isset(self::all()[$symbol]);
    }

    public static function name(string $symbol): string
    {
        return (string) (self::all()[$symbol]['name'] ?? ucfirst($symbol));
    }

    public static function tier(string $symbol): int
    {
        return (int) (self::all()[$symbol]['tier'] ?? 1);
    }

    /** Base pair payout before the global scale and any multipliers. */
    public static function pairPayout(string $symbol): int
    {
        return (int) (self::all()[$symbol]['pair'] ?? 0);
    }

    /** Base triple payout before the global scale and any multipliers. */
    public static function triplePayout(string $symbol): int
    {
        return (int) (self::all()[$symbol]['triple'] ?? 0);
    }

    /** @return array<string,float> base weights, unmodified */
    public static function baseWeights(): array
    {
        $weights = [];
        foreach (self::all() as $key => $definition) {
            $weights[$key] = (float) $definition['weight'];
        }
        return $weights;
    }

    /**
     * Client-facing symbol table (names, payouts, order). No probabilities —
     * those stay server side outside of local debug mode.
     *
     * @return list<array<string,mixed>>
     */
    public static function publicTable(): array
    {
        $scale = Config::float('game.payout_scale', 1.0);
        $table = [];
        foreach (self::all() as $key => $definition) {
            $table[] = [
                'key'    => $key,
                'name'   => (string) $definition['name'],
                'tier'   => (int) $definition['tier'],
                'pair'   => (int) round((int) $definition['pair'] * $scale),
                'triple' => (int) round((int) $definition['triple'] * $scale),
            ];
        }
        return $table;
    }
}
