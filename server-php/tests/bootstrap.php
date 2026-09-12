<?php

declare(strict_types=1);

/**
 * Shared harness for the Contracts test suite.
 *
 * The suite follows the AICOUNTLY house style (see Drive's server-php/tests):
 * plain PHP files, run directly, exiting non-zero on the first failure. No
 * PHPUnit — the fleet does not carry it, and adding a dev dependency to a repo
 * that deliberately ships without a vendor/ directory would be a step backward.
 *
 *   php server-php/tests/run.php            everything
 *   php server-php/tests/ContractServiceTest.php   one file
 *
 * Tests that need a database use a real PostgreSQL, not a mock: this product's
 * correctness lives in its constraints and its tenant filters, and neither is
 * exercised by a fake PDO. Set CONTRACTS_TEST_DSN (or DB_* in server-php/.env)
 * to point at a scratch database — the harness creates and drops its own
 * schema, so never point it at anything you care about.
 */

$root = dirname(__DIR__);

require_once $root . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register($root . '/app');

\App\Core\Env::setApiRoot($root);

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

final class TestState
{
    public static int $passed = 0;
    public static string $file = '';
    /** @var list<string> */
    public static array $notes = [];
}

function t_fail(string $label, string $detail): never
{
    fwrite(STDERR, "\n  FAIL  {$label}\n        {$detail}\n");
    fwrite(STDERR, "\n{$label} failed in " . TestState::$file . " after " . TestState::$passed . " passing assertion(s).\n");
    exit(1);
}

