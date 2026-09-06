<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use App\Support\Validator;
use PDO;

/**
 * Running an approval: matching a workflow to a record, opening its steps, and
 * recording what each approver did.
 *
 * The instance owns a frozen copy of the workflow's steps. A company that edits
 * its routing on Tuesday must not change who was supposed to approve the
 * contract submitted on Monday — the audit question is always "who was asked",
 * and a live join to `approval_workflow_steps` answers "who would be asked
 * today", which is a different and useless answer.
 *
 * `contract_approval_actions` is append-only and is the record of intent;
 * `contract_approval_assignments` is the mutable working set that answers "what
 * is on my desk" in one indexed read. Both are written for every action, and
 * neither is derived from the other.
 */
final class ApprovalService
{
    /** Instance statuses that still expect somebody to act. */
    private const OPEN_STATUSES = ['pending', 'in_progress'];

    /** Mirrors `ck_approval_instances_subject` and `ck_approval_workflows_applies`. */
    private const SUBJECT_TYPES = ['contract', 'request', 'amendment', 'termination', 'renewal'];

    /** Actions that end the run and hand the record back to its author. */
    private const RETURNING_ACTIONS = ['reject', 'send_back', 'request_changes'];

    /** Fallback approval window when neither the step, the workflow nor the company sets one. */
    private const DEFAULT_ESCALATION_DAYS = 3;

    private AuditService $audit;

    private ActivityService $activity;

