<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\Validator;
use PDO;

/**
 * Approval routing: the queue, the actions, and the workflows behind them.
 *
 * Workflow configuration sits behind `workflow.manage`, separately from acting
 * on an approval, because editing routing is how someone would arrange for
 * their own contract to skip the approver who is supposed to see it.
 */
final class ApprovalController extends BaseController
{
    public function queue(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $result = $this->run(fn () => $this->service()->myQueue($ctx, $page['per_page'], $page['offset']));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function instances(): void
    {
        $ctx         = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $subjectType = Enums::coerce(Request::query('subject_type'), ['contract', 'request', 'amendment', 'termination', 'renewal'], 'contract');
        $subjectId   = Request::query('subject_id');

        if ($subjectId === null || ! ctype_digit($subjectId)) {
            Response::validationError(['subject_id' => 'A numeric subject_id is required.']);
        }

        $this->respond(fn () => $this->service()->listForSubject($ctx, (string) $subjectType, (int) $subjectId));
    }

    public function submit(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $body = $this->body();

        $subjectType = Enums::coerce($body['subject_type'] ?? 'contract', ['contract', 'request', 'amendment', 'termination', 'renewal'], 'contract');
        $subjectId   = isset($body['subject_id']) && ctype_digit((string) $body['subject_id'])
            ? (int) $body['subject_id']
            : 0;

        if ($subjectId < 1) {
            Response::validationError(['subject_id' => 'A numeric subject_id is required.']);
        }

        $contractId = isset($body['contract_id']) && ctype_digit((string) $body['contract_id'])
            ? (int) $body['contract_id']
            : ($subjectType === 'contract' ? $subjectId : null);

        $this->respond(
            fn () => $this->service()->submit($ctx, (string) $subjectType, $subjectId, $contractId),
            201
        );
    }

    public function act(?string $id = null): void
    {
        $ctx  = $this->requirePermission(Permissions::APPROVAL_ACT);
        $body = $this->body();

        $action = Enums::coerce($body['action'] ?? null, [
            'approve', 'reject', 'send_back', 'request_changes', 'comment', 'reassign',
        ]);

        if ($action === null) {
            Response::validationError([
                'action' => 'Choose approve, reject, send_back, request_changes, comment or reassign.',
            ]);
        }

        // A reassignment with nobody to reassign to would silently drop the step
        // out of anyone's queue.
        if ($action === 'reassign' && trim((string) ($body['reassign_to'] ?? '')) === '') {
            Response::validationError(['reassign_to' => 'Choose who to reassign this step to.']);
        }

        $this->respond(fn () => $this->service()->act($ctx, $this->intId($id), $action, $body));
    }

    public function cancel(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $reason = trim((string) ($this->body()['reason'] ?? ''));

        if ($reason === '') {
            Response::validationError(['reason' => 'Say why this approval is being cancelled.']);
        }

        $this->respond(fn () => $this->service()->cancel($ctx, $this->intId($id), mb_substr($reason, 0, 1000)));
    }

    // --- Workflow configuration --------------------------------------------

    public function workflows(): void
    {
        $ctx = $this->requireAnyPermission([Permissions::WORKFLOW_MANAGE, Permissions::SETTINGS_MANAGE, Permissions::CONTRACT_VIEW]);
        $pdo = $this->db();

        $st = $pdo->prepare(
            'SELECT w.*,
                    COALESCE(json_agg(
                        json_build_object(
                            \'id\', s.id, \'step_no\', s.step_no, \'name\', s.name,
                            \'execution\', s.execution, \'approver_type\', s.approver_type,
                            \'approver_value\', s.approver_value, \'min_approvals\', s.min_approvals,
                            \'can_edit\', s.can_edit, \'escalation_days\', s.escalation_days,
                            \'escalate_to_uuid\', s.escalate_to_uuid
                        ) ORDER BY s.step_no
                    ) FILTER (WHERE s.id IS NOT NULL), \'[]\') AS steps
             FROM approval_workflows w
             LEFT JOIN approval_workflow_steps s ON s.workflow_id = w.id
             WHERE w.environment = ? AND w.cmp_id = ?
             GROUP BY w.id
             ORDER BY w.applies_to, w.priority, w.id'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        $rows = array_map(static function (array $row): array {
            $row['conditions'] = json_decode((string) $row['conditions'], true) ?: [];
            $row['steps']      = json_decode((string) $row['steps'], true) ?: [];
            $row['is_active']  = \App\Services\ContractService::toBool($row['is_active']);

            return $row;
        }, $st->fetchAll() ?: []);

        Response::success($rows);
    }

    public function storeWorkflow(): void
    {
        $ctx = $this->requirePermission(Permissions::WORKFLOW_MANAGE);

        $this->respond(fn () => $this->writeWorkflow($ctx, null, $this->body()), 201);
    }

    public function updateWorkflow(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::WORKFLOW_MANAGE);

        $this->respond(fn () => $this->writeWorkflow($ctx, $this->intId($id), $this->body()));
    }

    public function destroyWorkflow(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::WORKFLOW_MANAGE);
        $workflowId = $this->intId($id);
        $pdo        = $this->db();

        $st = $pdo->prepare(
            'DELETE FROM approval_workflows WHERE id = ? AND environment = ? AND cmp_id = ?'
        );
        $st->execute([$workflowId, $ctx->environment, $ctx->cmpId]);

        if ($st->rowCount() === 0) {
            Response::notFound('Approval workflow not found.');
        }

        (new AuditService($pdo))->log($ctx, 'approval_workflow', $workflowId, 'workflow.deleted');

