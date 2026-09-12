<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Services\ApprovalService;
use Throwable;

/**
 * Approval steps that have sat past their due date.
 *
 * A contract stuck waiting on one person is the most common way an approval
 * workflow fails in practice, and it fails silently — the requester assumes it
 * is progressing.
 */
final class ApprovalSweep
{
    public const TASK = 'approvals';

    public static function run(SweepContext $ctx): SweepContext
    {
        try {
            $escalated      = (new ApprovalService($ctx->pdo))->escalateOverdue($ctx->environment, $ctx->cmpId);
            $ctx->processed = $escalated;
            $ctx->detail['escalated'] = $escalated;
        } catch (Throwable $e) {
            $ctx->noteError(self::TASK, $e->getMessage());

            return $ctx;
        }

        if ($ctx->dryRun) {
            return $ctx;
        }

        // Remind the assignee as well as escalating. Escalation without a
        // reminder is how someone finds out their manager was told first.
        $st = $ctx->pdo->prepare(
            'SELECT a.id, a.approver_uuid, a.step_name, a.due_at, a.cmp_id,
                    i.contract_id, c.contract_number, c.title
             FROM contract_approval_assignments a
             JOIN contract_approval_instances i ON i.id = a.instance_id
             LEFT JOIN contracts c ON c.id = i.contract_id
             WHERE a.environment = ?
               AND a.status = \'pending\'
               AND a.due_at IS NOT NULL
               AND a.due_at < CURRENT_TIMESTAMP'
            . ($ctx->cmpId !== null ? ' AND a.cmp_id = ?' : '')
        );
        $st->execute($ctx->cmpId !== null
            ? [$ctx->environment, $ctx->cmpId]
            : [$ctx->environment]);

        foreach ($st->fetchAll() ?: [] as $row) {
            $id = $ctx->notifications()->notify(
                $ctx->environment,
                (int) $row['cmp_id'],
                (string) $row['approver_uuid'],
                'approval.overdue',
                sprintf('Approval overdue — %s', $row['contract_number'] ?? 'a contract'),
                sprintf(
                    '%s is waiting on your approval at the "%s" step.',
                    $row['title'] ?? 'A contract',
                    $row['step_name'] ?? 'current'
                ),
                [
                    'contract_id' => $row['contract_id'] !== null ? (int) $row['contract_id'] : null,
                    'link_path'   => '/approvals',
                    'severity'    => 'warning',
                    // Daily, not once: an approval that is blocking a contract
                    // should keep saying so until it is acted on.
                    'dedupe_key'  => 'approval_overdue:' . (int) $row['id'] . ':' . date('Y-m-d'),
                ]
            );
            if ($id !== null) {
                $ctx->notified++;
            }
        }

        return $ctx;
    }
}
