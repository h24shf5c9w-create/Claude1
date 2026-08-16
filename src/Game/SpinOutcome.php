<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

/** Immutable reel result, before payout calculation. */
final class SpinOutcome
{
    /**
     * @param list<string> $reels     three symbol keys (may include Symbols::WILD)
     * @param list<string> $modifiers labels of the effects that fired
     */
    public function __construct(
        public readonly array $reels,
        public readonly string $outcome,
        public readonly ?string $winSymbol,
        public readonly ?int $wildReel = null,
        public readonly array $modifiers = [],
    ) {
    }

    public function isWin(): bool
    {
        return $this->outcome !== SlotEngine::OUTCOME_NONE;
    }

    public function isTriple(): bool
    {
        return $this->outcome === SlotEngine::OUTCOME_TRIPLE;
    }

    /** True when reels 1 and 2 already match — the client plays the near-win build-up. */
    public function isNearWin(): bool
    {
        return $this->reels[0] === $this->reels[1] && $this->reels[1] !== $this->reels[2];
    }
}
