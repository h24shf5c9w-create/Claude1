<?php

declare(strict_types=1);

/**
 * Create/refresh the database schema and seed the reference data.
 *
 *   php bin/migrate.php              # apply schema, then seed
 *   php bin/migrate.php --fresh      # DROP everything first (destructive)
 *   php bin/migrate.php --seed-only  # only refresh the upgrade catalogue
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use RoyalSpin\Game\UpgradeCatalog;
use RoyalSpin\Support\Config;
use RoyalSpin\Support\Db;

$options  = getopt('', ['fresh', 'seed-only', 'quiet']);
$quiet    = isset($options['quiet']);
$fresh    = isset($options['fresh']);
$seedOnly = isset($options['seed-only']);

$say = static function (string $message) use ($quiet): void {
    if (!$quiet) {
        fwrite(STDOUT, $message . PHP_EOL);
    }
};

/** Tables in dependency order (children first for dropping). */
const TABLES = [
    'action_log', 'game_events', 'spins', 'dice_rolls', 'shop_offers',
    'player_upgrades', 'match_players', 'matches', 'room_players', 'rooms',
    'ws_tickets', 'rate_limits', 'user_stats', 'upgrades', 'users',
];

try {
    $driver = Db::driver();
    $say("Driver: {$driver}");

    if (!$seedOnly) {
        if ($fresh) {
            $say('Dropping existing tables…');
            if ($driver === 'mysql') {
                Db::run('SET FOREIGN_KEY_CHECKS = 0');
            } else {
                Db::run('PRAGMA foreign_keys = OFF');
            }
            foreach (TABLES as $table) {
                Db::run('DROP TABLE IF EXISTS ' . $table);
            }
            if ($driver === 'mysql') {
                Db::run('SET FOREIGN_KEY_CHECKS = 1');
            } else {
                Db::run('PRAGMA foreign_keys = ON');
            }
        }

        $schemaFile = ROYAL_SPIN_ROOT . '/database/schema.' . ($driver === 'mysql' ? 'mysql' : 'sqlite') . '.sql';
        $sql        = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException('Could not read ' . $schemaFile);
        }

        $say('Applying ' . basename($schemaFile) . '…');
        foreach (splitStatements($sql) as $statement) {
            Db::run($statement);
        }
        $say('Schema up to date (' . count(TABLES) . ' tables).');
    }

    // ---- seed: mirror config/upgrades.php into the `upgrades` table --------
    $say('Seeding upgrade catalogue…');
    $now   = Db::now();
    $count = 0;
    foreach (UpgradeCatalog::all() as $key => $upgrade) {
        $row = [
            'name'        => (string) $upgrade['name'],
            'description' => (string) $upgrade['description'],
            'category'    => (string) $upgrade['category'],
            'rarity'      => (string) $upgrade['rarity'],
            'icon'        => (string) $upgrade['icon'],
            'max_level'   => (int) $upgrade['max_level'],
            'base_cost'   => (int) $upgrade['base_cost'],
            'cost_growth' => (float) $upgrade['cost_growth'],
            'effects'     => json_encode($upgrade['effects'], JSON_UNESCAPED_UNICODE) ?: '[]',
            'updated_at'  => $now,
        ];

        $exists = Db::first('SELECT upgrade_key FROM upgrades WHERE upgrade_key = :key', ['key' => $key]);
        if ($exists === null) {
            Db::insert('upgrades', array_merge(['upgrade_key' => (string) $key], $row));
        } else {
            Db::update('upgrades', $row, ['upgrade_key' => (string) $key]);
        }
        $count++;
    }
    $say("Seeded {$count} upgrades.");

    $symbols = Config::array('game.symbols');
    $say('Symbols configured: ' . count($symbols) . ' (' . implode(', ', array_keys($symbols)) . ')');
    $say('Target: ' . Config::int('game.target_coins') . ' coins · Start: ' . Config::int('game.starting_coins'));
    $say('Done.');
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * Split a schema file into statements. Comments are stripped first so a `;`
 * inside a comment cannot break the split.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $lines = [];
    foreach (explode("\n", $sql) as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--')) {
            continue;
        }
        $lines[] = $line;
    }

    $statements = [];
    foreach (explode(';', implode("\n", $lines)) as $statement) {
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
    }
    return $statements;
}
