<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use RoyalSpin\Support\Config;
use RoyalSpin\Support\Rng;

/**
 * Generates the three reel symbols and classifies the outcome.
 *
 * ALL of this runs on the server. The client receives the finished symbols and
 * only decides how to animate them — it can never influence what is shown.
 */
final class SlotEngine
{
    public const OUTCOME_NONE   = 'none';
    public const OUTCOME_PAIR   = 'pair';
    public const OUTCOME_TRIPLE = 'triple';

    public function __construct(private readonly Rng $rng)
    {
    }

    /**
     * @param int $spinsSinceTriple drives the Royal Blessing pity bonus
     */
    public function spin(Modifiers $modifiers, int $spinsSinceTriple = 0): SpinOutcome
    {
        $probabilities = self::effectiveProbabilities($modifiers, $spinsSinceTriple);
        $applied       = [];

        if ($spinsSinceTriple > Config::int('game.special.royal_blessing.spins_without_triple', 12)
            && $modifiers->specialInt('royal_blessing') > 0
        ) {
            $applied[] = 'Royal Blessing';
        }

        // ---- Reel 1 -----------------------------------------------------------
        $reels    = [];
        $reels[1] = $this->rng->weighted($probabilities[1]);

        // ---- Reel 2: Pair Hunter may copy reel 1 -------------------------------
        $pairHunter2 = $modifiers->pairHunter[2] ?? 0.0;
        if ($pairHunter2 > 0.0 && $this->rng->chance($pairHunter2)) {
            $reels[2]  = $reels[1];
            $applied[] = 'Pair Hunter II';
        } else {
            $reels[2] = $this->rng->weighted($probabilities[2]);
        }

        // ---- Reel 3: Third Reel Magnet, then Pair Hunter -----------------------
        $magnet = $modifiers->thirdReelMagnet;
        if ($reels[1] === $reels[2] && $magnet > 0.0 && $this->rng->chance($magnet)) {
            $reels[3]  = $reels[1];
            $applied[] = 'Third Reel Magnet';
        } else {
            $pairHunter3 = $modifiers->pairHunter[3] ?? 0.0;
            if ($pairHunter3 > 0.0 && $this->rng->chance($pairHunter3)) {
                $reels[3]  = $this->rng->chance(0.5) ? $reels[1] : $reels[2];
                $applied[] = 'Pair Hunter III';
            } else {
                $reels[3] = $this->rng->weighted($probabilities[3]);
            }
        }

        // ---- Wild Chance ------------------------------------------------------
        $wildReel   = null;
        $wildChance = $modifiers->specialFloat('wild_chance');
        if ($wildChance > 0.0 && $this->rng->chance($wildChance)) {
            $wildReel            = $this->rng->int(1, 3);
            $reels[$wildReel]    = Symbols::WILD;
            $applied[]           = 'Wild Chance';
        }

        $evaluation = self::evaluate([$reels[1], $reels[2], $reels[3]]);

        return new SpinOutcome(
            reels: [$reels[1], $reels[2], $reels[3]],
            outcome: $evaluation['outcome'],
            winSymbol: $evaluation['symbol'],
            wildReel: $wildReel,
            modifiers: $applied,
        );
    }

    /**
     * Classify three symbols. A wild substitutes for anything; if the other two
     * differ, the wild forms a pair with the more valuable of them.
     *
     * @param list<string> $reels
     * @return array{outcome:string, symbol:?string}
     */
    public static function evaluate(array $reels): array
    {
        $wildCount = count(array_filter($reels, static fn (string $s): bool => $s === Symbols::WILD));
        $concrete  = array_values(array_filter($reels, static fn (string $s): bool => $s !== Symbols::WILD));

        if ($wildCount >= 2) {
            // Two or more wilds always complete a triple of the remaining symbol.
            $symbol = $concrete[0] ?? Symbols::keys()[0];
            return ['outcome' => self::OUTCOME_TRIPLE, 'symbol' => $symbol];
        }

        if ($wildCount === 1) {
            [$a, $b] = [$concrete[0], $concrete[1]];
            if ($a === $b) {
                return ['outcome' => self::OUTCOME_TRIPLE, 'symbol' => $a];
            }
            $best = Symbols::pairPayout($a) >= Symbols::pairPayout($b) ? $a : $b;
            return ['outcome' => self::OUTCOME_PAIR, 'symbol' => $best];
        }

        $counts = array_count_values($reels);
        foreach ($counts as $symbol => $count) {
            if ($count === 3) {
                return ['outcome' => self::OUTCOME_TRIPLE, 'symbol' => (string) $symbol];
            }
        }
        foreach ($counts as $symbol => $count) {
            if ($count === 2) {
                return ['outcome' => self::OUTCOME_PAIR, 'symbol' => (string) $symbol];
            }
        }

        return ['outcome' => self::OUTCOME_NONE, 'symbol' => null];
    }

    /**
     * Per-reel probabilities actually used for the next spin, including the
     * dynamic Royal Blessing pity bonus.
     *
     * @return array<int,array<string,float>>
     */
    public static function effectiveProbabilities(Modifiers $modifiers, int $spinsSinceTriple = 0): array
    {
        $blessingLevel = $modifiers->specialInt('royal_blessing');
        if ($blessingLevel <= 0) {
            return $modifiers->reelProbabilities;
        }

        $settings  = Config::array('game.special');
        $blessing  = $settings['royal_blessing'] ?? [];
        $threshold = (int) ($blessing['spins_without_triple'] ?? 12);
        if ($spinsSinceTriple <= $threshold) {
            return $modifiers->reelProbabilities;
        }

        $perSpin = (float) ($blessing['bonus_per_spin'] ?? 0.03);
        $maximum = (float) ($blessing['max_bonus'] ?? 0.6);
        $bonus   = min($maximum, ($spinsSinceTriple - $threshold) * $perSpin * $blessingLevel);
        if ($bonus <= 0.0) {
            return $modifiers->reelProbabilities;
        }

        $cap    = Config::float('game.caps.symbol_probability', 0.45);
        $result = [];
        foreach ($modifiers->reelProbabilities as $reel => $distribution) {
            $weights = [];
            foreach ($distribution as $symbol => $probability) {
                $weights[$symbol] = Symbols::tier($symbol) >= 4
                    ? $probability * (1.0 + $bonus)
                    : $probability;
            }
            $result[$reel] = UpgradeEngine::normalise($weights, $cap);
        }

        return $result;
    }
}
