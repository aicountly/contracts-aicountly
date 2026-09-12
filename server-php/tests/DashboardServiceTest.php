<?php

declare(strict_types=1);

/**
 * The landing screen's four reads.
 *
 * Two things are being defended here. The first is arithmetic: every figure is
 * an aggregate over the whole matching set, so a wrong predicate shows up as a
 * number that is plausible and false rather than as an error. The second is
 * scope — a KPI that counts rows the caller cannot open is a data leak wearing
 * a number, so the counts are checked from a second company's context and from
 * a user who holds CONTRACT_VIEW but not CONTRACT_VIEW_ALL.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractService;
use App\Services\DashboardService;
use App\Support\Permissions;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured');
}
t_reset_database($pdo);

$contracts = new ContractService($pdo);
$dashboard = new DashboardService($pdo);

$alice = t_context(cmpId: 1, uuid: 'ALICE');
$bob   = t_context(cmpId: 2, uuid: 'BOB');

/** A user who may view contracts but not all of them. */
$carol = t_context(
    cmpId: 1,
    uuid: 'CAROL',
    permissions: [Permissions::CONTRACT_VIEW, Permissions::AI_USE],
    roles: ['read_only']
);

$today     = date('Y-m-d');
$inDays    = static fn (int $days): string => date('Y-m-d', strtotime("{$days} days"));
$thisMonth = date('Y-m');

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$pdo->prepare('INSERT INTO contract_settings (environment, cmp_id, default_currency) VALUES (?, ?, ?), (?, ?, ?)')
    ->execute(['sandbox', 1, 'INR', 'sandbox', 2, 'INR']);

$type = static function (int $cmpId, string $code, string $name, string $category, string $side) use ($pdo): int {
    $st = $pdo->prepare(
        'INSERT INTO contract_types (environment, cmp_id, code, name, category, counterparty_side)
         VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $code, $name, $category, $side]);

    return (int) $st->fetchColumn();
};

$customerType = $type(1, 'customer_agreement', 'Customer Agreement', 'revenue', 'customer');
$vendorType   = $type(1, 'vendor_agreement', 'Vendor Agreement', 'procurement', 'vendor');
$bobType      = $type(2, 'customer_agreement', 'Customer Agreement', 'revenue', 'customer');

$st = $pdo->prepare(
    'INSERT INTO contract_departments (environment, cmp_id, name, code) VALUES (?, ?, ?, ?) RETURNING id'
);
$st->execute(['sandbox', 1, 'Legal', 'LEG']);
$legal = (int) $st->fetchColumn();

// Company 1: a customer contract awaiting approval, expiring inside the alert
// window, and a signed vendor contract that is live.
$acme = $contracts->create($alice, [
    'title'             => 'Acme Master Services Agreement',
    'contract_type_id'  => $customerType,
    'department_id'     => $legal,
    'counterparty_name' => 'Acme Industries',
    'effective_date'    => '2026-01-01',
    'expiry_date'       => $inDays(45),
    'total_value'       => '1200000.00',
    'currency'          => 'INR',
]);
$acmeId = (int) $acme['id'];
$contracts->changeStatus($alice, $acmeId, 'under_review');
$contracts->changeStatus($alice, $acmeId, 'awaiting_approval');
// risk_level is written by RiskEngine, not by the contract form.
$pdo->prepare('UPDATE contracts SET risk_level = ?, ai_risk_score = ? WHERE id = ?')
    ->execute(['high', 82, $acmeId]);

$bolt = $contracts->create($alice, [
    'title'             => 'Bolt Supplies Agreement',
    'contract_type_id'  => $vendorType,
    'counterparty_name' => 'Bolt Supplies',
    'effective_date'    => '2026-02-01',
    'expiry_date'       => $inDays(400),
    'total_value'       => '300000.00',
    'currency'          => 'INR',
]);
$boltId = (int) $bolt['id'];
$pdo->prepare("UPDATE contracts SET status = 'active', lifecycle_stage = 'active', execution_date = ?, risk_level = ? WHERE id = ?")
    ->execute([$today, 'low', $boltId]);

// A contract in a currency the company does not report in, to prove the money
// figures do not silently add two currencies together.
$euro = $contracts->create($alice, [
    'title'          => 'Zurich Licence',
    'effective_date' => '2026-03-01',
    'total_value'    => '900000.00',
    'currency'       => 'EUR',
]);

