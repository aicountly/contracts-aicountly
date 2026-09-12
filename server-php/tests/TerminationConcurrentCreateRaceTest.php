<?php

declare(strict_types=1);

/**
 * create() must serialize two simultaneous terminations of one contract into
 * a single open termination and a clean refusal for the other — a contract
 * can only be ending once, and nothing downstream (approval, notice,
 * settlement) can tell which of two open rows is the real one.
 *
 * The race is between "read: no open termination" and "write: a draft
 * termination", so it can only be produced by two callers actually running
 * at once. This spawns two real OS processes (see TerminationRaceWorker.php)
 * against the same contract, synchronised on a file barrier so neither wins
 * just by starting first.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractService;
use App\Services\TerminationService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$contracts = new ContractService($pdo);
$ctx       = t_context(uuid: 'OWNER');

$contract = $contracts->create($ctx, [
    'title'  => 'Northwind master services agreement',
    'status' => 'active',
]);
$contractId = (int) $contract['id'];

$tmp = sys_get_temp_dir() . '/contracts_termination_race_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($tmp);
$readyA = $tmp . '/ready_a';
$readyB = $tmp . '/ready_b';
$go     = $tmp . '/go';

$worker = __DIR__ . '/TerminationRaceWorker.php';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

$procA = proc_open([PHP_BINARY, $worker, (string) $contractId, $readyA, $go], $descriptors, $pipesA);
$procB = proc_open([PHP_BINARY, $worker, (string) $contractId, $readyB, $go], $descriptors, $pipesB);

if (! is_resource($procA) || ! is_resource($procB)) {
    t_fail('spawn termination workers', 'proc_open failed to start one of the two worker processes');
}
fclose($pipesA[0]);
fclose($pipesB[0]);

$deadline = microtime(true) + 5.0;
while (! (file_exists($readyA) && file_exists($readyB))) {
    if (microtime(true) > $deadline) {
        t_fail('workers reached the barrier', 'timed out waiting for both worker processes to signal ready');
    }
    usleep(1000);
}
touch($go);

$outA = stream_get_contents($pipesA[1]);
$errA = stream_get_contents($pipesA[2]);
fclose($pipesA[1]);
fclose($pipesA[2]);
$codeA = proc_close($procA);

$outB = stream_get_contents($pipesB[1]);
$errB = stream_get_contents($pipesB[2]);
fclose($pipesB[1]);
fclose($pipesB[2]);
$codeB = proc_close($procB);

foreach ([$readyA, $readyB, $go] as $f) {
    if (file_exists($f)) {
        unlink($f);
    }
}
rmdir($tmp);

assert_same(0, $codeA, 'worker A exits cleanly (stderr: ' . trim($errA) . ')');
assert_same(0, $codeB, 'worker B exits cleanly (stderr: ' . trim($errB) . ')');

$resultA = json_decode(trim($outA), true);
$resultB = json_decode(trim($outB), true);

assert_true(is_array($resultA), 'worker A produced a JSON result (got: ' . $outA . ' / stderr: ' . $errA . ')');
assert_true(is_array($resultB), 'worker B produced a JSON result (got: ' . $outB . ' / stderr: ' . $errB . ')');

$outcomes  = [$resultA, $resultB];
$successes = array_values(array_filter($outcomes, static fn (array $r): bool => $r['ok'] === true));
$failures  = array_values(array_filter($outcomes, static fn (array $r): bool => $r['ok'] === false));

// This is the assertion the whole file exists for: two processes racing to
// open a termination on the same contract must not both succeed. Pre-fix,
// they do — reproducibly, because openTerminationId() runs before either
// side has written anything and nothing locks the contract in between.
assert_same(
    1,
    count($successes),
    'exactly one of two simultaneous termination creates succeeds (got ' . json_encode($outcomes) . ')'
);
assert_same(
    1,
    count($failures),
    'the other is refused rather than also opening a termination (got ' . json_encode($outcomes) . ')'
);
assert_same(
    'TERMINATION_IN_PROGRESS',
    $failures[0]['error_code'] ?? null,
    'the refused side is told a termination is already in progress, not left with an unrelated error'
);

assert_same(
    1,
    (int) $pdo->query(
        "SELECT COUNT(*) FROM contract_terminations WHERE contract_id = {$contractId}
         AND status IN ('draft','pending_approval','approved','notice_issued')"
    )->fetchColumn(),
    'the race leaves exactly one open termination on the contract, not two'
);

$service = new TerminationService($pdo);
$open    = $service->listForContract($ctx, $contractId);
assert_count(1, $open, 'exactly one termination row exists for the contract after the race');

t_done('TerminationConcurrentCreateRaceTest');