        Response::success(['deleted' => true]);
    }

    /**
     * Create or replace a workflow and its steps.
     *
     * The steps are rewritten wholesale rather than diffed. An in-flight
     * approval is unaffected because `contract_approval_instances` froze the
     * step definitions at submission — a workflow edited mid-approval must not
     * change who was supposed to approve a contract already in the queue.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function writeWorkflow(\App\Support\TenantContext $ctx, ?int $workflowId, array $body): array
    {
        $v = new Validator($body);

        $name      = $v->requiredString('name', 200);
        $appliesTo = $v->optionalEnum('applies_to', ['contract', 'request', 'amendment', 'termination', 'renewal'], 'contract') ?? 'contract';
        $matchMode = $v->optionalEnum('match_mode', ['all', 'any'], 'all') ?? 'all';
        $priority  = $v->optionalInt('priority', 1, 10000, 100) ?? 100;
        $isActive  = $v->optionalBool('is_active', true) ?? true;
        $escalate  = $v->optionalInt('escalation_days', 1, 365);
        $conditions = $v->optionalArray('conditions', 50);
        $steps      = $v->optionalArray('steps', 30);

        foreach ($conditions as $index => $condition) {
            if (! is_array($condition) || ! isset($condition['field'], $condition['operator'])) {
                $v->fail("conditions.{$index}", 'Each condition needs a field and an operator.');
            }
        }

        if ($steps === []) {
            $v->fail('steps', 'A workflow needs at least one approval step.');
        }

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                $v->fail("steps.{$index}", 'Each step must be an object.');
                continue;
            }
            $approverType = Enums::coerce($step['approver_type'] ?? null, [
                'user', 'role', 'department_head', 'contract_owner', 'manager',
            ]);
            if ($approverType === null) {
                $v->fail("steps.{$index}.approver_type", 'Choose user, role, department_head, contract_owner or manager.');
            }
            if (in_array($approverType, ['user', 'role'], true) && trim((string) ($step['approver_value'] ?? '')) === '') {
                $v->fail("steps.{$index}.approver_value", 'Name the user or role for this step.');
            }
        }

        $v->assert();

        $pdo = $this->db();

        return Database::transaction($pdo, function (PDO $pdo) use ($ctx, $workflowId, $name, $appliesTo, $matchMode, $priority, $isActive, $escalate, $conditions, $steps): array {
            if ($workflowId === null) {
                $st = $pdo->prepare(
                    'INSERT INTO approval_workflows
                     (environment, cmp_id, name, applies_to, conditions, match_mode, priority, is_active, escalation_days, created_by)
                     VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?) RETURNING id'
                );
                $st->execute([
                    $ctx->environment, $ctx->cmpId, $name, $appliesTo,
                    json_encode($conditions), $matchMode, $priority,
                    $isActive ? 'true' : 'false', $escalate, $ctx->uuid,
                ]);
                $workflowId = (int) $st->fetchColumn();
            } else {
                $st = $pdo->prepare(
                    'UPDATE approval_workflows
                     SET name = ?, applies_to = ?, conditions = ?::jsonb, match_mode = ?,
                         priority = ?, is_active = ?, escalation_days = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND environment = ? AND cmp_id = ?'
                );
                $st->execute([
                    $name, $appliesTo, json_encode($conditions), $matchMode,
                    $priority, $isActive ? 'true' : 'false', $escalate,
                    $workflowId, $ctx->environment, $ctx->cmpId,
                ]);

                if ($st->rowCount() === 0) {
                    throw \App\Support\DomainException::notFound('Approval workflow not found.');
                }

                $pdo->prepare('DELETE FROM approval_workflow_steps WHERE workflow_id = ?')->execute([$workflowId]);
            }

            $insertStep = $pdo->prepare(
                'INSERT INTO approval_workflow_steps
                 (workflow_id, environment, cmp_id, step_no, name, execution, approver_type,
                  approver_value, min_approvals, can_edit, escalation_days, escalate_to_uuid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($steps as $index => $step) {
                $insertStep->execute([
                    $workflowId,
                    $ctx->environment,
                    $ctx->cmpId,
                    isset($step['step_no']) && (int) $step['step_no'] > 0 ? (int) $step['step_no'] : $index + 1,
                    mb_substr(trim((string) ($step['name'] ?? 'Approval')), 0, 160),
                    Enums::coerce($step['execution'] ?? 'sequential', ['sequential', 'parallel'], 'sequential'),
                    Enums::coerce($step['approver_type'] ?? 'user', ['user', 'role', 'department_head', 'contract_owner', 'manager'], 'user'),
                    isset($step['approver_value']) ? mb_substr((string) $step['approver_value'], 0, 128) : null,
                    max(1, (int) ($step['min_approvals'] ?? 1)),
                    ! empty($step['can_edit']) ? 'true' : 'false',
                    isset($step['escalation_days']) && (int) $step['escalation_days'] > 0 ? (int) $step['escalation_days'] : null,
                    isset($step['escalate_to_uuid']) ? mb_substr((string) $step['escalate_to_uuid'], 0, 64) : null,
                ]);
            }

            (new AuditService($pdo))->log(
                $ctx,
                'approval_workflow',
                $workflowId,
                $workflowId === null ? 'workflow.created' : 'workflow.updated'
            );

            $read = $pdo->prepare('SELECT * FROM approval_workflows WHERE id = ?');
            $read->execute([$workflowId]);
            $row = $read->fetch();

            $row['conditions'] = json_decode((string) $row['conditions'], true) ?: [];
            $row['is_active']  = \App\Services\ContractService::toBool($row['is_active']);

            $readSteps = $pdo->prepare('SELECT * FROM approval_workflow_steps WHERE workflow_id = ? ORDER BY step_no');
            $readSteps->execute([$workflowId]);
            $row['steps'] = $readSteps->fetchAll() ?: [];

            return $row;
        });
    }

    private function service(): ApprovalService
    {
        return new ApprovalService($this->db());
    }
}