    public function __construct(private PDO $pdo)
    {
        $this->audit    = new AuditService($pdo);
        $this->activity = new ActivityService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(TenantContext $ctx, int $instanceId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT i.*, c.contract_number, c.title AS contract_title, c.status AS contract_status
             FROM contract_approval_instances i
             LEFT JOIN contracts c ON c.id = i.contract_id
             WHERE i.id = ? AND i.environment = ? AND i.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$instanceId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrate($ctx, $row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $instanceId): array
    {
        $row = $this->find($ctx, $instanceId);
        if ($row === null) {
            throw DomainException::notFound('Approval not found.');
        }

        return $row;
    }

    /**
     * Every approval run against one record, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listForSubject(TenantContext $ctx, string $subjectType, int $subjectId): array
    {
        $st = $this->pdo->prepare(
            'SELECT i.*, c.contract_number, c.title AS contract_title, c.status AS contract_status
             FROM contract_approval_instances i
             LEFT JOIN contracts c ON c.id = i.contract_id
             WHERE i.environment = ? AND i.cmp_id = ? AND i.subject_type = ? AND i.subject_id = ?
             ORDER BY i.submitted_at DESC, i.id DESC'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $subjectType, $subjectId]);

        return array_map(fn (array $r): array => $this->hydrate($ctx, $r), $st->fetchAll() ?: []);
    }

    /**
     * What is waiting on the caller.
     *
     * Restricted to the instance's current step: an assignment left pending on
     * a step the run has already moved past is history, not work, and showing
     * it would train people to ignore the queue.
     *
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function myQueue(TenantContext $ctx, int $limit, int $offset): array
    {
        $where = 'WHERE a.environment = :env AND a.cmp_id = :cmp
                    AND a.approver_uuid = :me AND a.status = \'pending\'
                    AND i.status IN (\'pending\', \'in_progress\')
                    AND a.step_no = i.current_step';
        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'me' => $ctx->uuid];

        $countSt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM contract_approval_assignments a
             JOIN contract_approval_instances i ON i.id = a.instance_id
             {$where}"
        );
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $st = $this->pdo->prepare(
            "SELECT a.id AS assignment_id, a.instance_id, a.step_no, a.step_name, a.status,
                    a.assigned_at, a.due_at, a.escalated_at, a.delegated_from,
                    i.uuid AS instance_uuid, i.subject_type, i.subject_id, i.contract_id,
                    i.workflow_name, i.status AS instance_status, i.current_step, i.submitted_by,
                    i.submitted_at,
                    c.contract_number, c.title AS contract_title, c.counterparty_name,
                    c.currency, c.total_value, c.risk_level,
                    (a.due_at IS NOT NULL AND a.due_at <= CURRENT_TIMESTAMP) AS is_overdue
             FROM contract_approval_assignments a
             JOIN contract_approval_instances i ON i.id = a.instance_id
             LEFT JOIN contracts c ON c.id = i.contract_id
             {$where}
             ORDER BY a.due_at ASC NULLS LAST, a.assigned_at ASC, a.id ASC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        $items = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            foreach (['assignment_id', 'instance_id', 'step_no', 'subject_id', 'contract_id', 'current_step'] as $key) {
                if (isset($row[$key])) {
                    $row[$key] = (int) $row[$key];
                }
            }
            $row['is_overdue'] = ContractService::toBool($row['is_overdue'] ?? false);
            $items[]           = $row;
        }

        return ['items' => $items, 'total' => $total];
    }

    // -----------------------------------------------------------------------
    // Submitting
    // -----------------------------------------------------------------------

    /**
     * Route a record into approval.
     *
     * @param string   $subjectType contract, request, amendment, termination or renewal
     * @param int|null $contractId  the contract this run is about, when the subject is not itself one
     * @return array<string,mixed> the open instance
     */
    public function submit(TenantContext $ctx, string $subjectType, int $subjectId, ?int $contractId): array
    {
        if (! in_array($subjectType, self::SUBJECT_TYPES, true)) {
            throw DomainException::badRequest('Unknown approval subject.');
        }

        // A contract submitting itself does not need to say so twice, and a
        // null contract_id here would cost every later query its join.
        if ($contractId === null && $subjectType === 'contract') {
            $contractId = $subjectId;
        }

        if ($this->openInstanceId($ctx, $subjectType, $subjectId) !== null) {
            throw DomainException::conflict('This record is already awaiting approval.', 'APPROVAL_ALREADY_OPEN');
        }

        $contract = $contractId === null ? null : (new ContractService($this->pdo))->find($ctx, $contractId);
        if ($contractId !== null && $contract === null) {
            throw DomainException::notFound('Contract not found.');
        }

        if ($contract !== null) {
            $from = (string) $contract['status'];
            if ($from !== 'awaiting_approval' && ! ContractService::transitionAllowed($from, 'awaiting_approval')) {
                throw DomainException::conflict(
                    sprintf('A %s contract cannot be sent for approval.', Enums::label($from)),
                    'INVALID_STATUS_TRANSITION'
                );
            }
        }

        $facts    = $this->matchFacts($ctx, $contract);
        $workflow = WorkflowMatcher::firstMatch($facts, $this->activeWorkflows($ctx, $subjectType));

        if ($workflow === null) {
            throw DomainException::conflict(
                'No approval workflow matches this record. Ask an administrator to configure approval routing.',
                'NO_WORKFLOW_MATCH'
            );
        }

        $snapshot = $this->snapshotSteps($ctx, (int) $workflow['id'], $workflow);
        if ($snapshot === []) {
            throw DomainException::conflict('That approval workflow has no steps.', 'WORKFLOW_HAS_NO_STEPS');
        }

        $firstStep = (int) $snapshot[0]['step_no'];

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $subjectType,
            $subjectId,
            $contractId,
            $contract,
            $workflow,
            $snapshot,
            $firstStep
        ): array {
            // Re-checked under the transaction: the check above ran before it,
            // and two submits racing each other would otherwise both pass and
            // leave the record with two live approvals and two sets of
            // approvers who each think theirs is the real one.
            if ($contractId !== null) {
                $pdo->prepare('SELECT id FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? FOR UPDATE')
                    ->execute([$contractId, $ctx->environment, $ctx->cmpId]);
            }
            if ($this->openInstanceId($ctx, $subjectType, $subjectId) !== null) {
                throw DomainException::conflict('This record is already awaiting approval.', 'APPROVAL_ALREADY_OPEN');
            }

            $st = $pdo->prepare(
                'INSERT INTO contract_approval_instances
                 (environment, cmp_id, subject_type, subject_id, contract_id, workflow_id,
                  workflow_name, status, current_step, steps_snapshot, submitted_by)
                 VALUES (:env, :cmp, :subject_type, :subject_id, :contract_id, :workflow_id,
                         :workflow_name, \'in_progress\', :step, :snapshot::jsonb, :actor)
                 RETURNING id'
            );
            $st->execute([
                'env'           => $ctx->environment,
                'cmp'           => $ctx->cmpId,
                'subject_type'  => $subjectType,
                'subject_id'    => $subjectId,
                'contract_id'   => $contractId,
                'workflow_id'   => (int) $workflow['id'],
                'workflow_name' => (string) $workflow['name'],
                'step'          => $firstStep,
                'snapshot'      => json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                'actor'         => $ctx->uuid,
            ]);

            $instanceId = (int) $st->fetchColumn();

            $opened = $this->openStep($ctx, $instanceId, $snapshot, $firstStep, $contract);
            if ($opened === 0) {
                // Rolls back the instance. An approval nobody is assigned to is
                // a contract that silently stops moving, which is worse than a
                // refusal the submitter can act on.
                throw DomainException::conflict(
                    'The matched workflow resolves to no approver. Check the workflow\'s first step.',
                    'NO_APPROVERS'
                );
            }

            $this->setContractApproval($ctx, $contractId, 'pending', 'awaiting_approval');

            $this->audit->log($ctx, 'approval', $instanceId, 'approval.submitted', $contractId, [
                'workflow'     => ['from' => null, 'to' => (string) $workflow['name']],
                'subject_type' => ['from' => null, 'to' => $subjectType],
                'subject_id'   => ['from' => null, 'to' => $subjectId],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'approval.submitted',
                sprintf('Sent for approval via %s', (string) $workflow['name']),
                ['instance_id' => $instanceId, 'approvers' => $opened]
            );

            return $this->findOrFail($ctx, $instanceId);
        });
    }

    // -----------------------------------------------------------------------
    // Acting
    // -----------------------------------------------------------------------

    /**
     * Record one approver's decision and move the run on.
     *
     * @param array<string,mixed> $body comment, and to_uuid for a reassignment
     * @return array<string,mixed> the instance as it now stands
     */
    public function act(TenantContext $ctx, int $instanceId, string $action, array $body = []): array
    {
        if (! in_array($action, Enums::APPROVAL_ACTIONS, true)) {
            throw DomainException::badRequest('Unknown approval action.');
        }

        $v       = new Validator($body);
        $comment = $v->optionalText('comment', 4000);
        $toUuid  = $action === 'reassign' ? $v->requiredString('to_uuid', 64) : null;
        $v->assert();

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $instanceId, $action, $comment, $toUuid): array {
            // Two approvers on the same parallel step can press approve at the
            // same moment. Without this lock both read the same approval count,
            // both decide the quorum is not met, and the step never advances.
            $pdo->prepare(
                'SELECT id FROM contract_approval_instances
                 WHERE id = ? AND environment = ? AND cmp_id = ? FOR UPDATE'
            )->execute([$instanceId, $ctx->environment, $ctx->cmpId]);

            $instance = $this->findOrFail($ctx, $instanceId);

            if (! in_array((string) $instance['status'], self::OPEN_STATUSES, true)) {
                throw DomainException::conflict('This approval is already closed.', 'APPROVAL_CLOSED');
            }

            $stepNo     = (int) $instance['current_step'];
            $contractId = $instance['contract_id'] === null ? null : (int) $instance['contract_id'];
            $assignment = $this->pendingAssignment($ctx, $instanceId, $stepNo, $ctx->uuid);
            $isAdmin    = $this->isApprovalAdmin($ctx);

            // The step, not the workflow, is the gate: someone assigned to step
            // 3 must not be able to approve step 1 out from under the people who
            // were meant to see it first.
            if ($assignment === null && ! $isAdmin) {
                throw DomainException::forbidden('This approval step is not assigned to you.');
            }

            $assignmentId = $assignment === null ? null : (int) $assignment['id'];

            if ($action === 'comment') {
                $this->recordAction($ctx, $instanceId, $assignmentId, $stepNo, 'comment', $comment);
                $this->activity->record($ctx, $contractId, 'approval.comment', 'Comment added on the approval', [
                    'instance_id' => $instanceId,
                    'step_no'     => $stepNo,
                ]);

                return $this->findOrFail($ctx, $instanceId);
            }

            if ($action === 'reassign') {
                return $this->reassign($ctx, $instance, $assignment, (string) $toUuid, $comment);
            }

            if (in_array($action, self::RETURNING_ACTIONS, true)) {
                return $this->closeReturning($ctx, $instance, $assignmentId, $action, $comment);
            }

            return $this->approve($ctx, $instance, $assignment, $isAdmin, $comment);
        });
    }

