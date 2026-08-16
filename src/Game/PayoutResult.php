<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

/** Immutable payout calculation. */
final class PayoutResult
{
    /** @param list<array{label:string,value:string}> $breakdown */
    public function __construct(
        public readonly int $base,
        public readonly int $total,
        public readonly int $bonus,
        public readonly float $multiplier,
        public readonly array $breakdown,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'base'       => $this->base,
            'total'      => $this->total,
            'bonus'      => $this->bonus,
            'multiplier' => round($this->multiplier, 3),
            'breakdown'  => $this->breakdown,
        ];
    }
}
