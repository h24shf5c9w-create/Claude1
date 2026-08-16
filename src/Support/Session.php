<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

/**
 * Hardened PHP session handling + CSRF tokens.
 */
final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || PHP_SAPI === 'cli') {
            self::$started = true;
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $secure = Env::bool('SESSION_SECURE', false)
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        session_name(Env::get('SESSION_NAME', 'royal_spin_session') ?? 'royal_spin_session');

        // Keep sessions on their own storage path so two copies of the game in
        // different subfolders of one host cannot clobber each other's login.
        $sessionPath = Installer::storagePath('sessions');
        if (is_dir($sessionPath) || @mkdir($sessionPath, 0775, true)) {
            if (is_writable($sessionPath)) {
                session_save_path($sessionPath);
            }
        }

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => Path::base() === '' ? '/' : Path::base() . '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,        // no JS access to the session cookie
            'samesite' => 'Lax',       // blocks cross-site POSTs carrying the cookie
        ]);
        ini_set('session.use_strict_mode', '1');   // reject attacker-supplied session ids
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        session_start();
        self::$started = true;

        // Bind the session to the user agent to make sidejacking harder.
        $fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . Installer::applicationKey());
        if (!isset($_SESSION['_fp'])) {
            $_SESSION['_fp'] = $fingerprint;
        } elseif (!hash_equals((string) $_SESSION['_fp'], $fingerprint)) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_fp'] = $fingerprint;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function userId(): ?int
    {
        $id = self::get('user_id');
        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    public static function login(int $userId): void
    {
        self::start();
        // Prevent session fixation: brand new id at every privilege change.
        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
        $_SESSION['user_id']    = $userId;
        $_SESSION['logged_at']  = time();
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli') {
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name() ?: 'royal_spin_session', '', [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly' => $params['httponly'],
                    'samesite' => 'Lax',
                ]);
            }
            session_destroy();
        }
        self::$started = false;
    }

    /* --------------------------------------------------------------- CSRF */

    public static function csrfToken(): string
    {
        self::start();
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::start();
        $expected = $_SESSION['_csrf'] ?? null;
        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /** One-shot flash messages for redirect-after-post flows. */
    public static function flash(string $key, mixed $value = null): mixed
    {
        self::start();
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $stored = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $stored;
    }
}
