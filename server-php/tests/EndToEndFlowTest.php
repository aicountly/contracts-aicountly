<?php

declare(strict_types=1);

/**
 * The flows from the product brief, end to end through the services.
 *
 * These are not unit tests of one method — they walk a contract through the
 * lifecycle the way a company would, and assert the state at each step. They
 * are the closest thing this suite has to "does the product actually work",
 * and they are the first place to look when a refactor breaks something
 * subtle.
 *
 * Flow B (contract request), Flow F (grounded Q&A) and the AI half of Flow A
 * need services and a provider that are not exercised without a network, and
 * are covered elsewhere or listed in IMPLEMENTATION_STATUS.md.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ApprovalService;
use App\Services\CompanyBootstrapService;
use App\Services\ContractService;
use App\Services\ObligationService;
use App\Services\RenewalService;
use App\Services\RiskEngine;
use App\Support\Dates;
use App\Support\Permissions;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured');
}
t_reset_database($pdo);
CompanyBootstrapService::resetMemo();

$ctx       = t_context(cmpId: 1, uuid: 'ALICE');
$contracts = new ContractService($pdo);

// ---------------------------------------------------------------------------
// A new company gets a working configuration on first use
// ---------------------------------------------------------------------------
$bootstrap = new CompanyBootstrapService($pdo);
assert_true($bootstrap->ensure('sandbox', 1, 'INR'), 'the first touch seeds the company');
assert_false($bootstrap->ensure('sandbox', 1, 'INR'), 'seeding is idempotent within a process');

CompanyBootstrapService::resetMemo();
assert_false($bootstrap->ensure('sandbox', 1, 'INR'), 'seeding is idempotent across processes too');

$typeCount = (int) $pdo->query("SELECT COUNT(*) FROM contract_types WHERE cmp_id = 1")->fetchColumn();
assert_true($typeCount >= 29, "the company got the seeded contract types (got {$typeCount})");

$clauseCount = (int) $pdo->query("SELECT COUNT(*) FROM clause_library WHERE cmp_id = 1")->fetchColumn();
assert_true($clauseCount >= 19, "the company got the seeded clause library (got {$clauseCount})");

$ruleCount = (int) $pdo->query("SELECT COUNT(*) FROM contract_risk_rules WHERE cmp_id = 1")->fetchColumn();
assert_true($ruleCount >= 17, "the company got the seeded risk rules (got {$ruleCount})");

// Seeds belong to the company, not to a shared row every tenant reads.
$otherCompanyTypes = (int) $pdo->query("SELECT COUNT(*) FROM contract_types WHERE cmp_id = 0")->fetchColumn();
assert_same(0, $otherCompanyTypes, 'nothing was seeded against a shared cmp_id = 0 row');

// ---------------------------------------------------------------------------
// Flow A — an existing contract is brought in and becomes a live record
// ---------------------------------------------------------------------------
$vendorType = (int) $pdo->query(
    "SELECT id FROM contract_types WHERE cmp_id = 1 AND code = 'vendor_agreement'"
)->fetchColumn();

$expiry = Dates::addMonths(Dates::today(), 14);

$contract = $contracts->create($ctx, [
    'title'              => 'Vendor Services Agreement — Acme Industries',
    'contract_type_id'   => $vendorType,
    'counterparty_name'  => 'Acme Industries Pvt Ltd',
    'source'             => 'uploaded',
    'effective_date'     => Dates::addMonths(Dates::today(), -10),
    'expiry_date'        => $expiry,
    'notice_period_days' => 90,
    'auto_renewal'       => true,
    'renewal_type'       => 'auto_renew',
    'renewal_frequency'  => 'annual',
    'currency'           => 'INR',
    'total_value'        => '1200000.00',
    'governing_law'      => 'India',
]);

$contractId = (int) $contract['id'];

assert_same('CON-' . date('Y') . '-000001', $contract['contract_number'], 'the contract was numbered');
assert_same('draft', $contract['status'], 'it starts as a draft');
assert_true($contract['auto_renewal'], 'auto_renewal survived the PostgreSQL boolean round trip');

// The notice deadline is derived and stored, because the nightly sweep filters
// on it across every tenant.
assert_same(
    Dates::addDays($expiry, -90),
    $contract['notice_deadline'],
    'the notice deadline is expiry minus the notice period'
);

// ---------------------------------------------------------------------------
// Flow C — approval
// ---------------------------------------------------------------------------
$pdo->prepare(
    'INSERT INTO approval_workflows (environment, cmp_id, name, applies_to, conditions, match_mode, priority)
     VALUES (?, ?, ?, ?, ?::jsonb, ?, ?) RETURNING id'
)->execute(['sandbox', 1, 'High value', 'contract',
    json_encode([['field' => 'total_value', 'operator' => 'gte', 'value' => 1000000]]), 'all', 10]);
$workflowId = (int) $pdo->query('SELECT id FROM approval_workflows ORDER BY id DESC LIMIT 1')->fetchColumn();

$pdo->prepare(
    'INSERT INTO approval_workflow_steps
     (workflow_id, environment, cmp_id, step_no, name, execution, approver_type, approver_value, min_approvals)
     VALUES (?, ?, ?, 1, ?, ?, ?, ?, 1)'
)->execute([$workflowId, 'sandbox', 1, 'Legal review', 'sequential', 'user', 'BOB']);

$approvals = new ApprovalService($pdo);
$instance  = $approvals->submit($ctx, 'contract', $contractId, $contractId);

assert_not_null($instance, 'a matching workflow produced an approval instance');
assert_same(
    'awaiting_approval',
    (string) $contracts->findOrFail($ctx, $contractId)['status'],
    'submitting for approval moved the contract to awaiting_approval'
);

// Someone who is not the assignee cannot approve on their behalf.
$carol = t_context(cmpId: 1, uuid: 'CAROL', permissions: [
    Permissions::CONTRACT_VIEW, Permissions::CONTRACT_VIEW_ALL, Permissions::APPROVAL_ACT,
]);
assert_throws(
    static fn () => $approvals->act($carol, (int) $instance['id'], 'approve', []),
    'a non-assignee cannot approve the step'
);

$bob = t_context(cmpId: 1, uuid: 'BOB', permissions: [
    Permissions::CONTRACT_VIEW, Permissions::CONTRACT_VIEW_ALL, Permissions::APPROVAL_ACT,
]);
$approvals->act($bob, (int) $instance['id'], 'approve', ['comment' => 'Terms are acceptable.']);

$afterApproval = $contracts->findOrFail($ctx, $contractId);
assert_same('approved', (string) $afterApproval['status'], 'the contract is approved once the last step approves');
assert_same('approved', (string) $afterApproval['approval_status'], 'approval_status followed');

$actions = $pdo->prepare('SELECT action, actor_uuid FROM contract_approval_actions WHERE instance_id = ?');
$actions->execute([(int) $instance['id']]);
$recorded = $actions->fetchAll();
assert_true(count($recorded) >= 1, 'the approval action was recorded');
assert_same('approve', (string) $recorded[0]['action'], 'the recorded action is the one taken');
assert_same('BOB', (string) $recorded[0]['actor_uuid'], 'the actor is recorded');

// ---------------------------------------------------------------------------
// Flow D — obligations begin when the contract goes live
// ---------------------------------------------------------------------------
$obligations = new ObligationService($pdo);

$obligation = $obligations->create($ctx, $contractId, [
    'title'             => 'Monthly service report',
    'obligation_type'   => 'report',
    'responsible_party' => 'counterparty',
    'owner_uuid'        => 'ALICE',
    'frequency'         => 'monthly',
    'start_date'        => Dates::addMonths(Dates::today(), -3),
    'first_due_date'    => Dates::addMonths(Dates::today(), -3),
    'grace_period_days' => 5,
    'evidence_required' => true,
]);

$contracts->changeStatus($ctx, $contractId, 'active', 'Executed copy received.');

$live = $contracts->findOrFail($ctx, $contractId);
assert_same('active', (string) $live['status'], 'the contract is active');
assert_same('active', (string) $live['lifecycle_stage'], 'the lifecycle stage followed the status');

$occurrences = (int) $pdo->prepare(
    'SELECT COUNT(*) FROM obligation_occurrences WHERE obligation_id = ?'
)->execute([(int) $obligation['id']]) ? (int) $pdo->query(
    'SELECT COUNT(*) FROM obligation_occurrences WHERE obligation_id = ' . (int) $obligation['id']
)->fetchColumn() : 0;

assert_true($occurrences > 0, "going active materialised obligation occurrences (got {$occurrences})");

// Generation assigns the right status immediately rather than leaving a
// back-dated occurrence sitting as "upcoming" until the next nightly run.
$statuses = $pdo->query(
    'SELECT due_date, status FROM obligation_occurrences
     WHERE obligation_id = ' . (int) $obligation['id'] . ' ORDER BY due_date'
)->fetchAll();

$today = Dates::today();
foreach ($statuses as $row) {
    $due    = (string) $row['due_date'];
    $status = (string) $row['status'];

    if ($due < $today) {
        assert_true(
            in_array($status, ['due', 'overdue'], true),
            "an occurrence due {$due} is not still upcoming (is {$status})"
        );
    } elseif ($due > $today) {
        assert_same('upcoming', $status, "a future occurrence due {$due} is upcoming");
    }
}

// The sweep therefore has nothing to do, and says so — which is what makes it
// safe on a cron that is not exactly-once.
$refreshed = $obligations->refreshDueStatuses('sandbox', 1);
assert_same(0, $refreshed['due'] ?? 0, 'a correctly generated set needs no due transition');
assert_same(0, $refreshed['overdue'] ?? 0, 'a correctly generated set needs no overdue transition');

// Back-date one past its grace period and confirm the sweep does act.
$stale = (int) $pdo->query(
    'SELECT id FROM obligation_occurrences
     WHERE obligation_id = ' . (int) $obligation['id'] . " AND status = 'upcoming'
     ORDER BY due_date LIMIT 1"
)->fetchColumn();

$pdo->prepare(
    "UPDATE obligation_occurrences
     SET due_date = CURRENT_DATE - 10, grace_until = CURRENT_DATE - 5
     WHERE id = ?"
)->execute([$stale]);

$afterBackdate = $obligations->refreshDueStatuses('sandbox', 1);
assert_true(
    ($afterBackdate['due'] ?? 0) + ($afterBackdate['overdue'] ?? 0) > 0,
    'the sweep transitions an occurrence whose due date has passed'
);

assert_same(
    'overdue',
    (string) $pdo->query('SELECT status FROM obligation_occurrences WHERE id = ' . $stale)->fetchColumn(),
    'past the grace period, the occurrence is overdue rather than merely due'
);

// And running it again changes nothing.
$secondRun = $obligations->refreshDueStatuses('sandbox', 1);
assert_same(0, $secondRun['due'] ?? 0, 're-running the sweep marks nothing else due');
assert_same(0, $secondRun['overdue'] ?? 0, 're-running the sweep marks nothing else overdue');

// Completing one records who and when.
$firstOccurrence = (int) $pdo->query(
    'SELECT id FROM obligation_occurrences WHERE obligation_id = ' . (int) $obligation['id']
    . ' ORDER BY due_date LIMIT 1'
)->fetchColumn();

// This obligation requires evidence, so completing it without any is refused.
// An obligation that can be ticked off with no proof is a checkbox, not a
// compliance record.
assert_throws(
    static fn () => $obligations->completeOccurrence($ctx, $firstOccurrence, [
        'completion_note' => 'Trust me.',
    ]),
    'an evidence-required obligation cannot be completed without evidence',
    'evidence'
);

$completed = $obligations->completeOccurrence($ctx, $firstOccurrence, [
    'completion_note' => 'Report filed.',
    'evidence_note'   => 'Emailed to the vendor contact on the due date.',
]);
assert_same('completed', (string) $completed['status'], 'the occurrence is completed once evidence is supplied');
assert_same('ALICE', (string) $completed['completed_by'], 'the completer is recorded');

$evidenceCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM obligation_evidence WHERE occurrence_id = ' . $firstOccurrence
)->fetchColumn();
assert_true($evidenceCount > 0, 'the evidence was recorded against the occurrence');

// ---------------------------------------------------------------------------
// Flow E — renewal
// ---------------------------------------------------------------------------
$renewals = new RenewalService($pdo);

$cycles = $renewals->listForContract($ctx, $contractId);
assert_count(1, $cycles, 'going active opened the first renewal cycle');
assert_same(1, (int) $cycles[0]['cycle_no'], 'it is cycle 1');
assert_same(
    Dates::addDays($expiry, -90),
    (string) $cycles[0]['notice_deadline'],
    'the cycle carries the contract notice deadline'
);

// Idempotent — a second call must not open a duplicate cycle.
$renewals->ensureCycle($ctx, $contractId);
assert_count(1, $renewals->listForContract($ctx, $contractId), 'ensureCycle does not duplicate a cycle');

$decision = $renewals->recordDecision($ctx, (int) $cycles[0]['id'], 'renew', [
    'renewal_term_months' => 12,
    'notes'               => 'Performance has been good.',
]);
assert_same('renewed', (string) $decision['status'], 'the cycle is marked renewed');

$afterRenewal = $contracts->findOrFail($ctx, $contractId);
assert_true(
    (string) $afterRenewal['expiry_date'] > $expiry,
    'renewing extended the contract expiry'
);

$cyclesAfter = $renewals->listForContract($ctx, $contractId);
assert_count(2, $cyclesAfter, 'renewing opened the next cycle');

// ---------------------------------------------------------------------------
// Risk — deterministic, and reproducible
// ---------------------------------------------------------------------------
$risk       = new RiskEngine($pdo);
$assessment = $risk->assess($ctx, $contractId);

assert_not_null($assessment, 'the contract was assessed');
assert_true(
    ($assessment['findings'] ?? []) !== [],
    'the seeded rules found something on a contract with auto-renewal and no documents'
);

$scored = $contracts->findOrFail($ctx, $contractId);
assert_not_null($scored['ai_risk_score'], 'the score was written back to the contract');
assert_not_null($scored['risk_level'], 'the level was written back to the contract');

// An auto-renewing contract with a 90-day notice period is exactly what the
// renewal-risk rule exists to catch.
$findingKeys = array_map(
    static fn (array $f): string => (string) ($f['rule_key'] ?? ''),
    $assessment['findings'] ?? []
);
assert_true(
    in_array('auto_renewal_long_notice', $findingKeys, true) || in_array('auto_renewal_present', $findingKeys, true),
    'the auto-renewal risk was raised (' . implode(', ', $findingKeys) . ')'
);

// Re-assessing demotes the previous assessment rather than leaving two current.
$risk->assess($ctx, $contractId);
$currentCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM contract_risk_assessments WHERE contract_id = ' . $contractId . ' AND is_current'
)->fetchColumn();
assert_same(1, $currentCount, 'exactly one assessment is current after re-running');

// ---------------------------------------------------------------------------
// The audit trail exists, and says what happened
// ---------------------------------------------------------------------------
$audit = $pdo->prepare(
    'SELECT action FROM contract_audit_logs WHERE contract_id = ? ORDER BY id'
);
$audit->execute([$contractId]);
$actionsLogged = array_map(static fn (array $r): string => (string) $r['action'], $audit->fetchAll());

assert_true(in_array('contract.created', $actionsLogged, true), 'creation is audited');
assert_true(in_array('contract.status_changed', $actionsLogged, true), 'the status change is audited');

$activity = $pdo->prepare(
    'SELECT event_type FROM contract_activity_logs WHERE contract_id = ? ORDER BY id'
);
$activity->execute([$contractId]);
$events = array_map(static fn (array $r): string => (string) $r['event_type'], $activity->fetchAll());

assert_true(in_array('contract.created', $events, true), 'the timeline records creation');
assert_true(
    in_array('contract.status.active', $events, true),
    'the timeline records the contract going live'
);

// ---------------------------------------------------------------------------
// An executed contract is amended, never edited in place
// ---------------------------------------------------------------------------
$contracts->changeStatus($ctx, $contractId, 'terminated', 'Ended by mutual agreement.');

assert_throws(
    static fn () => $contracts->update($ctx, $contractId, ['title' => 'Rewritten after the fact']),
    'an ended contract cannot be edited in place',
    'amendment'
);

t_done('EndToEndFlowTest');
