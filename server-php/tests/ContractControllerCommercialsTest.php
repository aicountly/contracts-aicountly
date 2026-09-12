<?php

declare(strict_types=1);

/**
 * docs/SECURITY.md: commercial values are removed from the payload for a
 * caller without contract.commercials.view, not hidden client-side.
 * show() enforced that; index() (the list endpoint, up to 100 rows a
 * request) did not, so a role like `reviewer` — real contract.view,
 * no commercials.view — got every contract's total_value in the list even
 * though the same contract's detail view withheld it. Drives the real
 * controller actions, gate included, so a regression here fails the same way
 * the live endpoint would.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\Api\BaseController;
use App\Controllers\Api\ContractController;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResponseSent;
use App\Services\CompanyBootstrapService;
use App\Services\ContractService;
use App\Support\Permissions;
use App\Support\TenantContext;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

(new CompanyBootstrapService($pdo))->ensure('sandbox', 1);

$admin = t_context(1, 'ADMIN-ALICE');
$svc   = new ContractService($pdo);

$created = $svc->create($admin, [
    'title'             => 'Commercials visibility fixture',
    'status'            => 'draft',
    'currency'          => 'INR',
    'total_value'       => '7500000.00',
    'counterparty_name' => 'Acme Co',
]);
$contractId = (int) $created['id'];

/** Drives a real controller action without a live portal session behind it. */
function cc_call(ContractController $controller, TenantContext $ctx, string $method, array $args, array $query = []): array
{
    $_GET = $query;
    Request::configureForTests([]);

    $ref  = new ReflectionClass(BaseController::class);
    $prop = $ref->getProperty('context');
    $prop->setAccessible(true);
    $prop->setValue($controller, $ctx);

    try {
        $controller->$method(...$args);
    } catch (ResponseSent $e) {
        $last = Response::lastForTests();
        assert_not_null($last, 'a response was captured');

        return $last;
    }

    throw new RuntimeException($method . '() did not send a response');
}

Response::enableTestMode();

// `reviewer`: real contract.view + contract.view_all, no commercials.view —
// docs/PERMISSIONS.md's own "comments, does not edit" role.
$reviewerPerms = Permissions::forRoles(['reviewer']);
assert_true(in_array(Permissions::CONTRACT_VIEW, $reviewerPerms, true), 'reviewer can view contracts');
assert_false(in_array(Permissions::COMMERCIALS_VIEW, $reviewerPerms, true), 'reviewer holds no commercials grant');
$reviewer = t_context(1, 'REVIEWER-CARL', $reviewerPerms, 'sandbox', ['reviewer']);

// --- show(): the endpoint that already stripped correctly -------------------
$showResp = cc_call(new ContractController(), $reviewer, 'show', [(string) $contractId]);
assert_same(200, $showResp['status'], 'show() succeeds for a reviewer');
assert_null($showResp['body']['data']['total_value'], 'show() nulls total_value for a caller without commercials.view');
assert_true($showResp['body']['data']['commercials_hidden'], 'show() flags commercials_hidden');

// --- index(): the list endpoint the finding says leaked ---------------------
$indexResp = cc_call(new ContractController(), $reviewer, 'index', []);
assert_same(200, $indexResp['status'], 'index() succeeds for a reviewer');
$items = $indexResp['body']['data']['items'];
assert_count(1, $items, 'the fixture contract is in the list');
assert_null($items[0]['total_value'], 'index() nulls total_value for a caller without commercials.view — the leak the finding described');
assert_true($items[0]['commercials_hidden'], 'index() flags commercials_hidden on each row, same as show()');

// --- a role that does hold commercials.view still sees the real figure ------
$financePerms = Permissions::forRoles(['finance']);
assert_true(in_array(Permissions::COMMERCIALS_VIEW, $financePerms, true), 'finance holds commercials.view');
$finance = t_context(1, 'FINANCE-DAN', $financePerms, 'sandbox', ['finance']);

$financeIndex = cc_call(new ContractController(), $finance, 'index', []);
$financeItems = $financeIndex['body']['data']['items'];
assert_same('7500000.00', $financeItems[0]['total_value'], 'a caller with commercials.view still sees the real total_value in the list');
assert_false(isset($financeItems[0]['commercials_hidden']) && $financeItems[0]['commercials_hidden'], 'commercials_hidden is not set true for a caller who can see the figure');

t_done('ContractControllerCommercialsTest');
