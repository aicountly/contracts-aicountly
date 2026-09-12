<?php

declare(strict_types=1);

/**
 * Obligations, their occurrences, and milestones.
 *
 * The four things this suite exists to hold still: a recurrence that lands on
 * the right calendar days (month-end and across a year boundary), generation
 * that can be re-run without duplicating a due date, a sweep that ages
 * occurrences through their grace period exactly once, and the tenant filter —
 * company 1 must not be able to see, read or touch anything belonging to
 * company 2, whatever id it guesses.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\MilestoneService;
use App\Services\ObligationService;
use App\Support\Dates;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx1 = t_context(1, 'USER-A');
$ctx2 = t_context(2, 'USER-B');

$obligations = new ObligationService($pdo);
$milestones  = new MilestoneService($pdo);
$today       = Dates::today();

/** A contract inserted directly, so this suite does not depend on services other agents own. */
function o_contract(PDO $pdo, int $cmpId, string $number, string $title, string $status = 'draft', ?string $expiry = null): int
{
    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, expiry_date, currency, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $number, $title, $status, $status === 'active' ? 'active' : 'draft', $expiry, 'INR', 'USER-A']);

    return (int) $st->fetchColumn();
}

/** @return list<string> */
function o_due_dates(PDO $pdo, int $obligationId): array
{
    $st = $pdo->prepare('SELECT due_date FROM obligation_occurrences WHERE obligation_id = ? ORDER BY due_date');
    $st->execute([$obligationId]);

    return array_map(static fn (array $r): string => (string) $r['due_date'], $st->fetchAll() ?: []);
}

/** @return array<string,mixed> */
function o_first_occurrence(PDO $pdo, int $obligationId): array
{
    $st = $pdo->prepare('SELECT * FROM obligation_occurrences WHERE obligation_id = ? ORDER BY due_date, id LIMIT 1');
    $st->execute([$obligationId]);
    $row = $st->fetch();

    return is_array($row) ? $row : [];
}

function o_status(PDO $pdo, int $occurrenceId): string
{
    $st = $pdo->prepare('SELECT status FROM obligation_occurrences WHERE id = ?');
    $st->execute([$occurrenceId]);

    return (string) $st->fetchColumn();
}

// ---------------------------------------------------------------------------
// Monthly recurrence: month-end clamping, no drift, across a year boundary
// ---------------------------------------------------------------------------

$msa = o_contract($pdo, 1, 'CON-2026-000001', 'Managed services agreement');

$sla = $obligations->create($ctx1, $msa, [
    'title'             => 'Monthly SLA report',
    'frequency'         => 'monthly',
    'first_due_date'    => '2025-11-30',
    'responsible_party' => 'counterparty',
    'owner_uuid'        => 'USER-C',
]);

assert_same('monthly', $sla['frequency'], 'the obligation stored its frequency');
assert_same('counterparty', $sla['responsible_party'], 'responsible party is stored as given');
assert_true($sla['is_active'], 'a new obligation is active');
assert_count(0, o_due_dates($pdo, (int) $sla['id']), 'a draft contract generates nothing on create');

$made = $obligations->generateOccurrences($ctx1, (int) $sla['id'], '2026-03-31');
assert_same(5, $made, 'generation stops at the horizon it was given');
assert_same(
    ['2025-11-30', '2025-12-30', '2026-01-30', '2026-02-28', '2026-03-30'],
    o_due_dates($pdo, (int) $sla['id']),
    'a monthly series crosses the year boundary and clamps to a short February'
);

// The drift case the schedule exists to prevent: chaining "+1 month" from the
// clamped February date would produce 28 March, not the 31st the contract says.
$reconciliation = $obligations->create($ctx1, $msa, [
    'title'          => 'Month-end reconciliation',
    'frequency'      => 'monthly',
    'first_due_date' => '2026-01-31',
]);

$obligations->generateOccurrences($ctx1, (int) $reconciliation['id'], '2026-06-30');
assert_same(
    ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
    o_due_dates($pdo, (int) $reconciliation['id']),
    'a 31st recurrence returns to the 31st after a short month'
);

// ---------------------------------------------------------------------------
// Generation is idempotent
// ---------------------------------------------------------------------------

assert_same(0, $obligations->generateOccurrences($ctx1, (int) $sla['id'], '2026-03-31'), 're-running the same horizon adds nothing');
assert_count(5, o_due_dates($pdo, (int) $sla['id']), 're-running leaves the row count alone');

