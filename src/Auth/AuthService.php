<?php

declare(strict_types=1);

namespace RoyalSpin\Auth;

use RoyalSpin\Support\Db;
use RoyalSpin\Support\RateLimiter;
use RoyalSpin\Support\Session;

/**
 * Accounts: registration, login, logout, WebSocket tickets.
 *
 * Passwords are hashed with PASSWORD_DEFAULT (Argon2id or bcrypt depending on
 * the build) and are re-hashed transparently when PHP's default improves.
 */
final class AuthService
{
    private const LOGIN_MAX_ATTEMPTS  = 8;
    private const LOGIN_WINDOW        = 900;   // 15 minutes
    private const SIGNUP_MAX_ATTEMPTS = 5;
    private const SIGNUP_WINDOW       = 3600;

    /**
     * @return array{ok:bool, error?:string, field?:string, user_id?:int}
     */
    public function register(string $username, string $email, string $password, string $ip): array
    {
        $limit = RateLimiter::hit('signup:' . $ip, self::SIGNUP_MAX_ATTEMPTS, self::SIGNUP_WINDOW);
        if (!$limit['allowed']) {
            return ['ok' => false, 'error' => 'Too many sign-ups from this network. Please try again later.'];
        }

        $usernameKey = mb_strtolower($username);
        $email       = mb_strtolower($email);

        return Db::transaction(static function () use ($username, $usernameKey, $email, $password): array {
            if (Db::first('SELECT id FROM users WHERE username_key = :key', ['key' => $usernameKey]) !== null) {
                return ['ok' => false, 'error' => 'That username is already taken.', 'field' => 'username'];
            }
            if (Db::first('SELECT id FROM users WHERE email = :email', ['email' => $email]) !== null) {
                return ['ok' => false, 'error' => 'An account with that email already exists.', 'field' => 'email'];
            }

            $now    = Db::now();
            $userId = Db::insert('users', [
                'username'      => $username,
                'username_key'  => $usernameKey,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

            Db::insert('user_stats', ['user_id' => $userId, 'updated_at' => $now]);

            return ['ok' => true, 'user_id' => $userId];
        });
    }

    /**
     * @return array{ok:bool, error?:string, user_id?:int}
     */
    public function login(string $identifier, string $password, string $ip): array
    {
        $ipLimit = RateLimiter::hit('login-ip:' . $ip, self::LOGIN_MAX_ATTEMPTS * 3, self::LOGIN_WINDOW);
        if (!$ipLimit['allowed']) {
            return ['ok' => false, 'error' => 'Too many attempts. Please wait a few minutes and try again.'];
        }
        $userLimit = RateLimiter::hit('login-user:' . mb_strtolower($identifier), self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW);
        if (!$userLimit['allowed']) {
            return ['ok' => false, 'error' => 'Too many attempts for this account. Please wait a few minutes.'];
        }

        $key  = mb_strtolower(trim($identifier));
        $user = Db::first(
            'SELECT id, password_hash FROM users WHERE username_key = :key OR email = :key LIMIT 1',
            ['key' => $key]
        );

        if ($user === null) {
            // Constant-ish work either way so timing does not reveal whether
            // the account exists.
            password_verify($password, '$2y$12$usesomesillystringfoeleventwentytwoxxxxxxxxxxxxxxxxxxxxxxxxxxx');
            return ['ok' => false, 'error' => 'Wrong username or password.'];
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return ['ok' => false, 'error' => 'Wrong username or password.'];
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', [
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at'    => Db::now(),
            ], ['id' => (int) $user['id']]);
        }

        RateLimiter::clear('login-user:' . mb_strtolower($identifier));

        return ['ok' => true, 'user_id' => (int) $user['id']];
    }

    /** @return array<string,mixed>|null */
    public static function user(int $userId): ?array
    {
        return Db::first(
            'SELECT id, username, email, created_at FROM users WHERE id = :id',
            ['id' => $userId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function currentUser(): ?array
    {
        $userId = Session::userId();
        return $userId === null ? null : self::user($userId);
    }

    /** @return array<string,int> */
    public static function stats(int $userId): array
    {
        $row = Db::first('SELECT * FROM user_stats WHERE user_id = :id', ['id' => $userId]);
        if ($row === null) {
            return [
                'games_played' => 0, 'wins' => 0, 'losses' => 0, 'total_spins' => 0,
                'total_pairs' => 0, 'total_triples' => 0, 'crown_triples' => 0,
                'trophy_triples' => 0, 'highest_match_score' => 0,
                'biggest_single_win' => 0, 'total_coins_won' => 0,
            ];
        }
        $stats = [];
        foreach ($row as $key => $value) {
            if ($key === 'user_id' || $key === 'updated_at') {
                continue;
            }
            $stats[(string) $key] = (int) $value;
        }
        return $stats;
    }

    /* ==================================================================
     |  WebSocket tickets
     |
     |  The browser cannot set headers on a WebSocket handshake, and cookies
     |  are unreliable across origins/ports. So an authenticated HTTP request
     |  mints a short-lived, single-use ticket which the socket presents.
     * ================================================================== */

    public static function issueWebSocketTicket(int $userId, int $ttlSeconds = 60): string
    {
        $ticket = bin2hex(random_bytes(24));
        Db::insert('ws_tickets', [
            'ticket'     => $ticket,
            'user_id'    => $userId,
            'expires_at' => time() + $ttlSeconds,
            'used_at'    => null,
        ]);
        // Opportunistic cleanup.
        Db::run('DELETE FROM ws_tickets WHERE expires_at < :cutoff', ['cutoff' => time() - 300]);
        return $ticket;
    }

    /** Consume a ticket, returning the user id it authenticates. */
    public static function redeemWebSocketTicket(string $ticket): ?int
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $ticket)) {
            return null;
        }

        return Db::transaction(static function () use ($ticket): ?int {
            $row = Db::first(
                'SELECT user_id, expires_at, used_at FROM ws_tickets WHERE ticket = :ticket' . Db::forUpdate(),
                ['ticket' => $ticket]
            );
            if ($row === null) {
                return null;
            }
            if ((int) $row['expires_at'] < time() || $row['used_at'] !== null) {
                Db::run('DELETE FROM ws_tickets WHERE ticket = :ticket', ['ticket' => $ticket]);
                return null;
            }
            Db::update('ws_tickets', ['used_at' => time()], ['ticket' => $ticket]);
            return (int) $row['user_id'];
        });
    }
}
