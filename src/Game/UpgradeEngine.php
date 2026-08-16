<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use RoyalSpin\Support\Config;

/**
 * Turns a player's owned upgrade levels into a fully-resolved, capped set of
 * Modifiers.
 *
 * Guarantees enforced here (see §18 of the design brief):
 *   - no negative or zero-sum probability distributions
 *   - a symbol's weight can never exceed `caps.symbol_weight_multiplier` x base
 *   - after normalising, no symbol may exceed `caps.symbol_probability`
 *   - every payout multiplier is clamped
 *   - all reel distributions always sum to exactly 1
 */
final class UpgradeEngine
{
    /**
     * @param array<string,int> $ownedLevels upgrade_key => level
     */
    public static function compute(array $ownedLevels): Modifiers
    {
        $symbols   = Symbols::all();
        $caps      = Config::array('game.caps');
        $reelCount = Config::int('game.reels', 3);

        // ------------------------------------------------------------------
        // 1. Collect relative weight bonuses per reel/symbol.
        //    `$relative[$reel][$symbol]` accumulates additively; the weight is
        //    later multiplied by (1 + bonus). This is what makes "+10% Crown"
        //    mean 4% -> 4.4%, not 4% -> 14%.
        // ------------------------------------------------------------------
        $relative = [];
        for ($reel = 1; $reel <= $reelCount; $reel++) {
            foreach (array_keys($symbols) as $symbol) {
                $relative[$reel][$symbol] = 0.0;
            }
        }

        $dice = [
            'min_face'          => 1,
            'flat_bonus'        => 0,
            'six_bonus'         => 0,
            'second_roll'       => false,
            'advantage'         => false,
            'double_chance'     => 0.0,
            'reroll_ones'       => false,
            'lucky_start'       => 0,
            'multiplier_chance' => 0.0,
        ];

        $pairBonus       = 0.0;
        $tripleBonus     = 0.0;
        $symbolBonus     = [];
        $comboMaxBonus   = 0.0;
        $pairHunter      = [];
        $thirdReelMagnet = 0.0;
        $special         = [
            'wild_chance'    => 0.0,
            'second_chance'  => 0.0,
            'coin_rain'      => 0.0,
            'royal_blessing' => 0,
            'lucky_streak'   => 0,
            'jackpot_spark'  => 0.0,
        ];

        foreach ($ownedLevels as $key => $level) {
            $level   = (int) $level;
            $upgrade = UpgradeCatalog::get((string) $key);
            if ($upgrade === null || $level < 1) {
                continue;
            }
            // Defensive: never let stored data exceed the catalogue's max level.
            $level = min($level, (int) ($upgrade['max_level'] ?? 1));

            /** @var list<array<string,mixed>> $effects */
            $effects = $upgrade['effects'] ?? [];
            foreach ($effects as $effect) {
                $type  = (string) ($effect['type'] ?? '');
                $value = (float) ($effect['value'] ?? 0);

                switch ($type) {
                    case 'reel_symbol_rel':
                        /** @var list<int> $reels */
                        $reels = $effect['reels'] ?? [1, 2, 3];
                        /** @var list<string> $targets */
                        $targets = $effect['symbols'] ?? [];
                        foreach ($reels as $reel) {
                            foreach ($targets as $symbol) {
                                if (isset($relative[$reel][$symbol])) {
                                    $relative[$reel][$symbol] += $value * $level;
                                }
                            }
                        }
                        break;

                    case 'reel_tier_rel':
                        $reels   = $effect['reels'] ?? [1, 2, 3];
                        $minTier = (int) ($effect['min_tier'] ?? 3);
                        foreach ($reels as $reel) {
                            foreach ($symbols as $symbol => $definition) {
                                if ((int) $definition['tier'] >= $minTier && isset($relative[$reel][$symbol])) {
                                    $relative[$reel][$symbol] += $value * $level;
                                }
                            }
                        }
                        break;

                    case 'dice':
                        $diceKey = (string) ($effect['key'] ?? '');
                        switch ($diceKey) {
                            case 'min_face':
                                // Loaded Dice I/II are cumulative in intent but
                                // not additive — the strongest floor wins.
                                $dice['min_face'] = max($dice['min_face'], (int) $value);
                                break;
                            case 'flat_bonus':
                            case 'six_bonus':
                            case 'lucky_start':
                                $dice[$diceKey] += (int) $value * $level;
                                break;
                            case 'second_roll':
                            case 'advantage':
                            case 'reroll_ones':
                                $dice[$diceKey] = true;
                                break;
                            case 'double_chance':
                            case 'multiplier_chance':
                                $dice[$diceKey] += $value * $level;
                                break;
                        }
                        break;

                    case 'payout_pair':
                        $pairBonus += $value * $level;
                        break;

                    case 'payout_triple':
                        $tripleBonus += $value * $level;
                        break;

                    case 'payout_symbol':
                        /** @var list<string> $targets */
                        $targets = $effect['symbols'] ?? [];
                        foreach ($targets as $symbol) {
                            $symbolBonus[$symbol] = ($symbolBonus[$symbol] ?? 0.0) + $value * $level;
                        }
                        break;

                    case 'combo':
                        $comboMaxBonus += $value * $level;
                        break;

                    case 'pair_hunter':
                        $reel              = (int) ($effect['reel'] ?? 3);
                        $pairHunter[$reel] = ($pairHunter[$reel] ?? 0.0) + $value * $level;
                        break;

                    case 'third_reel_magnet':
                        $thirdReelMagnet += $value * $level;
                        break;

                    case 'wild_chance':
                    case 'second_chance':
                    case 'coin_rain':
                    case 'jackpot_spark':
                        $special[$type] = (float) $special[$type] + $value * $level;
                        break;

                    case 'royal_blessing':
                        $special['royal_blessing'] = (int) $special['royal_blessing'] + $level;
                        break;

                    case 'lucky_streak':
                        $special['lucky_streak'] = (int) $special['lucky_streak'] + (int) ($value * $level);
                        break;
                }
            }
        }

        // ------------------------------------------------------------------
        // 2. Apply the bonuses to the base weights, cap them, normalise.
        // ------------------------------------------------------------------
        $weightCap         = (float) ($caps['symbol_weight_multiplier'] ?? 4.0);
        $probabilityCap    = (float) ($caps['symbol_probability'] ?? 0.45);
        $reelProbabilities = [];

        for ($reel = 1; $reel <= $reelCount; $reel++) {
            $weights = [];
            foreach ($symbols as $symbol => $definition) {
                $base      = max(0.0, (float) $definition['weight']);
                $bonus     = max(-0.95, $relative[$reel][$symbol] ?? 0.0); // never negative weight
                $multiplier = min($weightCap, 1.0 + $bonus);
                $weights[$symbol] = $base * $multiplier;
            }
            $reelProbabilities[$reel] = self::normalise($weights, $probabilityCap);
        }

        // ------------------------------------------------------------------
        // 3. Clamp everything else.
        // ------------------------------------------------------------------
        $pairMultiplier   = min(1.0 + max(0.0, $pairBonus), (float) ($caps['pair_multiplier'] ?? 2.5));
        $tripleMultiplier = min(1.0 + max(0.0, $tripleBonus), (float) ($caps['triple_multiplier'] ?? 2.5));

        $symbolMultipliers = [];
        foreach (array_keys($symbols) as $symbol) {
            $bonus = max(0.0, $symbolBonus[$symbol] ?? 0.0);
            $symbolMultipliers[$symbol] = min(1.0 + $bonus, (float) ($caps['symbol_multiplier'] ?? 2.5));
        }

        $comboMaxBonus = min(max(0.0, $comboMaxBonus), (float) ($caps['combo_multiplier'] ?? 2.0) - 1.0);

        $conditionalCap = (float) ($caps['conditional_reel_chance'] ?? 0.6);
        foreach ($pairHunter as $reel => $chance) {
            $pairHunter[$reel] = min(max(0.0, $chance), $conditionalCap);
        }
        $thirdReelMagnet = min(max(0.0, $thirdReelMagnet), $conditionalCap);

        $special['wild_chance']   = min(max(0.0, (float) $special['wild_chance']), (float) ($caps['wild_chance'] ?? 0.1));
        $special['second_chance'] = min(max(0.0, (float) $special['second_chance']), 0.5);
        $special['coin_rain']     = min(max(0.0, (float) $special['coin_rain']), 0.6);
        $special['jackpot_spark'] = min(max(0.0, (float) $special['jackpot_spark']), 0.5);

        $dice['double_chance']     = min(max(0.0, (float) $dice['double_chance']), 0.35);
        $dice['multiplier_chance'] = min(max(0.0, (float) $dice['multiplier_chance']), 0.35);
        $dice['min_face']          = max(1, min((int) $dice['min_face'], Config::int('game.dice.faces', 6)));
        $dice['flat_bonus']        = max(0, min((int) $dice['flat_bonus'], 6));
        $dice['six_bonus']         = max(0, min((int) $dice['six_bonus'], 6));

        return new Modifiers(
            reelProbabilities: $reelProbabilities,
            dice: $dice,
            pairMultiplier: $pairMultiplier,
            tripleMultiplier: $tripleMultiplier,
            symbolPayoutMultipliers: $symbolMultipliers,
            comboMaxBonus: $comboMaxBonus,
            pairHunter: $pairHunter,
            thirdReelMagnet: $thirdReelMagnet,
            special: $special,
        );
    }

