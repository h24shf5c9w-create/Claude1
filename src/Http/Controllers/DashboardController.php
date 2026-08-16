<?php

declare(strict_types=1);

namespace RoyalSpin\Http\Controllers;

use RoyalSpin\Auth\AuthService;
use RoyalSpin\Game\GameService;
use RoyalSpin\Game\RoomService;
use RoyalSpin\Http\Request;
use RoyalSpin\Http\Response;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Session;

final class DashboardController
{
    public function index(Request $request): never
    {
        $userId = (int) Session::userId();
        $user   = AuthService::user($userId);
        if ($user === null) {
            Session::logout();
            Response::redirect('/login');
        }

        $rooms  = new RoomService();
        $game   = new GameService();

        $activeRoom  = $rooms->activeRoomForUser($userId);
        $activeMatch = $game->activeMatchIdFor($userId);

        Response::view('dashboard', [
            'title'        => 'Dashboard',
            'user'         => $user,
            'stats'        => AuthService::stats($userId),
            'activeRoom'   => $activeRoom,
            'activeMatch'  => $activeMatch,
            'history'      => $this->history($userId),
            'maxPlayers'   => Config::int('game.max_players', 4),
            'minPlayers'   => Config::int('game.min_players', 2),
            'targetCoins'  => Config::int('game.target_coins', 1500),
            'error'        => Session::flash('error'),
            'notice'       => Session::flash('notice'),
        ]);
    }

    public function profile(Request $request): never
    {
        $userId = (int) Session::userId();
        $user   = AuthService::user($userId);
        if ($user === null) {
            Session::logout();
            Response::redirect('/login');
        }

        Response::view('profile', [
            'title'   => 'Profile',
            'user'    => $user,
            'stats'   => AuthService::stats($userId),
            'history' => $this->history($userId, 25),
        ]);
    }

    /**
     * Compact match history. `matches` rows survive room deletion, so this keeps
     * working long after the room code has been recycled.
     *
     * @return list<array<string,mixed>>
     */
    private function history(int $userId, int $limit = 8): array
    {
        $rows = Db::all(
            "SELECT m.id, m.room_code, m.started_at, m.finished_at, m.winner_user_id,
                    mp.coins, mp.placement, m.player_count
               FROM match_players mp
               INNER JOIN matches m ON m.id = mp.match_id
              WHERE mp.user_id = :user_id AND m.status = 'finished'
           ORDER BY m.finished_at DESC
              LIMIT " . max(1, min($limit, 50)),
            ['user_id' => $userId]
        );

        $history = [];
        foreach ($rows as $row) {
            $started  = $row['started_at'] === null ? null : strtotime((string) $row['started_at'] . ' UTC');
            $finished = $row['finished_at'] === null ? null : strtotime((string) $row['finished_at'] . ' UTC');
            $winner   = $row['winner_user_id'] === null ? null : (int) $row['winner_user_id'];

            $history[] = [
                'match_id'  => (int) $row['id'],
                'room_code' => (string) $row['room_code'],
                'coins'     => (int) $row['coins'],
                'placement' => $row['placement'] === null ? null : (int) $row['placement'],
                'players'   => (int) $row['player_count'],
                'won'       => $winner === $userId,
                'finished'  => $finished,
                'duration'  => $started !== null && $finished !== null ? max(0, $finished - $started) : null,
            ];
        }

        return $history;
    }
}
