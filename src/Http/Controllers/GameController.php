<?php

declare(strict_types=1);

namespace RoyalSpin\Http\Controllers;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Game\GamePhase;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\SlotEngine;
use RoyalSpin\Game\Symbols;
use RoyalSpin\Game\UpgradeCatalog;
use RoyalSpin\Game\UpgradeEngine;
use RoyalSpin\Http\Request;
use RoyalSpin\Http\Response;
use RoyalSpin\Realtime\EventBus;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Env;
use RoyalSpin\Support\Session;

/**
 * HTTP surface for the game.
 *
 * The WebSocket transport is the primary path, but every action is also
 * reachable here so the game stays fully playable if WebSockets are blocked
 * (corporate proxies, some mobile networks). Both paths call the identical
 * GameService methods, so there is exactly one implementation of the rules.
 */
final class GameController
{
    public function show(Request $request, string $matchId): never
    {
        $userId = (int) Session::userId();
        $id     = (int) $matchId;
        $game   = new GameService();

        $state = $game->state($id, $userId);
        if ($state === null) {
            Session::flash('error', 'That match is not available for your account.');
            Response::redirect('/dashboard');
        }

        if ((string) $state['match']['status'] === 'finished') {
            Response::redirect('/result/' . $id);
        }

        Response::view('game', [
            'title'     => 'Royal Spin — ' . $state['match']['room_code'],
            'user'      => AuthService::user($userId),
            'state'     => $state,
            'symbols'   => Symbols::publicTable(),
            'rarities'  => Config::array('game.rarities'),
            'bodyClass' => 'page-game',
            // Tells the client whether a WebSocket is worth attempting at all.
            // On hosting that cannot run the service it goes straight to the
            // HTTP polling transport instead of stalling on dead connections.
            'realtime'  => \RoyalSpin\Realtime\BrowserConfig::resolve(),
            'heartbeat' => Config::int('game.connection.heartbeat_seconds', 15),
        ]);
    }

    public function result(Request $request, string $matchId): never
    {
        $userId = (int) Session::userId();
        $id     = (int) $matchId;
        $game   = new GameService();

        if (!$game->isParticipant($id, $userId)) {
            Session::flash('error', 'That match result is not available for your account.');
            Response::redirect('/dashboard');
        }

        $results = $game->results($id);
        if ((string) $results['status'] === 'active') {
            Response::redirect('/game/' . $id);
        }

        Response::view('result', [
            'title'     => 'Match result',
            'user'      => AuthService::user($userId),
            'results'   => $results,
            'bodyClass' => 'page-result',
        ]);
    }

    /* ==================================================================
     |  JSON API
     * ================================================================== */

    public function state(Request $request, string $matchId): never
    {
        $userId = (int) Session::userId();
        $game   = new GameService();
        $state  = $game->state((int) $matchId, $userId);

        if ($state === null) {
            Response::error('Match not found.', 404);
        }
        $game->touch((int) $matchId, $userId);

        Response::json(['ok' => true, 'state' => $state]);
    }

    public function events(Request $request, string $matchId): never
    {
        $userId = (int) Session::userId();
        $id     = (int) $matchId;

        if (!(new GameService())->isParticipant($id, $userId)) {
            Response::error('Match not found.', 404);
        }

        $since  = max(0, $request->int('since', 0));
        $events = EventBus::since($id, $since, $userId);

        Response::json(['ok' => true, 'events' => $events]);
    }

    public function roll(Request $request, string $matchId): never
    {
        $this->respond((new GameService())->rollDice(
            (int) Session::userId(),
            (int) $matchId,
            $request->string('action_id') ?: null,
            false
        ));
    }

    public function reroll(Request $request, string $matchId): never
    {
        $this->respond((new GameService())->rollDice(
            (int) Session::userId(),
            (int) $matchId,
            $request->string('action_id') ?: null,
            true
        ));
    }

    public function spin(Request $request, string $matchId): never
    {
        $this->respond((new GameService())->spin(
            (int) Session::userId(),
            (int) $matchId,
            $request->string('action_id') ?: null
        ));
    }

    public function buy(Request $request, string $matchId): never
    {
        $this->respond((new GameService())->buyUpgrade(
            (int) Session::userId(),
            (int) $matchId,
            $request->string('upgrade_key'),
            $request->string('action_id') ?: null
        ));
    }

    public function endTurn(Request $request, string $matchId): never
    {
        $this->respond((new GameService())->endTurn(
            (int) Session::userId(),
            (int) $matchId,
            $request->string('action_id') ?: null
        ));
    }

    public function heartbeat(Request $request, string $matchId): never
    {
        $userId = (int) Session::userId();
        $id     = (int) $matchId;
        $game   = new GameService();

        if (!$game->isParticipant($id, $userId)) {
            Response::error('Match not found.', 404);
        }

        $game->setConnectionStatus($id, $userId, 'online');
        $game->touch($id, $userId);

        Response::json(['ok' => true, 'seq' => $this->currentSeq($id)]);
    }

    /** Mint a single-use ticket for the WebSocket handshake. */
    public function websocketTicket(Request $request): never
    {
        $userId = (int) Session::userId();
        Response::json([
            'ok'     => true,
            'ticket' => AuthService::issueWebSocketTicket($userId),
            'url'    => Env::get('WS_PUBLIC_URL', ''),
        ]);
    }

    /**
     * Local-development balance overlay. Refuses to run outside debug mode so
     * production can never leak effective probabilities.
     */
    public function debugBalance(Request $request, string $matchId): never
    {
        if (!Env::isDebug()) {
            Response::error('Not found.', 404);
        }

        $userId = (int) Session::userId();
        $id     = (int) $matchId;
        $game   = new GameService();

        $player = Db::first(
            'SELECT id, spins_since_triple, turns_taken FROM match_players
              WHERE match_id = :match_id AND user_id = :user_id',
            ['match_id' => $id, 'user_id' => $userId]
        );
        if ($player === null) {
            Response::error('Match not found.', 404);
        }

        $levels    = $game->upgradeLevels((int) $player['id']);
        $modifiers = UpgradeEngine::compute($levels);

        Response::json([
            'ok'    => true,
            'debug' => [
                'match_state_id'      => $id,
                'upgrade_levels'      => $levels,
                'effective_reels'     => SlotEngine::effectiveProbabilities($modifiers, (int) $player['spins_since_triple']),
                'base_reels'          => $modifiers->reelProbabilities,
                'dice_distribution'   => \RoyalSpin\Game\DiceEngine::spinDistribution($modifiers, (int) $player['turns_taken']),
                'payout_multipliers'  => $modifiers->toDisplayArray(true),
                'expected_per_spin'   => round(\RoyalSpin\Game\PayoutEngine::expectedValue($modifiers), 3),
            ],
        ]);
    }

    /** @param array<string,mixed> $result */
    private function respond(array $result): never
    {
        if (($result['ok'] ?? false) !== true) {
            Response::error((string) ($result['error'] ?? 'Action rejected.'), 409);
        }
        Response::json($result);
    }

    private function currentSeq(int $matchId): int
    {
        $row = Db::first('SELECT event_seq FROM matches WHERE id = :id', ['id' => $matchId]);
        return (int) ($row['event_seq'] ?? 0);
    }
}
