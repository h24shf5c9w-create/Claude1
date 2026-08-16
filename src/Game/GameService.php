<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use PDOException;
use RoyalSpin\Realtime\EventBus;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Rng;
use Throwable;

/**
 * The authoritative game server.
 *
 * Every mutating method follows the same shape:
 *   1. open a transaction and SELECT ... FOR UPDATE the `matches` row
 *      (serialises all concurrent actions on that match)
 *   2. claim the client's action id in `action_log` — a replay returns the
 *      stored result instead of running again
 *   3. validate: authenticated? in this match? their turn? right phase?
 *   4. mutate state, write the audit rows, append events
 *   5. commit — the WebSocket relay picks the events up and fans them out
 *
 * The client never decides anything. It sends an intent and animates a result.
 */
final class GameService
{
    public function __construct(private readonly Rng $rng = new Rng())
    {
    }

    /* ==================================================================
     |  Match start
     * ================================================================== */

    /**
     * @return array{ok:bool, error?:string, match_id?:int}
     */
    public function startMatch(int $userId, int $roomId): array
    {
        return Db::transaction(function () use ($userId, $roomId): array {
            $room = Db::first('SELECT * FROM rooms WHERE id = :id' . Db::forUpdate(), ['id' => $roomId]);
            if ($room === null) {
                return ['ok' => false, 'error' => 'This room no longer exists.'];
            }
            if ((int) $room['host_user_id'] !== $userId) {
                return ['ok' => false, 'error' => 'Only the host can start the match.'];
            }
            if ((string) $room['status'] === 'active') {
                return ['ok' => true, 'match_id' => (int) $room['match_id']];
            }

            $players = Db::all(
                'SELECT rp.*, u.username FROM room_players rp
                   INNER JOIN users u ON u.id = rp.user_id
                  WHERE rp.room_id = :room_id
               ORDER BY rp.seat ASC',
                ['room_id' => $roomId]
            );

            $minPlayers = Config::int('game.min_players', 2);
            if (count($players) < $minPlayers) {
                return ['ok' => false, 'error' => "You need at least {$minPlayers} players to start."];
            }
            foreach ($players as $player) {
                if (!(bool) $player['is_ready']) {
                    return ['ok' => false, 'error' => 'All players must be ready.'];
                }
            }

            $now           = Db::now();
            $startingCoins = Config::int('game.starting_coins', 150);

            $matchId = Db::insert('matches', [
                'room_code'       => (string) $room['code'],
                'status'          => 'active',
                'phase'           => GamePhase::WAITING_FOR_DICE,
                'target_coins'    => (int) $room['target_coins'],
                'starting_coins'  => $startingCoins,
                'player_count'    => count($players),
                'current_seat'    => (int) $players[0]['seat'],
                'turn_number'     => 1,
                'round_number'    => 1,
                'winner_user_id'  => null,
                'event_seq'       => 0,
                'config_snapshot' => json_encode([
                    'target_coins'   => (int) $room['target_coins'],
                    'starting_coins' => $startingCoins,
                    'payout_scale'   => Config::float('game.payout_scale', 1.0),
                    'symbols'        => Config::array('game.symbols'),
                ], JSON_UNESCAPED_UNICODE) ?: null,
                'turn_started_at' => $now,
                'started_at'      => $now,
                'finished_at'     => null,
                'updated_at'      => $now,
            ]);

            // Re-seat contiguously from 0 so turn order is always 0,1,2,3.
            $seat = 0;
            foreach ($players as $player) {
                Db::insert('match_players', [
                    'match_id'           => $matchId,
                    'user_id'            => (int) $player['user_id'],
                    'username'           => (string) $player['username'],
                    'seat'               => $seat,
                    'coins'              => $startingCoins,
                    'dice_value'         => null,
                    'dice_display'       => null,
                    'spins_total'        => 0,
                    'spins_remaining'    => 0,
                    'second_roll_used'   => 0,
                    'combo_streak'       => 0,
                    'win_streak'         => 0,
                    'spins_since_triple' => 0,
                    'turns_taken'        => 0,
                    'connection_status'  => 'online',
                    'last_seen_at'       => $now,
                    'stats'              => json_encode(PlayerStats::fresh()) ?: null,
                    'placement'          => null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
                $seat++;
            }

            Db::update('rooms', [
                'status'     => 'active',
                'match_id'   => $matchId,
                'updated_at' => $now,
            ], ['id' => $roomId]);

            Db::update('matches', ['current_seat' => 0], ['id' => $matchId]);

            EventBus::emit($matchId, EventBus::MATCH_STARTED, [
                'match_id'     => $matchId,
                'room_code'    => (string) $room['code'],
                'target_coins' => (int) $room['target_coins'],
                'current_seat' => 0,
            ]);

            return ['ok' => true, 'match_id' => $matchId];
        });
    }

    /* ==================================================================
     |  Actions
     * ================================================================== */

    /**
     * Roll the die (or use Second Roll).
     *
     * @return array{ok:bool, error?:string, dice?:array<string,mixed>, replayed?:bool}
     */
    public function rollDice(int $userId, int $matchId, ?string $actionId = null, bool $reroll = false): array
    {
        return $this->mutate($matchId, $userId, $actionId, $reroll ? 'reroll_dice' : 'roll_dice',
            function (array $match, array $player) use ($matchId, $userId, $reroll): array {
                $action = $reroll ? 'reroll_dice' : 'roll_dice';
                if (!GamePhase::allows((string) $match['phase'], $action)) {
                    return ['ok' => false, 'error' => $reroll
                        ? 'You can only re-roll right after your first roll.'
                        : 'You have already rolled this turn.'];
                }

                $levels    = $this->upgradeLevels((int) $player['id']);
                $modifiers = UpgradeEngine::compute($levels);

                if ($reroll) {
                    if (!DiceEngine::canReroll($modifiers, (bool) $player['second_roll_used'])) {
                        return ['ok' => false, 'error' => 'You have no re-roll available.'];
                    }
                    // Re-rolling is only allowed before any spin of this turn.
                    if ((int) $player['spins_remaining'] !== (int) $player['spins_total']) {
                        return ['ok' => false, 'error' => 'You already used a spin this turn.'];
                    }
                }

                $dice   = (new DiceEngine($this->rng))->roll($modifiers, (int) $player['turns_taken']);
                $now    = Db::now();
                $stats  = PlayerStats::fromJson($player['stats'] === null ? null : (string) $player['stats']);
                $stats['dice_rolls']++;
                $stats['dice_total'] += $dice->finalValue;

                Db::update('match_players', [
                    'dice_value'       => $dice->finalValue,
                    'dice_display'     => $dice->display(),
                    'spins_total'      => $dice->spins,
                    'spins_remaining'  => $dice->spins,
                    'second_roll_used' => $reroll ? 1 : (int) $player['second_roll_used'],
                    'stats'            => json_encode($stats) ?: null,
                    'updated_at'       => $now,
                ], ['id' => (int) $player['id']]);

                Db::insert('dice_rolls', [
                    'match_id'        => $matchId,
                    'match_player_id' => (int) $player['id'],
                    'turn_number'     => (int) $match['turn_number'],
                    'raw_value'       => $dice->rawValue,
                    'final_value'     => $dice->finalValue,
                    'spins_granted'   => $dice->spins,
                    'is_reroll'       => $reroll ? 1 : 0,
                    'modifiers'       => json_encode($dice->modifiers) ?: null,
                    'created_at'      => $now,
                ]);

                Db::update('matches', [
                    'phase'      => GamePhase::WAITING_FOR_SPIN,
                    'updated_at' => $now,
                ], ['id' => $matchId]);

                EventBus::emit($matchId, EventBus::DICE_ROLLED, [
                    'user_id'   => $userId,
                    'seat'      => (int) $player['seat'],
                    'username'  => (string) $player['username'],
                    'dice'      => $dice->toArray(),
                    'is_reroll' => $reroll,
                    'can_reroll'=> DiceEngine::canReroll($modifiers, $reroll || (bool) $player['second_roll_used']),
                ]);

                return ['ok' => true, 'dice' => $dice->toArray()];
            });
    }

    /**
     * Play one spin.
     *
     * @return array{ok:bool, error?:string, spin?:array<string,mixed>, replayed?:bool}
     */
    public function spin(int $userId, int $matchId, ?string $actionId = null): array
    {
        return $this->mutate($matchId, $userId, $actionId, 'spin',
            function (array $match, array $player) use ($matchId, $userId): array {
                if (!GamePhase::allows((string) $match['phase'], 'spin')) {
                    return ['ok' => false, 'error' => 'Roll the dice first.'];
                }
                if ((int) $player['spins_remaining'] < 1) {
                    return ['ok' => false, 'error' => 'You have no spins left this turn.'];
                }

                $playerId  = (int) $player['id'];
                $levels    = $this->upgradeLevels($playerId);
                $modifiers = UpgradeEngine::compute($levels);

                $spinsSinceTriple = (int) $player['spins_since_triple'];
                $comboStreak      = (int) $player['combo_streak'];
                $winStreak        = (int) $player['win_streak'];

                $outcome = (new SlotEngine($this->rng))->spin($modifiers, $spinsSinceTriple);
                $payout  = (new PayoutEngine($this->rng))->calculate($outcome, $modifiers, $comboStreak, $winStreak);

                $spinsRemaining = (int) $player['spins_remaining'] - 1;
                $spinIndex      = (int) $player['spins_total'] - $spinsRemaining;
                $coinsAfter     = (int) $player['coins'] + $payout->total;

                // --- streaks -------------------------------------------------
                if ($outcome->isWin()) {
                    $comboStreak++;
                    $winStreak++;
                } else {
                    $comboStreak = 0;
                    $winStreak   = 0;
                }
                $spinsSinceTriple = $outcome->isTriple() ? 0 : $spinsSinceTriple + 1;

                // --- Second Chance: a free extra spin after a total loss ------
                $bonusSpinGranted = false;
                $secondChance     = $modifiers->specialFloat('second_chance');
                if (!$outcome->isWin() && $secondChance > 0.0 && $this->rng->chance($secondChance)) {
                    $spinsRemaining++;
                    $bonusSpinGranted = true;
                }

                // --- statistics ----------------------------------------------
                $stats = PlayerStats::fromJson($player['stats'] === null ? null : (string) $player['stats']);
                $stats['spins']++;
                if ($bonusSpinGranted) {
                    $stats['bonus_spins']++;
                }
                if ($outcome->outcome === SlotEngine::OUTCOME_PAIR) {
                    $stats['pairs']++;
                } elseif ($outcome->isTriple()) {
                    $stats['triples']++;
                    if ($outcome->winSymbol === 'crown') {
                        $stats['crown_triples']++;
                    } elseif ($outcome->winSymbol === 'trophy') {
                        $stats['trophy_triples']++;
                    }
                }
                $stats['coins_won']    += $payout->total;
                $stats['biggest_win']   = max($stats['biggest_win'], $payout->total);
                $stats['highest_combo'] = max($stats['highest_combo'], $comboStreak);

                $now = Db::now();
                Db::update('match_players', [
                    'coins'              => $coinsAfter,
                    'spins_remaining'    => $spinsRemaining,
                    'combo_streak'       => $comboStreak,
                    'win_streak'         => $winStreak,
                    'spins_since_triple' => $spinsSinceTriple,
                    'stats'              => json_encode($stats) ?: null,
                    'updated_at'         => $now,
                ], ['id' => $playerId]);

                Db::insert('spins', [
                    'match_id'        => $matchId,
                    'match_player_id' => $playerId,
                    'turn_number'     => (int) $match['turn_number'],
                    'spin_index'      => max(1, $spinIndex),
                    'reel_1'          => $outcome->reels[0],
                    'reel_2'          => $outcome->reels[1],
                    'reel_3'          => $outcome->reels[2],
                    'outcome'         => $outcome->outcome,
                    'win_symbol'      => $outcome->winSymbol,
                    'base_payout'     => $payout->base,
                    'final_payout'    => $payout->total,
                    'bonus_payout'    => $payout->bonus,
                    'coins_after'     => $coinsAfter,
                    'is_bonus_spin'   => 0,
                    'modifiers'       => json_encode([
                        'spin'      => $outcome->modifiers,
                        'payout'    => $payout->breakdown,
                        'wild_reel' => $outcome->wildReel,
                        'upgrades'  => $levels,
                    ], JSON_UNESCAPED_UNICODE) ?: null,
                    'created_at'      => $now,
                ]);

                $payload = [
                    'user_id'         => $userId,
                    'seat'            => (int) $player['seat'],
                    'username'        => (string) $player['username'],
                    'reels'           => $outcome->reels,
                    'outcome'         => $outcome->outcome,
                    'win_symbol'      => $outcome->winSymbol,
                    'symbol_name'     => $outcome->winSymbol === null ? null : Symbols::name($outcome->winSymbol),
                    'wild_reel'       => $outcome->wildReel,
                    'near_win'        => $outcome->isNearWin(),
                    'payout'          => $payout->toArray(),
                    'coins'           => $coinsAfter,
                    'spins_remaining' => $spinsRemaining,
                    'combo_streak'    => $comboStreak,
                    'bonus_spin'      => $bonusSpinGranted,
                    'spin_modifiers'  => $outcome->modifiers,
                    'target_coins'    => (int) $match['target_coins'],
                ];

                // --- win check -----------------------------------------------
                $reachedTarget = $coinsAfter >= (int) $match['target_coins'];
                if ($reachedTarget) {
                    $payload['winning_spin'] = true;
                }

                if (!$reachedTarget && $spinsRemaining < 1) {
                    // Spins exhausted -> shop step.
                    Db::update('matches', ['phase' => GamePhase::SHOP, 'updated_at' => $now], ['id' => $matchId]);
                    $payload['phase'] = GamePhase::SHOP;
                } else {
                    $payload['phase'] = $reachedTarget ? GamePhase::FINISHED : GamePhase::WAITING_FOR_SPIN;
                }

                EventBus::emit($matchId, EventBus::SPIN_RESULT, $payload);

                if ($reachedTarget) {
                    // The spin is fully paid out and broadcast first; the match
                    // is then sealed so no further action can change anything.
                    $this->finishMatch($matchId, $userId);
                } elseif ($spinsRemaining < 1) {
                    $this->refreshShopOffers($matchId, $playerId, $userId, $coinsAfter);
                }

                return ['ok' => true, 'spin' => $payload];
            });
    }

    /**
     * Buy the next level of an offered upgrade.
     *
     * @return array{ok:bool, error?:string, upgrade?:array<string,mixed>, replayed?:bool}
     */
    public function buyUpgrade(int $userId, int $matchId, string $upgradeKey, ?string $actionId = null): array
    {
        return $this->mutate($matchId, $userId, $actionId, 'buy_upgrade',
            function (array $match, array $player) use ($matchId, $userId, $upgradeKey): array {
                if (!GamePhase::allows((string) $match['phase'], 'buy_upgrade')) {
                    return ['ok' => false, 'error' => 'The shop opens after you have played all your spins.'];
                }
                if (!UpgradeCatalog::has($upgradeKey)) {
                    return ['ok' => false, 'error' => 'Unknown upgrade.'];
                }

                $playerId = (int) $player['id'];

                // The price is taken from the persisted offer, never from the
                // client, and the upgrade must actually be on offer right now.
                $offer = Db::first(
                    'SELECT * FROM shop_offers
                      WHERE match_player_id = :player AND upgrade_key = :key AND purchased = 0',
                    ['player' => $playerId, 'key' => $upgradeKey]
                );
                if ($offer === null) {
                    return ['ok' => false, 'error' => 'That upgrade is not on offer right now.'];
                }

                $levels = $this->upgradeLevels($playerId);
                $check  = UpgradeCatalog::purchaseCheck($upgradeKey, $levels);
                if (!$check['ok']) {
                    return ['ok' => false, 'error' => $check['reason'] ?? 'You cannot buy this upgrade.'];
                }

                $cost = (int) $offer['cost'];
                if ((int) $player['coins'] < $cost) {
                    return ['ok' => false, 'error' => 'Not enough coins.'];
                }

                $nextLevel  = $check['next_level'];
                $coinsAfter = (int) $player['coins'] - $cost;
                $now        = Db::now();

                $existing = Db::first(
                    'SELECT id, coins_spent FROM player_upgrades
                      WHERE match_player_id = :player AND upgrade_key = :key',
                    ['player' => $playerId, 'key' => $upgradeKey]
                );
                if ($existing === null) {
                    Db::insert('player_upgrades', [
                        'match_player_id' => $playerId,
                        'upgrade_key'     => $upgradeKey,
                        'level'           => $nextLevel,
                        'coins_spent'     => $cost,
                        'updated_at'      => $now,
                    ]);
                } else {
                    Db::update('player_upgrades', [
                        'level'       => $nextLevel,
                        'coins_spent' => (int) $existing['coins_spent'] + $cost,
                        'updated_at'  => $now,
                    ], ['id' => (int) $existing['id']]);
                }

                Db::update('shop_offers', ['purchased' => 1], ['id' => (int) $offer['id']]);

                $stats = PlayerStats::fromJson($player['stats'] === null ? null : (string) $player['stats']);
                $stats['upgrades_bought']++;

                Db::update('match_players', [
                    'coins'      => $coinsAfter,
                    'stats'      => json_encode($stats) ?: null,
                    'updated_at' => $now,
                ], ['id' => $playerId]);
                Db::update('matches', ['updated_at' => $now], ['id' => $matchId]);

                $definition = UpgradeCatalog::get($upgradeKey) ?? [];

                EventBus::emit($matchId, EventBus::UPGRADE_PURCHASED, [
                    'user_id'     => $userId,
                    'seat'        => (int) $player['seat'],
                    'username'    => (string) $player['username'],
                    'upgrade_key' => $upgradeKey,
                    'name'        => (string) ($definition['name'] ?? $upgradeKey),
                    'icon'        => (string) ($definition['icon'] ?? 'sparkle'),
                    'category'    => (string) ($definition['category'] ?? 'special'),
                    'rarity'      => (string) ($definition['rarity'] ?? 'common'),
                    'reel'        => UpgradeCatalog::reelFor((string) ($definition['category'] ?? '')),
                    'level'       => $nextLevel,
                    'cost'        => $cost,
                    'coins'       => $coinsAfter,
                ]);

                $this->emitShopState($matchId, $playerId, $userId, $coinsAfter);

                return [
                    'ok'      => true,
                    'upgrade' => [
                        'key'   => $upgradeKey,
                        'level' => $nextLevel,
                        'cost'  => $cost,
                        'coins' => $coinsAfter,
                    ],
                ];
            });
    }

    /**
     * End the current turn and hand over to the next seat.
     *
     * @return array{ok:bool, error?:string, next_seat?:int, replayed?:bool}
     */
    public function endTurn(int $userId, int $matchId, ?string $actionId = null): array
    {
        return $this->mutate($matchId, $userId, $actionId, 'end_turn',
            function (array $match, array $player) use ($matchId, $userId): array {
                if (!GamePhase::allows((string) $match['phase'], 'end_turn')) {
                    return ['ok' => false, 'error' => 'You still have spins to play.'];
                }
                $next = $this->advanceTurn($match, $player, 'ended');
                return ['ok' => true, 'next_seat' => $next];
            });
    }

    /* ==================================================================
     |  Turn plumbing
     * ================================================================== */

    /**
     * @param array<string,mixed> $match
     * @param array<string,mixed> $player
     */
    private function advanceTurn(array $match, array $player, string $reason): int
    {
        $matchId = (int) $match['id'];
        $now     = Db::now();

        Db::update('match_players', [
            'dice_value'       => null,
            'dice_display'     => null,
            'spins_total'      => 0,
            'spins_remaining'  => 0,
            'second_roll_used' => 0,
            'combo_streak'     => 0,
            'turns_taken'      => (int) $player['turns_taken'] + 1,
            'updated_at'       => $now,
        ], ['id' => (int) $player['id']]);

        $seats = Db::all(
            'SELECT seat FROM match_players WHERE match_id = :match_id ORDER BY seat ASC',
            ['match_id' => $matchId]
        );
        $seatList = array_map(static fn (array $r): int => (int) $r['seat'], $seats);
        if ($seatList === []) {
            return 0;
        }

        $currentSeat = (int) $match['current_seat'];
        $position    = array_search($currentSeat, $seatList, true);
        $position    = $position === false ? 0 : (int) $position;
        $nextIndex   = ($position + 1) % count($seatList);
        $nextSeat    = $seatList[$nextIndex];

        $roundNumber = (int) $match['round_number'] + ($nextIndex === 0 ? 1 : 0);

        Db::update('matches', [
            'current_seat'    => $nextSeat,
            'turn_number'     => (int) $match['turn_number'] + 1,
            'round_number'    => $roundNumber,
            'phase'           => GamePhase::WAITING_FOR_DICE,
            'turn_started_at' => $now,
            'updated_at'      => $now,
        ], ['id' => $matchId]);

        $nextPlayer = Db::first(
            'SELECT id, user_id, username, coins FROM match_players WHERE match_id = :match_id AND seat = :seat',
            ['match_id' => $matchId, 'seat' => $nextSeat]
        );

        EventBus::emit(
            $matchId,
            $reason === 'skipped' ? EventBus::TURN_SKIPPED : EventBus::TURN_ENDED,
            [
                'previous_seat'    => $currentSeat,
                'previous_user_id' => (int) $player['user_id'],
                'previous_username'=> (string) $player['username'],
                'next_seat'        => $nextSeat,
                'next_user_id'     => $nextPlayer === null ? null : (int) $nextPlayer['user_id'],
                'next_username'    => $nextPlayer === null ? null : (string) $nextPlayer['username'],
                'turn_number'      => (int) $match['turn_number'] + 1,
                'round_number'     => $roundNumber,
                'reason'           => $reason,
            ]
        );

        return $nextSeat;
    }

    /**
     * Skip the active player's turn if they have been offline past the
     * timeout. Called periodically by the WebSocket service so one dropped
     * phone can never freeze a match permanently.
     *
     * @return array{skipped:bool, seat?:int}
     */
    public function skipStalledTurn(int $matchId): array
    {
        return Db::transaction(function () use ($matchId): array {
            $match = Db::first('SELECT * FROM matches WHERE id = :id' . Db::forUpdate(), ['id' => $matchId]);
            if ($match === null || (string) $match['status'] !== 'active') {
                return ['skipped' => false];
            }

            $player = Db::first(
                'SELECT * FROM match_players WHERE match_id = :match_id AND seat = :seat',
                ['match_id' => $matchId, 'seat' => (int) $match['current_seat']]
            );
            if ($player === null) {
                return ['skipped' => false];
            }

            $timeout = Config::int('game.connection.turn_timeout_seconds', 120);
            if ((string) $player['connection_status'] !== 'offline') {
                return ['skipped' => false];
            }

            $lastSeen = $player['last_seen_at'] === null ? 0 : strtotime((string) $player['last_seen_at'] . ' UTC');
            $turnAge  = $match['turn_started_at'] === null ? 0 : strtotime((string) $match['turn_started_at'] . ' UTC');
            $reference = max($lastSeen, $turnAge);
            if ($reference === 0 || (time() - $reference) < $timeout) {
                return ['skipped' => false];
            }

            $seat = $this->advanceTurn($match, $player, 'skipped');
            return ['skipped' => true, 'seat' => $seat];
        });
    }

    /* ==================================================================
     |  Match end
     * ================================================================== */

    private function finishMatch(int $matchId, int $winnerUserId): void
    {
        $now     = Db::now();
        $players = Db::all(
            'SELECT * FROM match_players WHERE match_id = :match_id ORDER BY coins DESC, id ASC',
            ['match_id' => $matchId]
        );

        $results   = [];
        $placement = 1;
        foreach ($players as $player) {
            Db::update('match_players', [
                'placement'       => $placement,
                'spins_remaining' => 0,
                'updated_at'      => $now,
            ], ['id' => (int) $player['id']]);

            $stats = PlayerStats::fromJson($player['stats'] === null ? null : (string) $player['stats']);
            $isWinner = (int) $player['user_id'] === $winnerUserId;

            $this->rollUpAccountStats((int) $player['user_id'], (int) $player['coins'], $stats, $isWinner);

            $results[] = [
                'user_id'   => (int) $player['user_id'],
                'username'  => (string) $player['username'],
                'seat'      => (int) $player['seat'],
                'coins'     => (int) $player['coins'],
                'placement' => $placement,
                'is_winner' => $isWinner,
                'stats'     => $stats,
                'upgrades'  => $this->upgradeSummary((int) $player['id']),
            ];
            $placement++;
        }

        $match    = Db::first('SELECT started_at, room_code FROM matches WHERE id = :id', ['id' => $matchId]);
        $started  = $match === null || $match['started_at'] === null ? time() : strtotime((string) $match['started_at'] . ' UTC');
        $duration = max(0, time() - $started);

        Db::update('matches', [
            'status'         => 'finished',
            'phase'          => GamePhase::FINISHED,
            'winner_user_id' => $winnerUserId,
            'finished_at'    => $now,
            'updated_at'     => $now,
        ], ['id' => $matchId]);

        $winner = null;
        foreach ($results as $result) {
            if ($result['is_winner']) {
                $winner = $result;
                break;
            }
        }

        EventBus::emit($matchId, EventBus::MATCH_FINISHED, [
            'match_id'         => $matchId,
            'winner_user_id'   => $winnerUserId,
            'winner_username'  => $winner['username'] ?? null,
            'results'          => $results,
            'duration_seconds' => $duration,
        ]);

        // Release the room code. History stays in `matches`/`match_players`.
        Db::run('DELETE FROM rooms WHERE match_id = :match_id', ['match_id' => $matchId]);
    }

    /** @param array<string,int> $stats */
    private function rollUpAccountStats(int $userId, int $finalCoins, array $stats, bool $isWinner): void
    {
        $existing = Db::first('SELECT * FROM user_stats WHERE user_id = :id' . Db::forUpdate(), ['id' => $userId]);
        $now      = Db::now();

        if ($existing === null) {
            Db::insert('user_stats', [
                'user_id'             => $userId,
                'games_played'        => 1,
                'wins'                => $isWinner ? 1 : 0,
                'losses'              => $isWinner ? 0 : 1,
                'total_spins'         => $stats['spins'],
                'total_pairs'         => $stats['pairs'],
                'total_triples'       => $stats['triples'],
                'crown_triples'       => $stats['crown_triples'],
                'trophy_triples'      => $stats['trophy_triples'],
                'highest_match_score' => max(0, $finalCoins),
                'biggest_single_win'  => $stats['biggest_win'],
                'total_coins_won'     => $stats['coins_won'],
                'updated_at'          => $now,
            ]);
            return;
        }

        Db::update('user_stats', [
            'games_played'        => (int) $existing['games_played'] + 1,
            'wins'                => (int) $existing['wins'] + ($isWinner ? 1 : 0),
            'losses'              => (int) $existing['losses'] + ($isWinner ? 0 : 1),
            'total_spins'         => (int) $existing['total_spins'] + $stats['spins'],
            'total_pairs'         => (int) $existing['total_pairs'] + $stats['pairs'],
            'total_triples'       => (int) $existing['total_triples'] + $stats['triples'],
            'crown_triples'       => (int) $existing['crown_triples'] + $stats['crown_triples'],
            'trophy_triples'      => (int) $existing['trophy_triples'] + $stats['trophy_triples'],
            'highest_match_score' => max((int) $existing['highest_match_score'], max(0, $finalCoins)),
            'biggest_single_win'  => max((int) $existing['biggest_single_win'], $stats['biggest_win']),
            'total_coins_won'     => (int) $existing['total_coins_won'] + $stats['coins_won'],
            'updated_at'          => $now,
        ], ['user_id' => $userId]);
    }

    /* ==================================================================
     |  Shop
     * ================================================================== */

    /** Roll a fresh set of offers for a player and tell them about it. */
    private function refreshShopOffers(int $matchId, int $playerId, int $userId, int $coins): void
    {
        $this->generateShopOffers($playerId);
        $this->emitShopState($matchId, $playerId, $userId, $coins);
    }

    private function emitShopState(int $matchId, int $playerId, int $userId, int $coins): void
    {
        EventBus::emit($matchId, EventBus::SHOP_UPDATED, [
            'user_id' => $userId,
            'coins'   => $coins,
            'offers'  => $this->shopOffers($playerId),
        ], privateToUserId: $userId);
    }

    /**
     * Pick `shop_options` distinct, still-purchasable upgrades weighted by rarity.
     */
    public function generateShopOffers(int $playerId): void
    {
        $levels     = $this->upgradeLevels($playerId);
        $candidates = UpgradeCatalog::purchasableKeys($levels);
        $count      = Config::int('game.shop_options', 4);

        Db::run('DELETE FROM shop_offers WHERE match_player_id = :player', ['player' => $playerId]);
        if ($candidates === []) {
            return;
        }

        $rarityWeights = Config::array('game.shop.rarity_weights');
        $pool          = [];
        foreach ($candidates as $key) {
            $upgrade      = UpgradeCatalog::get($key) ?? [];
            $rarity       = (string) ($upgrade['rarity'] ?? 'common');
            $pool[$key]   = (float) ($rarityWeights[$rarity] ?? 10);
        }

        $now  = Db::now();
        $slot = 0;
        for ($i = 0; $i < $count && $pool !== []; $i++) {
            $key = $this->rng->weighted($pool);
            unset($pool[$key]);

            $check = UpgradeCatalog::purchaseCheck($key, $levels);
            Db::insert('shop_offers', [
                'match_player_id' => $playerId,
                'slot'            => $slot++,
                'upgrade_key'     => $key,
                'level'           => $check['next_level'],
                'cost'            => $check['cost'],
                'purchased'       => 0,
                'created_at'      => $now,
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    public function shopOffers(int $playerId): array
    {
        $levels = $this->upgradeLevels($playerId);
        $rows   = Db::all(
            'SELECT * FROM shop_offers WHERE match_player_id = :player ORDER BY slot ASC',
            ['player' => $playerId]
        );

        $offers = [];
        foreach ($rows as $row) {
            $key         = (string) $row['upgrade_key'];
            $description = UpgradeCatalog::describe($key, $levels);
            $description['cost']      = (int) $row['cost'];
            $description['slot']      = (int) $row['slot'];
            $description['purchased'] = (bool) $row['purchased'];
            $description['next_level']= (int) $row['level'];
            $offers[]                 = $description;
        }

        return $offers;
    }

    /* ==================================================================
     |  Connection tracking
     * ================================================================== */

    public function touch(int $matchId, int $userId): void
    {
        Db::run(
            'UPDATE match_players SET last_seen_at = :now, updated_at = :now
              WHERE match_id = :match_id AND user_id = :user_id',
            ['now' => Db::now(), 'match_id' => $matchId, 'user_id' => $userId]
        );
    }

    /** Flip a player's presence and broadcast it. Losing connection never removes them. */
    public function setConnectionStatus(int $matchId, int $userId, string $status): void
    {
        $status = $status === 'online' ? 'online' : 'offline';

        Db::transaction(static function () use ($matchId, $userId, $status): void {
            $player = Db::first(
                'SELECT id, seat, username, connection_status FROM match_players
                  WHERE match_id = :match_id AND user_id = :user_id' . Db::forUpdate(),
                ['match_id' => $matchId, 'user_id' => $userId]
            );
            if ($player === null || (string) $player['connection_status'] === $status) {
                if ($player !== null && $status === 'online') {
                    Db::update('match_players', ['last_seen_at' => Db::now()], ['id' => (int) $player['id']]);
                }
                return;
            }

            Db::update('match_players', [
                'connection_status' => $status,
                'last_seen_at'      => Db::now(),
                'updated_at'        => Db::now(),
            ], ['id' => (int) $player['id']]);

            $match = Db::first('SELECT status FROM matches WHERE id = :id', ['id' => $matchId]);
            if ($match === null || (string) $match['status'] !== 'active') {
                return;
            }

            EventBus::emit($matchId, EventBus::PLAYER_CONNECTION, [
                'user_id'  => $userId,
                'seat'     => (int) $player['seat'],
                'username' => (string) $player['username'],
                'status'   => $status,
            ]);
        });
    }

    /** Mark players offline whose heartbeat has lapsed. Returns affected match ids. */
    public function reapStalePresence(): array
    {
        $cutoff = gmdate(
            'Y-m-d H:i:s',
            time() - Config::int('game.connection.offline_after_seconds', 25)
        );

        $stale = Db::all(
            "SELECT mp.match_id, mp.user_id
               FROM match_players mp
               INNER JOIN matches m ON m.id = mp.match_id
              WHERE m.status = 'active'
                AND mp.connection_status = 'online'
                AND (mp.last_seen_at IS NULL OR mp.last_seen_at < :cutoff)",
            ['cutoff' => $cutoff]
        );

        $matchIds = [];
        foreach ($stale as $row) {
            $this->setConnectionStatus((int) $row['match_id'], (int) $row['user_id'], 'offline');
            $matchIds[(int) $row['match_id']] = true;
        }

        return array_keys($matchIds);
    }

    /* ==================================================================
     |  State reads
     * ================================================================== */

    /**
     * The complete authoritative state for one viewer. This is what a client
     * asks for after a reconnect — it never tries to resume from local state.
     *
     * @return array<string,mixed>|null
     */
    public function state(int $matchId, int $userId): ?array
    {
        $match = Db::first('SELECT * FROM matches WHERE id = :id', ['id' => $matchId]);
        if ($match === null) {
            return null;
        }

        $players = Db::all(
            'SELECT * FROM match_players WHERE match_id = :match_id ORDER BY seat ASC',
            ['match_id' => $matchId]
        );

        $you       = null;
        $viewList  = [];
        foreach ($players as $player) {
            $isYou      = (int) $player['user_id'] === $userId;
            $upgrades   = $this->upgradeSummary((int) $player['id']);
            $viewList[] = [
                'user_id'           => (int) $player['user_id'],
                'username'          => (string) $player['username'],
                'seat'              => (int) $player['seat'],
                'coins'             => (int) $player['coins'],
                'dice_value'        => $player['dice_value'] === null ? null : (int) $player['dice_value'],
                'dice_display'      => $player['dice_display'] === null ? null : (string) $player['dice_display'],
                'spins_remaining'   => (int) $player['spins_remaining'],
                'spins_total'       => (int) $player['spins_total'],
                'connection_status' => (string) $player['connection_status'],
                'is_current'        => (int) $player['seat'] === (int) $match['current_seat'],
                'is_you'            => $isYou,
                'placement'         => $player['placement'] === null ? null : (int) $player['placement'],
                'upgrade_count'     => count($upgrades),
                'upgrades'          => $upgrades,
                'stats'             => PlayerStats::fromJson($player['stats'] === null ? null : (string) $player['stats']),
            ];
            if ($isYou) {
                $you = $player;
            }
        }

        if ($you === null) {
            return null; // not a participant — caller turns this into a 403
        }

        $playerId  = (int) $you['id'];
        $levels    = $this->upgradeLevels($playerId);
        $modifiers = UpgradeEngine::compute($levels);
        $phase     = (string) $match['phase'];
        $isYourTurn = (int) $you['seat'] === (int) $match['current_seat']
            && (string) $match['status'] === 'active';

        $lastSpin = Db::first(
            'SELECT * FROM spins WHERE match_id = :match_id ORDER BY id DESC LIMIT 1',
            ['match_id' => $matchId]
        );

        $state = [
            'match' => [
                'id'            => $matchId,
                'room_code'     => (string) $match['room_code'],
                'status'        => (string) $match['status'],
                'phase'         => $phase,
                'target_coins'  => (int) $match['target_coins'],
                'current_seat'  => (int) $match['current_seat'],
                'turn_number'   => (int) $match['turn_number'],
                'round_number'  => (int) $match['round_number'],
                'player_count'  => (int) $match['player_count'],
                'winner_user_id'=> $match['winner_user_id'] === null ? null : (int) $match['winner_user_id'],
                'seq'           => (int) $match['event_seq'],
            ],
            'you' => [
                'user_id'         => $userId,
                'seat'            => (int) $you['seat'],
                'coins'           => (int) $you['coins'],
                'dice_value'      => $you['dice_value'] === null ? null : (int) $you['dice_value'],
                'dice_display'    => $you['dice_display'] === null ? null : (string) $you['dice_display'],
                'spins_remaining' => (int) $you['spins_remaining'],
                'spins_total'     => (int) $you['spins_total'],
                'combo_streak'    => (int) $you['combo_streak'],
                'is_your_turn'    => $isYourTurn,
                'can_roll'        => $isYourTurn && GamePhase::allows($phase, 'roll_dice'),
                'can_spin'        => $isYourTurn && GamePhase::allows($phase, 'spin') && (int) $you['spins_remaining'] > 0,
                'can_reroll'      => $isYourTurn
                    && GamePhase::allows($phase, 'reroll_dice')
                    && (int) $you['spins_remaining'] === (int) $you['spins_total']
                    && DiceEngine::canReroll($modifiers, (bool) $you['second_roll_used']),
                'can_shop'        => $isYourTurn && GamePhase::allows($phase, 'buy_upgrade'),
                'can_end_turn'    => $isYourTurn && GamePhase::allows($phase, 'end_turn'),
                'upgrades'        => $this->upgradeSummary($playerId),
                'shop_offers'     => $this->shopOffers($playerId),
                'modifiers'       => $modifiers->toDisplayArray(\RoyalSpin\Support\Env::isDebug()),
            ],
            'players'   => $viewList,
            'last_spin' => $lastSpin === null ? null : [
                'user_id' => (int) (Db::first(
                    'SELECT user_id FROM match_players WHERE id = :id',
                    ['id' => (int) $lastSpin['match_player_id']]
                )['user_id'] ?? 0),
                'reels'      => [(string) $lastSpin['reel_1'], (string) $lastSpin['reel_2'], (string) $lastSpin['reel_3']],
                'outcome'    => (string) $lastSpin['outcome'],
                'win_symbol' => $lastSpin['win_symbol'] === null ? null : (string) $lastSpin['win_symbol'],
                'payout'     => (int) $lastSpin['final_payout'],
            ],
        ];

        if ((string) $match['status'] === 'finished') {
            $state['results'] = $this->results($matchId);
        }

        return $state;
    }

    /** @return array<string,mixed> */
    public function results(int $matchId): array
    {
        $match   = Db::first('SELECT * FROM matches WHERE id = :id', ['id' => $matchId]);
        $players = Db::all(
            'SELECT * FROM match_players WHERE match_id = :match_id
           ORDER BY (CASE WHEN placement IS NULL THEN 1 ELSE 0 END), placement ASC, coins DESC',
            ['match_id' => $matchId]
        );

        $rows = [];
        foreach ($players as $player) {
            $rows[] = [
                'user_id'   => (int) $player['user_id'],
                'username'  => (string) $player['username'],
                'coins'     => (int) $player['coins'],
                'placement' => $player['placement'] === null ? null : (int) $player['placement'],
                'is_winner' => $match !== null && (int) $player['user_id'] === (int) ($match['winner_user_id'] ?? 0),
                'stats'     => PlayerStats::fromJson($player['stats'] === null ? null : (string) $player['stats']),
                'upgrades'  => $this->upgradeSummary((int) $player['id']),
            ];
        }

        $started  = $match !== null && $match['started_at'] !== null ? strtotime((string) $match['started_at'] . ' UTC') : null;
        $finished = $match !== null && $match['finished_at'] !== null ? strtotime((string) $match['finished_at'] . ' UTC') : null;

        return [
            'match_id'         => $matchId,
            'room_code'        => $match === null ? '' : (string) $match['room_code'],
            'status'           => $match === null ? 'unknown' : (string) $match['status'],
            'winner_user_id'   => $match === null || $match['winner_user_id'] === null ? null : (int) $match['winner_user_id'],
            'duration_seconds' => $started !== null && $finished !== null ? max(0, $finished - $started) : null,
            'players'          => $rows,
        ];
    }

    /** @return array<string,int> upgrade_key => level */
    public function upgradeLevels(int $playerId): array
    {
        $rows   = Db::all(
            'SELECT upgrade_key, level FROM player_upgrades WHERE match_player_id = :player',
            ['player' => $playerId]
        );
        $levels = [];
        foreach ($rows as $row) {
            $levels[(string) $row['upgrade_key']] = (int) $row['level'];
        }
        return $levels;
    }

    /**
     * Owned upgrades, decorated for display. Also drives the opponents' machine
     * styling so you can read someone's build at a glance.
     *
     * @return list<array<string,mixed>>
     */
    public function upgradeSummary(int $playerId): array
    {
        $rows    = Db::all(
            'SELECT upgrade_key, level, coins_spent FROM player_upgrades
              WHERE match_player_id = :player ORDER BY upgrade_key ASC',
            ['player' => $playerId]
        );
        $summary = [];
        foreach ($rows as $row) {
            $key        = (string) $row['upgrade_key'];
            $definition = UpgradeCatalog::get($key);
            if ($definition === null) {
                continue;
            }
            $summary[] = [
                'key'         => $key,
                'name'        => (string) $definition['name'],
                'icon'        => (string) $definition['icon'],
                'category'    => (string) $definition['category'],
                'rarity'      => (string) $definition['rarity'],
                'reel'        => UpgradeCatalog::reelFor((string) $definition['category']),
                'level'       => (int) $row['level'],
                'max_level'   => (int) $definition['max_level'],
                'description' => (string) $definition['description'],
                'coins_spent' => (int) $row['coins_spent'],
            ];
        }
        return $summary;
    }

    /** Which live match, if any, this account belongs to. */
    public function activeMatchIdFor(int $userId): ?int
    {
        $row = Db::first(
            "SELECT m.id FROM matches m
               INNER JOIN match_players mp ON mp.match_id = m.id
              WHERE mp.user_id = :user_id AND m.status = 'active'
           ORDER BY m.id DESC LIMIT 1",
            ['user_id' => $userId]
        );
        return $row === null ? null : (int) $row['id'];
    }

    public function isParticipant(int $matchId, int $userId): bool
    {
        return Db::first(
            'SELECT id FROM match_players WHERE match_id = :match_id AND user_id = :user_id',
            ['match_id' => $matchId, 'user_id' => $userId]
        ) !== null;
    }

    /* ==================================================================
     |  Transaction / idempotency wrapper
     * ================================================================== */

    /**
     * @param callable(array<string,mixed>,array<string,mixed>):array<string,mixed> $callback
     * @return array<string,mixed>
     */
    private function mutate(int $matchId, int $userId, ?string $actionId, string $action, callable $callback): array
    {
        $actionId = $this->normaliseActionId($actionId);

        return Db::transaction(function () use ($matchId, $userId, $actionId, $action, $callback): array {
            // Locking the match row first serialises every action in this match
            // and makes the idempotency claim below race-free.
            $match = Db::first('SELECT * FROM matches WHERE id = :id' . Db::forUpdate(), ['id' => $matchId]);
            if ($match === null) {
                return ['ok' => false, 'error' => 'This match no longer exists.'];
            }

            $player = Db::first(
                'SELECT * FROM match_players WHERE match_id = :match_id AND user_id = :user_id',
                ['match_id' => $matchId, 'user_id' => $userId]
            );
            if ($player === null) {
                return ['ok' => false, 'error' => 'You are not part of this match.'];
            }

            if ((string) $match['status'] !== 'active') {
                return ['ok' => false, 'error' => 'This match has already finished.'];
            }
            if ((int) $player['seat'] !== (int) $match['current_seat']) {
                return ['ok' => false, 'error' => 'It is not your turn.'];
            }

            // --- idempotency claim -------------------------------------------
            $replay = $this->claimAction($matchId, $userId, $actionId, $action);
            if ($replay !== null) {
                $replay['replayed'] = true;
                return $replay;
            }

            $result = $callback($match, $player);

            // Only successful actions are remembered; a rejected action should
            // be retryable once the client fixes whatever was wrong.
            if (($result['ok'] ?? false) === true) {
                Db::update('action_log', [
                    'result' => json_encode($result, JSON_UNESCAPED_UNICODE) ?: null,
                ], ['match_id' => $matchId, 'user_id' => $userId, 'action_id' => $actionId]);
            } else {
                Db::run(
                    'DELETE FROM action_log WHERE match_id = :match_id AND user_id = :user_id AND action_id = :action_id',
                    ['match_id' => $matchId, 'user_id' => $userId, 'action_id' => $actionId]
                );
            }

            return $result;
        });
    }

    /**
     * Insert the action id. Returns the previously stored result when this is a
     * duplicate (double tap, retry after a dropped connection, refresh).
     *
     * @return array<string,mixed>|null
     */
    private function claimAction(int $matchId, int $userId, string $actionId, string $action): ?array
    {
        try {
            Db::insert('action_log', [
                'match_id'   => $matchId,
                'user_id'    => $userId,
                'action_id'  => $actionId,
                'action'     => $action,
                'result'     => null,
                'created_at' => Db::now(),
            ]);
            return null;
        } catch (PDOException) {
            $existing = Db::first(
                'SELECT result FROM action_log
                  WHERE match_id = :match_id AND user_id = :user_id AND action_id = :action_id',
                ['match_id' => $matchId, 'user_id' => $userId, 'action_id' => $actionId]
            );
            if ($existing === null) {
                return ['ok' => false, 'error' => 'Duplicate action.'];
            }
            $decoded = $existing['result'] === null ? null : json_decode((string) $existing['result'], true);
            return is_array($decoded)
                ? $decoded
                : ['ok' => false, 'error' => 'That action is already being processed.'];
        } catch (Throwable) {
            return ['ok' => false, 'error' => 'Could not register the action.'];
        }
    }

    private function normaliseActionId(?string $actionId): string
    {
        if ($actionId === null || trim($actionId) === '') {
            return bin2hex(random_bytes(16));
        }
        $clean = preg_replace('/[^A-Za-z0-9_.:-]/', '', $actionId) ?? '';
        return substr($clean === '' ? bin2hex(random_bytes(16)) : $clean, 0, 64);
    }
}
