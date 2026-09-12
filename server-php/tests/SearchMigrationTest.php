<?php

declare(strict_types=1);

/**
 * Pins the backfill in 014_search_backfill.sql: a contract written before
 * trg_contracts_search_vector existed must end up with a populated
 * search_vector once the follow-up migration runs, not stay unsearchable
 * forever.
 *
 * 013_search.sql shipped its own backfill as `UPDATE contracts SET
 * updated_at = updated_at`, meant to make the trigger fire for pre-existing
 * rows. It doesn't: the trigger is `UPDATE OF contract_number, title,
 * counterparty_name, commercial_summary, description, notes`, and Postgres
 * matches a column-specific UPDATE trigger against the SET list of the
 * statement, not against which values actually changed — `updated_at` isn't
 * on that list. 013 is already applied everywhere, so it cannot be edited in
 * place; the fix is the new migration this test exercises directly.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Database;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

// Simulate a contract written before the trigger existed by disabling it for
// one insert.
$pdo->exec('ALTER TABLE contracts DISABLE TRIGGER trg_contracts_search_vector');
$pdo->prepare(
    'INSERT INTO contracts (environment, cmp_id, contract_number, title, counterparty_name)
     VALUES (?, ?, ?, ?, ?)'
)->execute(['sandbox', 1, 'LEGACY-1', 'Legacy Contract', 'Legacy Counterparty']);
$pdo->exec('ALTER TABLE contracts ENABLE TRIGGER trg_contracts_search_vector');

$row = $pdo->query("SELECT search_vector FROM contracts WHERE contract_number = 'LEGACY-1'")->fetch();
assert_null($row['search_vector'], 'legacy row starts with no search_vector, simulating pre-trigger data');

// The exact statement 013_search.sql shipped as its backfill stays a no-op —
// this is the bug itself, kept here as a guard against it resurfacing.
$pdo->exec('UPDATE contracts SET updated_at = updated_at');
$row = $pdo->query("SELECT search_vector FROM contracts WHERE contract_number = 'LEGACY-1'")->fetch();
assert_null($row['search_vector'], "013's own backfill statement still does not touch a watched column");

// Applying 014_search_backfill.sql's actual SQL must repair it.
$sql = file_get_contents(dirname(__DIR__) . '/database/migrations/014_search_backfill.sql');
foreach (Database::splitSqlStatements((string) $sql) as $statement) {
    $pdo->exec($statement);
}

$row = $pdo->query("SELECT search_vector FROM contracts WHERE contract_number = 'LEGACY-1'")->fetch();
assert_not_null($row['search_vector'], '014_search_backfill.sql populates search_vector for a pre-existing row');
assert_contains('legaci', strtolower((string) $row['search_vector']), 'the backfilled vector contains the contract title');

// Re-running it is harmless: only NULL rows are touched, so deploying it
// twice, or onto a database with a mix of legacy and already-searchable
// rows, never clobbers a vector that is already correct.
$pdo->exec(
    "UPDATE contracts SET search_vector = to_tsvector('english', 'sentinel-value') WHERE contract_number = 'LEGACY-1'"
);
foreach (Database::splitSqlStatements((string) $sql) as $statement) {
    $pdo->exec($statement);
}
$row = $pdo->query("SELECT search_vector FROM contracts WHERE contract_number = 'LEGACY-1'")->fetch();
assert_contains(
    'sentinel',
    strtolower((string) $row['search_vector']),
    'the migration only backfills rows that are still NULL, never overwriting an existing vector'
);

t_done('SearchMigrationTest');
