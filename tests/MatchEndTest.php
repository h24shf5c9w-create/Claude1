<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Game\GamePhase;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\RoomService;
use RoyalSpin\Realtime\EventBus;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Rng;

final class MatchEndTest extends DatabaseTestCase
{
    /**
     * Put the active player one good spin away from the target, then spin
     * until they cross it.
     *
     * @return array{match:array{match_id:int,room_id:int,code:string}, winner:int, loser:int, game:GameService}
     */
    private function playToVictory(): array
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(123));

        $target = Config::int('game.target_coins');
        $active = $this->activeUserId($match['match_id']);
        $loser  = $active === $tobi ? $alex : $tobi;

        for ($turn = 0; $turn < 60; $turn++) {
            $state = $game->state($match['match_id'], $active);
            if (($state['match']['status'] ?? '') !== 'active') {
                break;
            }

            // Sit just below the line so the very next win ends the match.
            $this->setCoins($match['match_id'], $active, $target - 1);

            $game->rollDice($active, $match['match_id']);
            for ($i = 0; $i < 20; $i++) {
                $state = $game->state($match['match_id'], $active);
                if (($state['match']['status'] ?? '') !== 'active'
                    || ($state['you']['spins_remaining'] ?? 0) < 1) {
                    break;
                }
                $game->spin($active, $match['match_id']);
            }

            if (($game->state($match['match_id'], $active)['match']['status'] ?? '') !== 'active') {
                break;
            }
            $game->endTurn($active, $match['match_id']);
            $active = $this->activeUserId($match['match_id']);
            $loser  = $active === $tobi ? $alex : $tobi;
        }

        return ['match' => $match, 'winner' => $active, 'loser' => $loser, 'game' => $game];
    }

    public function testReachingTheTargetFinishesTheMatch(): void
    {
        ['match' => $match, 'winner' => $winner, 'game' => $game] = $this->playToVictory();

        $row = Db::first('SELECT * FROM matches WHERE id = :id', ['id' => $match['match_id']]);
        $this->assertSame('finished', (string) $row['status'], 'The match must be marked finished');
        $this->assertSame(GamePhase::FINISHED, (string) $row['phase']);
        $this->assertSame($winner, (int) $row['winner_user_id'], 'The player who crossed the line must be the winner');
        $this->assertNotNull($row['finished_at']);

        $coins = $this->coinsOf($match['match_id'], $winner);
        $this->assertTrue($coins >= Config::int('game.target_coins'), 'The winner must be at or above the target');
    }

    public function testNoFurtherActionsAreAllowedAfterTheMatchEnds(): void
    {
        ['match' => $match, 'winner' => $winner, 'loser' => $loser, 'game' => $game] = $this->playToVictory();

        foreach ([$winner, $loser] as $userId) {
            foreach (['rollDice', 'spin', 'endTurn'] as $method) {
                $result = $game->{$method}($userId, $match['match_id']);
                $this->assertFalse($result['ok'], "{$method} must be blocked after the match ends");
                $this->assertStringContains('already finished', strtolower((string) $result['error']));
            }
            $buy = $game->buyUpgrade($userId, $match['match_id'], 'fruit_machine');
            $this->assertFalse($buy['ok'], 'Buying must be blocked after the match ends');
        }
    }

    public function testCoinsAreFrozenAfterTheMatchEnds(): void
    {
        ['match' => $match, 'winner' => $winner, 'game' => $game] = $this->playToVictory();

        $coins = $this->coinsOf($match['match_id'], $winner);
        $game->spin($winner, $match['match_id']);
        $game->rollDice($winner, $match['match_id']);

        $this->assertSame($coins, $this->coinsOf($match['match_id'], $winner),
            'A finished match must be immutable');
    }

    public function testTheWinningSpinIsBroadcastBeforeTheMatchFinishes(): void
    {
        ['match' => $match, 'winner' => $winner] = $this->playToVictory();

        $events = EventBus::since($match['match_id'], 0, $winner, 1000);
        $types  = array_map(static fn (array $event): string => $event['type'], $events);

        $lastSpin   = array_keys($types, EventBus::SPIN_RESULT, true);
        $finishedAt = array_search(EventBus::MATCH_FINISHED, $types, true);

        $this->assertTrue($finishedAt !== false, 'A match_finished event must be emitted');
        $this->assertTrue($lastSpin !== [], 'Spin events must be present');
        $this->assertTrue(
            max($lastSpin) < (int) $finishedAt,
            'The winning spin must be sent before the match is sealed, so clients can animate it'
        );

        $winningSpin = $events[max($lastSpin)]['payload'];
        $this->assertTrue(($winningSpin['winning_spin'] ?? false) === true,
            'The winning spin should be flagged for the client');
    }

    public function testPlacementsAndStandingsAreRecorded(): void
    {
        ['match' => $match, 'winner' => $winner, 'game' => $game] = $this->playToVictory();

        $results = $game->results($match['match_id']);
        $this->assertSame('finished', $results['status']);
        $this->assertCount(2, $results['players']);
        $this->assertNotNull($results['duration_seconds']);

        $this->assertSame(1, (int) $results['players'][0]['placement'], 'First place must be placement 1');
        $this->assertTrue($results['players'][0]['is_winner']);
        $this->assertSame($winner, (int) $results['players'][0]['user_id']);
        $this->assertSame(2, (int) $results['players'][1]['placement']);

        $this->assertTrue(
            $results['players'][0]['coins'] >= $results['players'][1]['coins'],
            'Standings must be ordered by coins'
        );

        // Per-match statistics survive into the result screen.
        $this->assertTrue(($results['players'][0]['stats']['spins'] ?? 0) > 0);
    }

    public function testTheRoomIsReleasedButHistorySurvives(): void
    {
        ['match' => $match, 'winner' => $winner] = $this->playToVictory();

        $room = Db::first('SELECT id FROM rooms WHERE code = :code', ['code' => $match['code']]);
        $this->assertNull($room, 'The active room must be removed so its code can be reused');

        $stored = Db::first('SELECT id, room_code FROM matches WHERE id = :id', ['id' => $match['match_id']]);
        $this->assertNotNull($stored, 'The match record must survive');
        $this->assertSame($match['code'], (string) $stored['room_code']);

        $players = Db::all('SELECT id FROM match_players WHERE match_id = :id', ['id' => $match['match_id']]);
        $this->assertCount(2, $players, 'Historical player rows must survive');
    }

    public function testAReleasedCodeCanBeIssuedAgain(): void
    {
        ['match' => $match] = $this->playToVictory();

        // The code is free again: creating a room with that exact code must work.
        $fresh  = $this->makeUser('freshuser');
        $roomId = Db::insert('rooms', [
            'code'         => $match['code'],
            'host_user_id' => $fresh,
            'max_players'  => 2,
            'target_coins' => 1500,
            'status'       => 'lobby',
            'match_id'     => null,
            'created_at'   => Db::now(),
            'updated_at'   => Db::now(),
        ]);

        $this->assertTrue($roomId > 0, 'The recycled code must be insertable again');
        $this->assertNotNull((new RoomService())->findByCode($match['code']));
    }

    public function testAccountStatisticsAreUpdatedPermanently(): void
    {
        ['match' => $match, 'winner' => $winner, 'loser' => $loser] = $this->playToVictory();

        $winnerStats = AuthService::stats($winner);
        $loserStats  = AuthService::stats($loser);

        $this->assertSame(1, $winnerStats['games_played']);
        $this->assertSame(1, $winnerStats['wins']);
        $this->assertSame(0, $winnerStats['losses']);
        $this->assertTrue($winnerStats['total_spins'] > 0, 'Spins should roll up to the account');
        $this->assertTrue($winnerStats['highest_match_score'] >= Config::int('game.target_coins'));

        $this->assertSame(1, $loserStats['games_played']);
        $this->assertSame(0, $loserStats['wins']);
        $this->assertSame(1, $loserStats['losses']);

        // Deleting the room must not touch the account record.
        $this->assertNotNull(Db::first('SELECT user_id FROM user_stats WHERE user_id = :id', ['id' => $winner]));
    }

    public function testMatchUpgradesAreNotCarriedIntoTheNextMatch(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $first = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(300));

        $active = $this->activeUserId($first['match_id']);
        $roll   = $game->rollDice($active, $first['match_id']);
        for ($i = 0; $i < (int) $roll['dice']['spins']; $i++) {
            $game->spin($active, $first['match_id']);
        }
        $this->setCoins($first['match_id'], $active, 5000);
        $offers = $game->shopOffers($this->matchPlayerId($first['match_id'], $active));
        $game->buyUpgrade($active, $first['match_id'], (string) $offers[0]['key']);

        $this->assertTrue(count($game->upgradeLevels($this->matchPlayerId($first['match_id'], $active))) > 0);

        // Force-finish and start a completely new match for the same accounts.
        Db::update('matches', ['status' => 'finished', 'finished_at' => Db::now(), 'updated_at' => Db::now()],
            ['id' => $first['match_id']]);
        Db::run('DELETE FROM rooms WHERE match_id = :id', ['id' => $first['match_id']]);

        $second = $this->startMatch([$tobi, $alex], 501);
        $levels = $game->upgradeLevels($this->matchPlayerId($second['match_id'], $active));

        $this->assertCount(0, $levels, 'Upgrades must not carry over between matches');
        $this->assertSame(
            Config::int('game.starting_coins'),
            $this->coinsOf($second['match_id'], $active),
            'Every match starts from the configured starting coins'
        );
    }

    public function testEveryPlayerStartsWithIdenticalConditions(): void
    {
        $users = [$this->makeUser('p1'), $this->makeUser('p2'), $this->makeUser('p3')];
        $match = $this->startMatch($users);
        $game  = new GameService();

        foreach ($users as $userId) {
            $state = $game->state($match['match_id'], $userId);
            $this->assertSame(Config::int('game.starting_coins'), (int) $state['you']['coins']);
            $this->assertCount(0, $state['you']['upgrades']);
            $this->assertSame(0, (int) $state['you']['spins_remaining']);
        }

        // Seats are contiguous from 0 so turn order is deterministic.
        $seats = array_map(
            static fn (array $player): int => (int) $player['seat'],
            $game->state($match['match_id'], $users[0])['players']
        );
        $this->assertSame([0, 1, 2], $seats);
    }
}
