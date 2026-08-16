<?php

declare(strict_types=1);

/**
 * Optional maintenance command.
 *
 * You normally never need this: the app installs itself on the first web
 * request (see src/Support/Installer.php). Use this when you want to run the
 * setup from a shell, or to reset a development database.
 *
 *   php bin/migrate.php              # create anything missing, refresh seeds
 *   php bin/migrate.php --fresh      # DROP every table first (destructive)
 *   php bin/migrate.php --seed-only  # only refresh the upgrade catalogue
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;
use RoyalSpin\Support\Installer;

$options  = getopt('', ['fresh', 'seed-only', 'quiet']);
$quiet    = isset($options['quiet']);
$fresh    = isset($options['fresh']);
$seedOnly = isset($options['seed-only']);

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
};

/** Tables in dependency order (children first, so dropping is safe). */
const TABLES = [
    'action_log', 'game_events', 'spins', 'dice_rolls', 'shop_offers',
    'player_upgrades', 'match_players', 'matches', 'room_players', 'rooms',
    'ws_tickets', 'rate_limits', 'user_stats', 'upgrades', 'users',
];

try {
    $driver = Db::driver();
    $say('Driver:  ' . $driver);
    if ($driver === 'sqlite') {
        $say('Database: ' . Installer::storagePath('royal-spin.sqlite'));
    }

    if ($fresh) {
        $say('Dropping existing tables…');
        Db::run($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF');
        foreach (TABLES as $table) {
            Db::run('DROP TABLE IF EXISTS ' . $table);
        }
        Db::run($driver === 'mysql' ? 'SET FOREIGN_KEY_CHECKS = 1' : 'PRAGMA foreign_keys = ON');

        // Drop the install marker so the next web request rebuilds cleanly.
        foreach (glob(Installer::storagePath('installed-*.lock')) ?: [] as $marker) {
            @unlink($marker);
        }
    }

    if ($seedOnly) {
        $say('Seeding upgrade catalogue…');
        $say('Seeded ' . Installer::seedUpgrades() . ' upgrades.');
    } else {
        $say('Applying schema…');
        Installer::install();
        $say('Schema up to date (' . count(TABLES) . ' tables).');
        $say('Upgrade catalogue seeded.');
    }

    $symbols = Config::array('game.symbols');
    $say('Symbols: ' . count($symbols) . ' (' . implode(', ', array_keys($symbols)) . ')');
    $say('Target:  ' . Config::int('game.target_coins') . ' coins · Start: ' . Config::int('game.starting_coins'));
    $say('Done.');
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
