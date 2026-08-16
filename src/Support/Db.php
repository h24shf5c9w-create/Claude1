<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

use PDO;
use PDOException;
use Throwable;

/**
 * Thin PDO wrapper.
 *
 * MySQL/MariaDB is the production target. SQLite is supported so the automated
 * test suite (and a quick local try-out) can run without a database server —
 * the schema and every query in the project are written to work on both.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'mysql';
    private static int $txDepth = 0;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        // SQLite is the default so a fresh unzip runs with no configuration at
        // all. Set DB_DRIVER=mysql in .env to use a real database server.
        $driver = strtolower((string) Env::get('DB_DRIVER', 'sqlite'));

        if ($driver === 'sqlite') {
            $configured = (string) Env::get('DB_DATABASE', '');
            // A MySQL-style database *name* is not a usable SQLite path; fall
            // back to the storage folder rather than creating a junk file.
            $path = ($configured !== '' && (str_contains($configured, '/') || str_contains($configured, '\\')))
                ? $configured
                : Installer::storagePath('royal-spin.sqlite');
            if ($path !== ':memory:' && !is_dir(dirname($path))) {
                @mkdir(dirname($path), 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $driver = 'mysql';
            $dsn    = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                Env::get('DB_HOST', '127.0.0.1'),
                Env::int('DB_PORT', 3306),
                Env::get('DB_DATABASE', 'royal_spin')
            );
            $pdo = new PDO($dsn, Env::get('DB_USERNAME', 'root'), Env::get('DB_PASSWORD', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements: the single most important SQL-injection guard.
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        self::$driver = $driver;
        self::$pdo    = $pdo;

        return $pdo;
    }

    public static function driver(): string
    {
        self::connection();
        return self::$driver;
    }

    public static function isMysql(): bool
    {
        return self::driver() === 'mysql';
    }

    /**
     * `FOR UPDATE` row lock — the backbone of our race-condition protection on
     * MySQL. SQLite serialises writes at the transaction level instead, so the
     * clause is simply omitted there.
     */
    public static function forUpdate(): string
    {
        return self::isMysql() ? ' FOR UPDATE' : '';
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::connection()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : $key;
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return array<string,mixed>|null
     */
    public static function first(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<string|int,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @param array<string,mixed> $data */
    public static function insert(string $table, array $data): int
    {
        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);
        $sql          = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        self::run($sql, $data);
        return (int) self::connection()->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        $set    = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[]                 = $column . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            $conditions[]              = $column . ' = :where_' . $column;
            $params['where_' . $column] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conditions));
        return self::run($sql, $params)->rowCount();
    }

    /** Nested-safe transaction helper. Returns whatever the callback returns. */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connection();

        if (self::$txDepth === 0) {
            $pdo->beginTransaction();
        }
        self::$txDepth++;

        try {
            $result = $callback();
            self::$txDepth--;
            if (self::$txDepth === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function inTransaction(): bool
    {
        return self::$txDepth > 0;
    }

    /** Ping + reconnect helper for the long-running WebSocket process. */
    public static function healthy(): bool
    {
        try {
            self::connection()->query('SELECT 1');
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    public static function reset(): void
    {
        self::$pdo     = null;
        self::$txDepth = 0;
    }

    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