assert_same(3, $obligations->generateOccurrences($ctx1, (int) $sla['id'], '2026-06-30'), 'a wider horizon adds only the dates it newly reaches');
assert_same(
    ['2025-11-30', '2025-12-30', '2026-01-30', '2026-02-28', '2026-03-30', '2026-04-30', '2026-05-30', '2026-06-30'],
    o_due_dates($pdo, (int) $sla['id']),
    'extending the horizon continues the same series'
);

$sequences = $pdo->query('SELECT sequence_no FROM obligation_occurrences WHERE obligation_id = ' . (int) $sla['id'] . ' ORDER BY due_date')
    ->fetchAll(PDO::FETCH_COLUMN);
assert_same([1, 2, 3, 4, 5, 6, 7, 8], array_map('intval', $sequences), 'sequence numbers stay stable across runs');

// Even a direct duplicate insert cannot get past the unique constraint.
assert_throws(
    static function () use ($pdo, $sla): void {
        $pdo->prepare(
            'INSERT INTO obligation_occurrences (obligation_id, contract_id, environment, cmp_id, due_date)
             SELECT obligation_id, contract_id, environment, cmp_id, due_date
             FROM obligation_occurrences WHERE obligation_id = ? LIMIT 1'
        )->execute([(int) $sla['id']]);
    },
    'a due date cannot be materialised twice for one obligation',
    'uq_obligation_occurrence'
);

// ---------------------------------------------------------------------------
// generateForContract, the entry point ContractService calls on activation
// ---------------------------------------------------------------------------

$lease = o_contract($pdo, 1, 'CON-2026-000004', 'Office lease');

$obligations->create($ctx1, $lease, ['title' => 'Deposit refund', 'frequency' => 'one_time', 'first_due_date' => '2026-05-01']);
$obligations->create($ctx1, $lease, ['title' => 'Fire certificate', 'frequency' => 'one_time', 'first_due_date' => '2026-06-01']);
$inactive = $obligations->create($ctx1, $lease, ['title' => 'Superseded clause', 'frequency' => 'one_time', 'first_due_date' => '2026-07-01', 'is_active' => false]);

assert_same(2, $obligations->generateForContract($ctx1, $lease), 'activation materialises every active obligation');
assert_same(0, $obligations->generateForContract($ctx1, $lease), 'a second activation pass adds nothing');
assert_count(0, o_due_dates($pdo, (int) $inactive['id']), 'an inactive obligation generates nothing');

$summary = $obligations->summaryForContract($ctx1, $lease);
assert_same(2, $summary['total'], 'the contract summary counts every occurrence');
assert_same(2, $summary['obligations'], 'the summary counts only active obligations');
assert_same(0, $summary['waived'], 'every status is present in the summary, at zero');

// ---------------------------------------------------------------------------
// The contract term bounds a recurrence, but never suppresses a single duty
// ---------------------------------------------------------------------------

$termExpiry = Dates::addMonths($today, 8);
$fixedTerm  = o_contract($pdo, 1, 'CON-2026-000005', 'Fixed-term services', 'draft', $termExpiry);

$invoices = $obligations->create($ctx1, $fixedTerm, [
    'title'          => 'Monthly invoice',
    'frequency'      => 'monthly',
    'first_due_date' => Dates::addDays($today, 20),
]);
$obligations->generateOccurrences($ctx1, (int) $invoices['id']);
$invoiceDates = o_due_dates($pdo, (int) $invoices['id']);

assert_true($invoiceDates !== [], 'an in-term recurrence generates');
assert_true(end($invoiceDates) <= (string) $termExpiry, 'a recurrence stops at the end of the contract term');
assert_true(Dates::addMonths(end($invoiceDates), 1) > (string) $termExpiry, 'it stops because the term ended, not early');

$survival = $obligations->create($ctx1, $fixedTerm, [
    'title'          => 'Return of materials',
    'frequency'      => 'one_time',
    'first_due_date' => Dates::addDays($termExpiry, 30),
]);
$obligations->generateOccurrences($ctx1, (int) $survival['id']);
assert_same(
    [(string) Dates::addDays($termExpiry, 30)],
    o_due_dates($pdo, (int) $survival['id']),
    'a duty falling due after the term still gets its occurrence'
);

