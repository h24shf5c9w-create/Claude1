<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\RoomService;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Env;
use RoyalSpin\Support\Rng;

/**
 * Base class for tests that need real persistence.
 *
 * Each test gets a brand new SQLite file, so no test can see another's rows.
 * SQLite is used purely so the suite runs anywhere; the schema mirrors the
 * MySQL one column for column.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected string $databasePath = '';

    public function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/royalspin-test-' . bin2hex(random_bytes(6)) . '.sqlite';

        Env::set('DB_DRIVER', 'sqlite');
        Env::set('DB_DATABASE', $this->databasePath);
        Db::reset();

        $sql = (string) file_get_contents(ROYAL_SPIN_ROOT . '/database/schema.sqlite.sql');
        foreach (self::statements($sql) as $statement) {
            Db::run($statement);
        }
    }

    public function tearDown(): void
    {
        Db::reset();
        foreach ([$this->databasePath, $this->databasePath . '-wal', $this->databasePath . '-shm'] as $file) {
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** @return list<string> */
    private static function statements(string $sql): array
    {
        $lines = [];
        foreach (explode("\n", $sql) as $line) {
            if (!str_starts_with(ltrim($line), '--')) {
                $lines[] = $line;
            }
        }
        $statements = [];
        foreach (explode(';', implode("\n", $lines)) as $statement) {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
        }
        return $statements;
    }

    /* --------------------------------------------------------- fixtures */

    private int $userSequence = 0;

    /**
     * Registers through the real AuthService so the tests exercise hashing and
     * validation too. Each fixture user gets its own source IP — the signup
     * rate limiter is per-network and would otherwise (correctly) block a test
     * that creates dozens of accounts.
     */
    protected function makeUser(string $username): int
    {
        $this->userSequence++;
        $ip = sprintf('10.%d.%d.%d',
            intdiv($this->userSequence, 65536) % 256,
            intdiv($this->userSequence, 256) % 256,
            $this->userSequence % 256
        );

        $result = (new AuthService())->register(
            $username,
            strtolower($username) . '@example.test',
            'correct-horse-battery',
            $ip
        );
        if (!($result['ok'] ?? false)) {
            $this->fail('Could not create the test user: ' . ($result['error'] ?? '?'));
        }
        return (int) $result['user_id'];
    }

    /**
     * Create a room, add everyone, mark them ready and start the match.
     *
     * @param list<int> $userIds first entry hosts
     * @return array{match_id:int, room_id:int, code:string}
     */
    protected function startMatch(array $userIds, ?int $seed = 4242): array
    {
        $rooms = new RoomService(new Rng($seed));
        $game  = new GameService(new Rng($seed));

        $created = $rooms->create($userIds[0], max(2, count($userIds)));
        if (!($created['ok'] ?? false)) {
            $this->fail('Could not create the room: ' . ($created['error'] ?? '?'));
        }
        $roomId = (int) $created['room']['id'];
        $code   = (string) $created['room']['code'];

        foreach (array_slice($userIds, 1) as $userId) {
            $joined = $rooms->join($userId, $code);
            if (!($joined['ok'] ?? false)) {
                $this->fail('Could not join the room: ' . ($joined['error'] ?? '?'));
            }
            $rooms->setReady($userId, $roomId, true);
        }

        $started = $game->startMatch($userIds[0], $roomId);
        if (!($started['ok'] ?? false)) {
            $this->fail('Could not start the match: ' . ($started['error'] ?? '?'));
        }

        return ['match_id' => (int) $started['match_id'], 'room_id' => $roomId, 'code' => $code];
    }

    /** The user id whose turn it currently is. */
    protected function activeUserId(int $matchId): int
    {
        $row = Db::first(
            'SELECT mp.user_id FROM matches m
               INNER JOIN match_players mp ON mp.match_id = m.id AND mp.seat = m.current_seat
              WHERE m.id = :id',
            ['id' => $matchId]
        );
        return (int) ($row['user_id'] ?? 0);
    }

    protected function matchPlayerId(int $matchId, int $userId): int
    {
        $row = Db::first(
            'SELECT id FROM match_players WHERE match_id = :match_id AND user_id = :user_id',
            ['match_id' => $matchId, 'user_id' => $userId]
        );
        return (int) ($row['id'] ?? 0);
    }

    protected function coinsOf(int $matchId, int $userId): int
    {
        $row = Db::first(
            'SELECT coins FROM match_players WHERE match_id = :match_id AND user_id = :user_id',
            ['match_id' => $matchId, 'user_id' => $userId]
        );
        return (int) ($row['coins'] ?? 0);
    }

    protected function spinsRemaining(int $matchId, int $userId): int
    {
        $row = Db::first(
            'SELECT spins_remaining FROM match_players WHERE match_id = :match_id AND user_id = :user_id',
            ['match_id' => $matchId, 'user_id' => $userId]
        );
        return (int) ($row['spins_remaining'] ?? 0);
    }

    protected function setCoins(int $matchId, int $userId, int $coins): void
    {
        Db::update('match_players', ['coins' => $coins, 'updated_at' => Db::now()], [
            'match_id' => $matchId,
            'user_id'  => $userId,
        ]);
    }

    /** Play out the active player's whole turn: roll, spin everything, end. */
    protected function playFullTurn(GameService $game, int $matchId, int $userId): void
    {
        $game->rollDice($userId, $matchId);
        for ($guard = 0; $guard < 30; $guard++) {
            $state = $game->state($matchId, $userId);
            if ($state === null || ($state['match']['status'] ?? '') !== 'active') {
                return;
            }
            if (($state['you']['spins_remaining'] ?? 0) < 1) {
                break;
            }
            $game->spin($userId, $matchId);
        }
        $game->endTurn($userId, $matchId);
    }
}
