<?php

declare(strict_types=1);

namespace RoyalSpin\Http;

use RoyalSpin\Support\Env;
use RoyalSpin\Support\Session;

final class Response
{
    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400, ?string $field = null): never
    {
        $payload = ['ok' => false, 'error' => $message];
        if ($field !== null) {
            $payload['field'] = $field;
        }
        self::json($payload, $status);
    }

    public static function redirect(string $location, int $status = 302): never
    {
        http_response_code($status);
        header('Location: ' . $location);
        exit;
    }

    /**
     * Render a PHP template inside the layout.
     *
     * @param array<string,mixed> $data
     */
    public static function view(string $template, array $data = [], int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        self::securityHeaders();

        $data['csrf']  = Session::csrfToken();
        $data['debug'] = Env::isDebug();

        $viewPath = ROYAL_SPIN_ROOT . '/resources/views/' . $template . '.php';
        if (!is_file($viewPath)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        extract($data, EXTR_SKIP);
        ob_start();
        require $viewPath;
        $content = (string) ob_get_clean();

        require ROYAL_SPIN_ROOT . '/resources/views/layouts/app.php';
        exit;
    }

    private static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        // All scripts and styles are served from this origin; no inline script
        // is used anywhere, so a strict CSP costs nothing.
        $wsSource = Env::get('WS_PUBLIC_URL', '') !== ''
            ? ' ' . self::connectSource((string) Env::get('WS_PUBLIC_URL', ''))
            : '';
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self'; style-src 'self'; img-src 'self' data:; "
            . "font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; "
            . "connect-src 'self' ws: wss:" . $wsSource
        );
    }

    private static function connectSource(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        $scheme = ($parts['scheme'] ?? 'ws') === 'wss' ? 'wss' : 'ws';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $parts['host'] . $port;
    }
}
