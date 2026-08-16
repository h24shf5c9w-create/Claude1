<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

use RoyalSpin\Game\GamePhase;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\RoomService;
use RoyalSpin\Realtime\EventBus;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Rng;
use RoyalSpin\Support\Validator;

final class MultiplayerTest extends DatabaseTestCase
{
    /* ================================================================== */
    /*  Rooms                                                             */
    /* ================================================================== */

    public function testRoomCodesUseTheUnambiguousAlphabet(): void
    {
        $rooms = new RoomService();

        for ($i = 0; $i < 30; $i++) {
            $userId = $this->makeUser('coder' . $i);
            $result = $rooms->create($userId, 4);
            $this->assertTrue($result['ok'], 'Room creation failed');

            $code = (string) $result['room']['code'];
            $this->assertSame(Validator::ROOM_CODE_LENGTH, strlen($code));

            for ($c = 0; $c < strlen($code); $c++) {
                $this->assertTrue(
                    str_contains(Validator::ROOM_CODE_ALPHABET, $code[$c]),
                    "Code {$code} contains a confusable character"
                );
            }
        }
    }

    public function testRoomCodesAreUniqueAmongActiveRooms(): void
    {
        $rooms = new RoomService();
        $seen  = [];

        for ($i = 0; $i < 40; $i++) {
            $code = (string) $rooms->create($this->makeUser('u' . $i), 2)['room']['code'];
            $this->assertFalse(isset($seen[$code]), "Duplicate active code {$code}");
            $seen[$code] = true;
        }
    }

    public function testCodeNormalisationAcceptsSloppyInput(): void
    {
        $this->assertSame('K7M4', Validator::normaliseRoomCode('k7m4'));
        $this->assertSame('K7M4', Validator::normaliseRoomCode(' k7-m4 '));
        $this->assertNull(Validator::normaliseRoomCode('K7M'), 'Too short must be rejected');
        $this->assertNull(Validator::normaliseRoomCode('K0M4'), 'Zero is not in the alphabet');
        $this->assertNull(Validator::normaliseRoomCode('KIM4'), 'I is not in the alphabet');
    }

    public function testJoiningAFullRoomIsRefused(): void
    {
        $rooms = new RoomService();
        $host  = $this->makeUser('host');
        $code  = (string) $rooms->create($host, 2)['room']['code'];

        $this->assertTrue($rooms->join($this->makeUser('second'), $code)['ok']);

        $third = $rooms->join($this->makeUser('third'), $code);
        $this->assertFalse($third['ok']);
        $this->assertStringContains('full', strtolower((string) $third['error']));
    }

    public function testJoiningAnUnknownCodeIsRefused(): void
    {
        $result = (new RoomService())->join($this->makeUser('lost'), 'ZZZZ');
        $this->assertFalse($result['ok']);
        $this->assertStringContains('not found', strtolower((string) $result['error']));
    }

    public function testMatchCannotStartWithoutEnoughPlayers(): void
    {
        $rooms  = new RoomService();
        $host   = $this->makeUser('solo');
        $roomId = (int) $rooms->create($host, 2)['room']['id'];

        $result = (new GameService())->startMatch($host, $roomId);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('at least', strtolower((string) $result['error']));
    }

    public function testOnlyTheHostCanStartTheMatch(): void
    {
        $rooms  = new RoomService();
        $host   = $this->makeUser('host');
        $guest  = $this->makeUser('guest');
        $room   = $rooms->create($host, 2)['room'];
        $rooms->join($guest, (string) $room['code']);
        $rooms->setReady($guest, (int) $room['id'], true);

        $result = (new GameService())->startMatch($guest, (int) $room['id']);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('host', strtolower((string) $result['error']));
    }

    public function testStrangersCannotJoinARunningMatch(): void
    {
        $tobi   = $this->makeUser('tobi');
        $alex   = $this->makeUser('alex');
        $match  = $this->startMatch([$tobi, $alex]);
        $intruder = $this->makeUser('intruder');

        $result = (new RoomService())->join($intruder, $match['code']);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('already running', strtolower((string) $result['error']));
    }

