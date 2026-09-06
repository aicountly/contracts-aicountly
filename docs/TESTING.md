# Testing

## Backend

Plain PHP files, run directly, exiting non-zero on the first failure. No
PHPUnit: the fleet does not carry it, and adding a dev dependency to a repo that
deliberately ships without a `vendor/` directory would be a step backward. This
matches Drive's `server-php/tests/`.

```bash
cd server-php
php tests/run.php              # everything
php tests/run.php Risk         # files matching "Risk"
php tests/SecurityTest.php     # one file
TEST_VERBOSE=1 php tests/run.php
```

Each file runs in its **own process**. These tests mutate static state (the env
cache, the portal memo, the shared PDO) and share one scratch database; running
them in one process would let an earlier file's leftovers decide a later one's
result.

### The database

Tests use a **real PostgreSQL**, not a mock. This product's correctness lives in
its constraints and its tenant filters, and neither is exercised by a fake PDO —
a mock would happily accept `status = 'whatever-i-like'`.

Point `server-php/.env` (or `CONTRACTS_TEST_DSN` / `CONTRACTS_TEST_USER` /
`CONTRACTS_TEST_PASS`) at a scratch database and apply the migrations:

```bash
createdb contracts_dev
php database/migrate.php
php tests/run.php
```

`t_reset_database()` truncates every table with `RESTART IDENTITY CASCADE`, so
never point this at anything you care about. A file with no database available
calls `t_skip()` and exits 0, so the pure-logic suites still run in a CI job
without PostgreSQL.

Because `run.php` starts files in overlapping processes, `t_reset_database()`
first takes a **session-level advisory lock**. Without it one file's `TRUNCATE`
lands in the middle of another's fixtures and both fail in ways that look like
real bugs. The lock is released when the process exits, including on a failed
assertion, so a crashed test cannot wedge the suite.

### Writing one

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured');
}
t_reset_database($pdo);

$ctx = t_context(cmpId: 1, uuid: 'ALICE');

assert_same('expected', $actual, 'what this proves');
assert_throws(fn () => $service->doThing($ctx, 999), 'another tenant is refused', 'not found');

t_done('MyThingTest');
```

Helpers: `assert_same`, `assert_equals`, `assert_true`, `assert_false`,
`assert_null`, `assert_not_null`, `assert_count`, `assert_contains`,
`assert_not_contains`, `assert_throws`.

`assert_throws` searches a `ValidationFailed`'s per-field messages as well as
the exception message, because the message a user actually reads is in the field
map.

`t_context()` builds a `TenantContext` with every permission; narrow it to test
a permission boundary:

```php
$readOnly = t_context(cmpId: 1, uuid: 'CAROL', permissions: [Permissions::CONTRACT_VIEW]);
```

### What is covered

| File | Covers |
|---|---|
| `SchemaTest` | Every expected table; the status/date/currency CHECK constraints; per-tenant number uniqueness; the append-only audit trigger; the search-vector trigger; the storage-location and custom-frequency constraints; one-current-risk-assessment |
| `CrossTenantIsolationTest` | Reading, editing, deleting and cross-referencing across companies; environment as a boundary; row-level visibility on both the list and a direct read |
| `SecurityTest` | Six SQL-injection payloads through two filters; the sort-key allow-list; pagination clamping; enum coercion; validation; status-transition rules; CSV formula injection; the SSRF guard including the loopback hatch; date arithmetic at month ends |
| `ObligationServiceTest` | Recurrence generation across year and month ends; idempotent regeneration; due/overdue transitions with grace; completion with evidence; tenant isolation |
| `RenewalServiceTest` | Cycle creation idempotency; a renew decision extending expiry and opening the next cycle; the due scan not reopening a decided cycle |
| `AmendmentServiceTest` | Numbering; `apply()` overlaying fields while preserving the originals; effective-position ordering |
| `ApprovalServiceTest` | Workflow matching including non-matching and unknown operators; sequential advance; parallel `min_approvals`; rejection returning the contract to draft; a non-assignee refused; escalation idempotency |

Two real security defects were found by these tests during development and fixed:
a row-level IDOR on direct contract reads, and an over-broad SSRF escape hatch
that permitted the cloud metadata endpoint. Both are described in
[`SECURITY.md`](./SECURITY.md).

## Frontend

Vitest with jsdom and Testing Library.

```bash
cd web
npm test
npm run test:watch
npx vitest run src/pages/__tests__/Repository.test.tsx
```

`src/test/setup.ts` stubs `matchMedia`, which jsdom does not implement and
`ThemeProvider` asks for on mount.

Type-checking is a separate gate and part of the build:

```bash
npm run typecheck     # tsc -b
npm run build         # tsc -b && vite build
```

## What is not covered

- **No end-to-end browser tests.** The flows in the brief were exercised by hand
  against a local PostgreSQL and the PHP built-in server; they are not automated.
  Playwright would be the natural home if that changes.
- **No load testing.** The indexes are designed for the query shapes the
  repository and dashboard use, but no profile has been taken against a large
  tenant.
- **Integration clients are tested against a fake transport**
  (`Http::setTransportForTests`), not against live Drive, Contacts, Manage or
  Console. That verifies the request shape and the failure handling, not the
  other product's current behaviour.
