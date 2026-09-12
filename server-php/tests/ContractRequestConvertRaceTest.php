<?php

declare(strict_types=1);

/**
 * convert() must serialize two simultaneous conversions of one approved
 * request into a single contract and a clean refusal — not two contracts
 * that both carry the same request_id while `converted_contract_id` can
 * only ever remember one of them.
 *
 * The race is between "read the status" and "write converted", so it can
 * only be produced by two callers actually running at once. This spawns two
 * real OS processes (see ConvertRaceWorker.php) against the same request,
 * synchronised on a file barrier so neither wins just by starting first.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractRequestService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$service   = new ContractRequestService($pdo);
$requester = t_context(uuid: 'REQUESTER');
$reviewer  = t_context(uuid: 'REVIEWER');

$request = $service->create($requester, [
    'title'             => 'Northwind cloud hosting',
    'purpose'           => 'Replace the expiring hosting contract before it lapses.',
    'counterparty_name' => 'Northwind Cloud Ltd',
    'estimated_value'   => '250000.00',
    'currency'          => 'USD',
    'required_by_date'  => '2026-11-30',
]);
$requestId = (int) $request['id'];

$service->submit($requester, $requestId);
$service->decide($reviewer, $requestId, 'review', []);
$service->decide($reviewer, $requestId, 'approve', ['notes' => 'Budget confirmed with finance.']);

$tmp = sys_get_temp_dir() . '/contracts_convert_race_' . getmypid() . '_' . bin2hex(random_bytes(4));
mkdir($tmp);
$readyA = $tmp . '/ready_a';
$readyB = $tmp . '/ready_b';
$go     = $tmp . '/go';

$worker = __DIR__ . '/ConvertRaceWorker.php';
$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

$procA = proc_open([PHP_BINARY, $worker, (string) $requestId, $readyA, $go], $descriptors, $pipesA);
$procB = proc_open([PHP_BINARY, $worker, (string) $requestId, $readyB, $go], $descriptors, $pipesB);

if (! is_resource($procA) || ! is_resource($procB)) {
    t_fail('spawn conversion workers', 'proc_open failed to start one of the two worker processes');
}
fclose($pipesA[0]);
fclose($pipesB[0]);

// Wait for both workers to be parked on the barrier before firing the
// starting gun, so the race is tight rather than a matter of which process
// the OS happened to schedule first.
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

// This is the assertion the whole file exists for: two processes converting
// the same approved request at once must not both succeed. Pre-fix, they do
// — reproducibly, because the "already converted" check happens before
// either side writes anything.
assert_same(
    1,
    count($successes),
    'exactly one of two simultaneous conversions succeeds (got ' . json_encode($outcomes) . ')'
);
assert_same(
    1,
    count($failures),
    'the other is refused rather than also creating a contract (got ' . json_encode($outcomes) . ')'
);
assert_same(
    'REQUEST_ALREADY_CONVERTED',
    $failures[0]['error_code'] ?? null,
    'the refused side is told the request is already converted, not left with an unrelated error'
);

assert_same(
    1,
    (int) $pdo->query("SELECT COUNT(*) FROM contracts WHERE request_id = {$requestId}")->fetchColumn(),
    'the race leaves exactly one contract pointing at the request, not two'
);

$reloadedRequest = $service->findOrFail($reviewer, $requestId);
assert_same('converted', (string) $reloadedRequest['status'], 'the request ends up converted');
assert_same(
    (int) $successes[0]['contract_id'],
    (int) $reloadedRequest['converted_contract_id'],
    'the request points at the contract that actually won the race, not an orphan'
);

t_done('ContractRequestConvertRaceTest');
