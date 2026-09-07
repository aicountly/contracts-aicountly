<?php

declare(strict_types=1);

/**
 * The background queue's four guarantees: the same work enqueued twice is one
 * job, a job is served to exactly one worker, a failing job backs off and then
 * dies rather than retrying forever, and a cron task that already ran today
 * refuses to run again.
 *
 * The concurrency assertion uses a second real connection. A mocked PDO cannot
 * demonstrate SKIP LOCKED — the behaviour under test is PostgreSQL's, not this
 * class's, and the only thing worth asserting is that the statement asks for it
 * correctly.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\JobQueue;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$queue = new JobQueue($pdo);
$env   = 'sandbox';

/** A second connection, so a row lock taken on one is visible to the other. */
$secondConnection = static function (): ?PDO {
    $params = Database::connectionParams();
    if ($params['user'] === '') {
        return null;
    }

    try {
        return new PDO($params['dsn'], $params['user'], Env::get('DB_PASS'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (Throwable $e) {
        return null;
    }
};

$statusOf = static fn (int $id): array => (array) $pdo
    ->query("SELECT status, attempts, max_attempts, error_message, locked_by,
                    available_at, available_at > CURRENT_TIMESTAMP AS deferred
             FROM contract_jobs WHERE id = {$id}")
    ->fetch();

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

$first  = $queue->push($env, 1, 'contract.reindex', ['contract_id' => 7], ['idempotency_key' => 'reindex:7']);
$second = $queue->push($env, 1, 'contract.reindex', ['contract_id' => 7], ['idempotency_key' => 'reindex:7']);

assert_same($first, $second, 'the same idempotency key returns the id of the job already queued');
assert_same(1, (int) $pdo->query("SELECT COUNT(*) FROM contract_jobs WHERE idempotency_key = 'reindex:7'")->fetchColumn(),
    'the same idempotency key writes one row, not two');

$unkeyed1 = $queue->push($env, 1, 'contract.reindex', ['contract_id' => 8]);
$unkeyed2 = $queue->push($env, 1, 'contract.reindex', ['contract_id' => 8]);
assert_true($unkeyed1 !== $unkeyed2, 'without an idempotency key, two pushes are two jobs');

// The key is scoped to the environment, so production and sandbox cannot
// collide on a database that holds both.
$otherEnv = $queue->push('production', 1, 'contract.reindex', [], ['idempotency_key' => 'reindex:7']);
assert_true($otherEnv !== $first, 'the same key in another environment is a different job');

// ---------------------------------------------------------------------------
// The payload survives the round trip
// ---------------------------------------------------------------------------

$claimed = $queue->claim($env, 'default', 10, 'worker-1');
assert_count(3, $claimed, 'claim takes every ready job in the queue');

$reindex = null;
foreach ($claimed as $job) {
    if ((int) $job['id'] === $first) {
        $reindex = $job;
    }
}
assert_not_null($reindex, 'the keyed job was among those claimed');
assert_same(7, $reindex['payload']['contract_id'], 'the JSONB payload comes back decoded');
assert_same(1, $reindex['attempts'], 'claiming a job counts an attempt');
assert_same('running', $reindex['status'], 'a claimed job is running');
assert_same('worker-1', $reindex['locked_by'], 'the claiming worker is recorded');

// A second drain finds nothing: every ready job is already held.
assert_count(0, $queue->claim($env, 'default', 10, 'worker-2'), 'a claimed job is not offered to a second worker');

foreach ($claimed as $job) {
    $queue->succeed((int) $job['id']);
}
assert_same('succeeded', $statusOf($first)['status'], 'succeed() closes the job');

// A finished job releases its idempotency key: the same recurring work may be
// scheduled again tomorrow.
$again = $queue->push($env, 1, 'contract.reindex', ['contract_id' => 7], ['idempotency_key' => 'reindex:7']);
assert_true($again !== $first, 'the key is free again once the job it named has finished');
$queue->claim($env, 'default', 10, 'worker-1');
$queue->succeed($again);

// ---------------------------------------------------------------------------
// Queues and priority
// ---------------------------------------------------------------------------

$lowPriority  = $queue->push($env, 1, 'report.export', [], ['priority' => 200]);
$highPriority = $queue->push($env, 1, 'notification.digest', [], ['priority' => 10]);
$otherQueue   = $queue->push($env, 1, 'ai.warm', [], ['queue' => 'ai']);

$batch = $queue->claim($env, 'default', 1, 'worker-1');
assert_count(1, $batch, 'claim honours its limit');
assert_same($highPriority, (int) $batch[0]['id'], 'the lower priority number is served first');

assert_count(1, $queue->claim($env, 'ai', 10, 'worker-1'), 'a named queue serves only its own jobs');
assert_count(1, $queue->claim($env, 'default', 10, 'worker-1'), 'the other queue still holds the remaining default job');

$queue->succeed($highPriority);
$queue->succeed($lowPriority);
$queue->succeed($otherQueue);

// A job scheduled for later is not ready now.
$deferred = $queue->push($env, 1, 'renewal.remind', [], ['available_at' => 3600]);
assert_count(0, $queue->claim($env, 'default', 10, 'worker-1'), 'a job with a future available_at is not claimed');
assert_true($statusOf($deferred)['status'] === 'queued', 'the deferred job is still queued');

// ---------------------------------------------------------------------------
// SKIP LOCKED — a locked row is stepped over, not waited on
// ---------------------------------------------------------------------------

$other = $secondConnection();
if ($other === null) {
    TestState::$notes[] = 'skipped the SKIP LOCKED assertion: no second connection';
} else {
    $lockTarget = $queue->push($env, 1, 'contract.reindex', ['contract_id' => 99]);
    $freeTarget = $queue->push($env, 1, 'contract.reindex', ['contract_id' => 100]);

    // Hold a row lock on one job from the other session, exactly as a worker
    // mid-claim would.
    $other->beginTransaction();
    $other->query("SELECT id FROM contract_jobs WHERE id = {$lockTarget} FOR UPDATE")->fetchAll();

    $withLockHeld = $queue->claim($env, 'default', 10, 'worker-2');
    $ids          = array_map(static fn (array $j): int => (int) $j['id'], $withLockHeld);

    assert_false(in_array($lockTarget, $ids, true), 'a row another worker holds is skipped, not double-claimed');
    assert_true(in_array($freeTarget, $ids, true), 'the unlocked job beside it is still served');

    $other->rollBack();

    $afterRelease = $queue->claim($env, 'default', 10, 'worker-2');
    assert_same([$lockTarget], array_map(static fn (array $j): int => (int) $j['id'], $afterRelease),
        'once the lock is released the skipped job is claimable again');

    $queue->succeed($lockTarget);
    $queue->succeed($freeTarget);
}

// ---------------------------------------------------------------------------
// Backoff and dead-lettering
// ---------------------------------------------------------------------------

assert_same(60, JobQueue::backoffSeconds(1), 'the first retry waits 60 seconds');
assert_same(120, JobQueue::backoffSeconds(2), 'the second waits twice as long');
assert_same(3600, JobQueue::backoffSeconds(12), 'the backoff is capped at an hour');

$flaky = $queue->push($env, 1, 'drive.sync', [], ['max_attempts' => 3]);

$queue->claim($env, 'default', 10, 'worker-1');
$queue->fail($flaky, 'Drive returned 503');

$afterFirstFailure = $statusOf($flaky);
assert_same('queued', $afterFirstFailure['status'], 'a job with attempts left goes back on the queue');
assert_same(1, (int) $afterFirstFailure['attempts'], 'the spent attempt is counted');
assert_same('Drive returned 503', $afterFirstFailure['error_message'], 'the failure reason is kept');
assert_true(\App\Services\ContractService::toBool($afterFirstFailure['deferred']),
    'the retry is deferred rather than immediately claimable');
assert_null($afterFirstFailure['locked_by'], 'a failed job releases its worker lock');

assert_count(0, $queue->claim($env, 'default', 10, 'worker-1'), 'the backoff keeps the retry out of the next drain');

// Failing an already-failed job must not spend a second attempt: cron catches
// what the service has already reported.
$queue->fail($flaky, 'Drive returned 503 again');
assert_same(1, (int) $statusOf($flaky)['attempts'], 'failing a job that is not running is a no-op');

// Bring the retry forward and spend the remaining attempts.
$pdo->exec("UPDATE contract_jobs SET available_at = CURRENT_TIMESTAMP WHERE id = {$flaky}");
$queue->claim($env, 'default', 10, 'worker-1');
$queue->fail($flaky, 'Drive returned 503');
assert_same('queued', $statusOf($flaky)['status'], 'the second failure still has an attempt left');

$pdo->exec("UPDATE contract_jobs SET available_at = CURRENT_TIMESTAMP WHERE id = {$flaky}");
$queue->claim($env, 'default', 10, 'worker-1');
$queue->fail($flaky, 'Drive is still down');

$dead = $statusOf($flaky);
assert_same('dead', $dead['status'], 'a job that has spent every attempt is dead, not queued');
assert_same(3, (int) $dead['attempts'], 'all three attempts were used');
assert_count(0, $queue->claim($env, 'default', 10, 'worker-1'), 'a dead job is never served again');

// ---------------------------------------------------------------------------
// Reaping a crashed worker
// ---------------------------------------------------------------------------

$crashed  = $queue->push($env, 1, 'document.extract', [], ['max_attempts' => 3]);
$exhausted = $queue->push($env, 1, 'document.extract', [], ['max_attempts' => 1]);
$queue->claim($env, 'default', 10, 'worker-doomed');

// The worker died: the lock is still held and nothing else will ever happen to
// these rows.
$pdo->exec("UPDATE contract_jobs SET locked_at = CURRENT_TIMESTAMP - INTERVAL '1 hour'
            WHERE id IN ({$crashed}, {$exhausted})");

assert_same(2, $queue->reapStale($env, 900), 'both stranded jobs are reaped');
assert_same('queued', $statusOf($crashed)['status'], 'a stranded job with attempts left goes back on the queue');
assert_same('dead', $statusOf($exhausted)['status'], 'a stranded job with no attempts left is dead rather than pending');
assert_same(0, $queue->reapStale($env, 900), 'reaping again finds nothing');

// ---------------------------------------------------------------------------
// Statistics
// ---------------------------------------------------------------------------

$stats = $queue->stats($env);
assert_same(2, $stats['dead'], 'stats counts the dead letters');
assert_true($stats['ready'] >= 1, 'stats counts the jobs ready to run');
assert_true(is_array($stats['by_status']), 'stats reports depth by status');
assert_same(0, $stats['by_status']['running'], 'nothing is running once every worker has reported');

// ---------------------------------------------------------------------------
// Cron run keys — a double-scheduled sweep is a no-op
// ---------------------------------------------------------------------------

$runKey = 'expiry:2026-03-01:all';

$runId = $queue->beginRun($env, 'expiry', $runKey);
assert_not_null($runId, 'the first claim of a run key opens a run');

assert_null($queue->beginRun($env, 'expiry', $runKey), 'the same run key a second time is refused');
assert_not_null($queue->beginRun($env, 'expiry', 'expiry:2026-03-02:all'), 'the next day is a new run key');
assert_not_null($queue->beginRun($env, 'obligations', $runKey), 'the same key under another task is a different run');
assert_not_null($queue->beginRun('production', 'expiry', $runKey), 'the same key in another environment is a different run');

$queue->finishRun((int) $runId, [
    'processed' => 12,
    'notified'  => 4,
    'errors'    => 1,
    'detail'    => ['expired' => ['CON-2026-000001']],
]);

$run = $pdo->query("SELECT processed, notified, errors, detail, finished_at FROM contract_job_runs WHERE id = {$runId}")->fetch();
assert_same(12, (int) $run['processed'], 'finishRun records what the sweep processed');
assert_same(4, (int) $run['notified'], 'finishRun records what the sweep notified');
assert_same(1, (int) $run['errors'], 'finishRun records the error count');
assert_not_null($run['finished_at'], 'finishRun closes the run');
assert_contains('CON-2026-000001', (string) $run['detail'], 'the detail payload is kept');

$recent = $queue->recentRuns($env, 10);
assert_true(count($recent) >= 3, 'recentRuns lists this environment\'s runs');

t_done('JobQueueTest');
