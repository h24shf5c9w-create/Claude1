<?php

declare(strict_types=1);

namespace RoyalSpin\Game;

use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Rng;
use RoyalSpin\Support\Validator;

/**
 * Lobby lifecycle: creating rooms, joining by code, readiness and match start.
 *
 * A `rooms` row exists only while the room is active. When the match finishes
 * the row is deleted, which is exactly what releases the 4-character code for
 * re-use — while `matches` / `match_players` keep the history forever.
 */
final class RoomService
{
    public function __construct(private readonly Rng $rng = new Rng())
    {
    }

    /* ================================================================== */
    /*  Creation                                                          */
    /* ================================================================== */

    /**
     * @return array{ok:bool, error?:string, room?:array<string,mixed>}
     */
    public function create(int $userId, int $maxPlayers, ?int $targetCoins = null): array
    {
        $min = Config::int('game.min_players', 2);
        $max = Config::int('game.max_players', 4);
        if ($maxPlayers < $min || $maxPlayers > $max) {
            return ['ok' => false, 'error' => "A room must allow between {$min} and {$max} players."];
        }

        $target = $targetCoins ?? Config::int('game.target_coins', 1500);
        if ($target < 100 || $target > 100000) {
            return ['ok' => false, 'error' => 'That target score is out of range.'];
        }

        return Db::transaction(function () use ($userId, $maxPlayers, $target): array {
            // One active room per account keeps codes and reconnects unambiguous.
            $existing = $this->activeRoomForUser($userId);
            if ($existing !== null) {
                return ['ok' => false, 'error' => 'You are already in room ' . $existing['code'] . '.'];
            }

            $code = $this->generateUniqueCode();
            if ($code === null) {
                return ['ok' => false, 'error' => 'Could not allocate a room code. Please try again.'];
            }

            $now    = Db::now();
            $roomId = Db::insert('rooms', [
                'code'         => $code,
                'host_user_id' => $userId,
                'max_players'  => $maxPlayers,
                'target_coins' => $target,
                'status'       => 'lobby',
                'match_id'     => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            Db::insert('room_players', [
                'room_id'   => $roomId,
                'user_id'   => $userId,
                'seat'      => 0,
                'is_host'   => 1,
                'is_ready'  => 1,   // the host is implicitly ready
                'joined_at' => $now,
            ]);

            return ['ok' => true, 'room' => $this->lobbyState($roomId)];
        });
    }

    /** Random, unique-among-active-rooms code from the unambiguous alphabet. */
    private function generateUniqueCode(): ?string
    {
        $alphabet = Validator::ROOM_CODE_ALPHABET;
        $length   = Validator::ROOM_CODE_LENGTH;
        $last     = strlen($alphabet) - 1;

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[$this->rng->int(0, $last)];
            }
            $taken = Db::first('SELECT id FROM rooms WHERE code = :code', ['code' => $code]);
            if ($taken === null) {
                return $code;
            }
        }
        return null;
    }

    /* ================================================================== */
    /*  Joining                                                           */
    /* ================================================================== */

    /**
     * Join by code, or reconnect into a running match the user already belongs to.
     *
     * @return array{ok:bool, error?:string, room?:array<string,mixed>, match_id?:int, reconnect?:bool}
     */
    public function join(int $userId, string $rawCode): array
    {
        $code = Validator::normaliseRoomCode($rawCode);
        if ($code === null) {
            return ['ok' => false, 'error' => 'That room code does not look right.'];
        }

        return Db::transaction(function () use ($userId, $code): array {
            $room = Db::first(
                'SELECT * FROM rooms WHERE code = :code' . Db::forUpdate(),
                ['code' => $code]
            );
            if ($room === null) {
                return ['ok' => false, 'error' => 'Room not found. Check the code and try again.'];
            }

            $roomId = (int) $room['id'];

            // --- already a member? ------------------------------------------------
            $member = Db::first(
                'SELECT * FROM room_players WHERE room_id = :room_id AND user_id = :user_id',
                ['room_id' => $roomId, 'user_id' => $userId]
            );

            if ((string) $room['status'] === 'active') {
                $matchId = (int) ($room['match_id'] ?? 0);
                if ($member === null) {
                    return ['ok' => false, 'error' => 'This match is already running.'];
                }
                // Reconnect: the account is a participant, so put them back in
                // their existing seat with their exact state.
                return ['ok' => true, 'match_id' => $matchId, 'reconnect' => true, 'room' => $this->lobbyState($roomId)];
            }

            if ($member !== null) {
                return ['ok' => true, 'room' => $this->lobbyState($roomId), 'reconnect' => true];
            }

            // --- new joiner -------------------------------------------------------
            $otherRoom = $this->activeRoomForUser($userId);
            if ($otherRoom !== null && (int) $otherRoom['id'] !== $roomId) {
                return ['ok' => false, 'error' => 'You are already in room ' . $otherRoom['code'] . '.'];
            }

            $seats = Db::all('SELECT seat FROM room_players WHERE room_id = :room_id', ['room_id' => $roomId]);
            if (count($seats) >= (int) $room['max_players']) {
                return ['ok' => false, 'error' => 'This room is already full.'];
            }

            $taken = array_map(static fn (array $r): int => (int) $r['seat'], $seats);
            $seat  = 0;
            while (in_array($seat, $taken, true)) {
                $seat++;
            }

            Db::insert('room_players', [
                'room_id'   => $roomId,
                'user_id'   => $userId,
                'seat'      => $seat,
                'is_host'   => 0,
                'is_ready'  => 0,
                'joined_at' => Db::now(),
            ]);
            Db::update('rooms', ['updated_at' => Db::now()], ['id' => $roomId]);

            return ['ok' => true, 'room' => $this->lobbyState($roomId)];
        });
    }