    /**
     * Normalise raw weights into a probability distribution, honouring a
     * per-symbol probability ceiling. Excess above the ceiling is redistributed
     * proportionally across the remaining symbols.
     *
     * @param array<string,float> $weights
     * @return array<string,float> sums to 1.0
     */
    public static function normalise(array $weights, float $cap = 1.0): array
    {
        $weights = array_map(static fn (float $w): float => max(0.0, $w), $weights);
        $total   = array_sum($weights);

        if ($total <= 0.0) {
            // Degenerate build — fall back to a uniform distribution rather
            // than dividing by zero mid-match.
            $count = max(1, count($weights));
            return array_map(static fn (): float => 1.0 / $count, $weights);
        }

        $probabilities = array_map(static fn (float $w): float => $w / $total, $weights);

        $cap   = max(0.0, min(1.0, $cap));
        $count = count($probabilities);
        if ($cap >= 1.0 || $count === 0 || $cap * $count <= 1.0) {
            // A cap that cannot be satisfied (too many symbols for the ceiling)
            // is ignored rather than producing an invalid distribution.
            return $probabilities;
        }

        for ($pass = 0; $pass < 10; $pass++) {
            $overflow = 0.0;
            $free     = [];
            foreach ($probabilities as $symbol => $probability) {
                if ($probability > $cap + 1e-12) {
                    $overflow             += $probability - $cap;
                    $probabilities[$symbol] = $cap;
                } elseif ($probability < $cap - 1e-12) {
                    $free[$symbol] = $probability;
                }
            }
            if ($overflow <= 1e-12) {
                break;
            }
            $freeTotal = array_sum($free);
            if ($freeTotal <= 0.0 || $free === []) {
                break;
            }
            foreach ($free as $symbol => $probability) {
                $probabilities[$symbol] = $probability + $overflow * ($probability / $freeTotal);
            }
        }

        // Guard against float drift so the distribution always sums to 1.
        $sum = array_sum($probabilities);
        if ($sum > 0.0 && abs($sum - 1.0) > 1e-9) {
            foreach ($probabilities as $symbol => $probability) {
                $probabilities[$symbol] = $probability / $sum;
            }
        }

        return $probabilities;
    }
}
