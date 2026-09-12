<?php

declare(strict_types=1);

/**
 * The Contracts automation entry point.
 *
 *   php database/cron.php <task> [--env=production] [--company=123] [--dry-run]
 *
 * Tasks:
 *   daily        expiry, notice deadlines, obligations, renewals, approvals   (run nightly)
 *   expiry       contracts approaching expiry and their cancellation windows
 *   obligations  obligation and milestone due/overdue status plus reminders
 *   renewals     renewal cycles becoming due for a decision
 *   approvals    approval steps past their due date
 *   jobs         drain the background job queue                              (run often)
 *   ai           drain the AI job queue and reap crashed workers             (run often)
 *   cleanup      expire stale upload sessions, purge rate-limit windows      (run nightly)
 *
 * Every task is idempotent. A double-scheduled cron, a manual re-run, or a
 * retry after a timeout does not produce a second round of notifications:
 * status transitions are conditional on the current state, and each notification
 * carries a dedupe key that the notifications table has a unique index on.
 *
 * The run itself is also recorded in contract_job_runs against a run key, so an
 * operator can see when each task last ran and whether it was clean.
 *
 * See docs/CRON_AND_JOBS.md for the cPanel cron lines.
 */

$root = dirname(__DIR__);

$vendorAutoload = $root . '/vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
} else {
    require_once $root . '/app/Core/Autoloader.php';
    \App\Core\Autoloader::register($root . '/app');
}

use App\Core\Database;
use App\Core\Env;
use App\Services\Automation\ApprovalSweep;
use App\Services\Automation\ExpirySweep;
use App\Services\Automation\ObligationSweep;
use App\Services\Automation\RenewalSweep;
use App\Services\Automation\SweepContext;
use App\Services\JobQueue;
use App\Services\RateLimiter;
use App\Support\Environment;

// Refuse to run over HTTP. Without this the whole automation surface is
// reachable at https://contracts.aicountly.com/api/database/cron.php by anyone
// who guesses the path.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

Env::setApiRoot($root);

$args    = array_slice($argv, 1);
$task    = $args[0] ?? '';
$dryRun  = in_array('--dry-run', $args, true);
$cmpId   = null;
$envName = Environment::resolve();

foreach ($args as $arg) {
    if (str_starts_with($arg, '--company=')) {
        $value = substr($arg, strlen('--company='));
        $cmpId = ctype_digit($value) ? (int) $value : null;
    }
    if (str_starts_with($arg, '--env=')) {
        $value = strtolower(substr($arg, strlen('--env=')));
        if (in_array($value, ['production', 'sandbox'], true)) {
            $envName = $value;
        }
    }
}

$pdo = Database::pdo();
if ($pdo === null) {
    fwrite(STDERR, "Cannot connect to the database.\n" . Database::unavailableMessage() . "\n");
    exit(1);
}

$tasks = [
    'daily'       => ['expiry', 'obligations', 'renewals', 'approvals'],
    'expiry'      => ['expiry'],
    'obligations' => ['obligations'],
    'renewals'    => ['renewals'],
    'approvals'   => ['approvals'],
    'jobs'        => ['jobs'],
    'ai'          => ['ai'],
    'cleanup'     => ['cleanup'],
];

if (! isset($tasks[$task])) {
    fwrite(STDERR, "Usage: php database/cron.php <" . implode('|', array_keys($tasks)) . "> [--env=] [--company=] [--dry-run]\n");
    exit(1);
}

$startedAt = microtime(true);
$exitCode  = 0;

