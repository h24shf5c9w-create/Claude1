<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

/** Immutable outcome of one dice roll. */
final class DiceResult
{
    /** @param list<string> $modifiers human-readable labels for the UI */
    public function __construct(
        public readonly int $rawValue,
        public readonly int $finalValue,
        public readonly int $spins,
        public readonly array $modifiers = [],
    ) {
    }

    /** e.g. "5 · Lucky Six, +1 Spin" */
    public function display(): string
    {
        if ($this->modifiers === []) {
            return (string) $this->finalValue;
        }
        return $this->finalValue . ' · ' . implode(', ', $this->modifiers);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'raw_value'   => $this->rawValue,
            'final_value' => $this->finalValue,
            'spins'       => $this->spins,
            'modifiers'   => $this->modifiers,
            'display'     => $this->display(),
        ];
    }
}
