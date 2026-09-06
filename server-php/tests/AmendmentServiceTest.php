<?php

declare(strict_types=1);

/**
 * Amendments: that numbering is per contract, that applying one writes the new
 * terms onto the contract without losing the old ones, and that the effective
 * position is assembled in effective-date order rather than in the order the
 * amendments happened to be executed.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\AmendmentService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx     = t_context();
$service = new AmendmentService($pdo);

/** Insert a contract directly; ContractService::create is exercised by its own suite. */
$makeContract = static function (array $overrides = []) use ($pdo): int {
    static $seq = 0;
    $seq++;

    $row = array_merge([
        'cmp_id'             => 1,
        'contract_number'    => sprintf('CON-2026-%06d', $seq),
        'title'              => 'Master services agreement',
        'total_value'        => '100000.00',
        'expiry_date'        => '2030-12-31',
        'notice_period_days' => 30,
        'governing_law'      => 'Indian law',
    ], $overrides);

    $st = $pdo->prepare(
        'INSERT INTO contracts
         (environment, cmp_id, contract_number, title, status, lifecycle_stage,
          effective_date, expiry_date, notice_period_days, notice_deadline,
          currency, total_value, governing_law, auto_renewal, owner_uuid, created_by)
         VALUES (:env, :cmp, :num, :title, \'active\', \'active\',
                 \'2025-01-01\', :exp, :days, :deadline,
                 \'INR\', :value, :law, FALSE, \'USER-A\', \'USER-A\')
         RETURNING id'
    );
    $st->execute([
        'env'      => 'sandbox',
        'cmp'      => $row['cmp_id'],
        'num'      => $row['contract_number'],
        'title'    => $row['title'],
        'exp'      => $row['expiry_date'],
        'days'     => $row['notice_period_days'],
        'deadline' => \App\Support\Dates::noticeDeadline($row['expiry_date'], $row['notice_period_days']),
        'value'    => $row['total_value'],
        'law'      => $row['governing_law'],
    ]);

    return (int) $st->fetchColumn();
};

// --- numbering is per contract, and consecutive -----------------------------
$contractId = $makeContract();

$a1 = $service->create($ctx, $contractId, [
    'title'           => 'Price revision',
    'effective_date'  => '2027-01-01',
    'affected_fields' => ['total_value' => '150000.00', 'governing_law' => 'English law'],
]);
$a2 = $service->create($ctx, $contractId, [
    'title'           => 'Scope reduction',
    'effective_date'  => '2026-01-01',
    'affected_fields' => ['total_value' => ['to' => '120000.00']],
]);
$a3 = $service->create($ctx, $contractId, [
    'title'           => 'Notice period change',
    'effective_date'  => '2026-06-01',
    'affected_fields' => ['notice_period_days' => 90],
]);

assert_same(1, $a1['amendment_no'], 'the first amendment of a contract is number 1');
assert_same(2, $a2['amendment_no'], 'the second is number 2');
assert_same(3, $a3['amendment_no'], 'the third is number 3');
assert_same('draft', $a1['status'], 'an amendment starts as a draft');

$otherContractId = $makeContract();
$b1 = $service->create($ctx, $otherContractId, [
    'title'           => 'Unrelated change',
    'effective_date'  => '2026-03-01',
    'affected_fields' => ['counterparty_name' => 'Acme Holdings Ltd'],
]);
assert_same(1, $b1['amendment_no'], 'numbering restarts at 1 on a different contract');

// --- the draft already shows what it will change ----------------------------
assert_same('100000.00', $a1['affected_fields']['total_value']['from'], 'a draft records the value it is changing from');
assert_same('150000.00', $a1['affected_fields']['total_value']['to'], 'a draft records the value it changes to');

// --- a field outside the amendable set is refused ---------------------------
assert_throws(
    static fn () => $service->create($ctx, $contractId, [
        'title'           => 'Ownership grab',
        'affected_fields' => ['status' => 'terminated'],
    ]),
    'an amendment cannot change a field outside the negotiated terms',
    'correct the highlighted fields'
);

// --- a value that a direct edit would refuse is refused here too ------------
assert_throws(
    static fn () => $service->create($ctx, $contractId, [
        'title'           => 'Impossible date',
        'affected_fields' => ['expiry_date' => '2026-02-31'],
    ]),
    'an amendment date is validated like any other date',
    'correct the highlighted fields'
);

// --- apply() writes the terms on and keeps the originals ---------------------
$applied = $service->apply($ctx, (int) $a1['id']);

assert_same('executed', $applied['status'], 'applying an amendment executes it');
assert_not_null($applied['applied_at'], 'the moment it was applied is recorded');
assert_same('USER-A', $applied['applied_by'], 'who applied it is recorded');

$contract = $pdo->query("SELECT total_value, governing_law, notice_deadline FROM contracts WHERE id = {$contractId}")->fetch();
assert_same('150000.00', $contract['total_value'], 'the new value is written onto the contract');
assert_same('English law', $contract['governing_law'], 'every field in the amendment is written');

// The originals live on in the amendment row, which is the point of keeping it.
assert_same('100000.00', $applied['affected_fields']['total_value']['from'], 'the original value survives on the amendment');
assert_same('150000.00', $applied['affected_fields']['total_value']['to'], 'alongside the value that replaced it');
assert_same('Indian law', $applied['affected_fields']['governing_law']['from'], 'the original governing law survives');

// ...and in the audit trail, one row per field.
$audit = $pdo->query(
    "SELECT field_name, old_value, new_value FROM contract_audit_logs
     WHERE contract_id = {$contractId} AND action = 'contract.amended'
     ORDER BY field_name"
)->fetchAll();
assert_count(2, $audit, 'applying an amendment audits every field it changed');
assert_same('governing_law', $audit[0]['field_name'], 'the governing law change is audited');
assert_same('Indian law', $audit[0]['old_value'], 'with the value it held before');
assert_same('100000.00', $audit[1]['old_value'], 'and the contract value it held before');
assert_same('150000.00', $audit[1]['new_value'], 'and the value it holds now');

// --- applying twice is refused ----------------------------------------------
assert_throws(
    static fn () => $service->apply($ctx, (int) $a1['id']),
    'an amendment cannot be applied twice',
    'already been applied'
);

// --- an executed amendment can no longer be edited or deleted ---------------
assert_throws(
    static fn () => $service->update($ctx, (int) $a1['id'], ['title' => 'Rewriting history']),
    'an executed amendment cannot be edited',
    'no longer be edited'
);
assert_throws(
    static fn () => $service->delete($ctx, (int) $a1['id']),
    'an executed amendment cannot be deleted',
    'Only a draft amendment can be deleted'
);

// --- a derived column moves with the field it is derived from ---------------
$noticeAmendment = $service->create($ctx, $otherContractId, [
    'title'           => 'Longer notice',
    'effective_date'  => '2026-04-01',
    'affected_fields' => ['notice_period_days' => 90],
]);
$service->apply($ctx, (int) $noticeAmendment['id']);
$otherContract = $pdo->query("SELECT notice_period_days, notice_deadline FROM contracts WHERE id = {$otherContractId}")->fetch();
assert_same(90, (int) $otherContract['notice_period_days'], 'the notice period is amended');
assert_same('2030-10-02', $otherContract['notice_deadline'], 'and the derived notice deadline is recomputed');

// --- effectivePosition overlays in effective-date order ---------------------
// a2 is effective a year *before* a1 but applied a moment after it: the current
// position must follow the agreements' dates, not the order somebody happened
// to press the button in.
$service->apply($ctx, (int) $a2['id']);
assert_same(
    '120000.00',
    $pdo->query("SELECT total_value FROM contracts WHERE id = {$contractId}")->fetchColumn(),
    'the contract row holds whatever was applied last'
);

$position = $service->effectivePosition($ctx, $contractId);

assert_same('150000.00', $position['current']['total_value'], 'the later-effective amendment decides the current value');
assert_same('English law', $position['current']['governing_law'], 'a field only one amendment touched keeps that value');
assert_count(2, $position['amendments'], 'only executed amendments are in the position');
assert_same(2, $position['amendments'][0]['amendment_no'], 'the earlier-effective amendment is overlaid first');
assert_same(1, $position['amendments'][1]['amendment_no'], 'and the later-effective one last');

assert_same(1, $position['overrides']['total_value']['amendment_no'], 'the value is attributed to amendment 1');
assert_same('2027-01-01', $position['overrides']['total_value']['effective_date'], 'with the date it took effect');
assert_same(1, $position['overrides']['governing_law']['amendment_no'], 'the governing law is attributed to amendment 1');

// The draft is not part of the position until it is applied.
assert_false(
    array_key_exists('notice_period_days', $position['overrides']),
    'a draft amendment does not change the effective position'
);

// --- drafts can be edited and deleted ---------------------------------------
$edited = $service->update($ctx, (int) $a3['id'], [
    'title'           => 'Notice period change (revised)',
    'effective_date'  => '2026-07-01',
    'status'          => 'under_review',
    'affected_fields' => ['notice_period_days' => 120],
]);
assert_same('Notice period change (revised)', $edited['title'], 'a draft amendment can be retitled');
assert_same(120, $edited['affected_fields']['notice_period_days']['to'], 'and its terms changed');
assert_same('under_review', $edited['status'], 'and moved along its own workflow');

try {
    $service->update($ctx, (int) $a3['id'], ['status' => 'executed']);
    t_fail('status cannot be set to executed by hand', 'expected a throw, but the call returned normally');
} catch (\App\Support\ValidationFailed $e) {
    assert_contains('Apply the amendment', $e->errors['status'] ?? '', 'status cannot be set to executed by hand');
}

$draft = $service->create($ctx, $contractId, [
    'title'           => 'Abandoned idea',
    'affected_fields' => ['notes' => 'never mind'],
]);
$service->delete($ctx, (int) $draft['id']);
assert_null($service->find($ctx, (int) $draft['id']), 'a draft amendment can be deleted');

// --- tenant scoping ----------------------------------------------------------
$otherCtx = t_context(cmpId: 2, uuid: 'USER-B');
assert_null($service->find($otherCtx, (int) $a1['id']), 'another company cannot read this amendment');
assert_count(0, $service->listForContract($otherCtx, $contractId), 'nor list the amendments of this contract');
assert_throws(
    static fn () => $service->apply($otherCtx, (int) $a3['id']),
    'nor apply one',
    'not found'
);

t_done('AmendmentServiceTest');
