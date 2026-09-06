<?php

declare(strict_types=1);

/**
 * The renewal clock: that a cycle is opened once and only once, that renewing
 * extends the contract and opens the next cycle, and that the nightly sweep
 * cannot undo a decision somebody has already made.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\RenewalService;
use App\Support\Dates;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx     = t_context();
$service = new RenewalService($pdo);

// Fixture contracts expire five years out so that nothing in this file falls
// inside a real alert ladder by accident: the only cycles the sweep should find
// are the ones a test has deliberately moved into the past.
$year        = (int) date('Y') + 5;
$baseExpiry  = sprintf('%d-12-31', $year);
$baseNotice  = sprintf('%d-11-01', $year);          // 31 December less 60 days
$nextExpiry  = sprintf('%d-12-31', $year + 1);
$nextNotice  = sprintf('%d-11-01', $year + 1);

/**
 * Insert a contract directly.
 *
 * ContractService::create is not used here: it allocates a number from the
 * counter table and runs the whole validation path, none of which this suite is
 * testing, and going through it would make a renewal failure look like a
 * contract failure.
 */
$makeContract = static function (array $overrides = []) use ($pdo, $baseExpiry): int {
    static $seq = 0;
    $seq++;

    $row = array_merge([
        'contract_number'    => sprintf('CON-2026-%06d', $seq),
        'title'              => 'Renewal fixture ' . $seq,
        'status'             => 'active',
        'lifecycle_stage'    => 'active',
        'effective_date'     => '2025-01-01',
        'expiry_date'        => $baseExpiry,
        'notice_period_days' => 60,
        'renewal_frequency'  => 'annual',
        'auto_renewal'       => 'false',
        'owner_uuid'         => 'USER-A',
        'cmp_id'             => 1,
    ], $overrides);

    $row['notice_deadline'] = Dates::noticeDeadline($row['expiry_date'], $row['notice_period_days']);

    $st = $pdo->prepare(
        'INSERT INTO contracts
         (environment, cmp_id, contract_number, title, status, lifecycle_stage,
          effective_date, expiry_date, notice_period_days, notice_deadline,
          renewal_frequency, auto_renewal, owner_uuid, created_by)
         VALUES (:env, :cmp, :num, :title, :status, :stage, :eff, :exp, :days, :deadline,
                 :freq, :auto, :owner, :owner2)
         RETURNING id'
    );
    $st->execute([
        'env'      => 'sandbox',
        'cmp'      => $row['cmp_id'],
        'num'      => $row['contract_number'],
        'title'    => $row['title'],
        'status'   => $row['status'],
        'stage'    => $row['lifecycle_stage'],
        'eff'      => $row['effective_date'],
        'exp'      => $row['expiry_date'],
        'days'     => $row['notice_period_days'],
        'deadline' => $row['notice_deadline'],
        'freq'     => $row['renewal_frequency'],
        'auto'     => $row['auto_renewal'],
        'owner'    => $row['owner_uuid'],
        'owner2'   => $row['owner_uuid'],
    ]);

    return (int) $st->fetchColumn();
};

// --- ensureCycle is idempotent ----------------------------------------------
$contractId = $makeContract();

$first = $service->ensureCycle($ctx, $contractId);
assert_not_null($first, 'ensureCycle opens a cycle for an active contract with an expiry date');
assert_same(1, $first['cycle_no'], 'the first cycle is cycle 1');
assert_same('not_yet_due', $first['status'], 'a new cycle starts not_yet_due');
assert_same($baseExpiry, $first['current_expiry'], 'the cycle carries the contract expiry');
assert_same($baseNotice, $first['notice_deadline'], 'the cycle carries the notice deadline (expiry - 60 days)');
assert_same($baseNotice, $first['decision_due_date'], 'the decision is due by the notice deadline');
assert_same(12, $first['renewal_term_months'], 'an annual renewal frequency means a 12-month term');

$second = $service->ensureCycle($ctx, $contractId);
assert_same($first['id'], $second['id'], 'ensureCycle called twice returns the same cycle');
assert_count(1, $service->listForContract($ctx, $contractId), 'ensureCycle called twice creates one cycle');

