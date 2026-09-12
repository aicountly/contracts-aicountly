<?php

declare(strict_types=1);

/**
 * Run every *Test.php in this directory, each in its own process.
 *
 * Separate processes on purpose: these tests mutate static state (the Env
 * cache, the portal memo, the shared PDO) and share one scratch database, so
 * running them in one process would let an earlier test's leftovers decide a
 * later test's result.
 *
 *   php server-php/tests/run.php
 *   php server-php/tests/run.php Risk        only files matching "Risk"
 */

$dir    = __DIR__;
$filter = $argv[1] ?? '';

$files = glob($dir . '/*Test.php') ?: [];
sort($files);

if ($filter !== '') {
    $files = array_values(array_filter(
        $files,
        static fn (string $f): bool => stripos(basename($f), $filter) !== false
    ));
}

if ($files === []) {
    fwrite(STDERR, "No test files matched.\n");
    exit(1);
}

$failed  = [];
$skipped = 0;
$started = microtime(true);

echo "\nContracts test suite — " . count($files) . " file(s)\n\n";

foreach ($files as $file) {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $output, $code);
    $text = implode("\n", $output);
    $output = [];

    echo $text . "\n";

    if ($code !== 0) {
        $failed[] = basename($file);
    } elseif (str_contains($text, 'SKIP')) {
        $skipped++;
    }
}

$elapsed = round(microtime(true) - $started, 2);

echo "\n";
if ($failed === []) {
    echo "All " . count($files) . " test file(s) passed";
    if ($skipped > 0) {
        echo " ({$skipped} skipped)";
    }
    echo " in {$elapsed}s.\n\n";
    exit(0);
}

echo count($failed) . " test file(s) FAILED in {$elapsed}s:\n";
foreach ($failed as $name) {
    echo "  - {$name}\n";
}
echo "\n";
exit(1);
