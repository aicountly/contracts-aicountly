<?php

declare(strict_types=1);

/**
 * Out-of-process half of SignatureConcurrentCreateRaceTest.php.
 *
 * create()'s "one envelope at a time" guard reads whether an open signature
 * request already exists before any transaction opens and, pre-fix, never
 * re-checked under a lock — so the race is between two callers who each read
 * "no open request" before either of them writes one. A single PHP process is
 * inherently sequential and cannot produce that interleaving, so this script
 * is spawned twice against the same contract and synchronised on a file
 * barrier so neither wins just by starting first.
 *
 * argv: <contractId> <readyFile> <goFile>
 * stdout: one JSON line describing the outcome.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\SignatureService;
use App\Support\DomainException;

[, $contractIdArg, $readyFile, $goFile] = $argv;
$contractId = (int) $contractIdArg;

$pdo = t_database();
if ($pdo === null) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error_code' => 'NO_DATABASE']) . "\n");
    exit(0);
}

$ctx     = t_context(uuid: 'SIGNER-' . getmypid());
$service = new SignatureService($pdo);

touch($readyFile);
$deadline = microtime(true) + 5.0;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        fwrite(STDOUT, json_encode(['ok' => false, 'error_code' => 'BARRIER_TIMEOUT']) . "\n");
        exit(0);
    }
    usleep(1000);
}

try {
    $result = $service->create($ctx, $contractId, [
        'signers' => [['name' => 'Someone ' . getmypid()]],
    ]);
    fwrite(STDOUT, json_encode([
        'ok'         => true,
        'request_id' => (int) $result['id'],
    ]) . "\n");
} catch (DomainException $e) {
    fwrite(STDOUT, json_encode([
        'ok'         => false,
        'error_code' => $e->errorCode,
        'message'    => $e->getMessage(),
    ]) . "\n");
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode([
        'ok'         => false,
        'error_code' => 'EXCEPTION',
        'message'    => $e->getMessage(),
    ]) . "\n");
}