$postTerm = $obligations->create($ctx1, $fixedTerm, [
    'title'          => 'Post-term audit',
    'frequency'      => 'annual',
    'first_due_date' => Dates::addDays($today, 20),
    'end_date'       => Dates::addMonths($today, 14),
]);
$obligations->generateOccurrences($ctx1, (int) $postTerm['id']);
assert_count(2, o_due_dates($pdo, (int) $postTerm['id']), 'an explicit end date overrides the contract term');

// ---------------------------------------------------------------------------
// due / overdue transitions, including the grace period
// ---------------------------------------------------------------------------

$facilities = o_contract($pdo, 1, 'CON-2026-000002', 'Facilities management', 'active');

$make = static function (string $title, int $daysFromToday, int $grace) use ($obligations, $ctx1, $facilities, $today): array {
    return $obligations->create($ctx1, $facilities, [
        'title'             => $title,
        'frequency'         => 'one_time',
        'first_due_date'    => Dates::addDays($today, $daysFromToday),
        'grace_period_days' => $grace,
        'owner_uuid'        => 'USER-D',
    ]);
};

$future    = $make('Insurance certificate', 10, 0);
$inGrace   = $make('Quarterly headcount report', -3, 5);
$graceEdge = $make('Cleaning audit', -5, 5);
$pastGrace = $make('Safety inspection', -10, 5);
$noGrace   = $make('Statutory filing', -1, 0);
$waived    = $make('Legacy return', -20, 0);

// A contract that is already active generates on create, and the new rows are
// born at the status their date deserves rather than uniformly 'upcoming'.
assert_same('upcoming', o_status($pdo, (int) o_first_occurrence($pdo, (int) $future['id'])['id']), 'a future date is born upcoming');
assert_same('due', o_status($pdo, (int) o_first_occurrence($pdo, (int) $inGrace['id'])['id']), 'a past date inside its grace is born due');
assert_same('overdue', o_status($pdo, (int) o_first_occurrence($pdo, (int) $pastGrace['id'])['id']), 'a date past its grace is born overdue');

$waivedOccurrence = (int) o_first_occurrence($pdo, (int) $waived['id'])['id'];
$obligations->updateOccurrenceStatus($ctx1, $waivedOccurrence, 'waived', 'Superseded by the 2026 addendum');
assert_same('waived', o_status($pdo, $waivedOccurrence), 'an occurrence can be waived');

// Rewind the live rows so the sweep has real work to do.
$pdo->prepare("UPDATE obligation_occurrences SET status = 'upcoming' WHERE contract_id = ? AND status IN ('due','overdue')")
    ->execute([$facilities]);

$swept = $obligations->refreshDueStatuses('sandbox', 1);
assert_same(2, $swept['due'], 'two occurrences reached their due date without exhausting grace');
assert_same(2, $swept['overdue'], 'two occurrences are past due date plus grace');

assert_same('upcoming', o_status($pdo, (int) o_first_occurrence($pdo, (int) $future['id'])['id']), 'a future occurrence is left alone');
assert_same('due', o_status($pdo, (int) o_first_occurrence($pdo, (int) $inGrace['id'])['id']), 'three days late with five days grace is due, not overdue');
assert_same('due', o_status($pdo, (int) o_first_occurrence($pdo, (int) $graceEdge['id'])['id']), 'the last day of grace is still only due');
assert_same('overdue', o_status($pdo, (int) o_first_occurrence($pdo, (int) $pastGrace['id'])['id']), 'past the grace period is overdue');
assert_same('overdue', o_status($pdo, (int) o_first_occurrence($pdo, (int) $noGrace['id'])['id']), 'with no grace, one day late is overdue');
assert_same('waived', o_status($pdo, $waivedOccurrence), 'the sweep does not reopen an occurrence that has an outcome');

$again = $obligations->refreshDueStatuses('sandbox', 1);
assert_same(['due' => 0, 'overdue' => 0], $again, 'the sweep is idempotent — running it again moves nothing');

$overdueOnly = $obligations->listOccurrences($ctx1, ['contract_id' => $facilities, 'overdue_only' => true], 50, 0);
assert_same(2, $overdueOnly['total'], 'overdue_only counts what has actually slipped past its grace');

