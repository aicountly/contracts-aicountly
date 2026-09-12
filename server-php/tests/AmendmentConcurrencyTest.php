<?php

declare(strict_types=1);

/**
 * Two apply() calls racing on the same amendment must not both succeed. The
 * bug this pins: apply() checks the amendment's status once, before it opens
 * its transaction; the contract lock it takes inside that transaction
 * serializes two racing calls against each other, but neither re-checks the
 * amendment's own status once it holds that lock. A loser that gets there
 * second re-derives `affected_fields.from` off the contract the winner just
 * amended, so it computes from === to and overwrites the real "previous
 * position" a dispute would have read.
 *
 * A single connection cannot exercise this: apply()'s row lock
 * (contractOrFail(..., true) inside Database::transaction) blocks, and one
 * PHP process cannot have a second call genuinely in flight behind it. Two
 * real OS processes, each with its own connection, held behind a third
 * connection's lock on the same row until both are queued, is the only way to
 * force the interleaving the bug depends on — see JobQueueTest for the same
 * reasoning applied to SKIP LOCKED.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Core\Env;
use App\Services\AmendmentService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx     = t_context();
$service = new AmendmentService($pdo);

$st = $pdo->prepare(
    'INSERT INTO contracts
     (environment, cmp_id, contract_number, title, status, lifecycle_stage,
      effective_date, expiry_date, notice_period_days, notice_deadline,
      currency, total_value, governing_law, auto_renewal, owner_uuid, created_by)
     VALUES (\'sandbox\', 1, \'CON-2026-900001\', \'Master services agreement\', \'active\', \'active\',
             \'2025-01-01\', \'2027-03-12\', 30, :deadline,
             \'INR\', \'1000000.00\', \'Indian law\', FALSE, \'USER-A\', \'USER-A\')
     RETURNING id'
);
$st->execute(['deadline' => \App\Support\Dates::noticeDeadline('2027-03-12', 30)]);
$contractId = (int) $st->fetchColumn();

$amendment = $service->create($ctx, $contractId, [
    'title'           => 'Price and term revision',
    'effective_date'  => '2026-10-01',
    'affected_fields' => ['total_value' => '1250000.00', 'expiry_date' => '2028-03-12'],
]);
$amendmentId = (int) $amendment['id'];
assert_same('1000000.00', $amendment['affected_fields']['total_value']['from'], 'the draft records the real original value');

// The worker: one OS process, one connection, one apply() call as its own actor.
$workerSrc = <<<'PHP'
    <?php
    declare(strict_types=1);
    require_once %BOOTSTRAP%;
    use App\Services\AmendmentService;
    $amendmentId = (int) $argv[1];
    $actor       = $argv[2];
    $pdo = t_database();
    $ctx = t_context(uuid: $actor);
    $svc = new AmendmentService($pdo);
    try {
        $r = $svc->apply($ctx, $amendmentId);
        fwrite(STDOUT, $actor . ' OK status=' . $r['status'] . ' applied_by=' . $r['applied_by']
            . ' affected=' . json_encode($r['affected_fields'], JSON_UNESCAPED_SLASHES) . "\n");
    } catch (Throwable $e) {
        fwrite(STDOUT, $actor . ' ERR ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    }
    PHP;

$workerPath = tempnam(sys_get_temp_dir(), 'amendment_race_') . '.php';
file_put_contents($workerPath, str_replace('%BOOTSTRAP%', var_export(__DIR__ . '/bootstrap.php', true), $workerSrc));
register_shutdown_function(static function () use ($workerPath): void {
    if (is_file($workerPath)) {
        unlink($workerPath);
    }
});

// A third, independent connection takes the same row lock apply() itself
// takes first. Holding it open guarantees both workers are queued behind it —
// past their own pre-transaction status read — before either is let through,
// which is exactly the interleaving that exposes the bug.
$params = Database::connectionParams();
$holder = new PDO($params['dsn'], $params['user'], Env::get('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->exec("SET TIME ZONE 'UTC'");
$holder->beginTransaction();
$holder->prepare('SELECT id FROM contracts WHERE id = ? FOR UPDATE')->execute([$contractId]);

$php  = PHP_BINARY;
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$procs = [];
foreach (['ACTOR-ONE', 'ACTOR-TWO'] as $actor) {
    $pipes = [];
    $handle = proc_open([$php, $workerPath, (string) $amendmentId, $actor], $descriptors, $pipes);
    if ($handle === false) {
        t_skip('could not spawn a worker process (proc_open unavailable)');
    }
    $procs[$actor] = ['h' => $handle, 'p' => $pipes];
    usleep(200000); // let this worker reach the row lock and start waiting on it
}

usleep(500000); // both workers are now queued behind $holder's lock
$holder->rollBack(); // release it — one worker proceeds, then the other

$outputs = [];
foreach ($procs as $actor => $proc) {
    $outputs[$actor] = trim((string) stream_get_contents($proc['p'][1]));
    $err = trim((string) stream_get_contents($proc['p'][2]));
    if ($err !== '') {
        $outputs[$actor] .= ' | stderr: ' . $err;
    }
    fclose($proc['p'][1]);
    fclose($proc['p'][2]);
    proc_close($proc['h']);
}

$oneOk = str_starts_with($outputs['ACTOR-ONE'], 'ACTOR-ONE OK');
$twoOk = str_starts_with($outputs['ACTOR-TWO'], 'ACTOR-TWO OK');

assert_true(
    $oneOk !== $twoOk,
    'exactly one of two racing apply() calls succeeds, the other is refused: ' . json_encode($outputs)
);

$loserOutput = $oneOk ? $outputs['ACTOR-TWO'] : $outputs['ACTOR-ONE'];
assert_contains('already been applied', $loserOutput, 'the loser is refused as an already-applied amendment, not a generic error');

$row = $pdo->query(
    "SELECT status, affected_fields FROM contract_amendments WHERE id = {$amendmentId}"
)->fetch();
assert_same('executed', $row['status'], 'the amendment ends up executed exactly once');

$affected = json_decode((string) $row['affected_fields'], true);
assert_same('1000000.00', $affected['total_value']['from'], "the winner's affected_fields keeps the real original value");
assert_same('1250000.00', $affected['total_value']['to'], 'alongside the value it was amended to');
assert_true(
    $affected['total_value']['from'] !== $affected['total_value']['to'],
    'a race must never leave affected_fields claiming a field changed to itself'
);

$auditRows = $pdo->query(
    "SELECT COUNT(*) FROM contract_audit_logs
     WHERE contract_id = {$contractId} AND action = 'contract.amended' AND field_name = 'total_value'"
)->fetchColumn();
assert_same(1, (int) $auditRows, 'the race produces one audit row per field, not one per racer');

t_done('AmendmentConcurrencyTest');
