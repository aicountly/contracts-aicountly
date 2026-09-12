<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use App\Support\Validator;
use PDO;

/**
 * Discussion threads hanging off a contract.
 *
 * Two decisions shape this class. Comments are soft-deleted, because a thread
 * with a hole in it stops making sense and a deleted comment is still part of
 * the record of how a clause came to be worded the way it is. And every read
 * and write is anchored to a contract the caller can already open — the comment
 * id alone is never enough, or the discussion would be a way around the
 * contract's own visibility rules.
 */
final class CommentService
{
    /** How deep a thread may go. */
    private const MAX_DEPTH = 2;

    private const MAX_MENTIONS = 50;

    /** What a comment may be attached to. Mirrors ck_comments_subject. */
    private const SUBJECT_TYPES = [
        'contract', 'clause', 'version', 'approval', 'obligation', 'risk_finding', 'amendment',
    ];

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

    /**
     * Every live comment on a contract, nested one level deep.
     *
     * Assembled in PHP rather than with a recursive CTE because the depth is
     * capped at a reply to a comment: the tree is two levels by construction,
     * and a recursive query would be machinery for a shape that cannot occur.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listForContract(TenantContext $ctx, int $contractId, array $filters = []): array
    {
        $this->assertContractVisible($ctx, $contractId);

        $clauses = ['environment = :env', 'cmp_id = :cmp', 'contract_id = :contract', 'deleted_at IS NULL'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'contract' => $contractId];

        $subjectType = $filters['subject_type'] ?? null;
        if (is_string($subjectType) && in_array($subjectType, self::SUBJECT_TYPES, true)) {
            $clauses[]              = 'subject_type = :subject_type';
            $params['subject_type'] = $subjectType;

            if (isset($filters['subject_id']) && is_numeric($filters['subject_id'])) {
                $clauses[]            = 'subject_id = :subject_id';
                $params['subject_id'] = (int) $filters['subject_id'];
            }
        }

        if (! empty($filters['unresolved_only'])) {
            $clauses[] = 'is_resolved = FALSE';
        }

        $st = $this->pdo->prepare(
            'SELECT id, uuid, contract_id, subject_type, subject_id, parent_id, author_uuid,
                    body, mentions, is_resolved, resolved_by, resolved_at, edited_at, created_at
             FROM contract_comments
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY created_at, id'
        );
        $st->execute($params);

        $roots   = [];
        $replies = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $comment            = $this->hydrate($row);
            $comment['replies'] = [];

            if ($comment['parent_id'] === null) {
                $roots[$comment['id']] = $comment;
            } else {
                $replies[] = $comment;
            }
        }

        foreach ($replies as $reply) {
            $parentId = $reply['parent_id'];
            if (isset($roots[$parentId])) {
                $roots[$parentId]['replies'][] = $reply;
                continue;
            }

            // The parent was soft-deleted out from under it. Orphaning the
            // reply would hide a live comment entirely, so it surfaces as a
            // root rather than disappearing.
            $roots[$reply['id']] = $reply;
        }

        return array_values($roots);
    }

    /**
     * One comment the caller is entitled to see, or a 404.
     *
     * @return array<string,mixed>
     */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_comments
             WHERE id = ? AND environment = ? AND cmp_id = ? AND deleted_at IS NULL
             LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Comment not found.');
        }

        // Same company is not the same thing as visible: the contract's own
        // row-level rules decide whether this caller may read its discussion.
        $this->assertContractVisible($ctx, (int) $row['contract_id']);

        return $this->hydrate($row);
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(TenantContext $ctx, int $contractId, array $body): array
    {
        $this->assertContractVisible($ctx, $contractId);

        $v        = new Validator($body);
        $text     = $v->requiredString('body', 10000);
        $mentions = $this->readMentions($v);
        $parentId = $v->optionalId('parent_id');
        $subject  = $v->optionalEnum('subject_type', self::SUBJECT_TYPES, 'contract') ?? 'contract';
        $subjectId = $v->optionalId('subject_id');
        $v->assert();

        $parent = $parentId === null ? null : $this->findOrFail($ctx, $parentId);
        if ($parent !== null) {
            if ((int) $parent['contract_id'] !== $contractId) {
                throw new ValidationFailed(['parent_id' => 'That comment belongs to another contract.']);
            }
            if ($parent['parent_id'] !== null) {
                // Depth is capped so a thread stays readable and so the two-level
                // assembly in listForContract() stays correct.
                throw new ValidationFailed(['parent_id' => 'Replies go on the first comment of a thread, not on another reply.']);
            }

            // A reply inherits its parent's anchor: a reply filed against a
            // different clause than the comment it answers would vanish from
            // whichever tab the reader had open.
            $subject   = (string) $parent['subject_type'];
            $subjectId = $parent['subject_id'] === null ? null : (int) $parent['subject_id'];
        }

        $st = $this->pdo->prepare(
            'INSERT INTO contract_comments
             (environment, cmp_id, contract_id, subject_type, subject_id, parent_id,
              author_uuid, body, mentions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
             RETURNING *'
        );
        $st->execute([
            $ctx->environment,
            $ctx->cmpId,
            $contractId,
            $subject,
            $subjectId,
            $parentId,
            $ctx->uuid,
            $text,
            json_encode($mentions, JSON_UNESCAPED_SLASHES),
        ]);

        $row = $this->hydrate($st->fetch() ?: []);

        $this->audit->log($ctx, 'comment', (int) $row['id'], 'comment.created', $contractId);
        $this->activity->record(
            $ctx,
            $contractId,
            $parentId === null ? 'comment.added' : 'comment.replied',
            $parentId === null ? 'Comment added' : 'Reply added to a comment',
            array_filter(['mentions' => $mentions])
        );

        return $row;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $id, array $body): array
    {
        $existing = $this->findOrFail($ctx, $id);
        $this->assertAuthor($ctx, $existing, 'edit');

        $v        = new Validator($body);
        $text     = $v->requiredString('body', 10000);
        $mentions = $v->has('mentions') ? $this->readMentions($v) : $existing['mentions'];
        $v->assert();

        // edited_at rather than a silent overwrite: a comment that changed
        // after someone replied to it should say so.
        $st = $this->pdo->prepare(
            'UPDATE contract_comments
             SET body = ?, mentions = ?::jsonb, edited_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ? AND deleted_at IS NULL
             RETURNING *'
        );
        $st->execute([
            $text,
            json_encode($mentions, JSON_UNESCAPED_SLASHES),
            $id,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        $row = $this->hydrate($st->fetch() ?: []);

        $this->audit->log(
            $ctx,
            'comment',
            $id,
            'comment.edited',
            (int) $existing['contract_id'],
            ['body' => ['from' => $existing['body'], 'to' => $text]]
        );

        return $row;
    }

    /**
     * Soft-delete: the row stays, the body stops being served.
     */
    public function delete(TenantContext $ctx, int $id): void
    {
        $existing = $this->findOrFail($ctx, $id);
        $this->assertAuthor($ctx, $existing, 'delete');

        $this->pdo->prepare(
            'UPDATE contract_comments SET deleted_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$id, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'comment', $id, 'comment.deleted', (int) $existing['contract_id']);
    }

    /**
     * Mark a thread settled, or reopen it.
     *
     * Resolution is a moderation act rather than an edit, so it is not limited
     * to the author: an administrator has to be able to close a thread whose
     * author has left the company.
     *
     * @return array<string,mixed>
     */
    public function resolve(TenantContext $ctx, int $id, bool $resolved): array
    {
        $existing = $this->findOrFail($ctx, $id);

        if ((string) $existing['author_uuid'] !== $ctx->uuid && ! self::isAdministrator($ctx)) {
            throw DomainException::forbidden('Only the author or a contract administrator can resolve this comment.');
        }

        // The timestamp comes from the database rather than PHP: the connection
        // is pinned to UTC, the PHP process's timezone is not.
        $st = $this->pdo->prepare(
            'UPDATE contract_comments
             SET is_resolved = ?, resolved_by = ?, resolved_at = ' . ($resolved ? 'CURRENT_TIMESTAMP' : 'NULL') . '
             WHERE id = ? AND environment = ? AND cmp_id = ? AND deleted_at IS NULL
             RETURNING *'
        );
        $st->execute([
            $resolved ? 'true' : 'false',
            $resolved ? $ctx->uuid : null,
            $id,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        $row = $this->hydrate($st->fetch() ?: []);

        $this->audit->log($ctx, 'comment', $id, $resolved ? 'comment.resolved' : 'comment.reopened', (int) $existing['contract_id']);

        return $row;
    }

    /** Live comments on a contract, for the workspace tab counter. */
    public function countForContract(TenantContext $ctx, int $contractId): int
    {
        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contract_comments
             WHERE environment = ? AND cmp_id = ? AND contract_id = ? AND deleted_at IS NULL'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        return (int) $st->fetchColumn();
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * A comment is only ever as visible as the contract it hangs off.
     *
     * Delegated to ContractService so there is one definition of "may this user
     * see this contract" — a second copy here would drift.
     */
    private function assertContractVisible(TenantContext $ctx, int $contractId): void
    {
        (new ContractService($this->pdo))->findOrFail($ctx, $contractId);
    }

    /** @param array<string,mixed> $comment */
    private function assertAuthor(TenantContext $ctx, array $comment, string $verb): void
    {
        if ((string) $comment['author_uuid'] === $ctx->uuid) {
            return;
        }

        // Not even an administrator: rewriting or removing what someone else
        // said would make the discussion useless as a record of who said what.
        throw DomainException::forbidden(sprintf('Only the author can %s this comment.', $verb));
    }

    /**
     * `contract_admin` is the only built-in role holding SETTINGS_MANAGE, and a
     * custom grant of that permission is the same authority under another name.
     */
    private static function isAdministrator(TenantContext $ctx): bool
    {
        return in_array('contract_admin', $ctx->roles, true) || $ctx->has(Permissions::SETTINGS_MANAGE);
    }

    /**
     * The uuids called out in the comment body.
     *
     * @return list<string>
     */
    private function readMentions(Validator $v): array
    {
        $mentions = [];
        foreach ($v->optionalArray('mentions', self::MAX_MENTIONS) as $entry) {
            if (! is_string($entry) && ! is_numeric($entry)) {
                continue;
            }
            $uuid = trim((string) $entry);
            if ($uuid !== '' && mb_strlen($uuid) <= 64) {
                $mentions[$uuid] = true;
            }
        }

        return array_keys($mentions);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        if (isset($row['mentions'])) {
            $decoded         = is_string($row['mentions']) ? json_decode($row['mentions'], true) : $row['mentions'];
            $row['mentions'] = is_array($decoded) ? array_values($decoded) : [];
        }

        if (array_key_exists('is_resolved', $row)) {
            $row['is_resolved'] = ContractService::toBool($row['is_resolved']);
        }

        foreach (['id', 'cmp_id', 'contract_id', 'parent_id', 'subject_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }
}