// --- a contract with no expiry date has nothing to renew ---------------------
$perpetualId = $makeContract(['expiry_date' => null, 'notice_period_days' => null]);
assert_null($service->ensureCycle($ctx, $perpetualId), 'a contract with no expiry date gets no renewal cycle');

// --- a renew decision extends the contract and opens the next cycle ----------
$decided = $service->recordDecision($ctx, (int) $first['id'], 'renew', ['notes' => 'Counterparty agreed terms']);

assert_same('renewed', $decided['status'], 'the decided cycle closes as renewed');
assert_same('renew', $decided['decision'], 'the decision is recorded');
assert_same('USER-A', $decided['decision_by'], 'the deciding user is recorded');

$contract = $pdo->query("SELECT expiry_date, notice_deadline FROM contracts WHERE id = {$contractId}")->fetch();
assert_same($nextExpiry, $contract['expiry_date'], 'renewing extends the expiry by the renewal term');
assert_same($nextNotice, $contract['notice_deadline'], 'the derived notice deadline moves with the expiry');

$next = $decided['next_cycle'];
assert_same(2, $next['cycle_no'], 'renewing opens the next cycle');
assert_same('not_yet_due', $next['status'], 'the next cycle starts not_yet_due');
assert_same($nextExpiry, $next['current_expiry'], 'the next cycle counts down to the new expiry');
assert_count(2, $service->listForContract($ctx, $contractId), 'the contract now has two cycles');

// The point of keeping both rows: the first cycle still says what the term was
// when it was decided.
$cycles = $service->listForContract($ctx, $contractId);
assert_same($baseExpiry, $cycles[1]['current_expiry'], 'the closed cycle keeps the expiry it was decided against');

// --- an explicit term overrides the contract's frequency ---------------------
$shortId    = $makeContract(['expiry_date' => sprintf('%d-06-30', $year), 'renewal_frequency' => null]);
$shortCycle = $service->ensureCycle($ctx, $shortId);
$service->recordDecision($ctx, (int) $shortCycle['id'], 'renew', ['renewal_term_months' => 6]);
$shortExpiry = $pdo->query("SELECT expiry_date FROM contracts WHERE id = {$shortId}")->fetchColumn();
// Dates::addMonths keeps the day of the month, so six months from 30 June is
// 30 December rather than a whole-month boundary.
assert_same(sprintf('%d-12-30', $year), $shortExpiry, 'an explicit term of 6 months is what the expiry moves by');

// --- terminate leaves the contract alone ------------------------------------
$termId    = $makeContract();
$termCycle = $service->ensureCycle($ctx, $termId);
$terminated = $service->recordDecision($ctx, (int) $termCycle['id'], 'terminate', []);
assert_same('terminate', $terminated['status'], 'a terminate decision marks the cycle');
assert_same(
    'active',
    $pdo->query("SELECT status FROM contracts WHERE id = {$termId}")->fetchColumn(),
    'a terminate decision does not itself end the contract'
);

// --- a decided cycle refuses a second decision -------------------------------
assert_throws(
    static fn () => $service->recordDecision($ctx, (int) $first['id'], 'renegotiate', []),
    'a renewed cycle refuses a further decision',
    'already closed'
);

// --- the sweep opens due cycles and never reopens decided ones ---------------
// Deadlines are moved into the past directly so the sweep has something to find
// without the test depending on today's date.
$dueId    = $makeContract();
$dueCycle = $service->ensureCycle($ctx, $dueId);
$pdo->prepare('UPDATE contract_renewals SET decision_due_date = CURRENT_DATE - 1 WHERE id = ?')
    ->execute([(int) $dueCycle['id']]);

$decidedCycleId = (int) $next['id'];
$pdo->prepare('UPDATE contract_renewals SET decision_due_date = CURRENT_DATE - 1, notice_deadline = CURRENT_DATE WHERE id = ?')
    ->execute([$decidedCycleId]);
