<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Enums;
use PDO;

/**
 * The general background queue, and the ledger of cron runs.
 *
 * PostgreSQL-backed rather than Redis or SQS: the fleet deploys onto cPanel
 * with cron, a broker would be infrastructure nobody asked for, and
 * `FOR UPDATE SKIP LOCKED` gives correct concurrent dequeue at this volume.
 * The AI queue (`ai_jobs`, AiJobService) is deliberately separate — its work
 * costs money per attempt and is drained on its own schedule with its own
 * batch size.
 *
 * Nothing here takes a TenantContext. A worker acts on jobs across every
 * company with no signed-in user; the job row carries its own environment and
 * cmp_id, and the only callers are the cron drain and the services that
 * enqueue.
 *
 * @audit-unscoped Worker path: jobs are claimed and completed by id across
 *                 every tenant. The row carries environment and cmp_id, and no
 *                 request handler reaches these methods.
 */
final class JobQueue
{
    public const DEFAULT_QUEUE = 'default';

    /** Highest number of jobs one claim() may take, whatever a caller asks for. */
    private const MAX_BATCH = 100;

    private const BACKOFF_BASE_SECONDS = 30;

    private const BACKOFF_CAP_SECONDS = 3600;

    public function __construct(private PDO $pdo)
    {
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Producing
    // -----------------------------------------------------------------------

    /**
     * Put work on the queue and return the job id.
     *
     * With `idempotency_key` in $opts the same work enqueued twice is one job:
     * the id of the job already waiting comes back instead of a second row.
     * That is what makes an enqueue safe to repeat from a retried request or a
     * sweep that runs twice, and it is scoped to jobs still `queued` or
     * `running` — once a job has finished, the same key may legitimately
     * describe the next occurrence of the same recurring work.
     *
     * @param array<string,mixed> $payload
     * @param array{queue?: string, priority?: int, available_at?: string|int,
     *              max_attempts?: int, idempotency_key?: string|null} $opts
     *              `available_at` is either a `Y-m-d H:i:s` UTC timestamp or a
     *              delay in seconds from now.
     */
    public function push(string $environment, ?int $cmpId, string $type, array $payload = [], array $opts = []): int
    {
        $queue    = self::cleanQueue($opts['queue'] ?? self::DEFAULT_QUEUE);
        $priority = max(0, min(32000, (int) ($opts['priority'] ?? 100)));
        $attempts = max(1, min(32, (int) ($opts['max_attempts'] ?? 5)));
        $key      = isset($opts['idempotency_key']) && is_string($opts['idempotency_key']) && $opts['idempotency_key'] !== ''
            ? mb_substr($opts['idempotency_key'], 0, 160)
            : null;

        $params = [
            'env'      => $environment,
            'cmp'      => $cmpId,
            'queue'    => $queue,
            'type'     => mb_substr($type, 0, 64),
            'payload'  => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
            'priority' => $priority,
            'attempts' => $attempts,
            'key'      => $key,
            'at'       => self::availableAt($opts['available_at'] ?? null),
        ];

        // The arbiter is the partial unique index uq_jobs_idempotency, so its
        // predicate has to be repeated here for PostgreSQL to infer it.
        $insert = $this->pdo->prepare(
            'INSERT INTO contract_jobs
             (environment, cmp_id, queue, job_type, payload, priority, max_attempts,
              idempotency_key, available_at)
             VALUES (:env, :cmp, :queue, :type, :payload::jsonb, :priority, :attempts,
                     :key, COALESCE(:at::timestamp, CURRENT_TIMESTAMP))
             ON CONFLICT (environment, idempotency_key)
                 WHERE idempotency_key IS NOT NULL AND status IN (\'queued\', \'running\')
                 DO NOTHING
             RETURNING id'
        );

        // DO NOTHING returns no row, so on a conflict the id of the job already
        // holding the key has to be read back — a caller that got an id for its
        // first push and nothing for its second would have no way to poll the
        // work it asked for. The loop covers the narrow case where that job
        // finishes between the INSERT and the read, which frees the key and
        // means the work still needs doing; two passes is enough, and the third
        // gives up on the key rather than spinning.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $insert->execute($params);
            $id = $insert->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }

            $existing = $this->pdo->prepare(
                'SELECT id FROM contract_jobs
                 WHERE environment = ? AND idempotency_key = ? AND status IN (\'queued\', \'running\')
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $existing->execute([$environment, $key]);
            $found = $existing->fetchColumn();

            if ($found !== false) {
                return (int) $found;
            }
        }

        $params['key'] = null;
        $insert->execute($params);

        return (int) $insert->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Consuming
    // -----------------------------------------------------------------------

    /**
     * Take up to $limit ready jobs for this worker.
     *
     * One statement, with `FOR UPDATE SKIP LOCKED`, rather than a SELECT
     * followed by an UPDATE: between those two statements a second worker reads
     * the same row and both run the job. That window is small, and a queue is
     * precisely where small windows are hit all day. SKIP LOCKED also means a
     * worker never waits behind another worker's row — it takes the next one.
     *
     * @return list<array<string,mixed>>
     */
    public function claim(string $environment, string $queue, int $limit, string $workerId): array
    {
        $st = $this->pdo->prepare(
            'UPDATE contract_jobs j
             SET status = \'running\',
                 locked_at = CURRENT_TIMESTAMP,
                 locked_by = :worker,
                 attempts = j.attempts + 1,
                 started_at = COALESCE(j.started_at, CURRENT_TIMESTAMP)
             FROM (
                 SELECT id FROM contract_jobs
                 WHERE environment = :env
                   AND queue = :queue
                   AND status = \'queued\'
                   AND available_at <= CURRENT_TIMESTAMP
                   AND attempts < max_attempts
                 ORDER BY priority, available_at, id
                 LIMIT :lim
                 FOR UPDATE SKIP LOCKED
             ) AS picked
             WHERE j.id = picked.id
             RETURNING j.*'
        );
        $st->bindValue(':worker', mb_substr($workerId, 0, 64));
        $st->bindValue(':env', $environment);
        $st->bindValue(':queue', self::cleanQueue($queue));
        $st->bindValue(':lim', max(1, min(self::MAX_BATCH, $limit)), PDO::PARAM_INT);
        $st->execute();

        return array_map(static fn (array $r): array => self::hydrate($r), $st->fetchAll() ?: []);
    }

    /** Mark a claimed job done. */
    public function succeed(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE contract_jobs
             SET status = \'succeeded\',
                 completed_at = CURRENT_TIMESTAMP,
                 locked_at = NULL,
                 locked_by = NULL,
                 error_message = NULL
             WHERE id = ? AND status = \'running\''
        )->execute([$id]);
    }

    /**
     * Record a failed attempt.
     *
     * While attempts remain the job goes back to `queued` with its next attempt
     * pushed out by an exponential backoff — an integration that is down stays
     * down for a while, and retrying it every minute turns one outage into a
     * few thousand log lines. Once the attempts are spent the job becomes
     * `dead`: a terminal state that keeps the row, its error and its payload
     * for an operator to look at, rather than a `queued` row that no worker
     * will ever pick up again and that therefore looks like pending work.
     *
     * The schema also allows `failed`; this queue does not use it. A retryable
     * failure is a return to `queued`, which is what the partial dequeue index
     * covers, and an unretryable one is `dead`.
     */
    public function fail(int $id, string $error): void
    {
        $st = $this->pdo->prepare(
            'SELECT attempts, max_attempts FROM contract_jobs WHERE id = ? AND status = \'running\''
        );
        $st->execute([$id]);
        $job = $st->fetch();

        if (! is_array($job)) {
            // Not running: either already resolved, or a caller failing a job
            // twice — cron's catch-all around a service that reported its own
            // failure. Spending a second attempt on that would halve the
            // retries the job actually gets.
            return;
        }

        $attempts = (int) $job['attempts'];
        $spent    = $attempts >= (int) $job['max_attempts'];

        $this->pdo->prepare(
            'UPDATE contract_jobs
             SET status = :status,
                 error_message = :error,
                 failed_at = CURRENT_TIMESTAMP,
                 locked_at = NULL,
                 locked_by = NULL,
                 available_at = CASE WHEN :retry THEN CURRENT_TIMESTAMP + make_interval(secs => :secs) ELSE available_at END,
                 completed_at = CASE WHEN :retry2 THEN NULL ELSE CURRENT_TIMESTAMP END
             WHERE id = :id AND status = \'running\''
        )->execute([
            'status' => $spent ? 'dead' : 'queued',
            'error'  => mb_substr($error, 0, 4000),
            'retry'  => $spent ? 'false' : 'true',
            'retry2' => $spent ? 'false' : 'true',
            'secs'   => self::backoffSeconds($attempts),
            'id'     => $id,
        ]);
    }

    /**
     * How long a job waits before its next attempt.
     *
     * Deterministic, with no jitter. Jitter spreads a thundering herd and this
     * queue never has one; a predictable delay is worth more, because it can be
     * asserted in a test and explained to an operator looking at a job that is
     * waiting.
     */
    public static function backoffSeconds(int $attempts): int
    {
        $delay = self::BACKOFF_BASE_SECONDS * (2 ** max(1, min(12, $attempts)));

        return (int) min($delay, self::BACKOFF_CAP_SECONDS);
    }

    /**
     * Return jobs whose worker died back to the queue.
     *
     * A crashed worker leaves `locked_at` set and nothing else happens to the
     * row — no exception was thrown anywhere that could have failed it — so
     * without this the job sits in `running` forever, neither done nor errored.
     *
     * @return int jobs released plus jobs given up on
     */
    public function reapStale(string $environment, int $staleSeconds): int
    {
        $staleSeconds = max(60, $staleSeconds);

        $released = $this->pdo->prepare(
            'UPDATE contract_jobs
             SET status = \'queued\',
                 locked_at = NULL,
                 locked_by = NULL,
                 available_at = CURRENT_TIMESTAMP,
                 error_message = \'The worker holding this job stopped without reporting a result.\'
             WHERE environment = :env
               AND status = \'running\'
               AND locked_at IS NOT NULL
               AND locked_at < CURRENT_TIMESTAMP - make_interval(secs => :secs)
               AND attempts < max_attempts'
        );
        $released->execute(['env' => $environment, 'secs' => $staleSeconds]);

        // A job whose attempts are spent must not go back on the queue: claim()
        // would never pick it up again and it would sit in `queued` looking
        // like work that is about to happen.
        $abandoned = $this->pdo->prepare(
            'UPDATE contract_jobs
             SET status = \'dead\',
                 locked_at = NULL,
                 locked_by = NULL,
                 failed_at = CURRENT_TIMESTAMP,
                 completed_at = CURRENT_TIMESTAMP,
                 error_message = \'The worker holding this job stopped, and no attempts remain.\'
             WHERE environment = :env
               AND status = \'running\'
               AND locked_at IS NOT NULL
               AND locked_at < CURRENT_TIMESTAMP - make_interval(secs => :secs)
               AND attempts >= max_attempts'
        );
        $abandoned->execute(['env' => $environment, 'secs' => $staleSeconds]);

        return $released->rowCount() + $abandoned->rowCount();
    }

    // -----------------------------------------------------------------------
    // Operating
    // -----------------------------------------------------------------------

    /**
     * Queue depth by status and by queue, plus the oldest waiting job.
     *
     * The age of the oldest ready job is the number that actually tells an
     * operator whether the drain cron is running: a queue can hold thousands of
     * rows and be healthy, and hold three and be broken.
     *
     * @return array{by_status: array<string,int>, by_queue: list<array<string,mixed>>,
     *               ready: int, oldest_ready_seconds: int|null, dead: int}
     */
    public function stats(string $environment): array
    {
        $byStatus = array_fill_keys(Enums::JOB_STATUSES, 0);

        $st = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n FROM contract_jobs WHERE environment = ? GROUP BY status'
        );
        $st->execute([$environment]);
        foreach ($st->fetchAll() ?: [] as $row) {
            $byStatus[(string) $row['status']] = (int) $row['n'];
        }

        $queues = $this->pdo->prepare(
            'SELECT queue,
                    COUNT(*) FILTER (WHERE status = \'queued\')  AS queued,
                    COUNT(*) FILTER (WHERE status = \'running\') AS running,
                    COUNT(*) FILTER (WHERE status = \'dead\')    AS dead
             FROM contract_jobs
             WHERE environment = ?
             GROUP BY queue
             ORDER BY queue'
        );
        $queues->execute([$environment]);

        $ready = $this->pdo->prepare(
            'SELECT COUNT(*) AS n,
                    MAX(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - available_at))) AS oldest
             FROM contract_jobs
             WHERE environment = ? AND status = \'queued\' AND available_at <= CURRENT_TIMESTAMP'
        );
        $ready->execute([$environment]);
        $readyRow = $ready->fetch() ?: ['n' => 0, 'oldest' => null];

        return [
            'by_status' => $byStatus,
            'by_queue'  => array_map(static fn (array $r): array => [
                'queue'   => (string) $r['queue'],
                'queued'  => (int) $r['queued'],
                'running' => (int) $r['running'],
                'dead'    => (int) $r['dead'],
            ], $queues->fetchAll() ?: []),
            'ready'                => (int) $readyRow['n'],
            'oldest_ready_seconds' => $readyRow['oldest'] === null ? null : (int) $readyRow['oldest'],
            'dead'                 => $byStatus['dead'],
        ];
    }

    // -----------------------------------------------------------------------
    // Cron run ledger
    // -----------------------------------------------------------------------

    /**
     * Claim a run key, or refuse.
     *
     * Returns the run id when this process is the first to claim the key, and
     * null when the key already exists. That null is the whole point: cPanel
     * cron is not exactly-once, an operator re-runs a task by hand, and a
     * scheduler occasionally fires the same minute twice. With `expiry` keyed
     * on the date, the second run of the night finds the key taken and does
     * nothing — instead of sending everyone a second copy of every warning.
     *
     * The uniqueness is the database's (`uq_job_run_key`), not a read followed
     * by a write, so two runs starting in the same second cannot both win.
     */
    public function beginRun(string $environment, string $task, string $runKey): ?int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO contract_job_runs (environment, task, run_key)
             VALUES (?, ?, ?)
             ON CONFLICT (environment, task, run_key) DO NOTHING
             RETURNING id'
        );
        $st->execute([$environment, mb_substr($task, 0, 64), mb_substr($runKey, 0, 96)]);
        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * Close a run and record what it did.
     *
     * @param array{processed?: int, notified?: int, errors?: int, detail?: array<string,mixed>} $counters
     */
    public function finishRun(int $runId, array $counters): void
    {
        $detail = $counters['detail'] ?? [];

        $this->pdo->prepare(
            'UPDATE contract_job_runs
             SET finished_at = CURRENT_TIMESTAMP,
                 processed = ?,
                 notified = ?,
                 errors = ?,
                 detail = ?::jsonb
             WHERE id = ?'
        )->execute([
            max(0, (int) ($counters['processed'] ?? 0)),
            max(0, (int) ($counters['notified'] ?? 0)),
            max(0, (int) ($counters['errors'] ?? 0)),
            json_encode(is_array($detail) ? $detail : [], JSON_UNESCAPED_SLASHES) ?: '{}',
            $runId,
        ]);
    }

    /**
     * The most recent runs of each task, for the operations screen.
     *
     * @return list<array<string,mixed>>
     */
    public function recentRuns(string $environment, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, task, run_key, started_at, finished_at, processed, notified, errors, detail
             FROM contract_job_runs
             WHERE environment = ?
             ORDER BY started_at DESC, id DESC
             LIMIT ?'
        );
        $st->execute([$environment, max(1, min(500, $limit))]);

        return array_map(static function (array $r): array {
            $r['id']        = (int) $r['id'];
            $r['processed'] = (int) $r['processed'];
            $r['notified']  = (int) $r['notified'];
            $r['errors']    = (int) $r['errors'];
            $r['detail']    = self::decodeJson($r['detail'] ?? null);

            return $r;
        }, $st->fetchAll() ?: []);
    }

    /** One job by id, for a worker that needs to re-read what it claimed. @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM contract_jobs WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * A queue name reduced to the shape the column and the index expect.
     *
     * Bound as a value everywhere, so this is not an injection guard — it stops
     * a typo or a stray space silently creating a second queue that no worker
     * is draining.
     */
    private static function cleanQueue(mixed $queue): string
    {
        $name = strtolower(trim((string) $queue));
        $name = preg_replace('/[^a-z0-9_.\-]/', '', $name) ?? '';

        return $name === '' ? self::DEFAULT_QUEUE : mb_substr($name, 0, 32);
    }

    /** A `Y-m-d H:i:s` UTC timestamp, a delay in seconds, or null for "now". */
    private static function availableAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,7}$/', $value) === 1)) {
            return date('Y-m-d H:i:s', time() + (int) $value);
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', trim($value)) === 1) {
            return str_replace('T', ' ', trim($value));
        }

        return null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        foreach (['id', 'cmp_id', 'priority', 'attempts', 'max_attempts'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['payload'] = self::decodeJson($row['payload'] ?? null);

        return $row;
    }

    /** @return array<string,mixed> */
    private static function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
