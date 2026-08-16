<?php

declare(strict_types=1);

namespace RoyalSpin\Support;

/**
 * Database-backed fixed-window rate limiter. Used for login/registration
 * brute-force protection and to throttle abusive WebSocket clients.
 */
final class RateLimiter
{
    /**
     * @return array{allowed:bool, remaining:int, retry_after:int}
     */
    public static function hit(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $bucket = substr(hash('sha256', $key), 0, 64);
        $now    = time();

        return Db::transaction(static function () use ($bucket, $maxAttempts, $windowSeconds, $now): array {
            $row = Db::first(
                'SELECT bucket, attempts, window_started_at FROM rate_limits WHERE bucket = :bucket' . Db::forUpdate(),
                ['bucket' => $bucket]
            );

            if ($row === null) {
                Db::insert('rate_limits', [
                    'bucket'            => $bucket,
                    'attempts'          => 1,
                    'window_started_at' => $now,
                ]);
                return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
            }

            $windowStart = (int) $row['window_started_at'];
            $attempts    = (int) $row['attempts'];

            if ($now - $windowStart >= $windowSeconds) {
                Db::update('rate_limits', ['attempts' => 1, 'window_started_at' => $now], ['bucket' => $bucket]);
                return ['allowed' => true, 'remaining' => $maxAttempts - 1, 'retry_after' => 0];
            }

            if ($attempts >= $maxAttempts) {
                return [
                    'allowed'     => false,
                    'remaining'   => 0,
                    'retry_after' => max(1, $windowSeconds - ($now - $windowStart)),
                ];
            }

            Db::update('rate_limits', ['attempts' => $attempts + 1], ['bucket' => $bucket]);
            return ['allowed' => true, 'remaining' => $maxAttempts - $attempts - 1, 'retry_after' => 0];
        });
    }

    /** Called after a successful login so a legitimate user is not punished. */
    public static function clear(string $key): void
    {
        Db::run('DELETE FROM rate_limits WHERE bucket = :bucket', [
            'bucket' => substr(hash('sha256', $key), 0, 64),
        ]);
    }

    public static function prune(int $olderThanSeconds = 86400): void
    {
        Db::run('DELETE FROM rate_limits WHERE window_started_at < :cutoff', [
            'cutoff' => time() - $olderThanSeconds,
        ]);
    }
}