$service->recordDecision($ctx, $decidedCycleId, 'renegotiate', ['notes' => 'Pricing under discussion']);

$result = $service->scanDue('sandbox', 1);
assert_same(1, $result['opened'], 'the sweep opens the one cycle that is due');
assert_same('review_due', $service->find($ctx, (int) $dueCycle['id'])['status'], 'the due cycle moved to review_due');
assert_same(
    'renegotiate',
    $service->find($ctx, $decidedCycleId)['status'],
    'the sweep does not reopen a cycle that has already been decided'
);

$again = $service->scanDue('sandbox', 1);
assert_same(0, $again['opened'], 'running the sweep twice opens nothing the second time');
assert_same('review_due', $service->find($ctx, (int) $dueCycle['id'])['status'], 'a cycle already in review stays there');

// --- the notice ladder opens a cycle before its decision date ----------------
$ladderId    = $makeContract();
$ladderCycle = $service->ensureCycle($ctx, $ladderId);
$pdo->prepare(
    'UPDATE contract_renewals SET decision_due_date = CURRENT_DATE + 200, notice_deadline = CURRENT_DATE + 10 WHERE id = ?'
)->execute([(int) $ladderCycle['id']]);

$ladderResult = $service->scanDue('sandbox', 1);
assert_same(1, $ladderResult['opened'], 'a notice deadline inside the alert ladder opens the cycle');
assert_true($ladderResult['notice_due'] >= 1, 'the sweep reports the cycles inside their notice window');

// --- a decision cannot be recorded on another company's cycle ----------------
$otherCtx = t_context(cmpId: 2, uuid: 'USER-B');
assert_throws(
    static fn () => $service->recordDecision($otherCtx, (int) $dueCycle['id'], 'renew', []),
    'a cycle belonging to another company is not found',
    'not found'
);
assert_null($service->find($otherCtx, (int) $dueCycle['id']), 'find is scoped to the tenant');

// --- recommendations ---------------------------------------------------------
$advised = $service->setRecommendation($ctx, (int) $dueCycle['id'], 'renew', 'Usage is up 40% year on year.', 'ai');
assert_same('renew', $advised['recommendation'], 'the recommendation is stored');
assert_same('ai', $advised['recommendation_source'], 'the recommendation source is stored');

assert_throws(
    static fn () => $service->setRecommendation($ctx, $decidedCycleId, 'terminate', 'Too late.', 'rules'),
    'a decided cycle refuses a recommendation recorded after the fact',
    'already been decided'
);

// --- the pipeline buckets ----------------------------------------------------
$riskId = $makeContract(['auto_renewal' => 'true']);
$risk   = $service->ensureCycle($ctx, $riskId);
$pdo->prepare('UPDATE contract_renewals SET notice_deadline = CURRENT_DATE + 5, current_expiry = CURRENT_DATE + 20 WHERE id = ?')
    ->execute([(int) $risk['id']]);

$autoRisk = $service->pipeline($ctx, ['bucket' => 'auto_renewal_risk'], 25, 0);
assert_same(1, $autoRisk['total'], 'the auto-renewal risk bucket finds the undecided auto-renewing contract');
assert_same((int) $risk['id'], $autoRisk['items'][0]['id'], 'and it is the right cycle');

$expiring30 = $service->pipeline($ctx, ['bucket' => 'expiring_30'], 25, 0);
assert_same(1, $expiring30['total'], 'the 30-day bucket finds only the cycle expiring inside 30 days');

$all = $service->pipeline($ctx, ['bucket' => 'all'], 25, 0);
assert_true($all['total'] >= 6, 'the all bucket returns every cycle in the company');

$byStatus = $service->pipeline($ctx, ['bucket' => 'all', 'status' => 'renewed'], 25, 0);
assert_same(2, $byStatus['total'], 'the status filter narrows the queue to the renewed cycles');

$otherCompany = $service->pipeline($otherCtx, ['bucket' => 'all'], 25, 0);
assert_same(0, $otherCompany['total'], 'another company sees none of this renewal queue');

t_done('RenewalServiceTest');
