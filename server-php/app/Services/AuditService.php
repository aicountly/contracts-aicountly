<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Support\TenantContext;
use PDO;
use Throwable;

/**
 * The compliance record: what changed, from what, to what, by whom.
 *
 * Separate from ActivityService on purpose. This table is append-only at the
 * database level (a trigger refuses UPDATE and DELETE), carries before/after
 * values, and is written for anything a regulator or a dispute would ask about.
 * The activity timeline is the readable story; this is the evidence.
 *
 * Writes here never abort the caller's transaction. An audit write that fails
 * is a serious operational problem, but losing the user's contract edit
 * because the audit insert hit a constraint is worse — the failure is logged
 * and the business write stands.
 */
final class AuditService
{
    /** Fields whose values must never be written into an audit row verbatim. */
    private const REDACTED_FIELDS = ['api_key', 'password', 'token', 'ses_key', 'secret', 'authorization'];

    private const MAX_VALUE_LENGTH = 4000;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Record one action.
     *
     * @param array<string,mixed>|null $changes {field: {from, to}} for a multi-field edit
     */
    public function log(
        TenantContext $ctx,
        string $entityType,
        ?int $entityId,
        string $action,
        ?int $contractId = null,
        ?array $changes = null,
        ?string $fieldName = null,
        mixed $oldValue = null,
        mixed $newValue = null
    ): void {
        try {
            $st = $this->pdo->prepare(
                'INSERT INTO contract_audit_logs
                 (environment, cmp_id, entity_type, entity_id, contract_id, action,
                  field_name, old_value, new_value, changes, actor_uuid, actor_ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?)'
            );
            $st->execute([
                $ctx->environment,
                $ctx->cmpId,
                $entityType,
                $entityId,
                $contractId,
                $action,
                $fieldName,
                self::scalar($fieldName, $oldValue),
                self::scalar($fieldName, $newValue),
                $changes === null ? null : json_encode(self::redact($changes), JSON_UNESCAPED_SLASHES),
                $ctx->uuid,
                Request::clientIp(),
                Request::userAgent(),
            ]);
        } catch (Throwable $e) {
            error_log('[contracts][audit] failed to write audit row: ' . $e->getMessage());
        }
    }

    /**
     * Diff two row snapshots and record only what actually changed.
     *
     * Returning early on an empty diff keeps the audit trail readable: a save
     * button pressed with no edits should not produce an entry.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param list<string>        $watch fields to consider
     */
    public function logChanges(
        TenantContext $ctx,
        string $entityType,
        int $entityId,
        array $before,
        array $after,
        array $watch,
        ?int $contractId = null,
        string $action = 'updated'
    ): void {
        $changes = [];
        foreach ($watch as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;

            // Loose comparison after string casting: 100 and "100" coming back
            // from PDO for the same unchanged numeric column is not a change.
            if ((string) (is_scalar($old) || $old === null ? $old : json_encode($old))
                === (string) (is_scalar($new) || $new === null ? $new : json_encode($new))) {
                continue;
            }

            $changes[$field] = [
                'from' => self::scalar($field, $old),
                'to'   => self::scalar($field, $new),
            ];
        }

        if ($changes === []) {
            return;
        }

        $this->log($ctx, $entityType, $entityId, $action, $contractId, $changes);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForContract(TenantContext $ctx, int $contractId, int $limit = 100, int $offset = 0): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, action, field_name, old_value, new_value,
                    changes, actor_uuid, created_at
             FROM contract_audit_logs
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId, $limit, $offset]);

        return $st->fetchAll() ?: [];
    }

    public function countForContract(TenantContext $ctx, int $contractId): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contract_audit_logs
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        return (int) $st->fetchColumn();
    }

    /** @param array<string,mixed> $changes @return array<string,mixed> */
    private static function redact(array $changes): array
    {
        $out = [];
        foreach ($changes as $field => $value) {
            $out[$field] = self::isSensitive((string) $field) ? '[redacted]' : $value;
        }

        return $out;
    }

    private static function isSensitive(?string $field): bool
    {
        if ($field === null) {
            return false;
        }
        $lower = strtolower($field);
        foreach (self::REDACTED_FIELDS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function scalar(?string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (self::isSensitive($field)) {
            return '[redacted]';
        }

        $text = is_scalar($value)
            ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)
            : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: '');

        return mb_substr($text, 0, self::MAX_VALUE_LENGTH);
    }
}
