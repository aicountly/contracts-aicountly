<?php

declare(strict_types=1);

/**
 * Hit every declared route over HTTP and confirm the answer is an intentional one.
 *
 *   php -S 127.0.0.1:8899 -t .          # in one shell, from server-php/
 *   php scripts/smoke-routes.php        # in another
 *   php scripts/smoke-routes.php https://contracts.gh.aicountly.com/api
 *
 * No credentials are sent, so almost everything should answer 401. What this
 * proves is not authorisation — the test suite covers that — but that every
 * route resolves: the controller class loads, its action exists, and nothing
 * throws before the auth check.
 *
 * That is precisely the failure a unit test cannot see, because a unit test
 * never goes through the router. A missing service, a typo in a handler name, a
 * class renamed without its route: all of them are a 500 here and green
 * everywhere else.
 *
 * Exits non-zero on any unexpected status or non-JSON body.
 */

$root = dirname(__DIR__);
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8899/index.php', '/');

$routesSrc = file_get_contents($root . '/app/Config/Routes.php');
if ($routesSrc === false) {
    fwrite(STDERR, "Cannot read app/Config/Routes.php\n");
    exit(1);
}

preg_match_all(
    "/->(get|post|put|patch|delete)\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/",
    $routesSrc,
    $matches,
    PREG_SET_ORDER
);

if ($matches === []) {
    fwrite(STDERR, "No routes found — has the route DSL changed?\n");
    exit(1);
}

// A route may legitimately answer without a session (health), refuse the
// request (401/400/404/405), or report a dependency down (503/504). Anything
// else — a 500, or a body that is not JSON — is a wiring failure.
$acceptable = [200, 201, 400, 401, 403, 404, 405, 422, 429, 503, 504];

$ok      = 0;
$broken  = [];

foreach ($matches as [, $verb, $path]) {
    $url = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_-]*\}/', '1', $path);
    $url = $base . (str_starts_with((string) $url, '/api') ? $url : '/api' . $url);

    $method = strtoupper($verb);
    $ch     = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) ? '{}' : null,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        $broken[] = sprintf('%-6s %-46s -> transport failure: %s', $method, $path, $error);
        continue;
    }

    $decoded = json_decode((string) $body, true);

    if (in_array($status, $acceptable, true) && is_array($decoded)) {
        $ok++;
        continue;
    }

    $broken[] = sprintf(
        '%-6s %-46s -> %d %s',
        $method,
        $path,
        $status,
        is_array($decoded)
            ? (string) ($decoded['error'] ?? 'unexpected status')
            : substr((string) preg_replace('/\s+/', ' ', (string) $body), 0, 140)
    );
}

printf("\nRoute smoke test against %s\n  %d route(s), %d answered intentionally\n", $base, count($matches), $ok);

if ($broken === []) {
    echo "  Every route resolves.\n\n";
    exit(0);
}

printf("\n  %d PROBLEM(S):\n", count($broken));
foreach ($broken as $line) {
    echo "    {$line}\n";
}
echo "\n";
exit(1);
