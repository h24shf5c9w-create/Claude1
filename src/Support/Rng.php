<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Randomizer;

/**
 * The single random source for all game outcomes.
 *
 * Production always uses the CSPRNG (`Random\Engine\Secure`). Tests and the
 * balance simulator may pass a seed to get reproducible sequences — a seeded
 * instance is never used to serve a real match.
 */
final class Rng
{
    private Randomizer $randomizer;

    public function __construct(private readonly ?int $seed = null)
    {
        $this->randomizer = $seed === null
            ? new Randomizer(new Secure())
            : new Randomizer(new Mt19937($seed));
    }

    public function isSeeded(): bool
    {
        return $this->seed !== null;
    }

    /** Inclusive on both ends. */
    public function int(int $min, int $max): int
    {
        if ($min >= $max) {
            return $min;
        }
        return $this->randomizer->getInt($min, $max);
    }

    /** Uniform float in [0, 1). */
    public function float(): float
    {
        return $this->randomizer->getFloat(0.0, 1.0, \Random\IntervalBoundary::ClosedOpen);
    }

    public function chance(float $probability): bool
    {
        if ($probability <= 0.0) {
            return false;
        }
        if ($probability >= 1.0) {
            return true;
        }
        return $this->float() < $probability;
    }

    /**
     * Weighted pick over `key => weight`. Weights may be any non-negative
     * floats; they do not have to sum to 1.
     *
     * @param array<string,float> $weights
     */
    public function weighted(array $weights): string
    {
        $total = 0.0;
        foreach ($weights as $weight) {
            if ($weight > 0) {
                $total += $weight;
            }
        }
        if ($total <= 0.0) {
            // Degenerate configuration — fall back to a uniform pick rather
            // than throwing in the middle of a live match.
            $keys = array_keys($weights);
            return $keys[$this->int(0, count($keys) - 1)];
        }

        $roll   = $this->float() * $total;
        $cursor = 0.0;
        foreach ($weights as $key => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $cursor += $weight;
            if ($roll < $cursor) {
                return (string) $key;
            }
        }

        // Floating point tail — return the last positive-weight key.
        $last = null;
        foreach ($weights as $key => $weight) {
            if ($weight > 0) {
                $last = (string) $key;
            }
        }
        return $last ?? (string) array_key_first($weights);
    }

    /**
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return $this->randomizer->shuffleArray($items);
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    public function pickDistinct(array $items, int $count): array
    {
        $shuffled = $this->shuffle($items);
        return array_slice($shuffled, 0, max(0, $count));
    }
}
