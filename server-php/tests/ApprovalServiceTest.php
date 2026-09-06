<?php

declare(strict_types=1);

/**
 * Approval routing and the run it produces: that conditions decide which
 * workflow applies, that a step advances only when it has the approvals it
 * asked for, that only the people a step names can act on it, and that the
 * overdue sweep can be run twice without escalating anything twice.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ApprovalService;
use App\Services\RoleService;
use App\Services\WorkflowMatcher;
use App\Support\Permissions;
use App\Support\TenantContext;

// ---------------------------------------------------------------------------
// The matcher — pure, so it runs whether or not a database is reachable
// ---------------------------------------------------------------------------

$subject = [
    'contract_type_id'         => 3,
    'department_id'            => 2,
    'total_value'              => '250000.00',
    'currency'                 => 'INR',
    'risk_level'               => 'high',
    'ai_risk_score'            => 72,
    // As PostgreSQL hands it back, not as PHP would like it.
    'auto_renewal'             => 'f',
    'governing_law'            => 'India',
    'notice_period_days'       => 60,
    'effective_date'           => '2026-01-01',
    'expiry_date'              => '2027-01-01',
    'has_non_standard_clauses' => true,
];

$cond = static fn (string $field, string $operator, mixed $value = null): array
    => ['field' => $field, 'operator' => $operator, 'value' => $value];

assert_true(
    WorkflowMatcher::matches($subject, [$cond('total_value', 'gte', 100000)], 'all'),
    'matcher: a satisfied numeric condition matches'
);
assert_false(
    WorkflowMatcher::matches($subject, [$cond('total_value', 'gte', 1000000)], 'all'),
    'matcher: an unsatisfied numeric condition does not match'
);

// A single failing condition sinks the whole workflow under 'all' and is
// tolerated under 'any' — this is the difference that decides routing.
$mixed = [$cond('risk_level', 'eq', 'high'), $cond('currency', 'eq', 'USD')];
assert_false(WorkflowMatcher::matches($subject, $mixed, 'all'), 'matcher: all mode fails on one bad condition');
assert_true(WorkflowMatcher::matches($subject, $mixed, 'any'), 'matcher: any mode passes on one good condition');

// An unknown operator is an unmatched condition, never an exception: a settings
// screen that has run ahead of the server must route to the next workflow
// rather than make the contract unsubmittable.
assert_false(
    WorkflowMatcher::matches($subject, [$cond('total_value', 'regex', '/^2/')], 'all'),
    'matcher: an unknown operator does not match'
);
assert_true(
    WorkflowMatcher::matches($subject, [$cond('total_value', 'regex', '/^2/'), $cond('risk_level', 'eq', 'high')], 'any'),
    'matcher: an unknown operator is skipped, not fatal'
);
assert_false(
    WorkflowMatcher::matches($subject, [$cond('shell_command', 'eq', 'rm -rf /')], 'all'),
    'matcher: an unknown field does not match'
);

assert_true(WorkflowMatcher::matches($subject, [$cond('currency', 'in', ['INR', 'AED'])], 'all'), 'matcher: in');
assert_false(WorkflowMatcher::matches($subject, [$cond('currency', 'not_in', ['INR'])], 'all'), 'matcher: not_in');
assert_true(WorkflowMatcher::matches($subject, [$cond('has_non_standard_clauses', 'is_true')], 'all'), 'matcher: is_true');
assert_true(WorkflowMatcher::matches($subject, [$cond('auto_renewal', 'is_false')], 'all'), 'matcher: is_false reads pg false');
assert_true(WorkflowMatcher::matches($subject, [$cond('duration_months', 'gte', 12)], 'all'), 'matcher: derived duration_months');
assert_false(WorkflowMatcher::matches($subject, [$cond('duration_months', 'gt', 12)], 'all'), 'matcher: duration is whole months');
assert_true(WorkflowMatcher::matches($subject, [], 'any'), 'matcher: no conditions is a catch-all');

// A field the subject does not carry cannot satisfy a condition about it.
assert_false(
    WorkflowMatcher::matches(['total_value' => null], [$cond('total_value', 'lt', 100)], 'all'),
    'matcher: a missing value never matches'
);

assert_same(
    'High value',
    WorkflowMatcher::firstMatch($subject, [
        ['id' => 1, 'name' => 'Catch all',  'conditions' => [],                                    'match_mode' => 'all', 'priority' => 100],
        ['id' => 2, 'name' => 'High value', 'conditions' => [$cond('total_value', 'gte', 100000)], 'match_mode' => 'all', 'priority' => 10],
    ])['name'],
    'matcher: lowest priority wins'
);
assert_null(
    WorkflowMatcher::firstMatch($subject, [
        ['id' => 1, 'name' => 'USD only', 'conditions' => json_encode([$cond('currency', 'eq', 'USD')]), 'match_mode' => 'all', 'priority' => 1],
    ]),
    'matcher: no workflow matches when every condition fails'
);

// ---------------------------------------------------------------------------
// The run
// ---------------------------------------------------------------------------

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);
RoleService::resetMemo();

$ctx     = t_context();                       // USER-A, contract_admin
$service = new ApprovalService($pdo);

/** An approver's context: deliberately not contract_admin, so the step gate is real. */
$actor = static function (string $uuid, array $roles = ['approver']): TenantContext {
    return new TenantContext(
        uuid: $uuid,
        sesKey: 'test-ses-key',
        cmpId: 1,
        fyId: 1,
        boId: 1,
        environment: 'sandbox',
        company: ['cmp_id' => 1, 'legal_name' => 'Test Company 1', 'currency' => 'INR'],
        permissions: Permissions::forRoles($roles),
        roles: $roles,
    );
};

