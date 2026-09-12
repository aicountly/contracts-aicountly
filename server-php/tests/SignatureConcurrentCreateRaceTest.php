<?php

declare(strict_types=1);

/**
 * create() must serialize two simultaneous signature requests against one
 * contract into a single open envelope and a clean refusal for the other — a
 * contract can only have one document out for signature at a time, and
 * nothing downstream can say which of two open envelopes is the one the
 * company is bound by once both are sendable and signable independently.
 *
 * The race is between "read: no open request" and "write: a draft request",
 * so it can only be produced by two callers actually running at once. This
 * spawns two real OS processes (see SignatureRaceWorker.php) against the same
 * contract, synchronised on a file barrier so neither wins just by starting
 * first.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractService;
use App\Services\SignatureService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$contracts = new ContractService($pdo);
$ctx       = t_context(uuid: 'OWNER');

$contract = $contracts->create($ctx, [
    'title'  => 'Globex NDA',
    'status' => 'draft',
]);
$contractId = (int) $contract['id'];

$tmp = sys_get_temp_dir() . '/contracts_signature_race_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($tmp);
$readyA = $tmp . '/ready_a';
$readyB = $tmp . '/ready_b';
$go     = $tmp . '/go';

$worker = __DIR__ . '/SignatureRaceWorker.php';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

$procA = proc_open([PHP_BINARY, $worker, (string) $contractId, $readyA, $go], $descriptors, $pipesA);
$procB = proc_open([PHP_BINARY, $worker, (string) $contractId, $readyB, $go], $descriptors, $pipesB);

if (! is_resource($procA) || ! is_resource($procB)) {
    t_fail('spawn signature workers', 'proc_open failed to start one of the two worker processes');
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
// open a signature request on the same contract must not both succeed.
// Pre-fix, they do — reproducibly, because the open-request probe in
// create() runs before either side has written anything and nothing locks
// the contract in between.
assert_same(
    1,
    count($successes),
    'exactly one of two simultaneous signature request creates succeeds (got ' . json_encode($outcomes) . ')'
);
assert_same(
    1,
    count($failures),
    'the other is refused rather than also opening a signature request (got ' . json_encode($outcomes) . ')'
);
assert_same(
    'SIGNATURE_ALREADY_OPEN',
    $failures[0]['error_code'] ?? null,
    'the refused side is told a signature request is already in progress, not left with an unrelated error'
);

assert_same(
    1,
    (int) $pdo->query(
        "SELECT COUNT(*) FROM signature_requests WHERE contract_id = {$contractId}
         AND status IN ('draft','sent','viewed','partially_signed')"
    )->fetchColumn(),
    'the race leaves exactly one open signature request on the contract, not two'
);

$service = new SignatureService($pdo);
$open    = $service->listForContract($ctx, $contractId);
assert_count(1, $open, 'exactly one signature request row exists for the contract after the race');

t_done('SignatureConcurrentCreateRaceTest');
