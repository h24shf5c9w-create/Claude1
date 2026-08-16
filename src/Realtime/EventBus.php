<?php

declare(strict_types=1);

namespace RoyalSpin\Realtime;

use RoyalSpin\Support\Db;

/**
 * The realtime event outbox.
 *
 * Every authoritative mutation appends here *inside the same transaction* that
 * changed the game state. The WebSocket service tails the table and fans events
 * out; HTTP-only clients poll the identical stream. That means there is exactly
 * one ordering of events, and a reconnecting client can always catch up by
 * asking for everything after the last `seq` it saw.
 */
final class EventBus
{
    public const MATCH_STARTED      = 'match_started';
    public const DICE_ROLLED        = 'dice_rolled';
    public const SPIN_RESULT        = 'spin_result';
    public const UPGRADE_PURCHASED  = 'upgrade_purchased';
    public const TURN_ENDED         = 'turn_ended';
    public const TURN_SKIPPED       = 'turn_skipped';
    public const MATCH_FINISHED     = 'match_finished';
    public const PLAYER_CONNECTION  = 'player_connection';
    public const SHOP_UPDATED       = 'shop_updated';
    public const LOBBY_UPDATED      = 'lobby_updated';

    /**
     * Append an event. Must be called inside a transaction that also persists
     * the state change the event describes.
     *
     * @param array<string,mixed> $payload
     * @param int|null $privateToUserId when set, only that user receives it
     */
    public static function emit(int $matchId, string $type, array $payload = [], ?int $privateToUserId = null): int
    {
        // Bump and read the per-match sequence atomically. The row was already
        // locked by the caller (SELECT ... FOR UPDATE on `matches`), so this
        // cannot interleave with a concurrent action on the same match.
        Db::run('UPDATE matches SET event_seq = event_seq + 1 WHERE id = :id', ['id' => $matchId]);
        $row = Db::first('SELECT event_seq FROM matches WHERE id = :id', ['id' => $matchId]);
        $seq = (int) ($row['event_seq'] ?? 0);

        if ($privateToUserId !== null) {
            $payload['private_to'] = $privateToUserId;
        }

        Db::insert('game_events', [
            'match_id'   => $matchId,
            'seq'        => $seq,
            'type'       => $type,
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => Db::now(),
        ]);

        return $seq;
    }

    /**
     * Events for a match after `$sinceSeq`, filtered for the requesting user.
     *
     * @return list<array{seq:int,type:string,payload:array<string,mixed>,created_at:string}>
     */
    public static function since(int $matchId, int $sinceSeq, ?int $forUserId = null, int $limit = 300): array
    {
        $rows = Db::all(
            'SELECT seq, type, payload, created_at
               FROM game_events
              WHERE match_id = :match_id AND seq > :since
           ORDER BY seq ASC
              LIMIT ' . max(1, min($limit, 1000)),
            ['match_id' => $matchId, 'since' => $sinceSeq]
        );

        $events = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);
            $payload = is_array($payload) ? $payload : [];

            if (isset($payload['private_to'])) {
                if ($forUserId === null || (int) $payload['private_to'] !== $forUserId) {
                    continue;
                }
                unset($payload['private_to']);
            }

            $events[] = [
                'seq'        => (int) $row['seq'],
                'type'       => (string) $row['type'],
                'payload'    => $payload,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $events;
    }

    /**
     * Raw tail used by the WebSocket relay: returns events with an id greater
     * than `$afterId` across all matches, so one query feeds every room.
     *
     * @return list<array{id:int,match_id:int,seq:int,type:string,payload:array<string,mixed>}>
     */
    public static function tail(int $afterId, int $limit = 500): array
    {
        $rows = Db::all(
            'SELECT id, match_id, seq, type, payload
               FROM game_events
              WHERE id > :after
           ORDER BY id ASC
              LIMIT ' . max(1, min($limit, 2000)),
            ['after' => $afterId]
        );

        $events = [];
        foreach ($rows as $row) {
            $payload  = json_decode((string) $row['payload'], true);
            $events[] = [
                'id'       => (int) $row['id'],
                'match_id' => (int) $row['match_id'],
                'seq'      => (int) $row['seq'],
                'type'     => (string) $row['type'],
                'payload'  => is_array($payload) ? $payload : [],
            ];
        }

        return $events;
    }

    public static function latestId(): int
    {
        $row = Db::first('SELECT MAX(id) AS max_id FROM game_events');
        return (int) ($row['max_id'] ?? 0);
    }
}
