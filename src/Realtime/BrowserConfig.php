<?php

declare(strict_types=1);

namespace RoyalSpin\Realtime;

use RoyalSpin\Support\Env;

/**
 * Decides what the browser should try for realtime updates.
 *
 * The WebSocket service is a long-running CLI process. Plenty of hosting —
 * ordinary shared hosting in particular — cannot run one, and cannot open a
 * custom port either. Rather than let the client burn several seconds on
 * connection attempts that can never succeed, the server states up front
 * whether a socket is worth trying.
 *
 * When it is not, the game runs on the HTTP polling transport, which uses the
 * identical event stream and is fully playable.
 */
final class BrowserConfig
{
    /** @return array{enabled:bool, url:string, port:int, reason:string} */
    public static function resolve(): array
    {
        $port      = Env::int('WS_PORT', 8081);
        $publicUrl = trim((string) Env::get('WS_PUBLIC_URL', ''));

        // Explicit override always wins.
        $override = Env::get('WS_ENABLED');
        if ($override !== null && $override !== '') {
            $enabled = Env::bool('WS_ENABLED', true);
            return [
                'enabled' => $enabled,
                'url'     => $publicUrl,
                'port'    => $port,
                'reason'  => 'WS_ENABLED is set explicitly',
            ];
        }

        // A configured public URL means someone deliberately set the service up.
        if ($publicUrl !== '') {
            return [
                'enabled' => true,
                'url'     => $publicUrl,
                'port'    => $port,
                'reason'  => 'WS_PUBLIC_URL is configured',
            ];
        }

        // Otherwise only assume a socket on a local/LAN development machine,
        // where `php bin/ws-server.php` is the normal way to run it.
        if (self::isLocalHost()) {
            return [
                'enabled' => true,
                'url'     => '',
                'port'    => $port,
                'reason'  => 'local development host',
            ];
        }

        return [
            'enabled' => false,
            'url'     => '',
            'port'    => $port,
            'reason'  => 'no WS_PUBLIC_URL on a remote host — using HTTP polling',
        ];
    }

    private static function isLocalHost(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $host = strtolower(explode(':', $host)[0]);

        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.local')) {
            return true;
        }

        // A private/LAN address (192.168.x.x, 10.x.x.x, …) means someone is
        // testing from a phone against their own machine, where the service is
        // normally running. A public IP or domain is treated as real hosting.
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $isPublic = filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        return !$isPublic;
    }
}
