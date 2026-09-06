<?php

declare(strict_types=1);

/**
 * The rule this product cannot afford to get wrong.
 *
 * Every tenant-scoped query filters on `environment` AND `cmp_id` taken from
 * the TenantContext, never from request input. This file exercises that from
 * the outside: it seeds two companies with deliberately identical-looking data
 * and then tries, from company 1's context, to read, edit, and delete company
 * 2's records by id.
 *
 * Any failure here is a data breach, not a bug.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractService;
use App\Support\DomainException;
use App\Support\Permissions;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured');
}
t_reset_database($pdo);

$service = new ContractService($pdo);

$alice = t_context(cmpId: 1, uuid: 'ALICE');
$bob   = t_context(cmpId: 2, uuid: 'BOB');

// --- Seed both companies ----------------------------------------------------
// The same title on purpose: a query that filtered on title alone, or that
// dropped the cmp_id predicate, would return the wrong row and look correct.
$aliceContract = $service->create($alice, [
    'title'          => 'Vendor Services Agreement',
    'counterparty_name' => 'Acme Industries',
    'effective_date' => '2026-01-01',
    'expiry_date'    => '2027-12-31',
    'total_value'    => '1200000.00',
]);
$bobContract = $service->create($bob, [
    'title'          => 'Vendor Services Agreement',
    'counterparty_name' => 'Acme Industries',
    'effective_date' => '2026-01-01',
    'expiry_date'    => '2027-12-31',
    'total_value'    => '9900000.00',
]);

assert_true($aliceContract['id'] !== $bobContract['id'], 'the two companies got different contract ids');
assert_same(
    'CON-' . date('Y') . '-000001',
    $aliceContract['contract_number'],
    'contract numbering starts at 1 for a company'
);
assert_same(
    'CON-' . date('Y') . '-000001',
    $bobContract['contract_number'],
    'numbering is per company — company 2 also starts at 1'
);

$bobId = (int) $bobContract['id'];

// --- Reading ----------------------------------------------------------------
assert_null($service->find($alice, $bobId), "company 1 cannot read company 2's contract by id");

assert_throws(
    static fn () => $service->findOrFail($alice, $bobId),
    'findOrFail reports another company\'s contract as not found',
    'not found'
);

$aliceList = $service->search($alice, [], 50, 0);
assert_same(1, $aliceList['total'], 'company 1 sees exactly its own contract');
assert_same(
    (int) $aliceContract['id'],
    (int) $aliceList['items'][0]['id'],
    'the contract company 1 sees is its own'
);

// A search term that matches both rows must still only return one.
$shared = $service->search($alice, ['q' => 'Vendor Services'], 50, 0);
assert_same(1, $shared['total'], 'full-text search does not cross the tenant boundary');

$byCounterparty = $service->search($alice, ['counterparty' => 'Acme'], 50, 0);
assert_same(1, $byCounterparty['total'], 'counterparty filter does not cross the tenant boundary');

// --- Writing ----------------------------------------------------------------
assert_throws(
    static fn () => $service->update($alice, $bobId, ['title' => 'Hijacked']),
    "company 1 cannot edit company 2's contract",
    'not found'
);

assert_throws(
    static fn () => $service->changeStatus($alice, $bobId, 'active'),
    "company 1 cannot change the status of company 2's contract",
    'not found'
);

assert_throws(
    static fn () => $service->archive($alice, $bobId, true),
    "company 1 cannot archive company 2's contract",
    'not found'
);

assert_throws(
    static fn () => $service->deleteDraft($alice, $bobId),
    "company 1 cannot delete company 2's contract",
    'not found'
);

assert_throws(
    static fn () => $service->setFavourite($alice, $bobId, true),
    "company 1 cannot favourite company 2's contract",
    'not found'
);

// Company 2's row is untouched by every attempt above.
$bobAfter = $service->findOrFail($bob, $bobId);
assert_same('Vendor Services Agreement', (string) $bobAfter['title'], "company 2's title survived");
assert_same('draft', (string) $bobAfter['status'], "company 2's status survived");
assert_null($bobAfter['archived_at'], "company 2's contract was not archived");

// --- The environment column is a boundary too -------------------------------
// A production dump restored into sandbox is the case this exists for: same
// cmp_id, different environment, and the rows must not mix.
$aliceProduction = t_context(cmpId: 1, uuid: 'ALICE', environment: 'production');
assert_null(
    $service->find($aliceProduction, (int) $aliceContract['id']),
    'a production context cannot read a sandbox row with the same cmp_id'
);
assert_same(
    0,
    $service->search($aliceProduction, [], 50, 0)['total'],
    'listing is scoped by environment as well as company'
);

// --- Foreign keys must not be a way across the boundary ---------------------
// A contract type belongs to one company. Pointing a contract at another
// company's type would be a cross-tenant reference the database alone would
// accept, because the FK only checks existence.
$pdo->prepare(
    'INSERT INTO contract_types (environment, cmp_id, code, name) VALUES (?, ?, ?, ?)'
)->execute(['sandbox', 2, 'bob_only', "Bob's private type"]);
$bobTypeId = (int) $pdo->query(
    "SELECT id FROM contract_types WHERE cmp_id = 2 AND code = 'bob_only'"
)->fetchColumn();

assert_throws(
    static fn () => $service->create($alice, [
        'title'            => 'Borrowing another company type',
        'contract_type_id' => $bobTypeId,
    ]),
    "company 1 cannot reference company 2's contract type",
    'your own list'
);

$pdo->prepare(
    'INSERT INTO contract_departments (environment, cmp_id, code, name) VALUES (?, ?, ?, ?)'
)->execute(['sandbox', 2, 'bob_dept', "Bob's department"]);
$bobDeptId = (int) $pdo->query(
    "SELECT id FROM contract_departments WHERE cmp_id = 2 AND code = 'bob_dept'"
)->fetchColumn();

assert_throws(
    static fn () => $service->create($alice, [
        'title'         => 'Borrowing another company department',
        'department_id' => $bobDeptId,
    ]),
    "company 1 cannot reference company 2's department",
    'your own list'
);

// --- Row-level visibility within one company --------------------------------
// A user without CONTRACT_VIEW_ALL sees only what they own or are involved in.
// This is not a tenant boundary but it is the same class of mistake.
$carol = t_context(
    cmpId: 1,
    uuid: 'CAROL',
    permissions: [Permissions::CONTRACT_VIEW, Permissions::CONTRACT_CREATE, Permissions::REPORT_VIEW]
);

assert_same(
    0,
    $service->search($carol, [], 50, 0)['total'],
    'a user without view_all does not see a colleague\'s contract'
);

$carolContract = $service->create($carol, ['title' => "Carol's own contract"]);
assert_same(
    1,
    $service->search($carol, [], 50, 0)['total'],
    'a user without view_all sees the contract they created'
);
assert_same(
    2,
    $service->search($alice, [], 50, 0)['total'],
    'a user with view_all sees both contracts in the company'
);

// Carol still cannot reach Alice's contract by id.
assert_null(
    $service->find($carol, (int) $aliceContract['id']),
    'row-level visibility is enforced on a direct read, not only on the list'
);
assert_true(
    $service->find($carol, (int) $carolContract['id']) !== null,
    'Carol can still read her own contract by id'
);

t_done('CrossTenantIsolationTest');