$byOwner = $obligations->listOccurrences($ctx1, ['owner_uuid' => 'USER-D'], 50, 0);
assert_same(6, $byOwner['total'], 'occurrences can be filtered by the obligation owner');
assert_same(0, $obligations->listOccurrences($ctx1, ['owner_uuid' => 'NOBODY'], 50, 0)['total'], 'an owner with nothing assigned gets an empty page');

$window = $obligations->listOccurrences($ctx1, [
    'contract_id' => $facilities,
    'due_from'    => Dates::addDays($today, -6),
    'due_to'      => $today,
], 50, 0);
assert_same(3, $window['total'], 'a due-date window bounds the page on both sides');

$byStatus = $obligations->listOccurrences($ctx1, ['contract_id' => $facilities, 'status' => 'waived'], 50, 0);
assert_same(1, $byStatus['total'], 'occurrences can be filtered by status');
assert_same('Legacy return', $byStatus['items'][0]['obligation_title'], 'the page carries the obligation title');

// ---------------------------------------------------------------------------
// Completion writes evidence
// ---------------------------------------------------------------------------

$dpa = o_contract($pdo, 1, 'CON-2026-000003', 'Data processing agreement', 'active');

$docSt = $pdo->prepare(
    'INSERT INTO contract_documents (environment, cmp_id, contract_id, doc_kind, title)
     VALUES (?, ?, ?, ?, ?) RETURNING id'
);
$docSt->execute(['sandbox', 1, $dpa, 'evidence', 'ISO 27001 certificate']);
$documentId = (int) $docSt->fetchColumn();

$audit = $obligations->create($ctx1, $dpa, [
    'title'             => 'Annual security audit',
    'frequency'         => 'annual',
    'first_due_date'    => Dates::addDays($today, -2),
    'grace_period_days' => 10,
    'evidence_required' => true,
    'amount'            => '2500',
]);

assert_true($audit['evidence_required'], 'evidence_required survives the round trip as a real boolean');
assert_same('2500.00', $audit['amount'], 'an amount is stored as a string, not a float');
assert_same('INR', $audit['currency'], 'the company currency is filled in beside an amount');

$auditOccurrence = (int) o_first_occurrence($pdo, (int) $audit['id'])['id'];

assert_throws(
    static fn () => $obligations->completeOccurrence($ctx1, $auditOccurrence, ['completion_note' => 'Done, trust me']),
    'an obligation that requires evidence refuses a bare completion',
    'needs evidence'
);

$completed = $obligations->completeOccurrence($ctx1, $auditOccurrence, [
    'completion_note' => 'Audit passed with no major findings.',
    'amount'          => '2500',
    'document_id'     => $documentId,
    'evidence_note'   => 'Certificate issued 2026-08-30',
]);

assert_same('completed', $completed['status'], 'the occurrence is completed');
assert_same('USER-A', $completed['completed_by'], 'the completing user is recorded');
assert_not_null($completed['completed_at'], 'the completion time is recorded');

$evidence = $obligations->listEvidence($ctx1, $auditOccurrence);
assert_count(1, $evidence, 'completion wrote exactly one evidence row');
assert_same($documentId, $evidence[0]['document_id'], 'the evidence points at the document supplied');
assert_same('Certificate issued 2026-08-30', $evidence[0]['note'], 'the evidence note is stored');
assert_same('USER-A', $evidence[0]['uploaded_by'], 'the evidence records who filed it');

assert_throws(
    static fn () => $obligations->completeOccurrence($ctx1, $auditOccurrence, ['document_id' => $documentId]),
    'an occurrence cannot be completed twice',
    'already recorded as completed'
);

assert_throws(
    static fn () => $obligations->updateOccurrenceStatus($ctx1, $auditOccurrence, 'completed', null),
    'completion cannot be reached by setting the status directly',
    'Record the completion with its evidence'
);

// A recurring obligation is not finished because one occurrence of it is.
assert_true(
    in_array((string) $obligations->findOrFail($ctx1, (int) $audit['id'])['status'], ['upcoming', 'due', 'overdue'], true),
    'an annual obligation stays open while later occurrences are outstanding'
);

// An obligation with a recorded outcome is compliance history, not a mistake.
assert_throws(
    static fn () => $obligations->delete($ctx1, (int) $audit['id']),
    'an obligation with a recorded outcome cannot be deleted',
    'Deactivate it instead'
);
$obligations->delete($ctx1, (int) $inactive['id']);
assert_null($obligations->find($ctx1, (int) $inactive['id']), 'an obligation that never produced an outcome can be deleted');