foreach ($tasks[$task] as $step) {
    // The run key makes a task at-most-once per day per environment/company.
    // A cron accidentally scheduled twice becomes a no-op rather than a second
    // round of emails; the drain tasks use a finer key because they are meant
    // to run many times a day.
    $runKey = in_array($step, ['jobs', 'ai'], true)
        ? $step . ':' . date('Y-m-d-H-i') . ':' . ($cmpId ?? 'all')
        : $step . ':' . date('Y-m-d') . ':' . ($cmpId ?? 'all');

    $queue = new JobQueue($pdo);
    $runId = $dryRun ? null : $queue->beginRun($envName, $step, $runKey);

    if (! $dryRun && $runId === null) {
        echo "skip  {$step} — already ran for {$runKey}\n";
        continue;
    }

    $ctx = new SweepContext($pdo, $envName, $cmpId, $dryRun);

    try {
        match ($step) {
            'expiry'      => ExpirySweep::run($ctx),
            'obligations' => ObligationSweep::run($ctx),
            'renewals'    => RenewalSweep::run($ctx),
            'approvals'   => ApprovalSweep::run($ctx),
            'jobs'        => drain_jobs($ctx, $queue),
            'ai'          => drain_ai_jobs($ctx),
            'cleanup'     => cleanup($ctx),
        };
    } catch (Throwable $e) {
        $ctx->noteError($step, $e->getMessage());
    }

    if ($runId !== null) {
        $queue->finishRun($runId, [
            'processed' => $ctx->processed,
            'notified'  => $ctx->notified,
            'errors'    => $ctx->errors,
            'detail'    => $ctx->detail,
        ]);
    }

    printf(
        "%-12s processed=%-5d notified=%-5d errors=%-3d %s\n",
        $step,
        $ctx->processed,
        $ctx->notified,
        $ctx->errors,
        $ctx->detail === [] ? '' : json_encode($ctx->detail, JSON_UNESCAPED_SLASHES)
    );

    if ($ctx->errors > 0) {
        // A non-zero exit is what makes cron send its failure mail. Reporting
        // success after a partial failure means nobody finds out.
        $exitCode = 1;
    }
}

printf("done in %.2fs\n", microtime(true) - $startedAt);
exit($exitCode);

/** Drain the general background queue. */
function drain_jobs(SweepContext $ctx, JobQueue $queue): void
{
    $workerId = gethostname() . ':' . getmypid();
    $batch    = Env::int('CONTRACTS_JOB_BATCH', 25);

    $ctx->detail['reaped'] = $queue->reapStale($ctx->environment, Env::int('CONTRACTS_JOB_STALE_SECONDS', 900));

    foreach ($queue->claim($ctx->environment, 'default', $batch, $workerId) as $job) {
        $ctx->processed++;
        try {
            \App\Services\JobRunner::handle($ctx->pdo, $job);
            $queue->succeed((int) $job['id']);
        } catch (Throwable $e) {
            $queue->fail((int) $job['id'], $e->getMessage());
            $ctx->noteError('jobs', 'job ' . $job['id'] . ': ' . $e->getMessage());
        }
    }
}

/** Drain the AI queue, which is separate because its work costs money per call. */
function drain_ai_jobs(SweepContext $ctx): void
{
    if (! class_exists(\App\Services\AiJobService::class)) {
        $ctx->detail['skipped'] = 'AI job service is not installed.';

        return;
    }

    $service  = new \App\Services\AiJobService($ctx->pdo);
    $workerId = gethostname() . ':' . getmypid();

    $ctx->detail['reaped'] = $service->reapStale($ctx->environment, Env::int('CONTRACTS_JOB_STALE_SECONDS', 900));

    foreach ($service->claim($ctx->environment, Env::int('CONTRACTS_AI_BATCH', 5), $workerId) as $job) {
        $ctx->processed++;
        try {
            $service->process((int) $job['id']);
        } catch (Throwable $e) {
            $service->fail((int) $job['id'], 'PROCESSING_FAILED', $e->getMessage());
            $ctx->noteError('ai', 'job ' . $job['id'] . ': ' . $e->getMessage());
        }
    }
}

/** Housekeeping that keeps tables from growing without bound. */
function cleanup(SweepContext $ctx): void
{
    $expired = $ctx->pdo->prepare(
        "UPDATE contract_upload_sessions
         SET status = 'expired'
         WHERE environment = ? AND status = 'pending' AND expires_at < CURRENT_TIMESTAMP"
    );
    $expired->execute([$ctx->environment]);
    $ctx->detail['upload_sessions_expired'] = $expired->rowCount();
    $ctx->processed += $expired->rowCount();

    $ctx->detail['rate_limit_windows_purged'] = RateLimiter::purgeExpired();

    // Recently-viewed is a convenience list; keeping a year of it per user is
    // just table growth.
    $views = $ctx->pdo->prepare(
        'DELETE FROM contract_recent_views WHERE viewed_at < CURRENT_TIMESTAMP - INTERVAL \'90 days\''
    );
    $views->execute();
    $ctx->detail['recent_views_pruned'] = $views->rowCount();
}
