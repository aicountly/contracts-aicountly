<?php

declare(strict_types=1);

/**
 * A renewal must extend a recurring obligation's occurrences, not leave it
 * marked 'completed' for a term that is still running.
 *
 * generateOccurrences() only ever fired from a contract activating, an
 * obligation being edited, or the manual endpoint — nothing periodic ever
 * called it, so an obligation whose last generated occurrence was completed
 * stayed 'completed' even after the contract's expiry moved out and more
 * instances of the duty were still owed. This pins the two things
 * ObligationService.php can fix on its own: a cron-safe entry point that
 * regenerates across every active contract (generateForActiveContracts(), for
 * whichever sweep ends up calling it), and reopening an obligation from
 * 'completed' only when that run actually materialises new work — never
 * merely because time passed.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ObligationService;
use App\Support\Dates;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx = t_context(1, 'OWNER');

$obligations = new ObligationService($pdo);
$today       = Dates::today();

/** A contract inserted directly, so this file does not depend on services other agents own. */
function r_contract(PDO $pdo, int $cmpId, string $number, string $expiry): int
{
    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, expiry_date, currency, created_by)
         VALUES (?, ?, ?, ?, \'active\', \'active\', ?, \'INR\', \'OWNER\') RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $number, $number, $expiry]);

    return (int) $st->fetchColumn();
}

/** @return list<int> */
function r_live_occurrence_ids(PDO $pdo, int $obligationId): array
{
    $st = $pdo->prepare(
        "SELECT id FROM obligation_occurrences
         WHERE obligation_id = ? AND status IN ('upcoming','due','overdue')
         ORDER BY due_date"
    );
    $st->execute([$obligationId]);

    return array_map(static fn (array $r): int => (int) $r['id'], $st->fetchAll() ?: []);
}

function r_occurrence_count(PDO $pdo, int $obligationId): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM obligation_occurrences WHERE obligation_id = ?');
    $st->execute([$obligationId]);

    return (int) $st->fetchColumn();
}

function r_obligation_status(PDO $pdo, int $obligationId): string
{
    $st = $pdo->prepare('SELECT status FROM contract_obligations WHERE id = ?');
    $st->execute([$obligationId]);

    return (string) $st->fetchColumn();
}

// ---------------------------------------------------------------------------
// A: renewed after closing out — must regenerate and reopen.
// ---------------------------------------------------------------------------

$renewedExpiry = Dates::addMonths($today, 12);
$contractA     = r_contract($pdo, 1, 'CON-REGEN-A', $renewedExpiry);

$obligationA = $obligations->create($ctx, $contractA, [
    'title'             => 'Monthly SLA report',
    'frequency'         => 'monthly',
    'first_due_date'    => $today,
    'responsible_party' => 'company',
]);
$obligationAId = (int) $obligationA['id'];

$generatedAtActivation = r_occurrence_count($pdo, $obligationAId);
assert_true($generatedAtActivation > 0, 'creating the obligation on an already-active contract generates its occurrences');

foreach (r_live_occurrence_ids($pdo, $obligationAId) as $occId) {
    $obligations->completeOccurrence($ctx, $occId, ['completion_note' => 'filed']);
}
assert_same('completed', r_obligation_status($pdo, $obligationAId), 'closing out every occurrence marks the obligation completed');

// A renewal moves only the contract's own dates.
$pdo->prepare('UPDATE contracts SET expiry_date = ? WHERE id = ?')
    ->execute([Dates::addMonths($renewedExpiry, 12), $contractA]);

// Pre-fix behaviour: nothing periodic ever called generation again, so three
// nightly-style sweeps in a row add nothing and the obligation stays closed.
for ($i = 0; $i < 3; $i++) {
    $obligations->refreshDueStatuses('sandbox', 1);
}
assert_same(
    $generatedAtActivation,
    r_occurrence_count($pdo, $obligationAId),
    'refreshDueStatuses alone never regenerates — it only ages statuses'
);
assert_same(
    'completed',
    r_obligation_status($pdo, $obligationAId),
    'and the obligation is still (wrongly) completed with a year of the renewed term still owed'
);

// The fix under test: a cron-safe run that actually regenerates.
$inserted = $obligations->generateForActiveContracts('sandbox', 1);

assert_true($inserted > 0, 'generateForActiveContracts() materialises the occurrences the renewed term now owes');
assert_true(
    r_occurrence_count($pdo, $obligationAId) > $generatedAtActivation,
    'the new rows land on the obligation whose contract was renewed'
);
assert_true(
    in_array(r_obligation_status($pdo, $obligationAId), ['upcoming', 'due', 'overdue'], true),
    'and the obligation is reopened to a live status now that it has live work again'
);

// Idempotent: running it again adds nothing further.
assert_same(0, $obligations->generateForActiveContracts('sandbox', 1), 'a second run duplicates nothing');

// ---------------------------------------------------------------------------
// B: closed out and NOT renewed — must stay completed.
// ---------------------------------------------------------------------------

$contractB = r_contract($pdo, 1, 'CON-REGEN-B', Dates::addMonths($today, 1));

$obligationB = $obligations->create($ctx, $contractB, [
    'title'             => 'One-off return of materials',
    'frequency'         => 'one_time',
    'first_due_date'    => $today,
    'responsible_party' => 'company',
]);
$obligationBId = (int) $obligationB['id'];

foreach (r_live_occurrence_ids($pdo, $obligationBId) as $occId) {
    $obligations->completeOccurrence($ctx, $occId, ['completion_note' => 'returned']);
}
assert_same('completed', r_obligation_status($pdo, $obligationBId), 'a one-time obligation closes out the same way');

$beforeCount = r_occurrence_count($pdo, $obligationBId);
$obligations->generateForActiveContracts('sandbox', 1);

assert_same(
    $beforeCount,
    r_occurrence_count($pdo, $obligationBId),
    'no term extension means nothing new to generate for a one-time obligation'
);
assert_same(
    'completed',
    r_obligation_status($pdo, $obligationBId),
    'so it stays completed — reopening only follows genuinely new occurrences, never a sweep running by itself'
);

t_done('ObligationRegenerationTest');
