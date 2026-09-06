<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\TenantContext;
use PDO;
use Throwable;

/**
 * The human-readable story of a contract.
 *
 * "Approval requested from Legal" rather than "status: under_review →
 * awaiting_approval". Trimmable, presentational, and deliberately not the
 * compliance record — that is AuditService.
 */
final class ActivityService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @param array<string,mixed> $metadata */
    public function record(
        TenantContext $ctx,
        ?int $contractId,
        string $eventType,
        string $summary,
        array $metadata = [],
        ?string $icon = null,
        ?int $requestId = null
    ): void {
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO contract_activity_logs
                 (environment, cmp_id, contract_id, request_id, actor_uuid, event_type, summary, icon, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)'
            );
            $st->execute([
                $ctx->environment,
                $ctx->cmpId,
                $contractId,
                $requestId,
                $ctx->uuid,
                $eventType,
                mb_substr($summary, 0, 500),
                $icon ?? self::iconFor($eventType),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            error_log('[contracts][activity] ' . $e->getMessage());
        }
    }

    /**
     * Record an event attributed to the system rather than a person.
     *
     * Cron sweeps have no acting user; writing them as the last human who
     * touched the contract would be a lie in the timeline.
     *
     * @param array<string,mixed> $metadata
     */
    public function recordSystem(
        string $environment,
        int $cmpId,
        ?int $contractId,
        string $eventType,
        string $summary,
        array $metadata = []
    ): void {
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO contract_activity_logs
                 (environment, cmp_id, contract_id, actor_uuid, actor_label, event_type, summary, icon, metadata)
                 VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?::jsonb)'
            );
            $st->execute([
                $environment,
                $cmpId,
                $contractId,
                'Automation',
                $eventType,
                mb_substr($summary, 0, 500),
                self::iconFor($eventType),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $e) {
            error_log('[contracts][activity] ' . $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    public function listForContract(TenantContext $ctx, int $contractId, int $limit = 50, int $offset = 0): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, actor_uuid, actor_label, event_type, summary, icon, metadata, created_at
             FROM contract_activity_logs
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId, $limit, $offset]);

        return $st->fetchAll() ?: [];
    }

    /** Company-wide recent activity, for the dashboard. @return list<array<string,mixed>> */
    public function recent(TenantContext $ctx, int $limit = 15): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id, a.actor_uuid, a.actor_label, a.event_type, a.summary, a.icon,
                    a.created_at, a.contract_id, c.contract_number, c.title AS contract_title
             FROM contract_activity_logs a
             LEFT JOIN contracts c ON c.id = a.contract_id
             WHERE a.environment = ? AND a.cmp_id = ?
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ?'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $limit]);

        return $st->fetchAll() ?: [];
    }

    private static function iconFor(string $eventType): string
    {
        return match (true) {
            str_starts_with($eventType, 'contract.created')   => 'file-plus',
            str_starts_with($eventType, 'contract.status')    => 'activity',
            str_starts_with($eventType, 'document')           => 'file-text',
            str_starts_with($eventType, 'version')            => 'git-branch',
            str_starts_with($eventType, 'ai')                 => 'sparkles',
            str_starts_with($eventType, 'approval')           => 'check-circle',
            str_starts_with($eventType, 'signature')          => 'pen-tool',
            str_starts_with($eventType, 'obligation')         => 'clipboard-check',
            str_starts_with($eventType, 'milestone')          => 'flag',
            str_starts_with($eventType, 'renewal')            => 'refresh-cw',
            str_starts_with($eventType, 'amendment')          => 'file-diff',
            str_starts_with($eventType, 'termination')        => 'x-circle',
            str_starts_with($eventType, 'risk')               => 'alert-triangle',
            str_starts_with($eventType, 'comment')            => 'message-square',
            default                                            => 'circle',
        };
    }
}