    /**
     * Abandon an open run.
     *
     * The submitter or an administrator only. An approver who disagrees rejects
     * or sends back — both of which are recorded as a decision; cancelling is
     * for a record that should never have been submitted, and letting an
     * approver do it would be a way to make an inconvenient approval disappear.
     *
     * @return array<string,mixed>
     */
    public function cancel(TenantContext $ctx, int $instanceId, string $reason): array
    {
        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $instanceId, $reason): array {
            $instance = $this->findOrFail($ctx, $instanceId);

            if (! in_array((string) $instance['status'], self::OPEN_STATUSES, true)) {
                throw DomainException::conflict('This approval is already closed.', 'APPROVAL_CLOSED');
            }

            if ((string) ($instance['submitted_by'] ?? '') !== $ctx->uuid && ! $this->isApprovalAdmin($ctx)) {
                throw DomainException::forbidden('Only the submitter or an administrator can cancel an approval.');
            }

            $contractId = $instance['contract_id'] === null ? null : (int) $instance['contract_id'];

            $this->closeInstance($ctx, $instanceId, 'cancelled', $reason);
            $this->skipPending($ctx, $instanceId, null);
            $this->recordAction($ctx, $instanceId, null, (int) $instance['current_step'], 'cancel', $reason);

            // Back to not_required rather than cancelled: the contract itself is
            // fine, it simply is not in an approval any more, and a leftover
            // terminal approval_status would keep it out of every "needs
            // approval" list forever.
            $this->setContractApproval($ctx, $contractId, 'not_required', 'draft');

            $this->audit->log($ctx, 'approval', $instanceId, 'approval.cancelled', $contractId, [
                'status' => ['from' => (string) $instance['status'], 'to' => 'cancelled'],
                'reason' => ['from' => null, 'to' => $reason],
            ]);
            $this->activity->record($ctx, $contractId, 'approval.cancelled', 'Approval cancelled', [
                'instance_id' => $instanceId,
                'reason'      => $reason,
            ]);

            return $this->findOrFail($ctx, $instanceId);
        });
    }

    // -----------------------------------------------------------------------
    // Routing
    // -----------------------------------------------------------------------

    /**
     * Who a step actually names.
     *
     * @param array<string,mixed> $step     one snapshot step
     * @param array<string,mixed> $contract the record being approved, or [] when there is none
     * @return list<string> approver uuids, de-duplicated
     */
    public function resolveApprovers(TenantContext $ctx, array $step, array $contract): array
    {
        $type  = (string) ($step['approver_type'] ?? '');
        $value = isset($step['approver_value']) && trim((string) $step['approver_value']) !== ''
            ? trim((string) $step['approver_value'])
            : null;

        $uuids = match ($type) {
            'user'            => $value === null ? [] : [$value],
            'role'            => $value === null ? [] : RoleService::usersWithRole($ctx->environment, $ctx->cmpId, $value),
            'department_head' => $this->departmentHeads($ctx, $value, $contract),
            'contract_owner'  => [
                (string) ($contract['owner_uuid'] ?? ''),
                (string) ($contract['created_by'] ?? ''),
            ],
            // Contracts holds no org chart — Manage does not expose one and this
            // product does not keep a second copy of one it cannot keep true. A
            // 'manager' step therefore means the uuid the step names, falling
            // back to the department head, and resolves to nobody if neither is
            // configured rather than guessing at a reporting line.
            'manager'         => $value !== null ? [$value] : $this->departmentHeads($ctx, null, $contract),
            default           => [],
        };

        $clean = [];
        foreach ($uuids as $uuid) {
            $uuid = trim((string) $uuid);
            if ($uuid !== '' && ! in_array($uuid, $clean, true)) {
                $clean[] = $uuid;

                // contract_owner lists the owner and the author as a fallback
                // pair, not as two approvers; the first that exists is the one.
                if ($type === 'contract_owner') {
                    break;
                }
            }
        }

        return $clean;
    }

    // -----------------------------------------------------------------------
    // The overdue sweep
    // -----------------------------------------------------------------------

    /**
     * Escalate assignments nobody acted on in time.
     *
     * Idempotent by construction: the claim and the stamp are one UPDATE
     * filtered on `escalated_at IS NULL`, so a second run — or two runs at
     * once — finds nothing left to claim. The escalation assignment itself is
     * created with no due date, because escalating an escalation has nowhere
     * further to go and a chain of them would be a loop.
     *
     * @return int assignments escalated
     */
    public function escalateOverdue(string $environment, ?int $cmpId = null): int
    {
        $sql = 'UPDATE contract_approval_assignments a
                SET escalated_at = CURRENT_TIMESTAMP
                FROM contract_approval_instances i
                WHERE i.id = a.instance_id
                  AND a.environment = :env
                  AND a.status = \'pending\'
                  AND a.escalated_at IS NULL
                  AND a.due_at IS NOT NULL
                  AND a.due_at <= CURRENT_TIMESTAMP
                  AND a.step_no = i.current_step
                  AND i.status IN (\'pending\', \'in_progress\')';
        $params = ['env' => $environment];

        if ($cmpId !== null) {
            $sql .= ' AND a.cmp_id = :cmp';
            $params['cmp'] = $cmpId;
        }

        $sql .= ' RETURNING a.id, a.instance_id, a.cmp_id, a.step_no, a.step_name, a.approver_uuid,
                            i.contract_id, i.steps_snapshot';

        // The claim and the stand-in it creates commit together: an assignment
        // stamped as escalated with nobody escalated to would be an approval
        // that has quietly stopped moving and will never be swept again.
        return Database::transaction($this->pdo, function (PDO $pdo) use ($sql, $params, $environment): int {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll() ?: [];

            foreach ($rows as $row) {
                $this->escalateOne($environment, $row);
            }

            return count($rows);
        });
    }

    /**
     * Hand one overdue assignment to the step's stand-in.
     *
     * The original assignment stays pending. The person who was late is still
     * the person the workflow chose, and taking the step away from them would
     * turn a missed deadline into a lost approval.
     *
     * @param array<string,mixed> $row a claimed assignment joined to its instance
     */
    private function escalateOne(string $environment, array $row): void
    {
        $instanceId = (int) $row['instance_id'];
        $cmp        = (int) $row['cmp_id'];
        $stepNo     = (int) $row['step_no'];
        $contractId = $row['contract_id'] === null ? null : (int) $row['contract_id'];
        $escalateTo = $this->escalationTarget($row['steps_snapshot'], $stepNo);

        if ($escalateTo !== null) {
            $this->pdo->prepare(
                'INSERT INTO contract_approval_assignments
                 (instance_id, environment, cmp_id, step_no, step_name, approver_uuid,
                  delegated_from, status, due_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', NULL)
                 ON CONFLICT (instance_id, step_no, approver_uuid) DO NOTHING'
            )->execute([
                $instanceId,
                $environment,
                $cmp,
                $stepNo,
                $row['step_name'],
                $escalateTo,
                (string) $row['approver_uuid'],
            ]);
        }

        $this->pdo->prepare(
            'INSERT INTO contract_approval_actions
             (instance_id, assignment_id, environment, cmp_id, step_no, actor_uuid, action, comment, reassigned_to)
             VALUES (?, ?, ?, ?, ?, \'system\', \'escalate\', ?, ?)'
        )->execute([
            $instanceId,
            (int) $row['id'],
            $environment,
            $cmp,
            $stepNo,
            sprintf('No response from %s before the deadline.', (string) $row['approver_uuid']),
            $escalateTo,
        ]);

        $this->activity->recordSystem(
            $environment,
            $cmp,
            $contractId,
            'approval.escalated',
            $escalateTo === null
                ? sprintf('Approval step %d is overdue', $stepNo)
                : sprintf('Approval step %d escalated after no response', $stepNo),
            ['instance_id' => $instanceId, 'step_no' => $stepNo, 'escalated_to' => $escalateTo]
        );
    }

    // -----------------------------------------------------------------------
    // Internals — the run
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed>      $instance
     * @param array<string,mixed>|null $assignment
     * @return array<string,mixed>
     */
    private function approve(TenantContext $ctx, array $instance, ?array $assignment, bool $isAdmin, ?string $comment): array
    {
        $instanceId = (int) $instance['id'];
        $stepNo     = (int) $instance['current_step'];
        $contractId = $instance['contract_id'] === null ? null : (int) $instance['contract_id'];
        $snapshot   = $instance['steps_snapshot'];

        if ($assignment !== null) {
            $this->settleAssignment($ctx, (int) $assignment['id'], 'approved', $comment);
        }

        $this->recordAction($ctx, $instanceId, $assignment === null ? null : (int) $assignment['id'], $stepNo, 'approve', $comment);

        // An administrator acting without an assignment is an override, not a
        // vote: counting it towards min_approvals would let one person satisfy
        // a quorum they were never part of, so it completes the step outright
        // and the action row says who did it.
        $satisfied = $assignment === null
            ? $isAdmin
            : $this->approvalsOnStep($ctx, $instanceId, $stepNo) >= $this->requiredApprovals($ctx, $instanceId, $snapshot, $stepNo);

        if (! $satisfied) {
            $this->audit->log($ctx, 'approval', $instanceId, 'approval.step_approved', $contractId, [
                'step_no' => ['from' => null, 'to' => $stepNo],
            ]);
            $this->activity->record($ctx, $contractId, 'approval.approved', 'Approval recorded', [
                'instance_id' => $instanceId,
                'step_no'     => $stepNo,
            ]);

            return $this->findOrFail($ctx, $instanceId);
        }

        $this->skipPending($ctx, $instanceId, $stepNo);

        $nextStep = $this->nextStepNo($snapshot, $stepNo);

        if ($nextStep !== null) {
            $contract = $contractId === null ? null : (new ContractService($this->pdo))->find($ctx, $contractId);
            $opened   = $this->openStep($ctx, $instanceId, $snapshot, $nextStep, $contract);

            if ($opened === 0) {
                throw DomainException::conflict(
                    sprintf('Approval step %d resolves to no approver.', $nextStep),
                    'NO_APPROVERS'
                );
            }

            $this->pdo->prepare(
                'UPDATE contract_approval_instances
                 SET current_step = ?, status = \'in_progress\'
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$nextStep, $instanceId, $ctx->environment, $ctx->cmpId]);

            $this->audit->log($ctx, 'approval', $instanceId, 'approval.step_advanced', $contractId, [
                'current_step' => ['from' => $stepNo, 'to' => $nextStep],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'approval.advanced',
                sprintf('Approval step %d complete; step %d opened', $stepNo, $nextStep),
                ['instance_id' => $instanceId, 'approvers' => $opened]
            );

            return $this->findOrFail($ctx, $instanceId);
        }

        $this->closeInstance($ctx, $instanceId, 'approved', $comment);
        $this->setContractApproval($ctx, $contractId, 'approved', 'approved');

        $this->audit->log($ctx, 'approval', $instanceId, 'approval.approved', $contractId, [
            'status' => ['from' => (string) $instance['status'], 'to' => 'approved'],
        ]);
        $this->activity->record($ctx, $contractId, 'approval.approved', 'Contract approved', [
            'instance_id' => $instanceId,
        ]);

        return $this->findOrFail($ctx, $instanceId);
    }

    /**
     * Reject, send back or request changes — all three end the run.
     *
     * They differ in what they say, not in what they do: each returns the record
     * to its author so it can be corrected and resubmitted. Leaving the run open
     * on a request for changes would mean a contract stuck in `awaiting_approval`
     * with nobody able to move it.
     *
     * @param array<string,mixed> $instance
     * @return array<string,mixed>
     */
    private function closeReturning(TenantContext $ctx, array $instance, ?int $assignmentId, string $action, ?string $comment): array
    {
        $instanceId = (int) $instance['id'];
        $stepNo     = (int) $instance['current_step'];
        $contractId = $instance['contract_id'] === null ? null : (int) $instance['contract_id'];

        $instanceStatus = $action === 'reject' ? 'rejected' : 'sent_back';
        $approvalStatus = match ($action) {
            'reject'          => 'rejected',
            'request_changes' => 'changes_requested',
            default           => 'sent_back',
        };

        if ($assignmentId !== null) {
            $this->settleAssignment($ctx, $assignmentId, $action === 'reject' ? 'rejected' : 'sent_back', $comment);
        }

        $this->recordAction($ctx, $instanceId, $assignmentId, $stepNo, $action, $comment);
        $this->skipPending($ctx, $instanceId, null);
        $this->closeInstance($ctx, $instanceId, $instanceStatus, $comment);
        $this->setContractApproval($ctx, $contractId, $approvalStatus, 'draft');

        $this->audit->log($ctx, 'approval', $instanceId, 'approval.' . $action, $contractId, [
            'status'  => ['from' => (string) $instance['status'], 'to' => $instanceStatus],
            'step_no' => ['from' => null, 'to' => $stepNo],
            'comment' => ['from' => null, 'to' => $comment],
        ]);
        $this->activity->record(
            $ctx,
            $contractId,
            'approval.' . $action,
            $action === 'reject' ? 'Contract rejected' : 'Contract returned for changes',
            ['instance_id' => $instanceId, 'step_no' => $stepNo]
        );

        return $this->findOrFail($ctx, $instanceId);
    }

    /**
     * @param array<string,mixed>      $instance
     * @param array<string,mixed>|null $assignment
     * @return array<string,mixed>
     */
    private function reassign(TenantContext $ctx, array $instance, ?array $assignment, string $toUuid, ?string $comment): array
    {
        $instanceId = (int) $instance['id'];
        $stepNo     = (int) $instance['current_step'];
        $contractId = $instance['contract_id'] === null ? null : (int) $instance['contract_id'];

        if ($assignment !== null) {
            $this->settleAssignment($ctx, (int) $assignment['id'], 'reassigned', $comment);
        }

        $this->pdo->prepare(
            'INSERT INTO contract_approval_assignments
             (instance_id, environment, cmp_id, step_no, step_name, approver_uuid, delegated_from, status, due_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', ?)
             ON CONFLICT (instance_id, step_no, approver_uuid) DO NOTHING'
        )->execute([
            $instanceId,
            $ctx->environment,
            $ctx->cmpId,
            $stepNo,
            $assignment['step_name'] ?? $this->stepName($instance['steps_snapshot'], $stepNo),
            $toUuid,
            $ctx->uuid,
            $assignment['due_at'] ?? null,
        ]);

        $this->recordAction(
            $ctx,
            $instanceId,
            $assignment === null ? null : (int) $assignment['id'],
            $stepNo,
            'reassign',
            $comment,
            $toUuid
        );

        $this->audit->log($ctx, 'approval', $instanceId, 'approval.reassigned', $contractId, [
            'approver_uuid' => ['from' => $assignment['approver_uuid'] ?? $ctx->uuid, 'to' => $toUuid],
        ]);
        $this->activity->record($ctx, $contractId, 'approval.reassigned', 'Approval reassigned', [
            'instance_id' => $instanceId,
            'step_no'     => $stepNo,
            'to'          => $toUuid,
        ]);

        return $this->findOrFail($ctx, $instanceId);
    }

    /**
     * Create the assignments for one step.
     *
     * @param list<array<string,mixed>> $snapshot
     * @param array<string,mixed>|null  $contract
     * @return int assignments created
     */
    private function openStep(TenantContext $ctx, int $instanceId, array $snapshot, int $stepNo, ?array $contract): int
    {
        $created = 0;

        foreach ($snapshot as $step) {
            if ((int) $step['step_no'] !== $stepNo) {
                continue;
            }

            $days  = $step['escalation_days'] === null ? null : (int) $step['escalation_days'];
            $dueAt = $days === null || $days <= 0 ? null : gmdate('Y-m-d H:i:s', time() + ($days * 86400));

            foreach ($this->resolveApprovers($ctx, $step, $contract ?? []) as $uuid) {
                $st = $this->pdo->prepare(
                    'INSERT INTO contract_approval_assignments
                     (instance_id, environment, cmp_id, step_no, step_name, approver_uuid, status, due_at)
                     VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?)
                     ON CONFLICT (instance_id, step_no, approver_uuid) DO NOTHING'
                );
                $st->execute([
                    $instanceId,
                    $ctx->environment,
                    $ctx->cmpId,
                    $stepNo,
                    mb_substr((string) $step['name'], 0, 160),
                    $uuid,
                    $dueAt,
                ]);

                $created += $st->rowCount();
            }
        }

        return $created;
    }

    /**
     * How many approvals this step needs.
     *
     * A step_no with several definitions (a parallel step naming a person *and*
     * a role, say) needs each definition's quota, so the quotas are summed. The
     * sum is capped at the number of people actually assigned: overlapping
     * definitions de-duplicate to one assignment through
     * `uq_approval_assignment`, and a quota larger than the assignee list would
     * be a step nobody can ever complete.
     *
     * @param list<array<string,mixed>> $snapshot
     */
    private function requiredApprovals(TenantContext $ctx, int $instanceId, array $snapshot, int $stepNo): int
    {
        $required = 0;
        foreach ($snapshot as $step) {
            if ((int) $step['step_no'] === $stepNo) {
                $required += max(1, (int) $step['min_approvals']);
            }
        }

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contract_approval_assignments
             WHERE instance_id = ? AND environment = ? AND cmp_id = ? AND step_no = ?
               AND status <> \'reassigned\''
        );
        $st->execute([$instanceId, $ctx->environment, $ctx->cmpId, $stepNo]);
        $assigned = (int) $st->fetchColumn();

        return max(1, min($required, max(1, $assigned)));
    }

    private function approvalsOnStep(TenantContext $ctx, int $instanceId, int $stepNo): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contract_approval_assignments
             WHERE instance_id = ? AND environment = ? AND cmp_id = ? AND step_no = ? AND status = \'approved\''
        );
        $st->execute([$instanceId, $ctx->environment, $ctx->cmpId, $stepNo]);

        return (int) $st->fetchColumn();
    }

    /** @param list<array<string,mixed>> $snapshot */
    private function nextStepNo(array $snapshot, int $currentStep): ?int
    {
        $next = null;
        foreach ($snapshot as $step) {
            $no = (int) $step['step_no'];
            if ($no > $currentStep && ($next === null || $no < $next)) {
                $next = $no;
            }
        }

        return $next;
    }

    /** @return array<string,mixed>|null */
    private function pendingAssignment(TenantContext $ctx, int $instanceId, int $stepNo, string $uuid): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_approval_assignments
             WHERE instance_id = ? AND environment = ? AND cmp_id = ? AND step_no = ?
               AND approver_uuid = ? AND status = \'pending\'
             ORDER BY id ASC
             LIMIT 1'
        );
        $st->execute([$instanceId, $ctx->environment, $ctx->cmpId, $stepNo, $uuid]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    private function settleAssignment(TenantContext $ctx, int $assignmentId, string $status, ?string $comment): void
    {
        $this->pdo->prepare(
            'UPDATE contract_approval_assignments
             SET status = ?, acted_at = CURRENT_TIMESTAMP, comment = COALESCE(?, comment)
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$status, $comment, $assignmentId, $ctx->environment, $ctx->cmpId]);
    }

    /** Close out assignments nobody needs to act on any more. */
    private function skipPending(TenantContext $ctx, int $instanceId, ?int $stepNo): void
    {
        $sql    = 'UPDATE contract_approval_assignments
                   SET status = \'skipped\', acted_at = CURRENT_TIMESTAMP
                   WHERE instance_id = ? AND environment = ? AND cmp_id = ? AND status = \'pending\'';
        $params = [$instanceId, $ctx->environment, $ctx->cmpId];

        if ($stepNo !== null) {
            $sql      .= ' AND step_no = ?';
            $params[] = $stepNo;
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    private function closeInstance(TenantContext $ctx, int $instanceId, string $status, ?string $note): void
    {
        $this->pdo->prepare(
            'UPDATE contract_approval_instances
             SET status = ?, completed_at = CURRENT_TIMESTAMP, outcome_note = COALESCE(?, outcome_note)
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$status, $note, $instanceId, $ctx->environment, $ctx->cmpId]);
    }

    private function recordAction(
        TenantContext $ctx,
        int $instanceId,
        ?int $assignmentId,
        int $stepNo,
        string $action,
        ?string $comment,
        ?string $reassignedTo = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO contract_approval_actions
             (instance_id, assignment_id, environment, cmp_id, step_no, actor_uuid, action, comment, reassigned_to)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $instanceId,
            $assignmentId,
            $ctx->environment,
            $ctx->cmpId,
            $stepNo,
            $ctx->uuid,
            $action,
            $comment,
            $reassignedTo,
        ]);
    }

    private function setContractApproval(TenantContext $ctx, ?int $contractId, string $approvalStatus, ?string $status): void
    {
        if ($contractId === null) {
            return;
        }

        if ($status === null) {
            $this->pdo->prepare(
                'UPDATE contracts SET approval_status = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$approvalStatus, $contractId, $ctx->environment, $ctx->cmpId]);

            return;
        }

        $this->pdo->prepare(
            'UPDATE contracts
             SET approval_status = ?, status = ?, lifecycle_stage = ?,
                 updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $approvalStatus,
            $status,
            ContractService::stageForStatus($status),
            $ctx->uuid,
            $contractId,
            $ctx->environment,
            $ctx->cmpId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals — workflow selection
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function activeWorkflows(TenantContext $ctx, string $appliesTo): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, name, conditions, match_mode, priority, escalation_days
             FROM approval_workflows
             WHERE environment = ? AND cmp_id = ? AND applies_to = ? AND is_active = TRUE
             ORDER BY priority ASC, id ASC'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $appliesTo]);

        return $st->fetchAll() ?: [];
    }

    /**
     * Freeze the workflow's steps.
     *
     * The escalation window is resolved here rather than at escalation time, for
     * the same reason as the steps themselves: a company that shortens its SLA
     * next month must not retroactively make today's approvals overdue.
     *
     * @param array<string,mixed> $workflow
     * @return list<array<string,mixed>>
     */
    private function snapshotSteps(TenantContext $ctx, int $workflowId, array $workflow): array
    {
        $st = $this->pdo->prepare(
            'SELECT step_no, name, execution, approver_type, approver_value,
                    min_approvals, can_edit, escalation_days, escalate_to_uuid
             FROM approval_workflow_steps
             WHERE workflow_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY step_no ASC, id ASC'
        );
        $st->execute([$workflowId, $ctx->environment, $ctx->cmpId]);

        $fallback = $workflow['escalation_days'] === null
            ? $this->settingsEscalationDays($ctx)
            : (int) $workflow['escalation_days'];

        $snapshot = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $days = $row['escalation_days'] === null ? $fallback : (int) $row['escalation_days'];

            $snapshot[] = [
                'step_no'          => (int) $row['step_no'],
                'name'             => (string) $row['name'],
                'execution'        => (string) $row['execution'],
                'approver_type'    => (string) $row['approver_type'],
                'approver_value'   => $row['approver_value'] === null ? null : (string) $row['approver_value'],
                'min_approvals'    => max(1, (int) $row['min_approvals']),
                'can_edit'         => ContractService::toBool($row['can_edit']),
                'escalation_days'  => $days > 0 ? $days : null,
                'escalate_to_uuid' => $row['escalate_to_uuid'] === null ? null : (string) $row['escalate_to_uuid'],
            ];
        }

        return $snapshot;
    }

    private function settingsEscalationDays(TenantContext $ctx): int
    {
        $st = $this->pdo->prepare(
            'SELECT approval_escalation_days FROM contract_settings
             WHERE environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);
        $days = $st->fetchColumn();

        return $days === false || (int) $days <= 0 ? self::DEFAULT_ESCALATION_DAYS : (int) $days;
    }

    /**
     * The attribute bag a workflow's conditions are evaluated against.
     *
     * The three derived facts are computed here rather than stored on the
     * contract: each is a live question about a growing child table, and a
     * cached flag on `contracts` would be a boolean that goes stale the moment
     * an AI extraction adds a clause.
     *
     * @param array<string,mixed>|null $contract
     * @return array<string,mixed>
     */
    private function matchFacts(TenantContext $ctx, ?array $contract): array
    {
        if ($contract === null) {
            return [];
        }

        $contractId = (int) $contract['id'];

        $st = $this->pdo->prepare(
            'SELECT
                EXISTS (
                    SELECT 1 FROM clause_deviations d
                    WHERE d.contract_id = :cid AND d.environment = :env AND d.cmp_id = :cmp
                      AND d.review_status IN (\'open\', \'negotiating\')
                ) AS non_standard,
                (
                    EXISTS (
                        SELECT 1 FROM contract_clauses cl
                        JOIN clause_categories cc ON cc.id = cl.category_id
                        WHERE cl.contract_id = :cid2 AND cl.environment = :env2 AND cl.cmp_id = :cmp2
                          AND cc.code = \'data_protection\'
                    )
                    OR EXISTS (
                        SELECT 1 FROM contract_risk_findings f
                        WHERE f.contract_id = :cid3 AND f.environment = :env3 AND f.cmp_id = :cmp3
                          AND f.risk_category = \'data_protection\'
                    )
                ) AS data_processing'
        );
        $st->execute([
            'cid'  => $contractId, 'env'  => $ctx->environment, 'cmp'  => $ctx->cmpId,
            'cid2' => $contractId, 'env2' => $ctx->environment, 'cmp2' => $ctx->cmpId,
            'cid3' => $contractId, 'env3' => $ctx->environment, 'cmp3' => $ctx->cmpId,
        ]);
        $flags = $st->fetch() ?: [];

        return $contract + [
            'duration_months'          => WorkflowMatcher::durationMonths(
                isset($contract['effective_date']) ? (string) $contract['effective_date'] : null,
                isset($contract['expiry_date']) ? (string) $contract['expiry_date'] : null
            ),
            'has_non_standard_clauses' => ContractService::toBool($flags['non_standard'] ?? false),
            'has_data_processing'      => ContractService::toBool($flags['data_processing'] ?? false),
        ];
    }

    private function openInstanceId(TenantContext $ctx, string $subjectType, int $subjectId): ?int
    {
        $st = $this->pdo->prepare(
            'SELECT id FROM contract_approval_instances
             WHERE environment = ? AND cmp_id = ? AND subject_type = ? AND subject_id = ?
               AND status IN (\'pending\', \'in_progress\')
             LIMIT 1'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $subjectType, $subjectId]);
        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string,mixed> $contract
     * @return list<string>
     */
    private function departmentHeads(TenantContext $ctx, ?string $value, array $contract): array
    {
        $sql    = 'SELECT head_uuid FROM contract_departments WHERE environment = ? AND cmp_id = ?';
        $params = [$ctx->environment, $ctx->cmpId];

        if ($value !== null && preg_match('/^\d+$/', $value) === 1) {
            $sql      .= ' AND id = ?';
            $params[] = (int) $value;
        } elseif ($value !== null) {
            $sql      .= ' AND code = ?';
            $params[] = $value;
        } elseif (! empty($contract['department_id'])) {
            $sql      .= ' AND id = ?';
            $params[] = (int) $contract['department_id'];
        } else {
            return [];
        }

        $st = $this->pdo->prepare($sql . ' LIMIT 1');
        $st->execute($params);
        $head = $st->fetchColumn();

        return $head === false || $head === null ? [] : [(string) $head];
    }

    /**
     * A contract administrator may act on any step.
     *
     * Deliberately the role and not a permission: `approval.act` is held by
     * every approver, so testing for it would make "only an assignee may act"
     * mean nothing at all.
     */
    private function isApprovalAdmin(TenantContext $ctx): bool
    {
        return in_array('contract_admin', $ctx->roles, true);
    }

    /** @param mixed $snapshot raw or decoded steps_snapshot */
    private function escalationTarget(mixed $snapshot, int $stepNo): ?string
    {
        foreach ($this->decodeSnapshot($snapshot) as $step) {
            if ((int) ($step['step_no'] ?? 0) === $stepNo
                && isset($step['escalate_to_uuid'])
                && trim((string) $step['escalate_to_uuid']) !== '') {
                return trim((string) $step['escalate_to_uuid']);
            }
        }

        return null;
    }

    private function stepName(mixed $snapshot, int $stepNo): ?string
    {
        foreach ($this->decodeSnapshot($snapshot) as $step) {
            if ((int) ($step['step_no'] ?? 0) === $stepNo) {
                return mb_substr((string) ($step['name'] ?? ''), 0, 160);
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function decodeSnapshot(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_array'));
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(TenantContext $ctx, array $row): array
    {
        $row['steps_snapshot'] = $this->decodeSnapshot($row['steps_snapshot'] ?? []);

        foreach (['id', 'cmp_id', 'subject_id', 'contract_id', 'workflow_id', 'current_step'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        $instanceId = (int) $row['id'];

        $st = $this->pdo->prepare(
            'SELECT id, step_no, step_name, approver_uuid, delegated_from, status,
                    assigned_at, acted_at, due_at, escalated_at, comment
             FROM contract_approval_assignments
             WHERE instance_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY step_no ASC, id ASC'
        );
        $st->execute([$instanceId, $ctx->environment, $ctx->cmpId]);
        $row['assignments'] = array_map(
            static function (array $a): array {
                $a['id']      = (int) $a['id'];
                $a['step_no'] = (int) $a['step_no'];

                return $a;
            },
            $st->fetchAll() ?: []
        );

        $actionsSt = $this->pdo->prepare(
            'SELECT id, assignment_id, step_no, actor_uuid, action, comment, reassigned_to, created_at
             FROM contract_approval_actions
             WHERE instance_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY created_at ASC, id ASC'
        );
        $actionsSt->execute([$instanceId, $ctx->environment, $ctx->cmpId]);
        $row['actions'] = $actionsSt->fetchAll() ?: [];

        $row['is_open'] = in_array((string) $row['status'], self::OPEN_STATUSES, true);

        return $row;
    }
}