// ---------------------------------------------------------------------------
// Editing the recurrence resyncs only untouched future work
// ---------------------------------------------------------------------------

$reshaped = $obligations->update($ctx1, (int) $reconciliation['id'], [
    'frequency'      => 'quarterly',
    'first_due_date' => '2026-01-31',
]);
assert_same('quarterly', $reshaped['frequency'], 'the recurrence was changed');
assert_same(
    ['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'],
    o_due_dates($pdo, (int) $reconciliation['id']),
    'dates already due or overdue survive a change of recurrence'
);

// ---------------------------------------------------------------------------
// Company 2 is invisible to company 1
// ---------------------------------------------------------------------------

$theirs = o_contract($pdo, 2, 'CON-2026-000001', 'Their master agreement');

$theirObligation = $obligations->create($ctx2, $theirs, [
    'title'          => 'Their monthly report',
    'frequency'      => 'monthly',
    'first_due_date' => '2026-01-31',
]);
$theirId = (int) $theirObligation['id'];
$obligations->generateOccurrences($ctx2, $theirId, '2026-04-30');
$theirOccurrence = (int) o_first_occurrence($pdo, $theirId)['id'];

assert_not_null($obligations->find($ctx2, $theirId), 'company 2 can read its own obligation');
assert_null($obligations->find($ctx1, $theirId), 'company 1 cannot read company 2\'s obligation');
assert_count(0, $obligations->listForContract($ctx1, $theirs), 'company 1 sees no obligations on company 2\'s contract');
assert_count(1, $obligations->listForContract($ctx2, $theirs), 'company 2 sees its own');

assert_throws(
    static fn () => $obligations->create($ctx1, $theirs, ['title' => 'Injected obligation']),
    'company 1 cannot add an obligation to company 2\'s contract',
    'Contract not found'
);
assert_throws(
    static fn () => $obligations->update($ctx1, $theirId, ['title' => 'Rewritten']),
    'company 1 cannot edit company 2\'s obligation',
    'Obligation not found'
);
assert_throws(
    static fn () => $obligations->delete($ctx1, $theirId),
    'company 1 cannot delete company 2\'s obligation',
    'Obligation not found'
);
assert_throws(
    static fn () => $obligations->generateOccurrences($ctx1, $theirId),
    'company 1 cannot drive generation for company 2',
    'Obligation not found'
);
assert_throws(
    static fn () => $obligations->completeOccurrence($ctx1, $theirOccurrence, ['completion_note' => 'x']),
    'company 1 cannot complete company 2\'s occurrence',
    'occurrence not found'
);
assert_throws(
    static fn () => $obligations->updateOccurrenceStatus($ctx1, $theirOccurrence, 'waived', null),
    'company 1 cannot restatus company 2\'s occurrence',
    'occurrence not found'
);

assert_same(0, $obligations->listOccurrences($ctx1, ['contract_id' => $theirs], 50, 0)['total'], 'a contract id from another company yields nothing');
assert_same(4, $obligations->listOccurrences($ctx2, ['contract_id' => $theirs], 50, 0)['total'], 'company 2 sees its own occurrences');
assert_same(0, $obligations->summaryForContract($ctx1, $theirs)['total'], 'the summary is tenant scoped');
assert_same(4, $obligations->summaryForContract($ctx2, $theirs)['total'], 'company 2 gets its own summary');

// Evidence filed by another company is not usable, even with a real id.
$foreignDoc = $pdo->prepare('INSERT INTO contract_documents (environment, cmp_id, contract_id, doc_kind, title) VALUES (?,?,?,?,?) RETURNING id');
$foreignDoc->execute(['sandbox', 2, $theirs, 'evidence', 'Their certificate']);
$foreignDocumentId = (int) $foreignDoc->fetchColumn();

$open = $obligations->create($ctx1, $dpa, ['title' => 'Quarterly pen test', 'frequency' => 'one_time', 'first_due_date' => $today]);
assert_throws(
    static fn () => $obligations->completeOccurrence($ctx1, (int) o_first_occurrence($pdo, (int) $open['id'])['id'], ['document_id' => $foreignDocumentId]),
    'a document from another company cannot be filed as evidence'
);

