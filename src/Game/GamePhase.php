<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

/**
 * The game state machine.
 *
 * SERVER-AUTHORITATIVE phases live in the `matches.phase` column and decide
 * which actions are legal. CLIENT phases are presentation-only steps the
 * frontend passes through while animating; they never unlock an action.
 *
 *   lobby ──start──▶ starting ──▶ waiting_for_dice
 *                                       │ roll_dice
 *                                       ▼
 *                              (dice_animating)            [client]
 *                                       │
 *                                       ▼
 *                              waiting_for_spin ◀──┐
 *                                       │ spin      │ spins_remaining > 0
 *                                       ▼           │
 *                        (spin_processing → spin_animating) [client]
 *                                       └───────────┘
 *                                       │ spins_remaining == 0
 *                                       ▼
 *                                     shop
 *                                       │ end_turn
 *                                       ▼
 *                                 turn_ending  ──▶ waiting_for_next_player [client]
 *                                       │
 *                                       ▼
 *                              waiting_for_dice (next seat)
 *
 *   any phase ──target reached──▶ finished
 */
final class GamePhase
{
    /* -------- server-authoritative -------- */
    public const LOBBY            = 'lobby';
    public const WAITING_FOR_DICE = 'waiting_for_dice';
    public const WAITING_FOR_SPIN = 'waiting_for_spin';
    public const SHOP             = 'shop';
    public const FINISHED         = 'finished';

    /* -------- client-side presentation only -------- */
    public const STARTING              = 'starting';
    public const DICE_ANIMATING        = 'dice_animating';
    public const SPIN_PROCESSING       = 'spin_processing';
    public const SPIN_ANIMATING        = 'spin_animating';
    public const TURN_ENDING           = 'turn_ending';
    public const WAITING_FOR_NEXT      = 'waiting_for_next_player';
    public const WAITING_FOR_PLAYERS   = 'waiting_for_players';

    /** @return list<string> */
    public static function serverPhases(): array
    {
        return [self::WAITING_FOR_DICE, self::WAITING_FOR_SPIN, self::SHOP, self::FINISHED];
    }

    /** Which actions the active player may perform in a given phase. */
    public static function allows(string $phase, string $action): bool
    {
        return match ($action) {
            'roll_dice'   => $phase === self::WAITING_FOR_DICE,
            // A re-roll is only legal while the dice result has not been spun on.
            'reroll_dice' => $phase === self::WAITING_FOR_SPIN,
            'spin'        => $phase === self::WAITING_FOR_SPIN,
            // Upgrades are bought after the spins, in the shop step.
            'buy_upgrade' => $phase === self::SHOP,
            'end_turn'    => $phase === self::SHOP,
            default       => false,
        };
    }

    public static function isPlayable(string $phase): bool
    {
        return $phase !== self::FINISHED;
    }
}
