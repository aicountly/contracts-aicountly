<?php

declare(strict_types=1);

/**
 * Contract requests: that the status graph is a graph and not a suggestion,
 * that a refused transition is a 409 rather than a silent write, and that
 * conversion produces a contract which carries the request that asked for it —
 * on both records, so the link reads the same from either side.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ContractRequestService;
use App\Services\ContractService;
use App\Support\DomainException;
use App\Support\Permissions;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$service  = new ContractRequestService($pdo);
$contracts = new ContractService($pdo);

$requester = t_context(uuid: 'REQUESTER');
$reviewer  = t_context(uuid: 'REVIEWER');

/** A request with everything submit() insists on, ready to be pushed along. */
$raise = static function (array $overrides = []) use ($service, $requester): array {
    return $service->create($requester, array_merge([
        'title'             => 'Cloud hosting agreement',
        'purpose'           => 'Replace the expiring hosting contract before it lapses.',
        'counterparty_name' => 'Northwind Cloud Ltd',
        'estimated_value'   => '480000.00',
        'currency'          => 'USD',
        'required_by_date'  => '2026-11-30',
    ], $overrides));
};

// --- a request starts as a draft, numbered in its own series ----------------
$request = $raise();

assert_same('draft', (string) $request['status'], 'a new request starts as a draft');
assert_same(
    'REQ-' . date('Y') . '-000001',
    (string) $request['request_number'],
    'requests are numbered in their own series, not the contract one'
);
assert_same('REQUESTER', (string) $request['requester_uuid'], 'the caller is the requester');
assert_null($request['converted_contract_id'], 'a new request points at no contract');

$requestId = (int) $request['id'];

// --- the graph refuses a jump straight past review --------------------------
// This is the assertion the whole class exists for: a request that reached
// "approved for drafting" without being submitted has skipped the review the
// queue exists to force.
assert_throws(
    static fn () => $service->decide($reviewer, $requestId, 'approve', ['notes' => 'Looks fine']),
    'a draft cannot be approved without being submitted',
    'cannot move from Draft to Approved For Drafting'
);

try {
    $service->decide($reviewer, $requestId, 'approve', []);
    t_fail('refused transition status', 'expected a throw');
} catch (DomainException $e) {
    assert_same(409, $e->status, 'a refused transition is a conflict, not a validation error');
    assert_same('INVALID_STATUS_TRANSITION', $e->errorCode, 'the refusal names the rule it broke');
}

assert_same(
    'draft',
    (string) $service->findOrFail($requester, $requestId)['status'],
    'the refused transition left the request where it was'
);

assert_throws(
    static fn () => $service->convert($reviewer, $requestId, []),
    'a draft cannot be converted to a contract',
    'must be approved for drafting'
);

// --- the happy path, one step at a time -------------------------------------
$submitted = $service->submit($requester, $requestId);
assert_same('submitted', (string) $submitted['status'], 'submitting hands the request to the reviewers');

$underReview = $service->decide($reviewer, $requestId, 'review', []);
assert_same('under_review', (string) $underReview['status'], 'a reviewer picks the request up');
assert_null($underReview['decided_by'], 'picking a request up is not yet a verdict');

$approved = $service->decide($reviewer, $requestId, 'approve', ['notes' => 'Budget confirmed with finance.']);
assert_same('approved_for_drafting', (string) $approved['status'], 'approval clears the request for drafting');
assert_same('REVIEWER', (string) $approved['decided_by'], 'the verdict records who made it');
assert_same('Budget confirmed with finance.', (string) $approved['decision_notes'], 'the verdict records why');
assert_not_null($approved['decided_at'], 'the verdict is timestamped');

// --- editing a request under review is refused ------------------------------
assert_throws(
    static fn () => $service->update($requester, $requestId, ['title' => 'Something else entirely']),
    'an approved request cannot be edited under the reviewer',
    'cannot be edited'
);

// --- conversion produces a contract that carries the request ----------------
$converted = $service->convert($reviewer, $requestId, ['title' => 'Northwind cloud hosting agreement']);

$contract   = $converted['contract'];
$contractId = (int) $contract['id'];

assert_same($requestId, (int) $contract['request_id'], 'the contract carries the request that asked for it');
assert_same('from_request', (string) $contract['source'], 'the contract knows it came from a request');
assert_same('Northwind cloud hosting agreement', (string) $contract['title'], 'the caller may retitle on conversion');
assert_same('Northwind Cloud Ltd', (string) $contract['counterparty_name'], 'the counterparty is copied across');
assert_same('480000.00', (string) $contract['total_value'], 'the estimated value becomes the contract value');
assert_same('USD', (string) $contract['currency'], 'the currency is copied across');
assert_same('REQUESTER', (string) $contract['owner_uuid'], 'the requester owns what they asked for');
assert_same('draft', (string) $contract['status'], 'the new contract starts as a draft');

assert_same('converted', (string) $converted['request']['status'], 'the request is marked converted');
assert_same($contractId, (int) $converted['request']['converted_contract_id'], 'the request points back at the contract');
assert_not_null($converted['request']['converted_at'], 'the conversion is timestamped');

// The contract must be readable as a contract, not only as the value returned
// from convert() — a link that only exists in one response is not a link.
$reloaded = $contracts->findOrFail($reviewer, $contractId);
assert_same($requestId, (int) $reloaded['request_id'], 'the stored contract row carries the request id');

// --- the link is visible from either side -----------------------------------
$requestTimeline = $service->activityFor($reviewer, $requestId);
$requestSummary  = implode(' | ', array_map(static fn (array $r): string => (string) $r['summary'], $requestTimeline));
assert_contains(
    (string) $contract['contract_number'],
    $requestSummary,
    "the request's timeline names the contract it became"
);

