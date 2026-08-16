<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

/**
 * Per-match statistics, stored as JSON on `match_players.stats` and rolled up
 * into the permanent `user_stats` table when the match ends.
 */
final class PlayerStats
{
    private const DEFAULTS = [
        'spins'           => 0,
        'bonus_spins'     => 0,
        'pairs'           => 0,
        'triples'         => 0,
        'crown_triples'   => 0,
        'trophy_triples'  => 0,
        'biggest_win'     => 0,
        'highest_combo'   => 0,
        'upgrades_bought' => 0,
        'coins_won'       => 0,
        'dice_rolls'      => 0,
        'dice_total'      => 0,
    ];

    /**
     * @param array<string,mixed> $values
     * @return array<string,int>
     */
    public static function normalise(array $values): array
    {
        $stats = self::DEFAULTS;
        foreach ($stats as $key => $default) {
            $stats[$key] = isset($values[$key]) && is_numeric($values[$key]) ? (int) $values[$key] : $default;
        }
        return $stats;
    }

    /** @return array<string,int> */
    public static function fresh(): array
    {
        return self::DEFAULTS;
    }

    /** @return array<string,int> */
    public static function fromJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return self::fresh();
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? self::normalise($decoded) : self::fresh();
    }

    /**
     * Human-readable labels for the match result screen.
     *
     * @param array<string,int> $stats
     * @return list<array{label:string,value:string}>
     */
    public static function describe(array $stats): array
    {
        $stats     = self::normalise($stats);
        $rolls     = $stats['dice_rolls'];
        $averageDie = $rolls > 0 ? round($stats['dice_total'] / $rolls, 2) : 0.0;

        return [
            ['label' => 'Spins',            'value' => (string) $stats['spins']],
            ['label' => 'Pairs',            'value' => (string) $stats['pairs']],
            ['label' => 'Triples',          'value' => (string) $stats['triples']],
            ['label' => 'Crown triples',    'value' => (string) $stats['crown_triples']],
            ['label' => 'Trophy triples',   'value' => (string) $stats['trophy_triples']],
            ['label' => 'Biggest payout',   'value' => number_format($stats['biggest_win']) . ' coins'],
            ['label' => 'Highest combo',    'value' => 'x' . $stats['highest_combo']],
            ['label' => 'Upgrades bought',  'value' => (string) $stats['upgrades_bought']],
            ['label' => 'Dice rolled',      'value' => (string) $rolls],
            ['label' => 'Average roll',     'value' => number_format($averageDie, 2)],
            ['label' => 'Bonus spins',      'value' => (string) $stats['bonus_spins']],
        ];
    }
}
