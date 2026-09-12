<?php

declare(strict_types=1);

/**
 * Out-of-process half of HttpTest.php: a loopback HTTP server that streams a
 * body of `?mb=<n>` megabytes, ignoring the request path.
 *
 * A real socket rather than a stubbed transport, because the bug under test
 * (Http::request()'s response-size cap) lives entirely inside curl's own
 * transfer loop — a stubbed Http::setTransportForTests() callback runs after
 * curl would have, so it cannot see whether curl buffered the whole body
 * before the cap was checked.
 *
 * Run with: php -S 127.0.0.1:<port> HttpBigBodyServer.php
 */

$mb    = isset($_GET['mb']) ? (int) $_GET['mb'] : 1;
$chunk = str_repeat('A', 1024 * 1024);

header('Content-Type: application/octet-stream');
for ($i = 0; $i < $mb; $i++) {
    echo $chunk;
}
