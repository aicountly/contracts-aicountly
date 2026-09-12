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

t_done('ObligationVisibilityTest');
