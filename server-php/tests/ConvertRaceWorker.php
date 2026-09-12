<?php

declare(strict_types=1);

/**
 * Out-of-process half of ContractRequestConvertRaceTest.php.
 *
 * convert()'s race lives between two callers who each read the request's
 * status before either of them writes it back as converted. A single PHP
 * process is inherently sequential and cannot produce that interleaving no
 * matter how the calling test is arranged, so this script is spawned twice
 * against the same request id and the two runs are made to reach convert()
 * together via a file barrier, rather than one simulating the other.
 *
 * argv: <requestId> <readyFile> <goFile>
 * stdout: one JSON line describing the outcome.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractRequestService;
use App\Support\DomainException;

[, $requestIdArg, $readyFile, $goFile] = $argv;
$requestId = (int) $requestIdArg;

$pdo = t_database();
if ($pdo === null) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error_code' => 'NO_DATABASE']) . "\n");
    exit(0);
}

$ctx     = t_context(uuid: 'REVIEWER');
$service = new ContractRequestService($pdo);

// Signal readiness, then wait for the starting gun so both processes call
// convert() as close to simultaneously as two OS processes can manage.
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
    $result = $service->convert($ctx, $requestId, []);
    fwrite(STDOUT, json_encode([
        'ok'          => true,
        'contract_id' => (int) $result['contract']['id'],
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
