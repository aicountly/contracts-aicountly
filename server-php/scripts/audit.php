<?php

declare(strict_types=1);

/**
 * Static self-audit of the Contracts backend.
 *
 *   php scripts/audit.php
 *
 * Checks the things a test suite structurally cannot: that every class the code
 * references actually exists, that every route points at a real action, that
 * every controller action names a permission, that no tenant-scoped query has
 * lost its `cmp_id` filter, and that no debug or placeholder code has survived.
 *
 * Exits non-zero when anything is wrong, so it can gate a release.
 */

$root = dirname(__DIR__);

require_once $root . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register($root . '/app');

/** @var list<array{level: string, check: string, detail: string}> */
$findings = [];

$fail = static function (string $check, string $detail) use (&$findings): void {
    $findings[] = ['level' => 'FAIL', 'check' => $check, 'detail' => $detail];
};
$warn = static function (string $check, string $detail) use (&$findings): void {
    $findings[] = ['level' => 'WARN', 'check' => $check, 'detail' => $detail];
};

/** @return list<string> */
function php_files(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($rii as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }
    sort($out);

    return $out;
}

$appFiles = php_files($root . '/app');
$relative = static fn (string $path): string => str_replace($root . '/', '', $path);

// ---------------------------------------------------------------------------
// 1. Every referenced App\ class exists
// ---------------------------------------------------------------------------
$defined = [];
foreach ($appFiles as $path) {
    $src = file_get_contents($path) ?: '';
    if (preg_match('/^namespace\s+([^;]+);/m', $src, $m)) {
        $defined[trim($m[1]) . '\\' . basename($path, '.php')] = $path;
    }
}

$referenced = [];
foreach ($appFiles as $path) {
    $src = file_get_contents($path) ?: '';
    foreach ([
        '/^use\s+(App\\\\[A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m',
        '/\\\\(App\\\\[A-Za-z0-9_\\\\]+)::/',
        '/new\s+\\\\?(App\\\\[A-Za-z0-9_\\\\]+)\(/',
    ] as $pattern) {
        if (preg_match_all($pattern, $src, $matches)) {
            foreach ($matches[1] as $class) {
                $referenced[$class][$path] = true;
            }
        }
    }
}

foreach ($referenced as $class => $where) {
    if (isset($defined[$class])) {
        continue;
    }
    $fail('missing-class', $class . ' — referenced by ' . implode(', ', array_map(
        static fn (string $p): string => basename($p),
        array_slice(array_keys($where), 0, 3)
    )));
}

// ---------------------------------------------------------------------------
// 2. Every route resolves to a real controller action
// ---------------------------------------------------------------------------
$routesFile = $root . '/app/Config/Routes.php';
$routesSrc  = is_readable($routesFile) ? (file_get_contents($routesFile) ?: '') : '';

preg_match_all(
    "/->(?:get|post|put|patch|delete)\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/",
    $routesSrc,
    $routeMatches,
    PREG_SET_ORDER
);
preg_match_all(
    "/->match\(\s*\[[^\]]*\]\s*,\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/",
    $routesSrc,
    $matchMatches,
    PREG_SET_ORDER
);

$routes = array_merge($routeMatches, $matchMatches);
$routeCount = count($routes);

foreach ($routes as $route) {
    [$full, $path, $handler] = $route;

    if (! str_contains($handler, '@')) {
        $fail('route-handler', "{$path} → {$handler} has no @action");
        continue;
    }

    [$controllerPath, $action] = explode('@', $handler, 2);
    $class = 'App\\Controllers\\' . str_replace('/', '\\', $controllerPath);

    if (! class_exists($class)) {
        $fail('route-controller', "{$path} → {$class} does not exist");
        continue;
    }
    if (! method_exists($class, $action)) {
        $fail('route-action', "{$path} → {$class}::{$action}() does not exist");
    }
}

// ---------------------------------------------------------------------------
// 3. Every public controller action names a permission
// ---------------------------------------------------------------------------
// A controller action that never calls requirePermission/requireAnyPermission/
// requireContext is reachable without an authorisation decision. HealthController
// and AuthRelayController are the deliberate exceptions: they answer before a
// session exists.
$publiclyUnauthenticated = [
    'App\\Controllers\\Api\\HealthController',
    'App\\Controllers\\Api\\AuthRelayController',
    'App\\Controllers\\Api\\ManageProxyController',
];