    /* ================================================================== */
    /*  Turn order and permissions                                        */
    /* ================================================================== */

    public function testTurnOrderCyclesThroughEverySeat(): void
    {
        $users = [$this->makeUser('p1'), $this->makeUser('p2'), $this->makeUser('p3')];
        $match = $this->startMatch($users);
        $game  = new GameService(new Rng(11));

        $order = [];
        for ($turn = 0; $turn < 6; $turn++) {
            $active  = $this->activeUserId($match['match_id']);
            $order[] = $active;
            $this->playFullTurn($game, $match['match_id'], $active);
        }

        $this->assertSame([$users[0], $users[1], $users[2], $users[0], $users[1], $users[2]], $order,
            'Seats must be visited in order and wrap around');
    }

    public function testAnotherPlayerCannotActOnYourTurn(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(3));

        $active   = $this->activeUserId($match['match_id']);
        $opponent = $active === $tobi ? $alex : $tobi;

        foreach (['rollDice', 'spin', 'endTurn'] as $method) {
            $result = $game->{$method}($opponent, $match['match_id']);
            $this->assertFalse($result['ok'], "{$method} must be refused for the wrong player");
            $this->assertStringContains('not your turn', strtolower((string) $result['error']));
        }

        $buy = $game->buyUpgrade($opponent, $match['match_id'], 'fruit_machine');
        $this->assertFalse($buy['ok']);
    }

    public function testANonParticipantCannotAct(): void
    {
        $match    = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $outsider = $this->makeUser('outsider');

        $result = (new GameService())->rollDice($outsider, $match['match_id']);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('not part of', strtolower((string) $result['error']));
    }

    public function testANonParticipantCannotReadMatchState(): void
    {
        $match    = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $outsider = $this->makeUser('outsider');

        $this->assertNull((new GameService())->state($match['match_id'], $outsider),
            'State must not leak to non-participants');
    }

    public function testYouCannotSpinBeforeRolling(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(9));
        $active = $this->activeUserId($match['match_id']);

        $result = $game->spin($active, $match['match_id']);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('roll the dice', strtolower((string) $result['error']));
    }

    public function testYouCannotRollTwiceInOneTurn(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(9));
        $active = $this->activeUserId($match['match_id']);

        $this->assertTrue($game->rollDice($active, $match['match_id'])['ok']);
        $second = $game->rollDice($active, $match['match_id']);
        $this->assertFalse($second['ok'], 'A second roll in the same turn must be refused');
    }

    public function testTurnCannotEndWhileSpinsRemain(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(9));
        $active = $this->activeUserId($match['match_id']);

        $game->rollDice($active, $match['match_id']);
        $result = $game->endTurn($active, $match['match_id']);

        $this->assertFalse($result['ok']);
        $this->assertStringContains('spins to play', strtolower((string) $result['error']));
    }

    public function testSpinsAreConsumedExactlyOnce(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(21));
        $active = $this->activeUserId($match['match_id']);

        $roll  = $game->rollDice($active, $match['match_id']);
        $spins = (int) $roll['dice']['spins'];

        for ($i = 0; $i < $spins; $i++) {
            $this->assertTrue($game->spin($active, $match['match_id'])['ok'], "Spin {$i} should be allowed");
        }

        $extra = $game->spin($active, $match['match_id']);
        $this->assertFalse($extra['ok'], 'Spinning past the allowance must be refused');
    }

    /* ================================================================== */
    /*  Race conditions / idempotency                                     */
    /* ================================================================== */

    public function testRepeatingAnActionIdDoesNotSpinTwice(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(55));
        $active = $this->activeUserId($match['match_id']);
        $game->rollDice($active, $match['match_id'], 'roll-1');

        $before = $this->coinsOf($match['match_id'], $active);
        $spins  = $this->spinsRemaining($match['match_id'], $active);

        $first  = $game->spin($active, $match['match_id'], 'double-tap');
        $second = $game->spin($active, $match['match_id'], 'double-tap');

        $this->assertTrue($first['ok']);
        $this->assertTrue($second['ok'], 'A replay should succeed, returning the stored result');
        $this->assertTrue(($second['replayed'] ?? false) === true, 'The second call must be flagged as a replay');

        $this->assertSame(
            $first['spin']['coins'],
            $second['spin']['coins'],
            'A replayed spin must return the identical result'
        );
        $this->assertSame(
            $spins - 1,
            $this->spinsRemaining($match['match_id'], $active),
            'Only one spin may be consumed'
        );

        $payout = (int) $first['spin']['payout']['total'];
        $this->assertSame(
            $before + $payout,
            $this->coinsOf($match['match_id'], $active),
            'The payout must be credited exactly once'
        );
    }

    public function testARejectedActionIdCanBeRetried(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(56));
        $active = $this->activeUserId($match['match_id']);

        // Spinning before rolling is rejected...
        $this->assertFalse($game->spin($active, $match['match_id'], 'same-id')['ok']);
        // ...and the same id must still work once the state is valid.
        $game->rollDice($active, $match['match_id']);
        $this->assertTrue($game->spin($active, $match['match_id'], 'same-id')['ok']);
    }

    public function testRepeatedUpgradePurchaseChargesOnce(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(57));
        $active = $this->activeUserId($match['match_id']);

        // Reach the shop phase with plenty of coins.
        $roll = $game->rollDice($active, $match['match_id']);
        for ($i = 0; $i < (int) $roll['dice']['spins']; $i++) {
            $game->spin($active, $match['match_id']);
        }
        $this->setCoins($match['match_id'], $active, 5000);

        $offers = $game->shopOffers($this->matchPlayerId($match['match_id'], $active));
        $this->assertTrue(count($offers) > 0, 'The shop should have offers');
        $key  = (string) $offers[0]['key'];
        $cost = (int) $offers[0]['cost'];

        $first  = $game->buyUpgrade($active, $match['match_id'], $key, 'buy-1');
        $second = $game->buyUpgrade($active, $match['match_id'], $key, 'buy-1');

        $this->assertTrue($first['ok']);
        $this->assertTrue(($second['replayed'] ?? false) === true);
        $this->assertSame(5000 - $cost, $this->coinsOf($match['match_id'], $active),
            'The upgrade must be charged exactly once');
    }

    public function testUpgradeCannotBeBoughtWithoutEnoughCoins(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(58));
        $active = $this->activeUserId($match['match_id']);

        $roll = $game->rollDice($active, $match['match_id']);
        for ($i = 0; $i < (int) $roll['dice']['spins']; $i++) {
            $game->spin($active, $match['match_id']);
        }
        $this->setCoins($match['match_id'], $active, 1);

        $offers = $game->shopOffers($this->matchPlayerId($match['match_id'], $active));
        $result = $game->buyUpgrade($active, $match['match_id'], (string) $offers[0]['key']);

        $this->assertFalse($result['ok']);
        $this->assertStringContains('not enough coins', strtolower((string) $result['error']));
    }

    public function testUpgradesNotOnOfferCannotBeBought(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(59));
        $active = $this->activeUserId($match['match_id']);

        $roll = $game->rollDice($active, $match['match_id']);
        for ($i = 0; $i < (int) $roll['dice']['spins']; $i++) {
            $game->spin($active, $match['match_id']);
        }
        $this->setCoins($match['match_id'], $active, 100000);

        $offered = array_map(
            static fn (array $offer): string => (string) $offer['key'],
            $game->shopOffers($this->matchPlayerId($match['match_id'], $active))
        );
        $notOffered = null;
        foreach (\RoyalSpin\Game\UpgradeCatalog::all() as $key => $_) {
            if (!in_array((string) $key, $offered, true)) {
                $notOffered = (string) $key;
                break;
            }
        }
        $this->assertNotNull($notOffered);

        $result = $game->buyUpgrade($active, $match['match_id'], (string) $notOffered);
        $this->assertFalse($result['ok'], 'Only offered upgrades may be bought');
        $this->assertStringContains('not on offer', strtolower((string) $result['error']));
    }

    /* ================================================================== */
    /*  Events                                                            */
    /* ================================================================== */

    public function testEveryActionAppendsAnOrderedEvent(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(71));

        $active = $this->activeUserId($match['match_id']);
        $game->rollDice($active, $match['match_id']);
        $game->spin($active, $match['match_id']);

        $events = EventBus::since($match['match_id'], 0, $active);
        $types  = array_map(static fn (array $event): string => $event['type'], $events);

        $this->assertTrue(in_array(EventBus::MATCH_STARTED, $types, true));
        $this->assertTrue(in_array(EventBus::DICE_ROLLED, $types, true));
        $this->assertTrue(in_array(EventBus::SPIN_RESULT, $types, true));

        $previous = 0;
        foreach ($events as $event) {
            $this->assertTrue($event['seq'] > $previous, 'Event sequence must strictly increase');
            $previous = $event['seq'];
        }
    }

    public function testPrivateEventsOnlyReachTheirOwner(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(72));

        $active   = $this->activeUserId($match['match_id']);
        $opponent = $active === $tobi ? $alex : $tobi;

        // Playing a full turn opens the shop, which emits a private event.
        $roll = $game->rollDice($active, $match['match_id']);
        for ($i = 0; $i < (int) $roll['dice']['spins']; $i++) {
            $game->spin($active, $match['match_id']);
        }

        $mine   = EventBus::since($match['match_id'], 0, $active);
        $theirs = EventBus::since($match['match_id'], 0, $opponent);

        $hasShop = static fn (array $events): bool => array_filter(
            $events,
            static fn (array $event): bool => $event['type'] === EventBus::SHOP_UPDATED
        ) !== [];

        $this->assertTrue($hasShop($mine), 'The active player should receive their shop offers');
        $this->assertFalse($hasShop($theirs), 'Shop offers must not leak to opponents');
    }

    /* ================================================================== */
    /*  Disconnects                                                       */
    /* ================================================================== */

    public function testLosingConnectionKeepsThePlayerInTheMatch(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService();

        $game->setConnectionStatus($match['match_id'], $tobi, 'offline');

        $state = $game->state($match['match_id'], $alex);
        $this->assertNotNull($state);
        $this->assertCount(2, $state['players'], 'A disconnect must never remove a player');

        $tobiView = null;
        foreach ($state['players'] as $player) {
            if ($player['user_id'] === $tobi) {
                $tobiView = $player;
            }
        }
        $this->assertSame('offline', $tobiView['connection_status']);
    }

    public function testAnAbandonedTurnIsSkippedAfterTheTimeout(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService();

        $active = $this->activeUserId($match['match_id']);

        // Not skipped while online.
        $this->assertFalse($game->skipStalledTurn($match['match_id'])['skipped']);

        $game->setConnectionStatus($match['match_id'], $active, 'offline');
        // Not skipped while still inside the grace period.
        $this->assertFalse($game->skipStalledTurn($match['match_id'])['skipped']);

        // Backdate the presence + turn clock past the timeout.
        $stale = gmdate('Y-m-d H:i:s', time() - 600);
        Db::update('match_players', ['last_seen_at' => $stale], [
            'match_id' => $match['match_id'], 'user_id' => $active,
        ]);
        Db::update('matches', ['turn_started_at' => $stale], ['id' => $match['match_id']]);

        $this->assertTrue($game->skipStalledTurn($match['match_id'])['skipped'],
            'An abandoned turn must eventually be skipped');
        $this->assertFalse($this->activeUserId($match['match_id']) === $active,
            'The turn should have moved on');

        // The skipped player is still in the match with their coins intact.
        $this->assertCount(2, $game->state($match['match_id'], $alex)['players']);
    }
}