// A contract belonging to someone else, for the visibility checks.
$carolBlind = $contracts->create($alice, [
    'title'          => 'Someone Else Agreement',
    'owner_uuid'     => 'DANA',
    'effective_date' => '2026-01-15',
    'total_value'    => '77000.00',
]);
$pdo->prepare('UPDATE contracts SET created_by = ? WHERE id = ?')->execute(['DANA', (int) $carolBlind['id']]);

// An archived contract, which the repository hides by default and so must the
// dashboard — a tile that counts it links to a list that does not show it.
$old = $contracts->create($alice, ['title' => 'Retired Agreement', 'total_value' => '5000.00']);
$pdo->prepare('UPDATE contracts SET archived_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([(int) $old['id']]);

// Company 2's lookalike row: same title, same shape, different tenant.
$bobContract = $contracts->create($bob, [
    'title'             => 'Acme Master Services Agreement',
    'contract_type_id'  => $bobType,
    'counterparty_name' => 'Acme Industries',
    'effective_date'    => '2026-01-01',
    'expiry_date'       => $inDays(45),
    'total_value'       => '9900000.00',
    'currency'          => 'INR',
]);

// Obligations: one due, one overdue, one settled.
$st = $pdo->prepare(
    'INSERT INTO contract_obligations (environment, cmp_id, contract_id, title, owner_uuid, frequency, first_due_date, amount, currency)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
);
$st->execute(['sandbox', 1, $boltId, 'Quarterly SLA report', 'ALICE', 'quarterly', $today, '25000.00', 'INR']);
$obligationId = (int) $st->fetchColumn();

$occurrence = $pdo->prepare(
    'INSERT INTO obligation_occurrences (obligation_id, contract_id, environment, cmp_id, sequence_no, due_date, status)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$occurrence->execute([$obligationId, $boltId, 'sandbox', 1, 1, $today, 'due']);
$occurrence->execute([$obligationId, $boltId, 'sandbox', 1, 2, $inDays(-20), 'overdue']);
$occurrence->execute([$obligationId, $boltId, 'sandbox', 1, 3, $inDays(-90), 'completed']);

// Renewals: one open decision, one already taken.
$renewal = $pdo->prepare(
    'INSERT INTO contract_renewals
     (environment, cmp_id, contract_id, cycle_no, current_expiry, decision_due_date, status, owner_uuid, decision)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$renewal->execute(['sandbox', 1, $boltId, 1, $inDays(400), $inDays(30), 'review_due', 'ALICE', null]);
$renewal->execute(['sandbox', 1, $acmeId, 1, $inDays(45), $inDays(10), 'renew', 'ALICE', 'renew']);

// An approval waiting on Carol, which is one of the three things that make a
// contract visible to her.
$st = $pdo->prepare(
    'INSERT INTO contract_approval_instances
     (environment, cmp_id, subject_type, subject_id, contract_id, workflow_name, status, current_step, submitted_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
);
$st->execute(['sandbox', 1, 'contract', $acmeId, $acmeId, 'Standard approval', 'in_progress', 1, 'ALICE']);
$instanceId = (int) $st->fetchColumn();

$pdo->prepare(
    'INSERT INTO contract_approval_assignments
     (instance_id, environment, cmp_id, step_no, step_name, approver_uuid, status, due_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([$instanceId, 'sandbox', 1, 1, 'Legal review', 'CAROL', 'pending', $inDays(3) . ' 12:00:00']);

// A run that finished, so the throughput chart has a duration to average. The
// one still in flight above must not be counted as a fast approval.
$pdo->prepare(
    'INSERT INTO contract_approval_instances
     (environment, cmp_id, subject_type, subject_id, contract_id, workflow_name, status, current_step,
      submitted_by, submitted_at, completed_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute([
    'sandbox', 1, 'contract', $boltId, $boltId, 'Standard approval', 'approved', 1, 'ALICE',
    $inDays(-6) . ' 09:00:00', $inDays(-2) . ' 09:00:00',
]);

// AI extractions awaiting a human.
$extraction = $pdo->prepare(
    'INSERT INTO ai_extractions (environment, cmp_id, contract_id, field_key, extracted_value, confidence, review_state)
     VALUES (?, ?, ?, ?, ?, ?, ?)'
);
$extraction->execute(['sandbox', 1, $acmeId, 'governing_law', 'India', '0.410', 'pending']);
$extraction->execute(['sandbox', 1, $acmeId, 'jurisdiction', 'Mumbai', '0.620', 'pending']);
$extraction->execute(['sandbox', 1, $boltId, 'expiry_date', '2027-01-01', '0.950', 'accepted']);

// ---------------------------------------------------------------------------
// KPIs
// ---------------------------------------------------------------------------
$kpis = $dashboard->kpis($alice, []);

assert_same(4, $kpis['total_contracts'], 'the total counts every unarchived contract in this company');
assert_same(1, $kpis['active'], 'active counts the live contract');
assert_same(2, $kpis['draft'], 'draft counts the two contracts still in draft');
assert_same(1, $kpis['awaiting_approval'], 'awaiting approval counts the contract in approval');
assert_same(0, $kpis['awaiting_signature'], 'nothing is out for signature');
assert_same(1, $kpis['expiring_soon'], 'only the contract inside the alert window is expiring soon');
assert_same(90, $kpis['expiring_within_days'], 'the window is the widest rung of the default alert ladder');
assert_same(1, $kpis['renewals_due'], 'a decided renewal cycle is not still due');
assert_same(1, $kpis['obligations_due'], 'one occurrence is due');
assert_same(1, $kpis['overdue_obligations'], 'one occurrence is overdue');
assert_same(1, $kpis['high_risk'], 'one contract is flagged high risk');

assert_same('INR', $kpis['currency'], 'the money figures name the currency they were added up in');
assert_same(
    '1577000.00',
    $kpis['total_value'],
    'the total sums only the reporting currency — the euro contract is excluded, not converted'
);
assert_same('1200000.00', $kpis['receivable_commitments'], 'receivables are the customer-side contracts');
assert_same('300000.00', $kpis['payable_commitments'], 'payables are the vendor-side contracts');

// --- Tenant isolation -------------------------------------------------------
$bobKpis = $dashboard->kpis($bob, []);
assert_same(1, $bobKpis['total_contracts'], "company 2 sees only its own contract");
assert_same('9900000.00', $bobKpis['total_value'], "company 2's total is its own");
assert_same(0, $bobKpis['obligations_due'], "company 1's obligations do not reach company 2");
assert_same(0, $bobKpis['renewals_due'], "company 1's renewals do not reach company 2");

// --- Row-level visibility ---------------------------------------------------
$carolKpis = $dashboard->kpis($carol, []);
assert_same(
    1,
    $carolKpis['total_contracts'],
    'without view_all, Carol counts only the contract she was asked to approve'
);
assert_same(
    $carolKpis['total_contracts'],
    $contracts->search($carol, [], 50, 0)['total'],
    'the dashboard and the repository agree on what Carol may see'
);
assert_same(0, $carolKpis['obligations_due'], 'obligations on contracts Carol cannot open are not counted for her');
assert_same(0, $carolKpis['renewals_due'], 'renewals on contracts Carol cannot open are not counted for her');

// --- Withheld rather than zeroed -------------------------------------------
assert_null($carolKpis['total_value'], 'a caller without the commercial permission gets no money figure');
assert_null($carolKpis['receivable_commitments'], 'receivables are withheld too');
assert_null($carolKpis['payable_commitments'], 'payables are withheld too');
assert_null($carolKpis['high_risk'], 'the risk count is withheld without the risk permission');

// --- Filters ----------------------------------------------------------------
$filtered = $dashboard->kpis($alice, ['contract_type_id' => $customerType]);
assert_same(1, $filtered['total_contracts'], 'the type filter narrows the whole KPI row');
assert_same('1200000.00', $filtered['total_value'], 'the money figures are narrowed by the same filter');

assert_same(
    1,
    $dashboard->kpis($alice, ['department_id' => $legal])['total_contracts'],
    'the department filter narrows the portfolio'
);
assert_same(
    1,
    $dashboard->kpis($alice, ['counterparty' => 'acme'])['total_contracts'],
    'the counterparty filter matches on a fragment of the name'
);
assert_same(
    1,
    $dashboard->kpis($alice, ['status' => 'active'])['total_contracts'],
    'the status filter narrows the portfolio'
);
assert_same(
    1,
    $dashboard->kpis($alice, ['risk_level' => 'high'])['total_contracts'],
    'the risk filter narrows the portfolio'
);
assert_same(
    1,
    $dashboard->kpis($alice, ['owner_uuid' => 'DANA'])['total_contracts'],
    'the owner filter narrows the portfolio'
);
assert_same(
    2,
    $dashboard->kpis($alice, ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'])['total_contracts'],
    'the period narrows on the effective date, as the repository link does'
);
assert_same(
    0,
    $dashboard->kpis($alice, ['bo_id' => 4242])['total_contracts'],
    'a branch this company does not have matches nothing'
);

// ---------------------------------------------------------------------------
// Charts
// ---------------------------------------------------------------------------
$charts = $dashboard->charts($alice, []);

// The ten the SPA destructures, plus the two the product spec asks for on top
// of them. Every one of them is a list, whatever the caller may see.
foreach ([
    'by_status', 'by_type', 'by_department', 'value_by_category', 'expiry_timeline',
    'renewal_pipeline', 'risk_distribution', 'obligations_timeline', 'customer_vs_vendor',
    'monthly_executed', 'counterparty_mix', 'approval_throughput',
] as $series) {
    assert_true(isset($charts[$series]) && is_array($charts[$series]), "charts include {$series}");
}

$sum = static fn (array $points): int => array_sum(array_map(
    static fn (array $point): int => (int) ($point['count'] ?? 0),
    $points
));

assert_same(4, $sum($charts['by_status']), 'the status mix accounts for every contract in the portfolio');
assert_same(4, $sum($charts['by_type']), 'the type mix accounts for every contract, typed or not');
assert_same(4, $sum($charts['by_department']), 'the department mix accounts for every contract');
assert_same(4, $sum($charts['risk_distribution']), 'the risk mix accounts for every contract, assessed or not');
assert_same(4, $sum($charts['customer_vs_vendor']), 'every contract lands on one side of the table');

assert_same(
    ['draft', 'awaiting_approval', 'active'],
    array_values(array_unique(array_map(
        static fn (array $point): string => (string) $point['key'],
        $charts['by_status']
    ))),
    'the status mix comes back in lifecycle order, not by size'
);

assert_same('high', (string) $charts['risk_distribution'][1]['key'], 'the risk ladder runs low to critical');
assert_same(
    'unassessed',
    (string) $charts['risk_distribution'][2]['key'],
    'contracts nobody has assessed are named rather than dropped'
);

assert_same('customer', (string) $charts['customer_vs_vendor'][0]['key'], 'the customer side is first');
assert_same(
    'revenue',
    (string) $charts['value_by_category'][0]['key'],
    'value by category is ranked, biggest first'
);
assert_same(
    '1200000.00',
    (string) $charts['value_by_category'][0]['amount'],
    'category value is money, carried as a string'
);
assert_same('INR', (string) $charts['value_by_category'][0]['currency'], 'each money bucket names its currency');

assert_count(12, $charts['expiry_timeline'], 'the expiry runway is a full year of months');
assert_count(12, $charts['monthly_executed'], 'the executed timeline is a full year of months');
assert_same($thisMonth, (string) $charts['expiry_timeline'][0]['key'], 'the expiry runway starts this month');
assert_same(
    $thisMonth,
    (string) $charts['monthly_executed'][11]['key'],
    'the executed timeline ends with the current month'
);
assert_same(1, (int) $charts['monthly_executed'][11]['count'], 'the contract signed today lands in this month');

assert_count(13, $charts['obligations_timeline'], 'the obligation timeline is twelve months plus the overdue bucket');
assert_same('overdue', (string) $charts['obligations_timeline'][0]['key'], 'overdue work leads the obligation timeline');
assert_same(1, (int) $charts['obligations_timeline'][0]['count'], 'the overdue occurrence is in the overdue bucket');
assert_same(
    1,
    (int) $charts['obligations_timeline'][1]['count'],
    'the occurrence due today is in this month, and the completed one is in neither'
);

assert_count(2, $charts['renewal_pipeline'], 'a decided cycle still appears in the funnel, a closed one would not');
assert_same(
    ['review_due', 'renew'],
    array_map(static fn (array $point): string => (string) $point['key'], $charts['renewal_pipeline']),
    'the funnel runs in cycle order rather than by size'
);

assert_same(4, $sum($charts['counterparty_mix']), 'the counterparty mix accounts for every contract');
assert_same(
    'Unnamed',
    (string) $charts['counterparty_mix'][0]['label'],
    'the counterparty mix is ranked, and contracts with no counterparty are named rather than dropped'
);

assert_count(1, $charts['approval_throughput'], 'only the finished approval run has a duration');
assert_same(4, (int) $charts['approval_throughput'][0]['avg_days'], 'the run took four days from submission to close');
assert_same(1, (int) $charts['approval_throughput'][0]['count'], 'the in-flight run is not counted as a fast one');

$carolCharts = $dashboard->charts($carol, []);
assert_same([], $carolCharts['risk_distribution'], 'the risk chart is withheld without the risk permission');
assert_same([], $carolCharts['value_by_category'], 'the value chart is withheld without the commercial permission');
assert_same(1, $sum($carolCharts['by_status']), 'Carol charts only the contract she can open');
assert_count(12, $carolCharts['expiry_timeline'], 'the month grid is drawn even when the caller sees almost nothing');

$empty = $dashboard->charts($alice, ['counterparty' => 'nobody at all']);
assert_same([], $empty['by_status'], 'a filter matching nothing produces an empty series, not a fabricated one');
assert_same(0, $sum($empty['expiry_timeline']), 'the month grid survives an empty filter with zero counts');

// ---------------------------------------------------------------------------
// Waiting on people
// ---------------------------------------------------------------------------
$aliceActions = $dashboard->myActions($alice);

assert_count(0, $aliceActions['approvals'], 'the approval is assigned to Carol, not to Alice');
assert_count(2, $aliceActions['obligations'], 'Alice owns the due and the overdue occurrence');
assert_same('overdue', (string) $aliceActions['obligations'][0]['status'], 'the late occurrence comes first');
assert_same(-20, $aliceActions['obligations'][0]['days_remaining'], 'a past due date reads as negative days');
assert_same('25000.00', (string) $aliceActions['obligations'][0]['amount'], 'the obligation carries its amount as a string');
assert_same((int) $boltId, $aliceActions['obligations'][0]['contract_id'], 'each row links to its contract');

assert_count(1, $aliceActions['renewals'], 'only the undecided cycle is waiting on Alice');
assert_same(30, $aliceActions['renewals'][0]['days_remaining'], 'the renewal counts down to its decision date');
assert_same('Cycle 1', (string) $aliceActions['renewals'][0]['description'], 'the renewal row names its cycle');

assert_count(1, $aliceActions['ai_reviews'], 'the review queue is one row per contract, not per field');
assert_same('2 fields to verify', (string) $aliceActions['ai_reviews'][0]['description'], 'the row counts the pending fields');
assert_same($acmeId, $aliceActions['ai_reviews'][0]['contract_id'], 'the review row points at the contract to open');

$carolActions = $dashboard->myActions($carol);
assert_count(1, $carolActions['approvals'], 'the approval step is waiting on Carol');
assert_same('Legal review', (string) $carolActions['approvals'][0]['description'], 'the row names the step');
assert_same($acmeId, $carolActions['approvals'][0]['contract_id'], 'the approval row links to its contract');
assert_same(3, $carolActions['approvals'][0]['days_remaining'], 'the approval counts down to its due date');
assert_count(0, $carolActions['obligations'], "Carol is not on the hook for Alice's obligations");
assert_count(0, $carolActions['renewals'], 'no renewal decision is Carol\'s to make');

$noAi = t_context(cmpId: 1, uuid: 'CAROL', permissions: [Permissions::CONTRACT_VIEW], roles: ['read_only']);
assert_count(0, $dashboard->myActions($noAi)['ai_reviews'], 'the AI queue is empty for a role that cannot use AI');

assert_count(0, $dashboard->myActions($bob)['approvals'], "company 2 sees none of company 1's work");

// ---------------------------------------------------------------------------
// Activity
// ---------------------------------------------------------------------------
$feed = $dashboard->recentActivity($alice, 5);
assert_count(5, $feed, 'the feed honours the limit it was given');
assert_same('contract', (string) $feed[0]['subject_type'], 'a contract event names its subject');
assert_true(is_int($feed[0]['id']), 'the entry id is an integer');
assert_true(
    strtotime((string) $feed[0]['created_at']) >= strtotime((string) $feed[1]['created_at']),
    'the feed is newest first'
);
assert_not_null($feed[0]['contract_number'], 'a contract event carries the contract it is about');

$bobFeed = $dashboard->recentActivity($bob, 20);
foreach ($bobFeed as $entry) {
    assert_true(
        $entry['contract_id'] === (int) $bobContract['id'],
        "company 2's feed contains only company 2's contracts"
    );
}

// Carol's feed is the trail of the one contract she was asked to approve —
// three entries — and nothing from the contracts she cannot open. The summary
// line names the contract, so a feed wider than the repository would be a way
// around it rather than a convenience.
$carolFeed = $dashboard->recentActivity($carol, 20);
assert_count(3, $carolFeed, 'Carol sees the trail of the contract she can open, and no other');
foreach ($carolFeed as $entry) {
    assert_same($acmeId, $entry['contract_id'], 'every entry in her feed is about that contract');
}

$carolContract = $contracts->create($carol, ['title' => 'Carol Own Agreement']);
$carolFeed     = $dashboard->recentActivity($carol, 20);
assert_count(4, $carolFeed, 'her own action reaches her feed');
assert_same(
    (int) $carolContract['id'],
    $carolFeed[0]['contract_id'],
    'and it is the newest thing in it'
);

t_done('DashboardServiceTest');