    /** @return array{ok:bool, error?:string, room?:array<string,mixed>} */
    public function setReady(int $userId, int $roomId, bool $ready): array
    {
        return Db::transaction(function () use ($userId, $roomId, $ready): array {
            $member = Db::first(
                'SELECT * FROM room_players WHERE room_id = :room_id AND user_id = :user_id',
                ['room_id' => $roomId, 'user_id' => $userId]
            );
            if ($member === null) {
                return ['ok' => false, 'error' => 'You are not in this room.'];
            }
            Db::update(
                'room_players',
                ['is_ready' => $ready ? 1 : 0],
                ['room_id' => $roomId, 'user_id' => $userId]
            );
            Db::update('rooms', ['updated_at' => Db::now()], ['id' => $roomId]);
            return ['ok' => true, 'room' => $this->lobbyState($roomId)];
        });
    }

    /**
     * Leave a lobby. The host leaving closes the room for everybody.
     *
     * @return array{ok:bool, error?:string, closed?:bool}
     */
    public function leave(int $userId, int $roomId): array
    {
        return Db::transaction(function () use ($userId, $roomId): array {
            $room = Db::first('SELECT * FROM rooms WHERE id = :id' . Db::forUpdate(), ['id' => $roomId]);
            if ($room === null) {
                return ['ok' => true, 'closed' => true];
            }
            if ((string) $room['status'] === 'active') {
                return ['ok' => false, 'error' => 'You cannot leave a running match.'];
            }

            if ((int) $room['host_user_id'] === $userId) {
                Db::run('DELETE FROM rooms WHERE id = :id', ['id' => $roomId]);
                return ['ok' => true, 'closed' => true];
            }

            Db::run(
                'DELETE FROM room_players WHERE room_id = :room_id AND user_id = :user_id',
                ['room_id' => $roomId, 'user_id' => $userId]
            );
            Db::update('rooms', ['updated_at' => Db::now()], ['id' => $roomId]);
            return ['ok' => true, 'closed' => false];
        });
    }

    /* ================================================================== */
    /*  Reads                                                             */
    /* ================================================================== */

    /** @return array<string,mixed>|null */
    public function activeRoomForUser(int $userId): ?array
    {
        return Db::first(
            'SELECT r.* FROM rooms r
               INNER JOIN room_players rp ON rp.room_id = r.id
              WHERE rp.user_id = :user_id
              LIMIT 1',
            ['user_id' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByCode(string $code): ?array
    {
        $normalised = Validator::normaliseRoomCode($code);
        if ($normalised === null) {
            return null;
        }
        return Db::first('SELECT * FROM rooms WHERE code = :code', ['code' => $normalised]);
    }

    /** @return array<string,mixed> */
    public function lobbyState(int $roomId): array
    {
        $room = Db::first('SELECT * FROM rooms WHERE id = :id', ['id' => $roomId]);
        if ($room === null) {
            return ['id' => $roomId, 'closed' => true, 'players' => []];
        }

        $players = Db::all(
            'SELECT rp.seat, rp.is_host, rp.is_ready, rp.user_id, u.username
               FROM room_players rp
               INNER JOIN users u ON u.id = rp.user_id
              WHERE rp.room_id = :room_id
           ORDER BY rp.seat ASC',
            ['room_id' => $roomId]
        );

        return [
            'id'           => (int) $room['id'],
            'code'         => (string) $room['code'],
            'status'       => (string) $room['status'],
            'match_id'     => $room['match_id'] === null ? null : (int) $room['match_id'],
            'host_user_id' => (int) $room['host_user_id'],
            'max_players'  => (int) $room['max_players'],
            'target_coins' => (int) $room['target_coins'],
            'min_players'  => Config::int('game.min_players', 2),
            'closed'       => false,
            'players'      => array_map(static fn (array $p): array => [
                'user_id'  => (int) $p['user_id'],
                'username' => (string) $p['username'],
                'seat'     => (int) $p['seat'],
                'is_host'  => (bool) $p['is_host'],
                'is_ready' => (bool) $p['is_ready'],
            ], $players),
        ];
    }

    /**
     * Reap lobbies/matches that nobody came back to, so their codes are freed.
     */
    public function pruneStaleRooms(): int
    {
        $lobbyTtl = Config::int('game.connection.lobby_ttl_minutes', 180);
        $matchTtl = Config::int('game.connection.match_ttl_minutes', 720);

        $removed = 0;
        $removed += Db::run(
            "DELETE FROM rooms WHERE status = 'lobby' AND updated_at < :cutoff",
            ['cutoff' => gmdate('Y-m-d H:i:s', time() - $lobbyTtl * 60)]
        )->rowCount();

        $stale = Db::all(
            "SELECT r.id, r.match_id FROM rooms r
              WHERE r.status = 'active' AND r.updated_at < :cutoff",
            ['cutoff' => gmdate('Y-m-d H:i:s', time() - $matchTtl * 60)]
        );
        foreach ($stale as $row) {
            Db::transaction(static function () use ($row): void {
                if ($row['match_id'] !== null) {
                    Db::update(
                        'matches',
                        ['status' => 'abandoned', 'finished_at' => Db::now(), 'updated_at' => Db::now()],
                        ['id' => (int) $row['match_id']]
                    );
                }
                Db::run('DELETE FROM rooms WHERE id = :id', ['id' => (int) $row['id']]);
            });
            $removed++;
        }

        return $removed;
    }
}
