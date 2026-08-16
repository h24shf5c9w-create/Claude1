<?php

declare(strict_types=1);

/**
 * Royal Spin realtime service.
 *
 *   php bin/ws-server.php
 *   php bin/ws-server.php --host=0.0.0.0 --port=8081
 *
 * Runs as a normal long-lived PHP CLI process — supervise it with systemd,
 * supervisord or `screen`. See README for a ready-made unit file.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use RoyalSpin\Realtime\WebSocketServer;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Env;

$options = getopt('', ['host::', 'port::']);

$host = (string) ($options['host'] ?? Env::get('WS_HOST', '0.0.0.0'));
$port = (int) ($options['port'] ?? Env::int('WS_PORT', 8081));

try {
    Db::connection();
} catch (Throwable $exception) {
    fwrite(STDERR, "Cannot reach the database: {$exception->getMessage()}\n");
    fwrite(STDERR, "Check your .env and run `php bin/migrate.php` first.\n");
    exit(1);
}

// Long-lived CLI process: no time limit, and unbuffered logs for journald.
set_time_limit(0);
ini_set('memory_limit', '256M');

(new WebSocketServer($host, $port))->run();
