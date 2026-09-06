<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Services\RenewalService;
use App\Services\RoleService;
use Throwable;

/**
 * Renewal cycles that have become due for a decision.
 *
 * The transition itself is RenewalService::scanDue(), so the rule is the same
 * whether it fires here or when someone opens the renewal queue. This class
 * notifies the people who have to decide.
 */
final class RenewalSweep
{
    public const TASK = 'renewals';

    public static function run(SweepContext $ctx): SweepContext
    {
        try {
            $counts = (new RenewalService($ctx->pdo))->scanDue($ctx->environment, $ctx->cmpId);
            $ctx->detail['opened']     = $counts['opened'] ?? 0;
            $ctx->detail['notice_due'] = $counts['notice_due'] ?? 0;
            $ctx->processed            = ($counts['opened'] ?? 0) + ($counts['notice_due'] ?? 0);
        } catch (Throwable $e) {
            $ctx->noteError(self::TASK, 'scan: ' . $e->getMessage());

            return $ctx;
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
        $st = $ctx->pdo->prepare(
            'SELECT r.id, r.contract_id, r.notice_deadline, r.decision_due_date, r.owner_uuid,
                    c.contract_number, c.title, c.auto_renewal, c.counterparty_name
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             WHERE r.environment = ? AND r.cmp_id = ?
               AND r.status = \'review_due\'
               AND c.archived_at IS NULL'
        );
        $st->execute([$ctx->environment, $cmpId]);

        foreach ($st->fetchAll() ?: [] as $row) {
            if ($ctx->dryRun) {
                continue;
            }

            $contractId = (int) $row['contract_id'];
            $owner      = trim((string) ($row['owner_uuid'] ?? ''));
            $recipients = $owner !== ''
                ? [$owner]
                : RoleService::usersWithRole($ctx->environment, $cmpId, 'contract_admin');

            $autoRenews = \App\Services\ContractService::toBool($row['auto_renewal']);

            foreach ($recipients as $uuid) {
                $id = $ctx->notifications()->notify(
                    $ctx->environment,
                    $cmpId,
                    $uuid,
                    'renewal.review_due',
                    sprintf('Renewal decision needed — %s', $row['contract_number']),
                    sprintf(
                        '%s with %s. %s',
                        $row['title'],
                        $row['counterparty_name'] ?: 'the counterparty',
                        $autoRenews
                            ? 'This contract renews automatically unless notice is given by ' . ($row['notice_deadline'] ?? 'the notice deadline') . '.'
                            : 'Decide whether to renew, renegotiate or let it end.'
                    ),
                    [
                        'contract_id' => $contractId,
                        'link_path'   => '/renewals',
                        // Auto-renewal is the case where doing nothing has a
                        // consequence, so it is the one that reads as critical.
                        'severity'    => $autoRenews ? 'critical' : 'warning',
                        'dedupe_key'  => 'renewal_review:' . (int) $row['id'],
                    ]
                );
                if ($id !== null) {
                    $ctx->notified++;
                }
            }
        }
    }
}
