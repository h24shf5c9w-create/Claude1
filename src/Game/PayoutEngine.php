<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use RoyalSpin\Support\Config;
use RoyalSpin\Support\Rng;

/**
 * Converts a reel outcome into coins.
 *
 *   base            = symbol table value x payout_scale
 *   multiplier      = pair/triple bonus x symbol bonus x combo bonus  (capped)
 *   bonus           = Coin Rain + Lucky Streak + Jackpot Spark        (flat adds)
 *   final           = round(base x multiplier) + bonus
 */
final class PayoutEngine
{
    public function __construct(private readonly Rng $rng)
    {
    }

    /**
     * @param int $comboStreak consecutive winning spins BEFORE this one
     * @param int $winStreak   consecutive winning spins BEFORE this one (Lucky Streak)
     */
    public function calculate(
        SpinOutcome $spin,
        Modifiers $modifiers,
        int $comboStreak = 0,
        int $winStreak = 0
    ): PayoutResult {
        if (!$spin->isWin() || $spin->winSymbol === null) {
            return new PayoutResult(0, 0, 0, 1.0, []);
        }

        $scale  = Config::float('game.payout_scale', 1.0);
        $symbol = $spin->winSymbol;

        $baseTable = $spin->isTriple()
            ? Symbols::triplePayout($symbol)
            : Symbols::pairPayout($symbol);

        $base       = (int) round($baseTable * $scale);
        $breakdown  = [];
        $multiplier = 1.0;

        // --- pair / triple multipliers -----------------------------------------
        if ($spin->isTriple()) {
            if ($modifiers->tripleMultiplier > 1.0) {
                $multiplier *= $modifiers->tripleMultiplier;
                $breakdown[] = ['label' => 'Triple Profit', 'value' => '+' . self::percent($modifiers->tripleMultiplier)];
            }
        } elseif ($modifiers->pairMultiplier > 1.0) {
            $multiplier *= $modifiers->pairMultiplier;
            $breakdown[] = ['label' => 'Golden Pair', 'value' => '+' . self::percent($modifiers->pairMultiplier)];
        }

        // --- per-symbol multiplier ---------------------------------------------
        $symbolMultiplier = $modifiers->symbolMultiplier($symbol);
        if ($symbolMultiplier > 1.0) {
            $multiplier *= $symbolMultiplier;
            $breakdown[] = [
                'label' => Symbols::name($symbol) . ' Bonus',
                'value' => '+' . self::percent($symbolMultiplier),
            ];
        }

        // --- combo multiplier ---------------------------------------------------
        $comboBonus = 0.0;
        if ($modifiers->comboMaxBonus > 0.0 && $comboStreak > 0) {
            $step       = Config::float('game.special.combo.step', 0.10);
            $comboBonus = min($comboStreak * $step, $modifiers->comboMaxBonus);
            if ($comboBonus > 0.0) {
                $multiplier *= 1.0 + $comboBonus;
                $breakdown[] = [
                    'label' => 'Combo x' . ($comboStreak + 1),
                    'value' => '+' . self::percent(1.0 + $comboBonus),
                ];
            }
        }

        // --- hard ceiling on the compounded multiplier --------------------------
        $multiplier = min($multiplier, Config::float('game.caps.total_payout_multiplier', 6.0));

        $final = (int) round($base * $multiplier);

        // --- flat bonuses -------------------------------------------------------
        $bonus = 0;

        $coinRain = $modifiers->specialFloat('coin_rain');
        if ($spin->isTriple() && $coinRain > 0.0 && $this->rng->chance($coinRain)) {
            $settings   = Config::array('game.special');
            $rain       = $settings['coin_rain'] ?? [];
            $amount     = $this->rng->int((int) ($rain['min'] ?? 25), (int) ($rain['max'] ?? 90));
            $bonus     += $amount;
            $breakdown[] = ['label' => 'Coin Rain', 'value' => '+' . $amount];
        }

        $luckyStreakCoins = $modifiers->specialInt('lucky_streak');
        $required         = Config::int('game.special.lucky_streak.wins_required', 3);
        if ($luckyStreakCoins > 0 && $required > 0 && (($winStreak + 1) % $required) === 0) {
            $bonus      += $luckyStreakCoins;
            $breakdown[] = ['label' => 'Lucky Streak', 'value' => '+' . $luckyStreakCoins];
        }

        $jackpotChance = $modifiers->specialFloat('jackpot_spark');
        if ($spin->isTriple()
            && in_array($symbol, ['crown', 'trophy'], true)
            && $jackpotChance > 0.0
            && $this->rng->chance($jackpotChance)
        ) {
            $jackpot     = Config::int('game.special.jackpot_spark.bonus', 500);
            $bonus      += $jackpot;
            $breakdown[] = ['label' => 'JACKPOT SPARK', 'value' => '+' . $jackpot];
        }

        return new PayoutResult($base, $final + $bonus, $bonus, $multiplier, $breakdown);
    }

    private static function percent(float $multiplier): string
    {
        return (int) round(($multiplier - 1.0) * 100) . '%';
    }

    /**
     * Expected coins per spin for a build — used by the simulator and the
     * balance overlay. Exact for pair/triple maths; conditional reel upgrades
     * are already baked into the passed-in probabilities.
     */
    public static function expectedValue(Modifiers $modifiers): float
    {
        $scale         = Config::float('game.payout_scale', 1.0);
        $probabilities = $modifiers->reelProbabilities;
        $expected      = 0.0;

        foreach (Symbols::keys() as $symbol) {
            $p1 = $probabilities[1][$symbol] ?? 0.0;
            $p2 = $probabilities[2][$symbol] ?? 0.0;
            $p3 = $probabilities[3][$symbol] ?? 0.0;

            $tripleProbability = $p1 * $p2 * $p3;
            $pairProbability   = $p1 * $p2 * (1 - $p3) + $p1 * (1 - $p2) * $p3 + (1 - $p1) * $p2 * $p3;

            $tripleValue = Symbols::triplePayout($symbol) * $scale
                * min($modifiers->tripleMultiplier * $modifiers->symbolMultiplier($symbol),
                    Config::float('game.caps.total_payout_multiplier', 6.0));
            $pairValue = Symbols::pairPayout($symbol) * $scale
                * min($modifiers->pairMultiplier * $modifiers->symbolMultiplier($symbol),
                    Config::float('game.caps.total_payout_multiplier', 6.0));

            $expected += $tripleProbability * $tripleValue + $pairProbability * $pairValue;
        }

        return $expected;
    }
}