function t_ok(string $label): void
{
    TestState::$passed++;
    if (getenv('TEST_VERBOSE') === '1') {
        fwrite(STDOUT, "  ok    {$label}\n");
    }
}

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected === $actual) {
        t_ok($label);

        return;
    }
    t_fail($label, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function assert_equals(mixed $expected, mixed $actual, string $label): void
{
    if ($expected == $actual) {
        t_ok($label);

        return;
    }
    t_fail($label, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

function assert_true(mixed $value, string $label): void
{
    if ($value === true) {
        t_ok($label);

        return;
    }
    t_fail($label, 'expected true, got ' . var_export($value, true));
}

function assert_false(mixed $value, string $label): void
{
    if ($value === false) {
        t_ok($label);

        return;
    }
    t_fail($label, 'expected false, got ' . var_export($value, true));
}

function assert_null(mixed $value, string $label): void
{
    if ($value === null) {
        t_ok($label);

        return;
    }
    t_fail($label, 'expected null, got ' . var_export($value, true));
}

function assert_not_null(mixed $value, string $label): void
{
    if ($value !== null) {
        t_ok($label);

        return;
    }
    t_fail($label, 'expected a value, got null');
}

function assert_count(int $expected, mixed $countable, string $label): void
{
    $actual = is_countable($countable) ? count($countable) : -1;
    if ($actual === $expected) {
        t_ok($label);

        return;
    }
    t_fail($label, "expected {$expected} item(s), got {$actual}");
}

function assert_contains(string $needle, string $haystack, string $label): void
{
    if (str_contains($haystack, $needle)) {
        t_ok($label);

        return;
    }
    t_fail($label, "expected to find '{$needle}' in: " . mb_substr($haystack, 0, 300));
}

function assert_not_contains(string $needle, string $haystack, string $label): void
{
    if (! str_contains($haystack, $needle)) {
        t_ok($label);

        return;
    }
    t_fail($label, "did not expect to find '{$needle}' in: " . mb_substr($haystack, 0, 300));
}

/**
 * Assert that $fn throws, optionally carrying $expectedMessage.
 *
 * A ValidationFailed's own message is the generic "correct the highlighted
 * fields" — the message a user actually reads is in the per-field map, so both
 * are searched. Requiring the caller to know which of the two a given failure
 * uses would make every assertion here brittle for no benefit.
 */
function assert_throws(callable $fn, string $label, ?string $expectedMessage = null): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($expectedMessage === null) {
            t_ok($label);

            return;
        }

        $haystack = $e->getMessage();
        if ($e instanceof \App\Support\ValidationFailed) {
            $haystack .= ' ' . implode(' ', $e->errors);
        }

        if (! str_contains($haystack, $expectedMessage)) {
            t_fail($label, "threw, but '{$haystack}' does not contain '{$expectedMessage}'");
        }

        t_ok($label);

        return;
    }
    t_fail($label, 'expected a throw, but the call returned normally');
}

function t_done(string $suite): void
{
    fwrite(STDOUT, sprintf("  PASS  %-46s %3d assertions\n", $suite, TestState::$passed));
}

// ---------------------------------------------------------------------------
// Database fixture
// ---------------------------------------------------------------------------

/**
 * Connect to the scratch database and apply every migration.
 *
 * Returns null when no database is reachable, so a test can skip rather than
 * fail — CI without PostgreSQL should still run the pure-logic suites.
 */
function t_database(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    $dsn  = getenv('CONTRACTS_TEST_DSN') ?: '';
    $user = getenv('CONTRACTS_TEST_USER') ?: '';
    $pass = getenv('CONTRACTS_TEST_PASS') ?: '';

    if ($dsn === '') {
        $params = \App\Core\Database::connectionParams();
        $dsn    = $params['dsn'];
        $user   = $params['user'];
        $pass   = \App\Core\Env::get('DB_PASS');
    }

    if ($user === '') {
        return null;
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'");
    } catch (Throwable $e) {
        $pdo = null;

        return null;
    }

    \App\Core\Database::configureForTests($pdo);

    return $pdo;
}


/**
 * Serialise database-backed tests across processes.
 *
 * The key is an arbitrary constant shared by the whole suite; any two test
 * processes contend on it and run one after the other. pg_advisory_lock blocks
 * rather than failing, which is what we want — a test should wait its turn, not
 * report a false failure because another file happened to be running.
 */
function t_lock_database(PDO $pdo): void
{
    static $held = false;

    if ($held) {
        return;
    }

    $pdo->exec('SELECT pg_advisory_lock(8823411)');
    $held = true;
}

/**
 * Empty every domain table, leaving the schema in place.
 *
 * TRUNCATE ... CASCADE rather than per-table DELETE so a test never depends on
 * teardown order, and RESTART IDENTITY so ids are comparable between runs.
 *
 * Takes a session-level advisory lock first. run.php starts each test file in
 * its own process and those can overlap, so without this one file's TRUNCATE
 * lands in the middle of another file's fixtures and both fail in ways that
 * look like real bugs. The lock is released when the process exits — including
 * when it exits on a failed assertion — so a crashed test cannot wedge the
 * suite.
 */
function t_reset_database(PDO $pdo): void
{
    t_lock_database($pdo);

    $tables = $pdo->query(
        "SELECT tablename FROM pg_tables
         WHERE schemaname = 'public' AND tablename <> 'contracts_migration'"
    )->fetchAll();

    if ($tables === []) {
        return;
    }

    $names = array_map(static fn (array $r): string => '"' . $r['tablename'] . '"', $tables);

    // The audit table refuses DELETE by trigger; TRUNCATE is not a row-level
    // operation so it is not caught by that, which is what makes a clean
    // fixture possible without weakening the immutability guarantee.
    $pdo->exec('TRUNCATE ' . implode(', ', $names) . ' RESTART IDENTITY CASCADE');
}

function t_skip(string $reason): never
{
    fwrite(STDOUT, "  SKIP  " . TestState::$file . " — {$reason}\n");
    exit(0);
}

/**
 * A TenantContext for tests.
 *
 * Defaults to a contract administrator with every permission, which is what
 * most tests want. Narrowing `$permissions` alone is NOT enough to test a
 * permission boundary: several services grant an admin bypass by role rather
 * than by permission — an approver's step guard, for one — so a context with a
 * narrowed permission list but the default admin role would sail past the very
 * check the test means to exercise. Pass `roles` too.
 */
function t_context(
    int $cmpId = 1,
    string $uuid = 'USER-A',
    array $permissions = null,
    string $environment = 'sandbox',
    array $roles = null
): \App\Support\TenantContext {
    return new \App\Support\TenantContext(
        uuid: $uuid,
        sesKey: 'test-ses-key',
        cmpId: $cmpId,
        fyId: 1,
        boId: 1,
        environment: $environment,
        company: ['cmp_id' => $cmpId, 'legal_name' => 'Test Company ' . $cmpId, 'currency' => 'INR'],
        permissions: $permissions ?? \App\Support\Permissions::all(),
        // A narrowed permission list without a narrowed role is almost always a
        // mistake, so the default role follows the default permissions.
        roles: $roles ?? ($permissions === null ? ['contract_admin'] : ['read_only']),
    );
}

TestState::$file = basename($_SERVER['argv'][0] ?? 'unknown');
