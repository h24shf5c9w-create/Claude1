<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use RoyalSpin\Support\Config;
use RoyalSpin\Support\Rng;

/**
 * Rolls the die and converts the face into a spin count.
 *
 * Design rule from the brief: a high roll must never be worse than a low roll.
 * Spins cost nothing, so more spins is strictly better and every dice upgrade
 * is purely additive.
 */
final class DiceEngine
{
    public function __construct(private readonly Rng $rng)
    {
    }

    /**
     * @param int $turnsTaken how many turns this player has already completed
     *                        (used by Lucky Start)
     */
    public function roll(Modifiers $modifiers, int $turnsTaken = 0): DiceResult
    {
        $faces     = Config::int('game.dice.faces', 6);
        $maxSpins  = Config::int('game.dice.max_spins', 14);
        $applied   = [];

        // --- 1. the physical roll -------------------------------------------------
        $rerollOnes = $modifiers->diceFlag('reroll_ones');
        $rollOne    = function () use ($faces, $rerollOnes): int {
            $value = $this->rng->int(1, $faces);
            if ($rerollOnes) {
                // "You can no longer roll a 1" — re-roll until it is not a 1,
                // giving an even chance across 2..faces.
                $guard = 0;
                while ($value === 1 && $guard++ < 50) {
                    $value = $this->rng->int(1, $faces);
                }
            }
            return $value;
        };

        $rawValue = $rollOne();
        if ($modifiers->diceFlag('advantage')) {
            $second   = $rollOne();
            $applied[] = 'Advantage Dice';
            $rawValue = max($rawValue, $second);
        }
        if ($rerollOnes) {
            $applied[] = 'Minimum Roll';
        }

        // --- 2. loaded dice: a rolled 1 is lifted -------------------------------
        $finalValue = $rawValue;
        $minFace    = $modifiers->diceInt('min_face', 1);
        if ($rawValue === 1 && $minFace > 1) {
            $finalValue = $minFace;
            $applied[]  = $minFace >= 3 ? 'Loaded Dice II' : 'Loaded Dice I';
        }

        // --- 3. face -> spins ------------------------------------------------------
        $spins = $finalValue;

        $flatBonus = $modifiers->diceInt('flat_bonus');
        if ($flatBonus > 0) {
            $spins    += $flatBonus;
            $applied[] = '+' . $flatBonus . ' Spin' . ($flatBonus > 1 ? 's' : '');
        }

        $sixBonus = $modifiers->diceInt('six_bonus');
        if ($sixBonus > 0 && $finalValue === $faces) {
            $spins    += $sixBonus;
            $applied[] = 'Lucky Six';
        }

        $luckyStart = $modifiers->diceInt('lucky_start');
        if ($luckyStart > 0 && $turnsTaken < Config::int('game.dice.lucky_start_turns', 2)) {
            $spins    += $luckyStart;
            $applied[] = 'Lucky Start';
        }

        // --- 4. multiplicative dice upgrades ---------------------------------------
        $doubleChance = $modifiers->diceFloat('double_chance');
        if ($doubleChance > 0.0 && $this->rng->chance($doubleChance)) {
            $spins    *= 2;
            $applied[] = 'Golden Dice';
        }

        $multiplierChance = $modifiers->diceFloat('multiplier_chance');
        if ($multiplierChance > 0.0 && $this->rng->chance($multiplierChance)) {
            $spins     = (int) ceil($spins * 1.5);
            $applied[] = 'Dice Multiplier';
        }

        $spins = max(1, min($spins, $maxSpins));

        return new DiceResult(
            rawValue: $rawValue,
            finalValue: $finalValue,
            spins: $spins,
            modifiers: $applied,
        );
    }

    /** Whether the player may still use their Second Roll this turn. */
    public static function canReroll(Modifiers $modifiers, bool $alreadyUsed): bool
    {
        return $modifiers->diceFlag('second_roll') && !$alreadyUsed;
    }

    /**
     * Exact spin-count distribution for the current build. Powers the debug
     * overlay and the balance simulator; never sent to production clients.
     *
     * @return array<int,float> spins => probability
     */
    public static function spinDistribution(Modifiers $modifiers, int $turnsTaken = 0): array
    {
        $faces      = Config::int('game.dice.faces', 6);
        $maxSpins   = Config::int('game.dice.max_spins', 14);
        $rerollOnes = $modifiers->diceFlag('reroll_ones');
        $advantage  = $modifiers->diceFlag('advantage');

        // Probability of each raw face.
        $facePr = [];
        for ($face = 1; $face <= $faces; $face++) {
            $facePr[$face] = $rerollOnes
                ? ($face === 1 ? 0.0 : 1.0 / ($faces - 1))
                : 1.0 / $faces;
        }
        if ($advantage) {
            // max of two independent draws: P(max = k) = P(<=k)^2 - P(<=k-1)^2
            $cumulative = 0.0;
            $advPr      = [];
            foreach ($facePr as $face => $probability) {
                $previous          = $cumulative;
                $cumulative       += $probability;
                $advPr[$face]      = $cumulative ** 2 - $previous ** 2;
            }
            $facePr = $advPr;
        }

        $minFace          = $modifiers->diceInt('min_face', 1);
        $flatBonus        = $modifiers->diceInt('flat_bonus');
        $sixBonus         = $modifiers->diceInt('six_bonus');
        $luckyStart       = $modifiers->diceInt('lucky_start') > 0
            && $turnsTaken < Config::int('game.dice.lucky_start_turns', 2)
            ? $modifiers->diceInt('lucky_start')
            : 0;
        $doubleChance     = $modifiers->diceFloat('double_chance');
        $multiplierChance = $modifiers->diceFloat('multiplier_chance');

        $distribution = [];
        foreach ($facePr as $face => $faceProbability) {
            if ($faceProbability <= 0.0) {
                continue;
            }
            $finalFace = ($face === 1 && $minFace > 1) ? $minFace : $face;
            $base      = $finalFace + $flatBonus + $luckyStart + ($finalFace === $faces ? $sixBonus : 0);

            foreach ([[true, $doubleChance], [false, 1.0 - $doubleChance]] as [$doubled, $doubleProbability]) {
                if ($doubleProbability <= 0.0) {
                    continue;
                }
                $afterDouble = $doubled ? $base * 2 : $base;
                foreach ([[true, $multiplierChance], [false, 1.0 - $multiplierChance]] as [$multiplied, $multProbability]) {
                    if ($multProbability <= 0.0) {
                        continue;
                    }
                    $spins = $multiplied ? (int) ceil($afterDouble * 1.5) : $afterDouble;
                    $spins = max(1, min($spins, $maxSpins));
                    $distribution[$spins] = ($distribution[$spins] ?? 0.0)
                        + $faceProbability * $doubleProbability * $multProbability;
                }
            }
        }

        ksort($distribution);
        return $distribution;
    }
}
