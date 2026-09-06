<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Services\MilestoneService;
use App\Services\ObligationService;
use App\Services\RoleService;
use App\Support\Dates;
use Throwable;

/**
 * Obligations and milestones falling due, and the ones already late.
 *
 * The status transitions themselves live in ObligationService so the same rule
 * applies whether it fires from cron or from a user action. This class is the
 * part that only makes sense at night: telling people.
 */
final class ObligationSweep
{
    public const TASK = 'obligations';

    public static function run(SweepContext $ctx): SweepContext
    {
        try {
            $counts = (new ObligationService($ctx->pdo))->refreshDueStatuses($ctx->environment, $ctx->cmpId);
            $ctx->detail['became_due']     = $counts['due'] ?? 0;
            $ctx->detail['became_overdue'] = $counts['overdue'] ?? 0;
        } catch (Throwable $e) {
            $ctx->noteError(self::TASK, 'status refresh: ' . $e->getMessage());
        }

        try {
            $milestones = (new MilestoneService($ctx->pdo))->refreshDueStatuses($ctx->environment, $ctx->cmpId);
            $ctx->detail['milestones_missed'] = $milestones['missed'] ?? 0;
        } catch (Throwable $e) {
            $ctx->noteError(self::TASK, 'milestone refresh: ' . $e->getMessage());
        }

        foreach ($ctx->companies() as $cmpId) {
            try {
                self::notifyForCompany($ctx, $cmpId);
            } catch (Throwable $e) {
                $ctx->noteError(self::TASK, "company {$cmpId}: " . $e->getMessage());
            }
        }

        return $ctx;
    }

    private static function notifyForCompany(SweepContext $ctx, int $cmpId): void
    {
        $ladder = $ctx->reminderLadder($cmpId, 'obligation_alert_days', [14, 7, 1]);
        $widest = max($ladder);

        $st = $ctx->pdo->prepare(
            'SELECT o.id, o.due_date, o.status, o.contract_id,
                    ob.title, ob.owner_uuid, ob.responsible_party, ob.escalate_to_uuid,
                    ob.escalation_days,
                    c.contract_number, c.title AS contract_title
             FROM obligation_occurrences o
             JOIN contract_obligations ob ON ob.id = o.obligation_id
             JOIN contracts c ON c.id = o.contract_id
             WHERE o.environment = :env
               AND o.cmp_id = :cmp
               AND o.status IN (\'upcoming\', \'due\', \'overdue\')
               AND ob.is_active
               AND c.archived_at IS NULL
               AND o.due_date <= CURRENT_DATE + make_interval(days => :window)'
        );
        $st->execute(['env' => $ctx->environment, 'cmp' => $cmpId, 'window' => $widest]);

        foreach ($st->fetchAll() ?: [] as $row) {
            $ctx->processed++;

            $days       = Dates::daysUntil($row['due_date']);
            $occurrence = (int) $row['id'];
            $contractId = (int) $row['contract_id'];

            $recipients = self::recipients($ctx, $cmpId, $row);
            if ($recipients === [] || $ctx->dryRun) {
                continue;
            }

            if ($days !== null && $days < 0) {
                // Overdue is re-stated daily up to a point, then escalated. A
                // reminder nobody acts on is not made more effective by
                // repeating it forever, so the dedupe key includes the day so
                // it can repeat, and escalation is what changes after that.
                $overdueDays = abs($days);
                $ctx->notified += self::send(
                    $ctx,
                    $cmpId,
                    $recipients,
                    'obligation.overdue',
                    sprintf('Overdue by %d day%s — %s', $overdueDays, $overdueDays === 1 ? '' : 's', $row['title']),
                    sprintf('%s under %s was due on %s.', $row['title'], $row['contract_number'], $row['due_date']),
                    $contractId,
                    'obligation_overdue:' . $occurrence . ':' . min($overdueDays, 30),
                    'critical'
                );

                $escalationDays = $row['escalation_days'] !== null ? (int) $row['escalation_days'] : null;
                $escalateTo     = trim((string) ($row['escalate_to_uuid'] ?? ''));
                if ($escalationDays !== null && $overdueDays >= $escalationDays && $escalateTo !== '') {
                    $ctx->notified += self::send(
                        $ctx,
                        $cmpId,
                        [$escalateTo],
                        'obligation.escalated',
                        sprintf('Escalation: %s is %d days overdue', $row['title'], $overdueDays),
                        sprintf(
                            'The owner has not completed this obligation under %s. It was due on %s.',
                            $row['contract_number'],
                            $row['due_date']
                        ),
                        $contractId,
                        'obligation_escalated:' . $occurrence,
                        'critical'
                    );
                }

                continue;
            }

            $threshold = $days === null ? null : ExpirySweep::thresholdFor($days, $ladder);
            if ($threshold === null) {
                continue;
            }

            $ctx->notified += self::send(
                $ctx,
                $cmpId,
                $recipients,
                'obligation.due',
                sprintf('Due in %d day%s — %s', $days, $days === 1 ? '' : 's', $row['title']),
                sprintf('%s under %s is due on %s.', $row['title'], $row['contract_number'], $row['due_date']),
                $contractId,
                'obligation_due:' . $occurrence . ':' . $threshold,
                $days <= 1 ? 'warning' : 'info'
            );
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private static function recipients(SweepContext $ctx, int $cmpId, array $row): array
    {
        $owner = trim((string) ($row['owner_uuid'] ?? ''));
        if ($owner !== '') {
            return [$owner];
        }

        // An obligation with no named owner still has to reach someone, or it
        // silently goes unwatched — which is the failure this product is for.
        return RoleService::usersWithRole($ctx->environment, $cmpId, 'contract_admin');
    }

    /** @param list<string> $recipients */
    private static function send(
        SweepContext $ctx,
        int $cmpId,
        array $recipients,
        string $event,
        string $title,
        string $body,
        int $contractId,
        string $dedupeKey,
        string $severity
    ): int {
        $sent = 0;
        foreach ($recipients as $uuid) {
            if ($uuid === '') {
                continue;
            }
            $id = $ctx->notifications()->notify(
                $ctx->environment,
                $cmpId,
                $uuid,
                $event,
                $title,
                $body,
                [
                    'contract_id' => $contractId,
                    'link_path'   => '/obligations',
                    'severity'    => $severity,
                    'dedupe_key'  => $dedupeKey,
                ]
            );
            if ($id !== null) {
                $sent++;
            }
        }

        return $sent;
    }
}
