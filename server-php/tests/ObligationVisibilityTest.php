<?php

declare(strict_types=1);

/**
 * The obligations register must narrow by contract visibility, not just by tenant.
 *
 * This pins a real leak. buildOccurrenceWhere() scoped occurrences by
 * environment and cmp_id and stopped there, while the register's SELECT joins
 * contracts to show the number and title beside every row — so a user holding
 * contract.view but not contract.view_all was shown the name of every contract
 * in the company that happened to carry an obligation, including ones they
 * could not open. Tenant isolation was intact throughout; the failure was one
 * level in from it, which is why the cross-tenant suite never saw it.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractService;
use App\Services\ObligationService;
use App\Support\Permissions;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured');
}
t_reset_database($pdo);

$contracts   = new ContractService($pdo);
$obligations = new ObligationService($pdo);

// One company, two people. Everything below turns on what each may see inside
// it, so both contexts share cmp_id — a difference there would be testing the
// tenant boundary again rather than this one.
$owner = t_context(cmpId: 1, uuid: 'OWNER');

$hers = $contracts->create($owner, [
    'title'             => 'Confidential Acquisition Support',
    'counterparty_name' => 'Project Redwood Holdings',
    'effective_date'    => '2026-01-01',
    'expiry_date'       => '2027-12-31',
]);

$obligation = $obligations->create($owner, (int) $hers['id'], [
    'title'             => 'Quarterly diligence report',
    'frequency'         => 'quarterly',
    'first_due_date'    => '2026-01-31',
    'responsible_party' => 'company',
]);

// A draft contract generates nothing on create, so the occurrences this test
// is about have to be asked for explicitly.
$obligations->generateOccurrences($owner, (int) $obligation['id'], '2026-12-31');

// A colleague in the same company with the ordinary read grant and nothing
// else. Narrowing the permission list alone is not enough — several services
// bypass by role — so the role is narrowed with it, which t_context() does.
$colleague = t_context(
    cmpId: 1,
    uuid: 'COLLEAGUE',
    permissions: [Permissions::CONTRACT_VIEW, Permissions::OBLIGATION_MANAGE],
);

$asColleague = $obligations->listOccurrences($colleague, [], 50, 0);

assert_same(
    0,
    $asColleague['total'],
    'a colleague who cannot open the contract sees none of its occurrences'
);
assert_same(
    [],
    $asColleague['items'],
    'and no row leaks through with the contract number and title attached'
);

// The owner must still see their own, or the fix would be a denial of service
// rather than a narrowing.
$asOwner = $obligations->listOccurrences($owner, [], 50, 0);
assert_true($asOwner['total'] > 0, 'the contract owner still sees their own occurrences');
assert_same(
    'Confidential Acquisition Support',
    (string) ($asOwner['items'][0]['contract_title'] ?? ''),
    'and the register still carries the contract title for someone entitled to it'
);

// A user with the estate-wide grant sees everything, which is the branch the
// helper short-circuits — worth pinning, because a predicate that narrowed for
// everyone would pass both assertions above.
$auditor = t_context(
    cmpId: 1,
    uuid: 'AUDITOR',
    permissions: [Permissions::CONTRACT_VIEW, Permissions::CONTRACT_VIEW_ALL, Permissions::OBLIGATION_MANAGE],
);

assert_same(
    $asOwner['total'],
    $obligations->listOccurrences($auditor, [], 50, 0)['total'],
    'contract.view_all sees the whole company, as the repository does'
);

// The register narrowing above is not the only door onto these rows: every
// by-id sibling — findOccurrence(), listForContract()/summaryForContract() by
// contract id, and the two write entry points that load an occurrence
// through findOccurrence() — must apply the same rule, or a colleague who
// cannot open the contract from the register can still walk in by id.
$occurrenceIds = array_map(static fn (array $r): int => (int) $r['id'], $asOwner['items']);
sort($occurrenceIds);
assert_true(count($occurrenceIds) >= 2, 'the fixture generated enough occurrences to exercise two separate write paths');
[$occA, $occB] = [$occurrenceIds[0], $occurrenceIds[1]];

assert_null(
    $obligations->findOccurrence($colleague, $occA),
    'a colleague without view_all cannot open the occurrence by id either'
);
$ownerView = $obligations->findOccurrence($owner, $occA);
assert_not_null($ownerView, 'the owner can still open it by id');
assert_same(
    'Confidential Acquisition Support',
    (string) ($ownerView['contract_title'] ?? ''),
    'and the contract title still comes through for someone entitled to it'
);
assert_not_null(
    $obligations->findOccurrence($auditor, $occB),
    'contract.view_all can open any occurrence in the company, as the repository does'
);

assert_count(
    0,
    $obligations->listForContract($colleague, (int) $hers['id']),
    'a colleague without view_all sees no obligations on a contract they cannot open'
);
assert_true(
    count($obligations->listForContract($owner, (int) $hers['id'])) > 0,
    'the owner still sees their own obligations by contract id'
);
assert_same(
    0,
    $obligations->summaryForContract($colleague, (int) $hers['id'])['total'],
    'and no occurrence summary either'
);
assert_true(
    $obligations->summaryForContract($owner, (int) $hers['id'])['total'] > 0,
    'while the owner gets a real summary'
);

// Writing through the by-id path has to be blocked the same way reading is,
// or the narrowing above is a read-only illusion: a colleague who cannot see
// the occurrence could otherwise still complete or restatus it directly.
assert_throws(
    static fn () => $obligations->completeOccurrence($colleague, $occA, ['completion_note' => 'closed by an outsider']),
    'a colleague without view_all cannot complete an occurrence on a contract they cannot open',
    'occurrence not found'
);
assert_throws(
    static fn () => $obligations->updateOccurrenceStatus($colleague, $occB, 'waived', null),
    'a colleague without view_all cannot restatus an occurrence either',
    'occurrence not found'
);
assert_throws(
    static fn () => $obligations->listEvidence($colleague, $occA),
    'a colleague without view_all cannot list evidence on an occurrence they cannot open',
    'occurrence not found'
);

// The owner's own writes still have to work, or the fix would be a denial of
// service rather than a narrowing.
$completed = $obligations->completeOccurrence($owner, $occA, [
    'completion_note' => 'filed',
    'evidence_note'   => 'the actual report',
]);
assert_same('completed', (string) $completed['status'], 'the owner can still complete their own occurrence');
assert_count(1, $obligations->listEvidence($owner, $occA), 'and read back the evidence they just filed');

t_done('ObligationVisibilityTest');
