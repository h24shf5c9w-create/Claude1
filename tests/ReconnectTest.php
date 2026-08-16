<?php

declare(strict_types=1);

namespace RoyalSpin\Tests;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Game\GamePhase;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\RoomService;
use RoyalSpin\Realtime\EventBus;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Rng;

/**
 * Reconnect is the requirement the whole persistence design exists for:
 * closing the browser mid-turn must lose nothing at all.
 */
final class ReconnectTest extends DatabaseTestCase
{
    public function testRejoiningByCodeReturnsTheSameSeatAndState(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(31));

        $active = $this->activeUserId($match['match_id']);
        $game->rollDice($active, $match['match_id']);
        $game->spin($active, $match['match_id']);

        $before = $game->state($match['match_id'], $active);

        // "Browser closed": the socket drops, presence flips to offline.
        $game->setConnectionStatus($match['match_id'], $active, 'offline');

        // "Logs back in and enters the room code".
        $rejoin = (new RoomService())->join($active, $match['code']);
        $this->assertTrue($rejoin['ok'], 'A participant must be allowed back in');
        $this->assertTrue(($rejoin['reconnect'] ?? false) === true, 'This must be flagged as a reconnect');
        $this->assertSame($match['match_id'], (int) $rejoin['match_id']);

        $after = $game->state($match['match_id'], $active);

