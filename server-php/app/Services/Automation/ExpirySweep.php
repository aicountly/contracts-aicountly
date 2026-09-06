<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Support\Dates;
use Throwable;

/**
 * Contracts approaching expiry, and contracts whose cancellation window is
 * about to close.
 *
 * The notice deadline is the one this product exists for. A contract that
 * auto-renews with 90 days notice has to be decided on 90 days before it ends,
 * and nobody remembers that unaided — which is how companies find themselves
 * locked into another year of something they wanted to stop.
 *
 * Every notification carries a dedupe key of
 * `<event>:<contract>:<threshold>`, and the notifications table has a unique
 * index on (recipient, dedupe_key). Running this twice in a night therefore
 * sends nothing twice, which matters because cPanel cron is not exactly-once.
 */
final class ExpirySweep
{
    public const TASK = 'expiry';

    public static function run(SweepContext $ctx): SweepContext
    {
        foreach ($ctx->companies() as $cmpId) {
            try {
                self::forCompany($ctx, $cmpId);
            } catch (Throwable $e) {
                $ctx->noteError(self::TASK, "company {$cmpId}: " . $e->getMessage());
            }
        }

        return $ctx;
    }

    private static function forCompany(SweepContext $ctx, int $cmpId): void
    {
        $ladder = $ctx->reminderLadder($cmpId, 'expiry_alert_days', [90, 60, 30, 15, 7]);
        $widest = max($ladder);

        $st = $ctx->pdo->prepare(
            'SELECT id, contract_number, title, owner_uuid, expiry_date, notice_deadline,
                    auto_renewal, renewal_type, counterparty_name
             FROM contracts
             WHERE environment = :env
               AND cmp_id = :cmp
               AND archived_at IS NULL
               AND status IN (\'active\', \'renewal_review\')
               AND (
                     (expiry_date IS NOT NULL AND expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :window))
                  OR (notice_deadline IS NOT NULL AND notice_deadline BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :window2))
                   )'
        );
        $st->execute(['env' => $ctx->environment, 'cmp' => $cmpId, 'window' => $widest, 'window2' => $widest]);

        foreach ($st->fetchAll() ?: [] as $contract) {
            $ctx->processed++;

            $contractId = (int) $contract['id'];
            $recipients = self::recipients($ctx, $cmpId, $contract);

            $daysToNotice = Dates::daysUntil($contract['notice_deadline'] ?? null);
            $daysToExpiry = Dates::daysUntil($contract['expiry_date'] ?? null);

            // The notice deadline is reported first and separately. Once it
            // passes, an auto-renewing contract is committed for another term
            // whatever the expiry date says, so the two are not the same alert.
            if ($daysToNotice !== null && $daysToNotice >= 0) {
                $threshold = self::thresholdFor($daysToNotice, $ladder);
                if ($threshold !== null) {
                    $ctx->notified += self::notify(
                        $ctx,
                        $cmpId,
                        $recipients,
                        'contract.notice_deadline',
                        sprintf(
                            'Cancellation deadline in %d days — %s',
                            $daysToNotice,
                            $contract['contract_number']
                        ),
                        sprintf(
                            '%s with %s. After %s this contract %s.',
                            $contract['title'],
                            $contract['counterparty_name'] ?: 'the counterparty',
                            $contract['notice_deadline'],
                            \App\Services\ContractService::toBool($contract['auto_renewal'])
                                ? 'renews automatically'
                                : 'can no longer be cancelled on notice'
                        ),
                        $contractId,
                        'notice:' . $contractId . ':' . $threshold,
                        \App\Services\ContractService::toBool($contract['auto_renewal']) ? 'critical' : 'warning'
                    );
                }
            }

            if ($daysToExpiry !== null && $daysToExpiry >= 0) {
                $threshold = self::thresholdFor($daysToExpiry, $ladder);
                if ($threshold !== null) {
                    $ctx->notified += self::notify(
                        $ctx,
                        $cmpId,
                        $recipients,
                        'contract.expiring',
                        sprintf('Expires in %d days — %s', $daysToExpiry, $contract['contract_number']),
                        sprintf('%s expires on %s.', $contract['title'], $contract['expiry_date']),
                        $contractId,
                        'expiry:' . $contractId . ':' . $threshold,
                        $daysToExpiry <= 7 ? 'critical' : 'warning'
                    );
                }
            }
        }

        // A contract past its expiry date is expired, whatever anyone updated
        // last. Doing this here rather than on read means every report and
        // dashboard agrees without each of them re-deriving it.
        $expired = $ctx->pdo->prepare(
            'UPDATE contracts
             SET status = \'expired\', lifecycle_stage = \'closed\', updated_at = CURRENT_TIMESTAMP
             WHERE environment = ? AND cmp_id = ? AND archived_at IS NULL
               AND status = \'active\'
               AND expiry_date IS NOT NULL
               AND expiry_date < CURRENT_DATE
               AND auto_renewal = FALSE
             RETURNING id, contract_number'
        );
        $expired->execute([$ctx->environment, $cmpId]);

        foreach ($expired->fetchAll() ?: [] as $row) {
            $ctx->activity()->recordSystem(
                $ctx->environment,
                $cmpId,
                (int) $row['id'],
                'contract.expired',
                sprintf('Contract %s reached its expiry date', $row['contract_number'])
            );
            $ctx->detail['expired'][] = $row['contract_number'];
        }
    }

    /**
     * The ladder step a given number of days falls into: the smallest step that
     * is still at or above it.
     *
     * With a ladder of 90/60/30/15/7, a contract 29 days out is in the "30"
     * band today and moves to "15" a fortnight later. Returning the step rather
     * than a boolean is what makes the dedupe key stable, so each band notifies
     * exactly once no matter how often the sweep runs.
     *
     * Null means the date is further away than the widest step — nothing to say
     * yet.
     *
     * @param list<int> $ladder
     */
    public static function thresholdFor(int $days, array $ladder): ?int
    {
        $match = null;
        foreach ($ladder as $step) {
            if ($days <= $step && ($match === null || $step < $match)) {
                $match = $step;
            }
        }

        return $match;
    }

    /**
     * Who hears about this contract.
     *
     * The owner, and anyone holding a renewal-managing role. Notifying the
     * whole company would train people to ignore these.
     *
     * @param array<string,mixed> $contract
     * @return list<string>
     */
    private static function recipients(SweepContext $ctx, int $cmpId, array $contract): array
    {
        $recipients = [];

        $owner = trim((string) ($contract['owner_uuid'] ?? ''));
        if ($owner !== '') {
            $recipients[] = $owner;
        }

        foreach (['contract_admin', 'legal'] as $role) {
            foreach (\App\Services\RoleService::usersWithRole($ctx->environment, $cmpId, $role) as $uuid) {
                $recipients[] = $uuid;
            }
        }

        return array_values(array_unique($recipients));
    }

    /** @param list<string> $recipients */
    private static function notify(
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
        if ($ctx->dryRun || $recipients === []) {
            return 0;
        }

        $sent = 0;
        foreach ($recipients as $uuid) {
            $id = $ctx->notifications()->notify(
                $ctx->environment,
                $cmpId,
                $uuid,
                $event,
                $title,
                $body,
                [
                    'contract_id' => $contractId,
                    'link_path'   => '/contracts/' . $contractId,
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
