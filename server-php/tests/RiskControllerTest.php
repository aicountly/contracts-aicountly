<?php

declare(strict_types=1);

/**
 * The authorization gate in front of RiskController::reviewFinding.
 *
 * Dismissing a finding rescores the contract's stored risk level, so this
 * drives the real controller action — gate included — rather than the
 * service underneath it, to pin the permission the endpoint actually checks
 * rather than the one it merely reads.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\Api\BaseController;
use App\Controllers\Api\RiskController;
use App\Core\Request;
use App\Core\Response;
use App\Core\ResponseSent;
use App\Services\CompanyBootstrapService;
use App\Services\RiskEngine;
use App\Support\Permissions;
use App\Support\TenantContext;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

(new CompanyBootstrapService($pdo))->ensure('sandbox', 1);

$admin  = t_context(1, 'ADMIN-ALICE');
$engine = new RiskEngine($pdo);

// Owned by OWNER-DAN: findingById() is ContractVisibility-scoped, so the
// contract.edit assertion below needs a caller who can see this contract at
// all, not just one who holds the permission.
$st = $pdo->prepare(
    "INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, currency, owner_uuid, created_by)
     VALUES ('sandbox', 1, 'CON-2026-000001', 'Uploaded agreement, nothing captured', 'draft', 'draft', 'INR', 'OWNER-DAN', 'OWNER-DAN')
     RETURNING id"
);
$st->execute();
$contractId = (int) $st->fetchColumn();

// An otherwise-empty contract fires the "missing X" rules on its own, so the
// fixture needs no clauses or documents to have something to review.
$assessment = $engine->assess($admin, $contractId);
$finding    = $assessment['findings'][0] ?? null;
assert_not_null($finding, 'an empty contract fires at least one finding to review');
$findingId = (int) $finding['id'];

/** Drives the real controller action without a live portal session behind it. */
function rc_review(RiskController $controller, TenantContext $ctx, int $findingId, array $body): int
{
    Request::configureForTests($body);

    $ref  = new ReflectionClass(BaseController::class);
    $prop = $ref->getProperty('context');
    $prop->setAccessible(true);
    $prop->setValue($controller, $ctx);

    try {
        $controller->reviewFinding((string) $findingId);
    } catch (ResponseSent $e) {
        return $e->status;
    }

    throw new RuntimeException('reviewFinding() did not send a response');
}

Response::enableTestMode();
$findingRow = $pdo->prepare('SELECT review_status, reviewed_by FROM contract_risk_findings WHERE id = ?');

// --- `auditor`: docs/PERMISSIONS.md calls it "no write at all" --------------
$auditorPerms = Permissions::forRoles(['auditor']);
assert_true(in_array(Permissions::AI_RISK_VIEW, $auditorPerms, true), 'auditor can read risk findings');
assert_false(in_array(Permissions::CONTRACT_EDIT, $auditorPerms, true), 'auditor holds no edit permission');

$auditor = t_context(1, 'AUDITOR-BOB', $auditorPerms, 'sandbox', ['auditor']);
$status  = rc_review(new RiskController(), $auditor, $findingId, [
    'status' => 'false_positive',
    'notes'  => 'nothing to see here',
]);
assert_same(403, $status, 'a read-only auditor is refused, not allowed to dismiss the finding');

$findingRow->execute([$findingId]);
$untouched = $findingRow->fetch();
assert_same('open', $untouched['review_status'], 'the refused call left the finding exactly as it was');
assert_null($untouched['reviewed_by'], 'and recorded no reviewer');

// --- `reviewer`: "Comments without editing ... no write" -------------------
$reviewerPerms = Permissions::forRoles(['reviewer']);
$reviewer      = t_context(1, 'REVIEWER-CARL', $reviewerPerms, 'sandbox', ['reviewer']);
$status        = rc_review(new RiskController(), $reviewer, $findingId, ['status' => 'false_positive']);
assert_same(403, $status, 'a comment-only reviewer is refused too');

// --- A role holding contract.edit may still review a finding ---------------
$editorPerms = Permissions::forRoles(['contract_owner']);
assert_true(in_array(Permissions::CONTRACT_EDIT, $editorPerms, true), 'contract_owner holds contract.edit');

$editor = t_context(1, 'OWNER-DAN', $editorPerms, 'sandbox', ['contract_owner']);
$status = rc_review(new RiskController(), $editor, $findingId, [
    'status' => 'false_positive',
    'notes'  => 'renegotiated in the addendum',
]);
assert_same(200, $status, 'a role holding contract.edit may dismiss the finding');

$findingRow->execute([$findingId]);
$reviewed = $findingRow->fetch();
assert_same('false_positive', $reviewed['review_status'], 'the review is recorded');
assert_same('OWNER-DAN', $reviewed['reviewed_by'], 'attributed to the caller who made it');

t_done('RiskControllerTest');