// The sweep is scoped too: company 2's rows are untouched by a company 1 run.
$pdo->prepare("UPDATE obligation_occurrences SET status = 'upcoming' WHERE cmp_id = 2")->execute();
$obligations->refreshDueStatuses('sandbox', 1);
assert_same('upcoming', o_status($pdo, $theirOccurrence), 'a company-scoped sweep leaves other companies alone');

$envWide = $obligations->refreshDueStatuses('sandbox');
assert_same(4, $envWide['overdue'], 'an environment-wide sweep reaches every company');
assert_same('overdue', o_status($pdo, $theirOccurrence), 'company 2\'s occurrence ages on its own sweep');

// ---------------------------------------------------------------------------
// Milestones
// ---------------------------------------------------------------------------

$delivery = $milestones->create($ctx1, $dpa, [
    'title'          => 'Platform delivery',
    'due_date'       => Dates::addDays($today, -2),
    'milestone_type' => 'delivery',
    'owner_uuid'     => 'USER-A',
]);
$acceptance = $milestones->create($ctx1, $dpa, [
    'title'         => 'Customer acceptance',
    'due_date'      => Dates::addDays($today, 5),
    'depends_on_id' => (int) $delivery['id'],
    'amount'        => '150000',
]);

assert_same('pending', $delivery['status'], 'a new milestone is pending');
assert_same('INR', $acceptance['currency'], 'a payment milestone takes the company currency');
assert_count(2, $milestones->listForContract($ctx1, $dpa), 'both milestones are listed for the contract');

assert_throws(
    static fn () => $milestones->complete($ctx1, (int) $acceptance['id']),
    'a dependent milestone cannot be completed before the one it waits on',
    'depends on it'
);

$deliveryDone = $milestones->complete($ctx1, (int) $delivery['id'], ['completion_note' => 'Signed off by the steering group']);
assert_same('completed', $deliveryDone['status'], 'the milestone completes');
assert_same('USER-A', $deliveryDone['completed_by'], 'the completing user is recorded');

$acceptanceDone = $milestones->complete($ctx1, (int) $acceptance['id']);
assert_same('completed', $acceptanceDone['status'], 'the dependant completes once its predecessor has');

assert_throws(
    static fn () => $milestones->complete($ctx1, (int) $acceptance['id']),
    'a milestone cannot be completed twice',
    'already recorded as completed'
);
assert_throws(
    static fn () => $milestones->delete($ctx1, (int) $delivery['id']),
    'a completed milestone cannot be deleted',
    'Cancel it instead'
);
assert_throws(
    static fn () => $milestones->update($ctx1, (int) $delivery['id'], ['depends_on_id' => (int) $acceptance['id']]),
    'a dependency cycle is refused'
);

$training = $milestones->create($ctx1, $dpa, ['title' => 'Admin training', 'due_date' => Dates::addDays($today, -1)]);
$upcoming = $milestones->create($ctx1, $dpa, ['title' => 'First invoice', 'due_date' => Dates::addDays($today, 30)]);

$missed = $milestones->refreshDueStatuses('sandbox', 1);
assert_same(1, $missed['missed'], 'a pending milestone whose date has passed is missed');
assert_same('missed', (string) $milestones->findOrFail($ctx1, (int) $training['id'])['status'], 'the missed milestone carries the new status');
assert_same('pending', (string) $milestones->findOrFail($ctx1, (int) $upcoming['id'])['status'], 'a future milestone is left alone');
assert_same(0, $milestones->refreshDueStatuses('sandbox', 1)['missed'], 'the milestone sweep is idempotent');
assert_same('completed', (string) $milestones->findOrFail($ctx1, (int) $delivery['id'])['status'], 'the sweep does not touch a completed milestone');

$theirMilestone = $milestones->create($ctx2, $theirs, ['title' => 'Their kickoff', 'due_date' => Dates::addDays($today, -3)]);
assert_null($milestones->find($ctx1, (int) $theirMilestone['id']), 'company 1 cannot read company 2\'s milestone');
assert_count(0, $milestones->listForContract($ctx1, $theirs), 'company 1 sees no milestones on company 2\'s contract');
assert_throws(
    static fn () => $milestones->complete($ctx1, (int) $theirMilestone['id']),
    'company 1 cannot complete company 2\'s milestone',
    'Milestone not found'
);
assert_same('pending', (string) $milestones->findOrFail($ctx2, (int) $theirMilestone['id'])['status'], 'a company 1 sweep left company 2\'s overdue milestone alone');

t_done('ObligationServiceTest');
