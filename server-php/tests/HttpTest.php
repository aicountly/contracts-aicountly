<?php

declare(strict_types=1);

/**
 * Http::request()'s optional response-size cap.
 *
 * The bug this pins: fetchBytes()-style callers pass a byte budget, but
 * without a way to enforce it inside the transfer, the only place left to
 * check it is after curl_exec() has already returned — by which point curl
 * (CURLOPT_RETURNTRANSFER, no CURLOPT_WRITEFUNCTION) has buffered the entire
 * response in memory regardless of size. A response larger than the caller's
 * memory_limit fatals the process instead of being refused.
 *
 * A stubbed transport (Http::setTransportForTests(), used everywhere else in
 * this suite) cannot exercise this: the stub stands in for curl itself, so it
 * never touches the buffering behaviour under test. This needs a real socket
 * and — to tell "refused cleanly" apart from "crashed the process" — a real
 * OS process with its own memory_limit, since a fatal error in-process would
 * take this test down with it rather than let it observe the failure.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Env;
use App\Core\Http;

Env::configureForTests(['ALLOW_LOOPBACK_INTEGRATIONS' => 'true']);

$port = 20000 + (getmypid() % 10000);
$docroot = __DIR__;

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/HttpBigBodyServer.php'],
    $descriptors,
    $serverPipes
);
if ($server === false) {
    t_skip('could not spawn a loopback HTTP server (proc_open unavailable)');
}
register_shutdown_function(static function () use ($server, $serverPipes): void {
    if (is_resource($server)) {
        proc_terminate($server);
        foreach ($serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($server);
    }
});

$base = "http://127.0.0.1:{$port}/";
$deadline = microtime(true) + 5.0;
$up = false;
while (microtime(true) < $deadline) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($probe !== false) {
        fclose($probe);
        $up = true;
        break;
    }
    usleep(50000);
}
if (! $up) {
    t_skip('loopback HTTP server did not come up in time');
}

// --- under the cap: bytes come back whole, in-process -----------------------

$cap    = 5 * 1024 * 1024;
$result = Http::request('GET', $base . '?mb=2', [], null, 15, 5, $cap);
assert_same(200, $result['status'], 'a response under the cap is a normal 200');
assert_same(2 * 1024 * 1024, strlen($result['body']), 'and the body arrives whole');

// --- over the cap: aborted mid-transfer, not buffered then discarded --------
//
// Run out-of-process under a memory_limit well below the 40MB body so that
// the pre-fix behaviour (buffer the whole thing, check the length after) is
// forced to fatal instead of merely being slow. That is what makes this a
// pinning test rather than a timing-sensitive guess: a memory ceiling either
// is or isn't breached, nothing in between.
$workerCmd = [PHP_BINARY, '-d', 'memory_limit=32M', __DIR__ . '/HttpMaxBytesWorker.php', $base . '?mb=40', (string) $cap];
$workerDescriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$worker = proc_open($workerCmd, $workerDescriptors, $workerPipes);
if ($worker === false) {
    t_skip('could not spawn the worker process (proc_open unavailable)');
}
$stdout = stream_get_contents($workerPipes[1]);
$stderr = stream_get_contents($workerPipes[2]);
fclose($workerPipes[1]);
fclose($workerPipes[2]);
$exitCode = proc_close($worker);

assert_same(
    0,
    $exitCode,
    "worker must not fatal under a 32MB memory_limit fetching a 40MB body with a 5MB cap (stderr: {$stderr})"
);

$decoded = json_decode(trim((string) $stdout), true);
assert_true(is_array($decoded), 'the worker printed a decodable result: ' . var_export($stdout, true));
assert_same(0, $decoded['status'] ?? null, 'an over-budget response is reported the same way a dead socket is: status 0');
assert_same(0, $decoded['body_length'] ?? null, 'no partial body is handed back');
assert_true(
    ($decoded['peak_memory'] ?? PHP_INT_MAX) < 20 * 1024 * 1024,
    'peak memory stays near the cap rather than the full 40MB body: ' . var_export($decoded['peak_memory'] ?? null, true)
);

t_done('HttpTest');
