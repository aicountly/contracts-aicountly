<?php

declare(strict_types=1);

/**
 * Out-of-process half of HttpTest.php.
 *
 * Run under a tight memory_limit so the pre-fix behaviour (curl_exec()
 * buffering the whole response before fetchBytes()-style callers ever get to
 * compare its length against the cap) is a PHP Fatal Error, not just a slow
 * path — the parent test tells the two apart by exit code, which a
 * same-process call under a lowered ini_set('memory_limit') cannot reliably
 * do (a fatal in the child would take the whole test process down with it).
 *
 * argv: <url> <maxResponseBytes>
 * stdout: one JSON line describing the outcome.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Env;
use App\Core\Http;

Env::configureForTests(['ALLOW_LOOPBACK_INTEGRATIONS' => 'true']);

[, $url, $maxBytesArg] = $argv;

$result = Http::request('GET', $url, [], null, 30, 5, (int) $maxBytesArg);

fwrite(STDOUT, json_encode([
    'status'      => $result['status'],
    'body_length' => strlen($result['body']),
    'peak_memory' => memory_get_peak_usage(true),
]) . "\n");