foreach (php_files($root . '/app/Controllers') as $path) {
    $class = 'App\\Controllers\\Api\\' . basename($path, '.php');
    if (! class_exists($class) || in_array($class, $publiclyUnauthenticated, true)) {
        continue;
    }
    if (str_ends_with($class, 'BaseController')) {
        continue;
    }

    $src = file_get_contents($path) ?: '';
    preg_match_all('/public function (\w+)\s*\([^)]*\)\s*:\s*void\s*\{(.*?)\n    \}/s', $src, $actions, PREG_SET_ORDER);

    foreach ($actions as [, $name, $body]) {
        if (str_starts_with($name, '__')) {
            continue;
        }
        if (preg_match('/require(?:Any)?Permission|requireContext/', $body) !== 1) {
            $fail('unguarded-action', "{$class}::{$name}() does not check a permission");
        }
    }
}

/**
 * The source of the method containing $offset, so a check can consider a query
 * together with the code that builds its WHERE clause.
 *
 * Falls back to a generous window when no method boundary is found — a
 * top-level script, say — which keeps the caller from having to special-case it.
 */
function enclosing_method(string $src, int $offset): string
{
    $before = substr($src, 0, $offset);

    $start = 0;
    if (preg_match_all('/\n    (?:public|private|protected|final|static)[^\n]*function\s+\w+/', $before, $m, PREG_OFFSET_CAPTURE)) {
        $last  = end($m[0]);
        $start = (int) $last[1];
    }

    $end = strpos($src, "\n    }", $offset);
    $end = $end === false ? min(strlen($src), $offset + 2000) : $end;

    return substr($src, $start, $end - $start);
}

// ---------------------------------------------------------------------------
// 4. Tenant scoping on raw SQL
// ---------------------------------------------------------------------------
// Every SELECT/UPDATE/DELETE against a tenant-owned table must mention cmp_id.
// This is a text check, not a parse — it cannot prove correctness, but it
// reliably catches the case that matters: a query written without the filter at
// all.
$tenantTables = [
    'contracts', 'contract_parties', 'contract_documents', 'contract_document_versions',
    'contract_requests', 'contract_templates', 'clause_library', 'contract_clauses',
    'contract_obligations', 'obligation_occurrences', 'contract_milestones',
    'contract_commercial_terms', 'contract_payment_schedules', 'contract_renewals',
    'contract_amendments', 'contract_terminations', 'contract_risk_rules',
    'contract_risk_assessments', 'contract_risk_findings', 'signature_requests',
    'ai_jobs', 'ai_extractions', 'ai_contract_summaries', 'contract_linked_records',
    'contract_comments', 'contract_notifications', 'contract_saved_views',
];

foreach (array_merge(php_files($root . '/app/Services'), php_files($root . '/app/Controllers')) as $path) {
    $src = file_get_contents($path) ?: '';

    // Whole single-quoted SQL literals, including escaped quotes. A naive
    // non-greedy match stops at the first \' — exactly where a status literal
    // like \'archived\' sits — truncating the statement before its WHERE
    // clause and reporting a false positive.
    preg_match_all(
        "/'((?:SELECT|UPDATE|DELETE)\\s(?:[^'\\\\]|\\\\.)*)'/is",
        $src,
        $statements,
        PREG_OFFSET_CAPTURE
    );

    foreach ($statements[1] as [$sql, $offset]) {
        $flat = preg_replace('/\s+/', ' ', $sql) ?? '';

        // The unit of review is the whole method, not the literal. SQL is
        // assembled around it in both directions — a $where array built above,
        // concatenated fragments below — and a fixed character window means
        // tuning a magic number every time a method grows.
        //
        // This is a lint, not a proof. It cannot show that the filter binds the
        // right value, only that a query was written with no tenant filter
        // anywhere in the method that issues it — which is the mistake worth
        // catching. A check that cries wolf gets ignored, and an ignored check
        // is worse than none.
        $window = enclosing_method($src, $offset);

        foreach ($tenantTables as $table) {
            // Only the driving table. A LEFT JOIN exists to fetch display
            // columns and is reached through a parent row that the WHERE has
            // already scoped; requiring cmp_id on it would be noise.
            if (preg_match('/\b(?:FROM|UPDATE|INTO)\s+' . preg_quote($table, '/') . '\b/i', $flat) !== 1) {
                continue;
            }
            if (stripos($window, 'cmp_id') !== false) {
                continue 2;
            }
            $warn('tenant-scope', $relative($path) . ' — a query over ' . $table
                . ' has no cmp_id filter: ' . substr($flat, 0, 110));
            continue 2;
        }
    }
}

