<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

/**
 * The fully-resolved, capped effect of a player's upgrade build.
 *
 * Produced only by UpgradeEngine (server side). Nothing here is ever accepted
 * from a client — the client receives a read-only copy for display purposes.
 */
final class Modifiers
{
    /**
     * @param array<int,array<string,float>> $reelProbabilities reel (1-3) => symbol => probability (sums to 1)
     * @param array<string,mixed>            $dice
     * @param array<string,float>            $symbolPayoutMultipliers
     * @param array<int,float>               $pairHunter reel => chance
     * @param array<string,mixed>            $special
     */
    public function __construct(
        public readonly array $reelProbabilities,
        public readonly array $dice,
        public readonly float $pairMultiplier,
        public readonly float $tripleMultiplier,
        public readonly array $symbolPayoutMultipliers,
        public readonly float $comboMaxBonus,
        public readonly array $pairHunter,
        public readonly float $thirdReelMagnet,
        public readonly array $special,
    ) {
    }

    public function symbolMultiplier(string $symbol): float
    {
        return $this->symbolPayoutMultipliers[$symbol] ?? 1.0;
    }

    public function diceFlag(string $key): bool
    {
        return (bool) ($this->dice[$key] ?? false);
    }

    public function diceInt(string $key, int $default = 0): int
    {
        return isset($this->dice[$key]) ? (int) $this->dice[$key] : $default;
    }

    public function diceFloat(string $key, float $default = 0.0): float
    {
        return isset($this->dice[$key]) ? (float) $this->dice[$key] : $default;
    }

    public function specialFloat(string $key, float $default = 0.0): float
    {
        return isset($this->special[$key]) ? (float) $this->special[$key] : $default;
    }

    public function specialInt(string $key, int $default = 0): int
    {
        return isset($this->special[$key]) ? (int) $this->special[$key] : $default;
    }

    /**
     * Safe-to-expose summary. Used by the game UI (owned-upgrade panel) and,
     * with `reelProbabilities`, by the debug overlay in local mode only.
     *
     * @return array<string,mixed>
     */
    public function toDisplayArray(bool $includeProbabilities = false): array
    {
        $payload = [
            'pair_multiplier'   => round($this->pairMultiplier, 3),
            'triple_multiplier' => round($this->tripleMultiplier, 3),
            'combo_max_bonus'   => round($this->comboMaxBonus, 3),
            'dice'              => $this->dice,
            'special'           => $this->special,
            'symbol_payout'     => array_map(static fn (float $v): float => round($v, 3), $this->symbolPayoutMultipliers),
            'pair_hunter'       => $this->pairHunter,
            'third_reel_magnet' => round($this->thirdReelMagnet, 3),
        ];

        if ($includeProbabilities) {
            $payload['reel_probabilities'] = array_map(
                static fn (array $reel): array => array_map(
                    static fn (float $p): float => round($p, 5),
                    $reel
                ),
                $this->reelProbabilities
            );
        }

        return $payload;
    }
}