$contractTimeline = (new \App\Services\ActivityService($pdo))->listForContract($reviewer, $contractId);
$contractSummary  = implode(' | ', array_map(static fn (array $r): string => (string) $r['summary'], $contractTimeline));
assert_contains(
    (string) $request['request_number'],
    $contractSummary,
    "the contract's timeline names the request it came from"
);

// --- converting twice would produce two contracts for one approval ----------
assert_throws(
    static fn () => $service->convert($reviewer, $requestId, []),
    'a converted request cannot be converted again',
    'already been converted'
);

assert_same(
    1,
    (int) $pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn(),
    'the refused second conversion created no contract'
);

// --- the side exits ---------------------------------------------------------
$needsInfo = $raise(['title' => 'Data processing addendum']);
$needsInfoId = (int) $needsInfo['id'];

$service->submit($requester, $needsInfoId);
$sentBack = $service->decide($reviewer, $needsInfoId, 'more_info', ['notes' => 'Which vendor, and for how long?']);
assert_same('more_info_required', (string) $sentBack['status'], 'a reviewer can send a request back for more information');

// Sent back is the one state after submission where the requester may edit
// again — that is what "more information required" means.
$amended = $service->update($requester, $needsInfoId, ['business_justification' => 'Required by the DPA renewal.']);
assert_same('Required by the DPA renewal.', (string) $amended['business_justification'], 'a request sent back can be edited');

$resubmitted = $service->submit($requester, $needsInfoId);
assert_same('submitted', (string) $resubmitted['status'], 'a request sent back can be submitted again');

$rejected = $service->decide($reviewer, $needsInfoId, 'reject', ['notes' => 'Covered by the existing master agreement.']);
assert_same('rejected', (string) $rejected['status'], 'a reviewer can reject a request outright');

assert_throws(
    static fn () => $service->decide($reviewer, $needsInfoId, 'approve', ['notes' => 'Changed my mind']),
    'a rejected request is terminal',
    'cannot move from Rejected to Approved For Drafting'
);
assert_throws(
    static fn () => $service->submit($requester, $needsInfoId),
    'a rejected request cannot be resubmitted',
    'cannot move from Rejected to Submitted'
);

// --- a rejection has to say why ---------------------------------------------
$unexplained = $raise(['title' => 'Office lease renewal']);
$service->submit($requester, (int) $unexplained['id']);
assert_throws(
    static fn () => $service->decide($reviewer, (int) $unexplained['id'], 'reject', []),
    'a rejection without a reason is refused',
    'Say why the request is being rejected'
);

// --- submitting an empty request is refused ---------------------------------
$empty = $service->create($requester, ['title' => 'Something, probably']);
assert_throws(
    static fn () => $service->submit($requester, (int) $empty['id']),
    'a request with no stated purpose cannot be submitted',
    'Describe what this contract is for'
);

// --- another company's request is not found, not forbidden ------------------
$otherCompany = t_context(cmpId: 2, uuid: 'OUTSIDER');
assert_null($service->find($otherCompany, $requestId), "company 2 cannot read company 1's request");
assert_throws(
    static fn () => $service->submit($otherCompany, $requestId),
    "company 2 cannot act on company 1's request",
    'not found'
);
assert_same(0, $service->search($otherCompany, [], 50, 0)['total'], 'listing is scoped to the company');

// --- inside one company, a colleague without the review grant sees only
//     what they raised ------------------------------------------------------
$colleague = t_context(
    uuid: 'COLLEAGUE',
    permissions: [Permissions::CONTRACT_VIEW, Permissions::REQUEST_CREATE, Permissions::REPORT_VIEW]
);

assert_same(0, $service->search($colleague, [], 50, 0)['total'], 'a colleague does not see the queue by default');
assert_null($service->find($colleague, $requestId), 'nor can they open a request by walking the id');

$colleagueRequest = $service->create($colleague, ['title' => 'Courier services', 'purpose' => 'Weekly document runs.']);
assert_same(1, $service->search($colleague, [], 50, 0)['total'], 'they do see the request they raised');
assert_throws(
    static fn () => $service->update($colleague, $requestId, ['title' => 'Not yours']),
    "a colleague cannot edit someone else's request",
    'not found'
);

// The reviewer works the whole queue, which is the point of the grant.
assert_same(5, $service->search($reviewer, [], 50, 0)['total'], 'a reviewer sees every request in the company');
assert_same(
    1,
    $service->search($reviewer, ['status' => ['rejected']], 50, 0)['total'],
    'the queue can be filtered by status'
);
assert_same(
    1,
    $service->search($reviewer, ['requester' => 'COLLEAGUE'], 50, 0)['total'],
    'the queue can be filtered by requester'
);
assert_same(
    1,
    $service->search($reviewer, ['q' => 'Courier'], 50, 0)['total'],
    'the queue can be searched by title'
);
assert_same(
    (int) $colleagueRequest['id'],
    (int) $service->search($reviewer, ['q' => 'Courier'], 50, 0)['items'][0]['id'],
    'the search returns the request it matched'
);

// --- an unknown decision is a bad request, not a status write ---------------
$fresh = $raise(['title' => 'Marketing retainer']);
$service->submit($requester, (int) $fresh['id']);
assert_throws(
    static fn () => $service->decide($reviewer, (int) $fresh['id'], 'escalate', []),
    'an unknown decision is refused',
    'Unknown review decision'
);
assert_same(
    'submitted',
    (string) $service->findOrFail($reviewer, (int) $fresh['id'])['status'],
    'the unknown decision changed nothing'
);

t_done('ContractRequestServiceTest');