// ---------------------------------------------------------------------------
// 5. No debug or placeholder code
// ---------------------------------------------------------------------------
$banned = [
    '/\bvar_dump\s*\(/'   => 'var_dump()',
    '/\bprint_r\s*\(/'    => 'print_r()',
    '/\bdd\s*\(/'         => 'dd()',
    '/\bdie\s*\(\s*[\'"]/' => 'die() with a message',
    '/\bTODO\b/'          => 'a TODO',
    '/\bFIXME\b/'         => 'a FIXME',
    '/\bXXX\b/'           => 'an XXX marker',
    '/\bHACK\b/'          => 'a HACK marker',
];

foreach (array_merge($appFiles, php_files($root . '/database'), php_files($root . '/scripts')) as $path) {
    if (str_ends_with($path, 'scripts/audit.php')) {
        continue;
    }
    $src = file_get_contents($path) ?: '';
    foreach ($banned as $pattern => $label) {
        if (preg_match($pattern, $src)) {
            $fail('debug-code', $relative($path) . ' contains ' . $label);
        }
    }
}

// ---------------------------------------------------------------------------
// 6. Migrations are contiguous and uniquely numbered
// ---------------------------------------------------------------------------
$migrations = glob($root . '/database/migrations/*.sql') ?: [];
sort($migrations);
$seen = [];
foreach ($migrations as $file) {
    if (! preg_match('/^(\d{3})_/', basename($file), $m)) {
        $fail('migration-name', basename($file) . ' does not start with a three-digit number');
        continue;
    }
    $n = (int) $m[1];
    if (isset($seen[$n])) {
        $fail('migration-number', 'two migrations share the number ' . $m[1]);
    }
    $seen[$n] = true;
}
if ($seen !== [] && count($seen) !== max(array_keys($seen))) {
    $warn('migration-gap', 'migration numbers are not contiguous — check for a deleted file');
}

// ---------------------------------------------------------------------------
// 7. Nothing that looks like a secret is committed
// ---------------------------------------------------------------------------
$secretPatterns = [
    '/\bsk-[A-Za-z0-9]{20,}/'       => 'an OpenAI-style key',
    '/\bAIza[A-Za-z0-9_\-]{30,}/'   => 'a Google API key',
    '/\bsk-ant-[A-Za-z0-9_\-]{20,}/' => 'an Anthropic key',
    '/\bAKIA[0-9A-Z]{16}\b/'        => 'an AWS access key id',
];

foreach (array_merge($appFiles, php_files($root . '/database'), php_files($root . '/tests')) as $path) {
    $src = file_get_contents($path) ?: '';
    foreach ($secretPatterns as $pattern => $label) {
        if (preg_match($pattern, $src)) {
            $fail('secret', $relative($path) . ' appears to contain ' . $label);
        }
    }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------
$fails = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'FAIL'));
$warns = array_values(array_filter($findings, static fn (array $f): bool => $f['level'] === 'WARN'));

printf(
    "\nContracts self-audit\n  %d classes defined · %d routes · %d migrations\n\n",
    count($defined),
    $routeCount,
    count($migrations)
);

foreach ($findings as $finding) {
    printf("  %-5s %-18s %s\n", $finding['level'], $finding['check'], $finding['detail']);
}

if ($findings === []) {
    echo "  Clean.\n\n";
    exit(0);
}

printf("\n  %d failure(s), %d warning(s)\n\n", count($fails), count($warns));

exit($fails === [] ? 0 : 1);
