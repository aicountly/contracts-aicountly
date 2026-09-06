<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Throwable;

/**
 * Fixed-window rate limiting, counted in PostgreSQL.
 *
 * Not APCu: PHP-FPM runs many workers and APCu is per-worker, so an in-process
 * counter would let a caller multiply their budget by the pool size. The AI
 * endpoints are the ones this actually protects — every call there costs real
 * money against the company's provider quota.
 *
 * Fails open. A rate limiter that returns 429 because the database blinked is
 * a worse outage than the abuse it prevents, and every endpoint behind this is
 * already authenticated and tenant-scoped.
 */
final class RateLimiter
{
    /** @return array{allowed: bool, remaining: int, retry_after: int} */
    public static function hit(string $key, int $limit, int $windowSeconds): array
    {
        $pdo = Database::pdo();
        if ($pdo === null || $limit < 1 || $windowSeconds < 1) {
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        // Bucket key is hashed: it contains a user uuid and a route, and this
        // table is one an operator may well read while debugging.
        $bucket = substr(hash('sha256', $key), 0, 64) . ':' . $windowSeconds;

        try {
            // One statement, so two concurrent requests cannot both read 4 and
            // both write 5. The window resets inside the UPDATE rather than in
            // a separate DELETE sweep, which keeps it correct without a reaper.
            $st = $pdo->prepare(
                'INSERT INTO contract_rate_limits (bucket_key, window_start, hits, updated_at)
                 VALUES (:k, CURRENT_TIMESTAMP, 1, CURRENT_TIMESTAMP)
                 ON CONFLICT (bucket_key) DO UPDATE
                 SET hits = CASE
                         WHEN contract_rate_limits.window_start < CURRENT_TIMESTAMP - make_interval(secs => :w)
                         THEN 1
                         ELSE contract_rate_limits.hits + 1
                     END,
                     window_start = CASE
                         WHEN contract_rate_limits.window_start < CURRENT_TIMESTAMP - make_interval(secs => :w2)
                         THEN CURRENT_TIMESTAMP
                         ELSE contract_rate_limits.window_start
                     END,
                     updated_at = CURRENT_TIMESTAMP
                 RETURNING hits,
                     GREATEST(0, :w3 - EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - window_start))::int) AS retry_after'
            );
            $st->execute([
                'k'  => $bucket,
                'w'  => $windowSeconds,
                'w2' => $windowSeconds,
                'w3' => $windowSeconds,
            ]);
            $row = $st->fetch();
        } catch (Throwable $e) {
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        if (! is_array($row)) {
            return ['allowed' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        $hits = (int) $row['hits'];

        return [
            'allowed'     => $hits <= $limit,
            'remaining'   => max(0, $limit - $hits),
            'retry_after' => max(1, (int) $row['retry_after']),
        ];
    }

    /** Drop windows that can no longer be current. Called by the nightly cleanup cron. */
    public static function purgeExpired(int $olderThanSeconds = 86400): int
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return 0;
        }

        $st = $pdo->prepare(
            'DELETE FROM contract_rate_limits
             WHERE window_start < CURRENT_TIMESTAMP - make_interval(secs => :s)'
        );
        $st->execute(['s' => $olderThanSeconds]);

        return $st->rowCount();
    }
}