        $this->assertSame($before['you']['seat'], $after['you']['seat'], 'Seat must be preserved');
        $this->assertSame($before['you']['coins'], $after['you']['coins'], 'Coins must be preserved');
        $this->assertSame(
            $before['you']['spins_remaining'],
            $after['you']['spins_remaining'],
            'Remaining spins must be preserved'
        );
        $this->assertSame($before['you']['dice_value'], $after['you']['dice_value'], 'The dice result must be preserved');
        $this->assertSame($before['match']['turn_number'], $after['match']['turn_number']);
        $this->assertCount(2, $after['players'], 'No duplicate player may be created');
    }

    public function testReconnectMidTurnKeepsExactlyTheRemainingSpins(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(4242));

        $active = $this->activeUserId($match['match_id']);

        $roll    = $game->rollDice($active, $match['match_id']);
        $granted = (int) $roll['dice']['spins'];
        $this->assertTrue($granted >= 1);

        // Play part of the turn, always leaving at least one spin unplayed so
        // the "came back mid-turn" case is what actually gets asserted.
        $played = intdiv($granted, 2);
        for ($i = 0; $i < $played; $i++) {
            $game->spin($active, $match['match_id']);
        }
        $expectedRemaining = $this->spinsRemaining($match['match_id'], $active);
        $expectedCoins     = $this->coinsOf($match['match_id'], $active);

        // Simulate the phone dying and the player coming back later.
        $game->setConnectionStatus($match['match_id'], $active, 'offline');
        Db::reset();                                   // brand new connection
        $game = new GameService(new Rng(999));         // brand new process

        $state = $game->state($match['match_id'], $active);

        $this->assertSame($granted, (int) $state['you']['spins_total']);
        $this->assertSame($expectedRemaining, (int) $state['you']['spins_remaining']);
        $this->assertSame($expectedCoins, (int) $state['you']['coins']);
        $this->assertTrue($state['you']['is_your_turn'], 'It must still be their turn');
        $this->assertFalse($state['you']['can_roll'], 'They must never be allowed to re-roll the turn');
        $this->assertTrue($state['you']['can_spin'], 'They must be able to continue spinning');

        // And the remaining spins really are playable.
        for ($i = 0; $i < $expectedRemaining; $i++) {
            $this->assertTrue($game->spin($active, $match['match_id'])['ok']);
        }
        $this->assertSame(0, $this->spinsRemaining($match['match_id'], $active));
    }

    public function testUpgradesSurviveAReconnect(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(77));

        $active = $this->activeUserId($match['match_id']);
        $roll   = $game->rollDice($active, $match['match_id']);
        for ($i = 0; $i < (int) $roll['dice']['spins']; $i++) {
            $game->spin($active, $match['match_id']);
        }

        $this->setCoins($match['match_id'], $active, 4000);
        $playerId = $this->matchPlayerId($match['match_id'], $active);
        $offers   = $game->shopOffers($playerId);
        $key      = (string) $offers[0]['key'];

        $this->assertTrue($game->buyUpgrade($active, $match['match_id'], $key)['ok']);
        $coinsAfterPurchase = $this->coinsOf($match['match_id'], $active);

        // Full process restart.
        Db::reset();
        $game = new GameService();

        $state = $game->state($match['match_id'], $active);
        $owned = array_map(static fn (array $u): string => (string) $u['key'], $state['you']['upgrades']);

        $this->assertTrue(in_array($key, $owned, true), 'The purchased upgrade must still be owned');
        $this->assertSame($coinsAfterPurchase, (int) $state['you']['coins'], 'Coins must not be refunded or re-charged');

        // And the effect is still applied server-side.
        $levels = $game->upgradeLevels($playerId);
        $this->assertTrue(($levels[$key] ?? 0) >= 1);
    }

    public function testShopOffersSurviveARefreshAndCannotBeRerolled(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(81));
        $active = $this->activeUserId($match['match_id']);

        $roll = $game->rollDice($active, $match['match_id']);
        for ($i = 0; $i < (int) $roll['dice']['spins']; $i++) {
            $game->spin($active, $match['match_id']);
        }

        $first = array_map(
            static fn (array $offer): string => (string) $offer['key'],
            $game->state($match['match_id'], $active)['you']['shop_offers']
        );

        // "Refresh": reload the state repeatedly.
        for ($i = 0; $i < 5; $i++) {
            $again = array_map(
                static fn (array $offer): string => (string) $offer['key'],
                $game->state($match['match_id'], $active)['you']['shop_offers']
            );
            $this->assertSame($first, $again, 'Refreshing must not re-roll the shop');
        }
    }

    public function testRefreshDoesNotReplayTheLastSpinPayout(): void
    {
        $match  = $this->startMatch([$this->makeUser('a'), $this->makeUser('b')]);
        $game   = new GameService(new Rng(84));
        $active = $this->activeUserId($match['match_id']);

        $game->rollDice($active, $match['match_id']);
        $result = $game->spin($active, $match['match_id'], 'spin-abc');
        $coins  = $this->coinsOf($match['match_id'], $active);

        // Reading state any number of times must never change coins.
        for ($i = 0; $i < 5; $i++) {
            $state = $game->state($match['match_id'], $active);
            $this->assertSame($coins, (int) $state['you']['coins']);
        }

        // And the already-known result is still available to rebuild the UI.
        $this->assertNotNull($state['last_spin']);
        $this->assertSame($result['spin']['reels'], $state['last_spin']['reels']);

        // Re-submitting the same action id after the "refresh" is a no-op.
        $replay = $game->spin($active, $match['match_id'], 'spin-abc');
        $this->assertTrue(($replay['replayed'] ?? false) === true);
        $this->assertSame($coins, $this->coinsOf($match['match_id'], $active));
    }

    public function testEventStreamCanBeReplayedFromAnySequence(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);
        $game  = new GameService(new Rng(85));

        $active = $this->activeUserId($match['match_id']);
        $game->rollDice($active, $match['match_id']);
        $game->spin($active, $match['match_id']);

        $all = EventBus::since($match['match_id'], 0, $active);
        $this->assertTrue(count($all) >= 3);

        // A client that saw up to seq N gets exactly the remainder.
        $midpoint = $all[1]['seq'];
        $rest     = EventBus::since($match['match_id'], $midpoint, $active);

        $this->assertSame(count($all) - 2, count($rest), 'Replay from a sequence must return only newer events');
        foreach ($rest as $event) {
            $this->assertTrue($event['seq'] > $midpoint);
        }
    }

    public function testWebSocketTicketsAreSingleUseAndExpire(): void
    {
        $userId = $this->makeUser('socketuser');

        $ticket = AuthService::issueWebSocketTicket($userId);
        $this->assertSame($userId, AuthService::redeemWebSocketTicket($ticket), 'A fresh ticket should authenticate');
        $this->assertNull(AuthService::redeemWebSocketTicket($ticket), 'A ticket must not be reusable');

        $expired = AuthService::issueWebSocketTicket($userId, -10);
        $this->assertNull(AuthService::redeemWebSocketTicket($expired), 'An expired ticket must be rejected');

        $this->assertNull(AuthService::redeemWebSocketTicket('not-a-ticket'), 'Malformed tickets must be rejected');
    }

    public function testAnOngoingMatchIsDiscoverableAfterLoggingBackIn(): void
    {
        $tobi  = $this->makeUser('tobi');
        $alex  = $this->makeUser('alex');
        $match = $this->startMatch([$tobi, $alex]);

        // Fresh "session": nothing but the user id.
        Db::reset();
        $game = new GameService();

        $this->assertSame($match['match_id'], $game->activeMatchIdFor($tobi),
            'The dashboard must be able to point the player back at their match');
    }
}
