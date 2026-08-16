<?php

declare(strict_types=1);

namespace RoyalSpin\Http\Controllers;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\RoomService;
use RoyalSpin\Http\Request;
use RoyalSpin\Http\Response;
use RoyalSpin\Realtime\EventBus;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Session;

final class RoomController
{
    public function create(Request $request): never
    {
        $userId  = (int) Session::userId();
        $service = new RoomService();

        $result = $service->create(
            $userId,
            $request->int('max_players', Config::int('game.max_players', 4)),
            $request->get('target_coins') === null ? null : $request->int('target_coins')
        );

        if (!($result['ok'] ?? false)) {
            Response::error((string) ($result['error'] ?? 'Could not create the room.'), 422);
        }

        /** @var array<string,mixed> $room */
        $room = $result['room'];
        Response::json(['ok' => true, 'room' => $room, 'redirect' => '/lobby/' . $room['code']]);
    }

    public function join(Request $request): never
    {
        $userId  = (int) Session::userId();
        $service = new RoomService();
        $result  = $service->join($userId, $request->string('code'));

        if (!($result['ok'] ?? false)) {
            Response::error((string) ($result['error'] ?? 'Could not join the room.'), 422);
        }

        // Rejoining a running match goes straight into the game.
        if (($result['reconnect'] ?? false) && isset($result['match_id'])) {
            Response::json([
                'ok'        => true,
                'reconnect' => true,
                'match_id'  => (int) $result['match_id'],
                'redirect'  => '/game/' . (int) $result['match_id'],
            ]);
        }

        /** @var array<string,mixed> $room */
        $room = $result['room'];
        Response::json(['ok' => true, 'room' => $room, 'redirect' => '/lobby/' . $room['code']]);
    }

    public function lobby(Request $request, string $code): never
    {
        $userId  = (int) Session::userId();
        $service = new RoomService();
        $room    = $service->findByCode($code);

        if ($room === null) {
            Session::flash('error', 'Room not found. It may have been closed.');
            Response::redirect('/dashboard');
        }

        $roomId = (int) $room['id'];
        $member = Db::first(
            'SELECT id FROM room_players WHERE room_id = :room_id AND user_id = :user_id',
            ['room_id' => $roomId, 'user_id' => $userId]
        );
        if ($member === null) {
            Session::flash('error', 'You are not a member of that room.');
            Response::redirect('/dashboard');
        }

        if ((string) $room['status'] === 'active' && $room['match_id'] !== null) {
            Response::redirect('/game/' . (int) $room['match_id']);
        }

        Response::view('lobby', [
            'title'    => 'Lobby ' . $room['code'],
            'user'     => AuthService::user($userId),
            'lobby'    => $service->lobbyState($roomId),
            'bodyClass'=> 'page-lobby',
        ]);
    }

    public function state(Request $request, string $roomId): never
    {
        $userId  = (int) Session::userId();
        $id      = (int) $roomId;
        $service = new RoomService();

        $member = Db::first(
            'SELECT id FROM room_players WHERE room_id = :room_id AND user_id = :user_id',
            ['room_id' => $id, 'user_id' => $userId]
        );
        if ($member === null) {
            // The room may have started (and this user is in the match) or been closed.
            $matchId = (new GameService())->activeMatchIdFor($userId);
            if ($matchId !== null) {
                Response::json(['ok' => true, 'started' => true, 'match_id' => $matchId]);
            }
            Response::error('This room is no longer available.', 404);
        }

        $room = Db::first('SELECT * FROM rooms WHERE id = :id', ['id' => $id]);
        if ($room === null) {
            Response::error('This room was closed.', 404);
        }
        if ((string) $room['status'] === 'active' && $room['match_id'] !== null) {
            Response::json(['ok' => true, 'started' => true, 'match_id' => (int) $room['match_id']]);
        }

        Response::json(['ok' => true, 'room' => $service->lobbyState($id)]);
    }

    public function ready(Request $request, string $roomId): never
    {
        $userId = (int) Session::userId();
        $result = (new RoomService())->setReady($userId, (int) $roomId, $request->bool('ready', true));

        if (!($result['ok'] ?? false)) {
            Response::error((string) ($result['error'] ?? 'Could not update your status.'), 422);
        }
        Response::json(['ok' => true, 'room' => $result['room']]);
    }

    public function leave(Request $request, string $roomId): never
    {
        $userId = (int) Session::userId();
        $result = (new RoomService())->leave($userId, (int) $roomId);

        if (!($result['ok'] ?? false)) {
            Response::error((string) ($result['error'] ?? 'Could not leave the room.'), 422);
        }
        Response::json(['ok' => true, 'redirect' => '/dashboard']);
    }

    public function start(Request $request, string $roomId): never
    {
        $userId = (int) Session::userId();
        $result = (new GameService())->startMatch($userId, (int) $roomId);

        if (!($result['ok'] ?? false)) {
            Response::error((string) ($result['error'] ?? 'Could not start the match.'), 422);
        }

        $matchId = (int) $result['match_id'];
        Response::json(['ok' => true, 'match_id' => $matchId, 'redirect' => '/game/' . $matchId]);
    }
}