$pdo->prepare('INSERT INTO contract_departments (environment, cmp_id, name, code, head_uuid) VALUES (?, ?, ?, ?, ?)')
    ->execute(['sandbox', 1, 'Legal', 'legal', 'USER-HEAD']);
$deptId = (int) $pdo->query('SELECT id FROM contract_departments LIMIT 1')->fetchColumn();

foreach (['USER-D', 'USER-E', 'USER-F'] as $financeUser) {
    RoleService::grant('sandbox', 1, $financeUser, 'finance');
}

/** @return int workflow id */
$makeWorkflow = static function (string $name, array $conditions, int $priority, array $steps) use ($pdo): int {
    $st = $pdo->prepare(
        'INSERT INTO approval_workflows (environment, cmp_id, name, applies_to, conditions, match_mode, priority)
         VALUES (?, ?, ?, \'contract\', ?::jsonb, \'all\', ?) RETURNING id'
    );
    $st->execute(['sandbox', 1, $name, json_encode($conditions), $priority]);
    $workflowId = (int) $st->fetchColumn();

    foreach ($steps as $step) {
        $pdo->prepare(
            'INSERT INTO approval_workflow_steps
             (workflow_id, environment, cmp_id, step_no, name, execution, approver_type, approver_value,
              min_approvals, escalation_days, escalate_to_uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $workflowId,
            'sandbox',
            1,
            $step['step_no'],
            $step['name'],
            $step['execution'] ?? 'sequential',
            $step['approver_type'],
            $step['approver_value'] ?? null,
            $step['min_approvals'] ?? 1,
            $step['escalation_days'] ?? null,
            $step['escalate_to_uuid'] ?? null,
        ]);
    }

    return $workflowId;
};

$makeWorkflow('Standard', [], 100, [
    ['step_no' => 1, 'name' => 'Legal review', 'approver_type' => 'user', 'approver_value' => 'USER-B',
     'escalation_days' => 2, 'escalate_to_uuid' => 'USER-ESC'],
    ['step_no' => 2, 'name' => 'Head of Legal', 'approver_type' => 'user', 'approver_value' => 'USER-C'],
]);

$makeWorkflow('High value', [$cond('total_value', 'gte', 1000000)], 10, [
    ['step_no' => 1, 'name' => 'Finance panel', 'execution' => 'parallel', 'approver_type' => 'role',
     'approver_value' => 'finance', 'min_approvals' => 2],
]);

$seq = 0;
$makeContract = static function (array $overrides = []) use ($pdo, $deptId, &$seq): int {
    $seq++;
    $row = array_merge([
        'title'       => 'Approval fixture ' . $seq,
        'status'      => 'draft',
        'total_value' => '250000.00',
        'owner_uuid'  => 'USER-OWNER',
    ], $overrides);

    $st = $pdo->prepare(
        'INSERT INTO contracts
         (environment, cmp_id, contract_number, title, status, lifecycle_stage, department_id,
          currency, total_value, effective_date, expiry_date, owner_uuid, created_by)
         VALUES (?, ?, ?, ?, ?, \'draft\', ?, \'INR\', ?, \'2026-01-01\', \'2027-01-01\', ?, ?)
         RETURNING id'
    );
    $st->execute([
        'sandbox',
        1,
        sprintf('CON-2026-%06d', $seq),
        $row['title'],
        $row['status'],
        $deptId,
        $row['total_value'],
        $row['owner_uuid'],
        'USER-A',
    ]);

    return (int) $st->fetchColumn();
};

$contractStatus = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT status, approval_status FROM contracts WHERE id = ?');
    $st->execute([$id]);

    return $st->fetch() ?: [];
};

// --- Routing: the priority-10 workflow's one condition fails, so the run falls
// through to the catch-all rather than being refused.
$lowValue = $makeContract();
$instance = $service->submit($ctx, 'contract', $lowValue, null);

assert_same('Standard', $instance['workflow_name'], 'submit: a failing condition falls through to the catch-all');
assert_same('in_progress', $instance['status'], 'submit: the instance opens in progress');
assert_same(1, $instance['current_step'], 'submit: the run starts at step 1');
assert_count(1, $instance['assignments'], 'submit: step 1 assigns one approver');
assert_same('USER-B', $instance['assignments'][0]['approver_uuid'], 'submit: the step names USER-B');
assert_same('awaiting_approval', $contractStatus($lowValue)['status'], 'submit: the contract is awaiting approval');
assert_same('pending', $contractStatus($lowValue)['approval_status'], 'submit: approval_status is pending');

// The snapshot is what protects an in-flight run from a workflow edit.
assert_count(2, $instance['steps_snapshot'], 'submit: both steps are frozen onto the instance');
$pdo->exec("UPDATE approval_workflow_steps SET approver_value = 'USER-INTRUDER' WHERE approver_value = 'USER-C'");
assert_same(
    'USER-C',
    $service->findOrFail($ctx, (int) $instance['id'])['steps_snapshot'][1]['approver_value'],
    'submit: editing the workflow does not change an open run'
);
$pdo->exec("UPDATE approval_workflow_steps SET approver_value = 'USER-C' WHERE approver_value = 'USER-INTRUDER'");

assert_throws(
    static fn () => $service->submit($ctx, 'contract', $lowValue, null),
    'submit: a second open instance is refused',
    'already awaiting approval'
);

// --- Only an assignee of the current step may act.
$instanceId = (int) $instance['id'];

assert_throws(
    static fn () => $service->act($actor('USER-Z'), $instanceId, 'approve'),
    'act: a stranger cannot approve',
    'not assigned to you'
);
assert_throws(
    static fn () => $service->act($actor('USER-C'), $instanceId, 'approve'),
    'act: a later step\'s approver cannot approve the current one',
    'not assigned to you'
);

// --- Sequential advance.
$after = $service->act($actor('USER-B'), $instanceId, 'approve', ['comment' => 'Looks fine.']);
assert_same(2, $after['current_step'], 'act: approving step 1 advances to step 2');
assert_same('in_progress', $after['status'], 'act: the run is still open at step 2');
assert_same('awaiting_approval', $contractStatus($lowValue)['status'], 'act: the contract stays in approval mid-run');
assert_count(2, $after['assignments'], 'act: step 2 assignments are created on advance');

$queue = $service->myQueue($actor('USER-C'), 20, 0);
assert_same(1, $queue['total'], 'myQueue: the open step is on the new approver\'s desk');
$staleQueue = $service->myQueue($actor('USER-B'), 20, 0);
assert_same(0, $staleQueue['total'], 'myQueue: a completed step leaves the queue');

$final = $service->act($actor('USER-C'), $instanceId, 'approve');
assert_same('approved', $final['status'], 'act: the last step closes the instance');
assert_same('approved', $contractStatus($lowValue)['status'], 'act: final approval approves the contract');
assert_same('approved', $contractStatus($lowValue)['approval_status'], 'act: approval_status is approved');
// Two approvals, and nothing from the three refusals: a refused action rolls
// back, so the append-only log carries decisions and not attempts.
assert_count(2, $final['actions'], 'act: every accepted action is recorded, and only those');

assert_throws(
    static fn () => $service->act($actor('USER-C'), $instanceId, 'approve'),
    'act: a closed instance refuses further action',
    'already closed'
);

// --- Parallel step: min_approvals must be met before the run advances.
$highValue = $makeContract(['total_value' => '2000000.00']);
$parallel  = $service->submit($ctx, 'contract', $highValue, null);

assert_same('High value', $parallel['workflow_name'], 'submit: a matching condition beats the catch-all on priority');
assert_count(3, $parallel['assignments'], 'submit: the role resolves to all three finance users');

$parallelId = (int) $parallel['id'];
$oneOf      = $service->act($actor('USER-D'), $parallelId, 'approve');
assert_same('in_progress', $oneOf['status'], 'parallel: one approval of two does not close the step');
assert_same('pending', $contractStatus($highValue)['approval_status'], 'parallel: the contract waits for the quorum');

$twoOf = $service->act($actor('USER-E'), $parallelId, 'approve');
assert_same('approved', $twoOf['status'], 'parallel: min_approvals closes the step');
assert_same('approved', $contractStatus($highValue)['status'], 'parallel: the contract is approved');

$leftover = array_values(array_filter(
    $twoOf['assignments'],
    static fn (array $a): bool => $a['approver_uuid'] === 'USER-F'
));
assert_same('skipped', $leftover[0]['status'], 'parallel: the surplus approver is skipped, not left pending');

// --- Rejection returns the contract to draft.
$rejected   = $makeContract();
$rejectRun  = $service->submit($ctx, 'contract', $rejected, null);
$rejectId   = (int) $rejectRun['id'];
$rejectDone = $service->act($actor('USER-B'), $rejectId, 'reject', ['comment' => 'Indemnity is unacceptable.']);

assert_same('rejected', $rejectDone['status'], 'reject: the instance is rejected');
assert_same('draft', $contractStatus($rejected)['status'], 'reject: the contract returns to draft');
assert_same('rejected', $contractStatus($rejected)['approval_status'], 'reject: approval_status is rejected');
assert_count(1, $service->listForSubject($ctx, 'contract', $rejected), 'listForSubject: the closed run is still listed');

// A rejected run is closed, so the contract can be corrected and resubmitted.
$resubmitted = $service->submit($ctx, 'contract', $rejected, null);
assert_same('in_progress', $resubmitted['status'], 'reject: a corrected contract can be resubmitted');
$service->cancel($ctx, (int) $resubmitted['id'], 'Raised in error.');
assert_same('draft', $contractStatus($rejected)['status'], 'cancel: the contract returns to draft');
assert_same('not_required', $contractStatus($rejected)['approval_status'], 'cancel: approval_status is cleared');

// --- Approver resolution.
assert_same(
    ['USER-HEAD'],
    $service->resolveApprovers($ctx, ['approver_type' => 'department_head'], ['department_id' => $deptId]),
    'resolveApprovers: department_head reads the department'
);
assert_same(
    ['USER-OWNER'],
    $service->resolveApprovers($ctx, ['approver_type' => 'contract_owner'], ['owner_uuid' => 'USER-OWNER']),
    'resolveApprovers: contract_owner reads the contract'
);
assert_same(
    [],
    $service->resolveApprovers($ctx, ['approver_type' => 'chief_wizard'], []),
    'resolveApprovers: an unknown approver type resolves to nobody'
);

// --- Escalation, twice.
$overdue     = $makeContract();
$overdueRun  = $service->submit($ctx, 'contract', $overdue, null);
$overdueId   = (int) $overdueRun['id'];

assert_not_null($overdueRun['assignments'][0]['due_at'], 'submit: the step SLA becomes a due date');

$pdo->prepare(
    'UPDATE contract_approval_assignments SET due_at = CURRENT_TIMESTAMP - INTERVAL \'1 day\'
     WHERE instance_id = ? AND approver_uuid = ?'
)->execute([$overdueId, 'USER-B']);

assert_same(1, $service->escalateOverdue('sandbox', 1), 'escalate: the overdue assignment is escalated');
assert_same(0, $service->escalateOverdue('sandbox', 1), 'escalate: a second run finds nothing left to claim');

$escalated = $service->findOrFail($ctx, $overdueId);
assert_count(2, $escalated['assignments'], 'escalate: exactly one escalation assignment is created');

$original = array_values(array_filter($escalated['assignments'], static fn (array $a): bool => $a['approver_uuid'] === 'USER-B'));
assert_not_null($original[0]['escalated_at'], 'escalate: the original assignment is stamped');
assert_same('pending', $original[0]['status'], 'escalate: the original approver may still act');

$standIn = array_values(array_filter($escalated['assignments'], static fn (array $a): bool => $a['approver_uuid'] === 'USER-ESC'));
assert_same('USER-B', $standIn[0]['delegated_from'], 'escalate: the stand-in is recorded as delegated');
assert_null($standIn[0]['due_at'], 'escalate: an escalation carries no deadline of its own');

$escalateActions = array_values(array_filter(
    $escalated['actions'],
    static fn (array $a): bool => $a['action'] === 'escalate'
));
assert_count(1, $escalateActions, 'escalate: one escalate action, not two');

// The stand-in can act, which is the whole point of escalating.
$standInDone = $service->act($actor('USER-ESC'), $overdueId, 'approve');
assert_same(2, $standInDone['current_step'], 'escalate: the stand-in can complete the step');

// --- Tenant isolation: another company cannot see or act on this run.
$other = t_context(cmpId: 2, uuid: 'USER-A');
assert_null($service->find($other, $overdueId), 'tenant: another company cannot read the instance');
assert_same(0, $service->myQueue($other, 20, 0)['total'], 'tenant: another company has an empty queue');

t_done('ApprovalServiceTest');
