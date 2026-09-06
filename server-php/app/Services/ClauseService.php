<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use App\Support\Validator;
use PDO;
use PDOException;

/**
 * The clause library, and the clauses standing in one contract.
 *
 * Two tables in one class because they hold the same wording at two different
 * ages. `clause_library` is what the company would like to agree;
 * `contract_clauses` is what one agreement actually says. Attaching copies the
 * text across rather than pointing at it — a signed contract has to read the
 * same in five years, whatever the library has become since — and records
 * where the copy came from, because that link is what lets the deviation
 * report say "this is our standard clause, unchanged" instead of putting a
 * paragraph nobody negotiated back in front of a reviewer.
 *
 * Published wording is never edited in place. A contract reviewed against
 * version 3 has to stay reviewable against version 3, so a save that rewords
 * approved text supersedes it: the outgoing wording stays in `clause_versions`
 * and the library row moves on to the next version. A draft has never been
 * offered to anyone, so it is edited in place and has no history to keep.
 *
 * Every query filters `environment` AND `cmp_id` from the TenantContext, never
 * from request input.
 */
final class ClauseService
{
    /**
     * Library states in which the wording has been offered for drafting.
     *
     * Deprecated wording belongs here with approved: it was in use, contracts
     * were reviewed against it, and its history is worth as much as an approved
     * clause's.
     */
    private const PUBLISHED_STATUSES = ['approved', 'deprecated'];

    /** Columns of a contract's own clause a caller may write. */
    private const CONTRACT_COLUMNS = [
        'category_id', 'clause_number', 'heading', 'body_text',
        'source_page', 'source_offset', 'source_excerpt', 'verification_state',
    ];

    /** Fields whose change on a contract's clause is worth an audit row. */
    private const AUDITED_CONTRACT_FIELDS = [
        'category_id', 'clause_number', 'heading', 'body_text',
        'source_page', 'source_excerpt', 'verification_state',
    ];

    /** Fields whose change on a library clause is worth an audit row. */
    private const AUDITED_LIBRARY_FIELDS = [
        'name', 'description', 'category_id', 'standard_text', 'fallback_text',
        'prohibited_wording', 'risk_classification', 'jurisdiction',
        'approval_status', 'effective_from', 'effective_to', 'version',
    ];

    /** Verification states that record a person's decision rather than the extractor's guess. */
    private const HUMAN_STATES = ['human_verified', 'human_edited', 'rejected'];

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
    // Clauses standing in one contract
    // -----------------------------------------------------------------------

    /**
     * Every clause recorded against a contract, in document order.
     *
     * Ordered by position rather than by `clause_number`: that column holds
     * whatever the agreement itself calls the paragraph — "4.2(b)", "Schedule
     * 1" — and sorting it as text puts clause 10 in front of clause 2. Clauses
     * with no position, which is everything taken from the library, follow in
     * the order somebody added them.
     *
     * @return list<array<string,mixed>>
     */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $this->assertContract($ctx, $contractId);

        $st = $this->pdo->prepare(
            'SELECT cc.*, cat.name AS category_name, cat.code AS category_code,
                    lib.name AS library_clause_name
             FROM contract_clauses cc
             LEFT JOIN clause_categories cat ON cat.id = cc.category_id
             LEFT JOIN clause_library lib ON lib.id = cc.library_clause_id
             WHERE cc.environment = ? AND cc.cmp_id = ? AND cc.contract_id = ?
             ORDER BY cc.source_page ASC NULLS LAST, cc.source_offset ASC NULLS LAST, cc.id ASC'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        return array_map(fn (array $r): array => self::hydrateContractClause($r), $st->fetchAll() ?: []);
    }

    /**
     * Record a clause somebody wrote or negotiated themselves.
     *
     * Marked verified by default: a person typing a paragraph into the form is
     * standing behind it, and leaving it in the extractor's `ai_extracted`
     * state would put it in the review queue asking a human to confirm their
     * own work.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed> the created clause
     */
    public function createForContract(TenantContext $ctx, int $contractId, array $body): array
    {
        $this->assertContract($ctx, $contractId);

        $v      = new Validator($body);
        $fields = $this->readContractFields($v, true);
        $v->assert();

        $this->assertCategory($ctx, $fields['category_id']);

        $human = in_array($fields['verification_state'], self::HUMAN_STATES, true);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $fields, $human): array {
            $st = $pdo->prepare(
                'INSERT INTO contract_clauses
                 (environment, cmp_id, contract_id, category_id, clause_number, heading, body_text,
                  source_page, source_offset, source_excerpt, is_ai_extracted, verification_state,
                  verified_by, verified_at)
                 VALUES (:env, :cmp, :contract, :category, :number, :heading, :body,
                         :page, :offset, :excerpt, FALSE, :state,
                         :verifier, CASE WHEN CAST(:verified AS BOOLEAN) THEN CURRENT_TIMESTAMP END)
                 RETURNING id'
            );
            $st->execute([
                'env'      => $ctx->environment,
                'cmp'      => $ctx->cmpId,
                'contract' => $contractId,
                'category' => $fields['category_id'],
                'number'   => $fields['clause_number'],
                'heading'  => $fields['heading'],
                'body'     => $fields['body_text'],
                'page'     => $fields['source_page'],
                'offset'   => $fields['source_offset'],
                'excerpt'  => $fields['source_excerpt'],
                'state'    => $fields['verification_state'],
                'verifier' => $human ? $ctx->uuid : null,
                'verified' => $human ? 'true' : 'false',
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'contract_clause', $id, 'clause.added', $contractId, [
                'heading'   => ['from' => null, 'to' => $fields['heading']],
                'body_text' => ['from' => null, 'to' => $fields['body_text']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'clause.added',
                sprintf('Clause "%s" recorded', self::clauseLabel($fields['heading'], $fields['clause_number'])),
                ['clause_id' => $id]
            );

            return $this->contractClauseOrFail($ctx, $id);
        });
    }

    /**
     * Copy a library clause onto a contract.
     *
     * The wording is copied, never referenced: the contract has to read the
     * same in five years when the library has been rewritten twice. What is
     * kept is the provenance — `library_clause_id` — because that is the link
     * the deviation report reads to say "this is our standard clause,
     * unchanged" rather than raising a negotiation about a paragraph nobody
     * negotiated.
     *
     * The version it was taken at has no column on `contract_clauses`, so it is
     * recorded where it cannot be lost: named in the append-only audit row, and
     * with the wording itself pinned into `clause_versions` first — the number
     * on its own stops meaning anything once the text behind it has been edited
     * away.
     *
     * @return array<string,mixed> the clause as it now stands on the contract
     */
    public function attachToContract(TenantContext $ctx, int $contractId, int $libraryClauseId): array
    {
        $this->assertContract($ctx, $contractId);
        $clause = $this->libraryClauseOrFail($ctx, $libraryClauseId);

        if ($clause['archived_at'] !== null) {
            throw DomainException::conflict(
                'That clause has been retired from the library. Choose current wording, or record the text as a clause of its own.',
                'CLAUSE_ARCHIVED'
            );
        }

        $version = (int) $clause['version'];

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $clause, $version): array {
            // Only published wording is pinned. A draft is still being written,
            // so a version row for it would be stale the next time its author
            // saves — and a history row that disagrees with the clause it
            // claims to record is worse than no row at all.
            if (in_array((string) $clause['approval_status'], self::PUBLISHED_STATUSES, true)) {
                self::writeVersion(
                    $pdo,
                    (int) $clause['id'],
                    $version,
                    (string) $clause['standard_text'],
                    $clause['fallback_text'],
                    null,
                    $clause['author_uuid']
                );
            }

            // No clause_number: the library has no opinion on where this sits
            // in the agreement, and inventing "12" for a document whose twelfth
            // clause is something else would be a fabrication a reviewer later
            // reads as fact.
            $st = $pdo->prepare(
                "INSERT INTO contract_clauses
                 (environment, cmp_id, contract_id, category_id, library_clause_id,
                  heading, body_text, is_ai_extracted, verification_state, verified_by, verified_at)
                 VALUES (:env, :cmp, :contract, :category, :library,
                         :heading, :body, FALSE, 'human_verified', :actor, CURRENT_TIMESTAMP)
                 RETURNING id"
            );
            $st->execute([
                'env'      => $ctx->environment,
                'cmp'      => $ctx->cmpId,
                'contract' => $contractId,
                'category' => $clause['category_id'],
                'library'  => (int) $clause['id'],
                'heading'  => $clause['name'],
                'body'     => $clause['standard_text'],
                'actor'    => $ctx->uuid,
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'contract_clause', $id, 'clause.attached', $contractId, [
                'library_clause_id' => ['from' => null, 'to' => (int) $clause['id']],
                'library_version'   => ['from' => null, 'to' => $version],
                'heading'           => ['from' => null, 'to' => $clause['name']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'clause.attached',
                sprintf('Clause "%s" taken from the library at version %d', (string) $clause['name'], $version),
                ['clause_id' => $id, 'library_clause_id' => (int) $clause['id'], 'library_version' => $version]
            );

            return $this->contractClauseOrFail($ctx, $id);
        });
    }

    /**
     * Change a clause on a contract.
     *
     * Only the keys the caller actually sent are written, so the verify button
     * — which posts `verification_state` alone — cannot blank the wording it is
     * confirming.
     *
     * The library link survives an edit on purpose. It says where the wording
     * came from, which stays true after someone amends it; whether it still
     * matches is a question the deviation report answers by comparing the text,
     * and cutting the link would leave it unable to tell an edited standard
     * clause from wording the counterparty supplied.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateForContract(TenantContext $ctx, int $clauseId, array $body): array
    {
        $existing   = $this->contractClauseOrFail($ctx, $clauseId);
        $contractId = (int) $existing['contract_id'];

        $v      = new Validator($body);
        $fields = $this->readContractFields($v, false);
        $v->assert();

        if (array_key_exists('category_id', $fields)) {
            $this->assertCategory($ctx, $fields['category_id']);
        }

        // Rewording is itself a review decision: the paragraph on the contract
        // is now what a person says it is rather than what the extractor read,
        // and leaving it in `ai_extracted` would keep it in the review queue
        // asking someone to confirm an edit they just made.
        if (! array_key_exists('verification_state', $fields)
            && array_key_exists('body_text', $fields)
            && $fields['body_text'] !== (string) $existing['body_text']) {
            $fields['verification_state'] = 'human_edited';
        }

        $stamps = isset($fields['verification_state'])
            && $fields['verification_state'] !== (string) $existing['verification_state']
            && in_array($fields['verification_state'], self::HUMAN_STATES, true);

        $set    = [];
        $params = [];
        foreach (self::CONTRACT_COLUMNS as $column) {
            if (! array_key_exists($column, $fields)) {
                continue;
            }
            $set[]           = $column . ' = :' . $column;
            $params[$column] = $fields[$column];
        }

        if ($stamps) {
            $set[]              = 'verified_by = :verifier';
            $set[]              = 'verified_at = CURRENT_TIMESTAMP';
            $params['verifier'] = $ctx->uuid;
        }

        // A save carrying nothing is not an event: writing an audit row and a
        // timeline entry for it would bury the changes that did happen.
        if ($set === []) {
            return $existing;
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $clauseId, $contractId, $existing, $set, $params): array {
            $params['id']  = $clauseId;
            $params['env'] = $ctx->environment;
            $params['cmp'] = $ctx->cmpId;

            $pdo->prepare(
                'UPDATE contract_clauses SET ' . implode(', ', $set) . ', updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            )->execute($params);

            $updated = $this->contractClauseOrFail($ctx, $clauseId);

            $this->audit->logChanges(
                $ctx,
                'contract_clause',
                $clauseId,
                $existing,
                $updated,
                self::AUDITED_CONTRACT_FIELDS,
                $contractId,
                'clause.updated'
            );
            $this->activity->record(
                $ctx,
                $contractId,
                'clause.updated',
                sprintf('Clause "%s" updated', self::clauseLabel($updated['heading'] ?? null, $updated['clause_number'] ?? null)),
                ['clause_id' => $clauseId]
            );

            return $updated;
        });
    }

    /**
     * Take a clause off a contract.
     *
     * Deleted rather than archived: unlike the library, a clause row here is a
     * reading of the agreement, and a reading that was wrong should stop being
     * asserted. Deviations raised against it go with it by cascade, which is
     * right — a negotiating point about a paragraph that is not there is noise.
     * Obligations and terminations that cite it keep their own rows and lose
     * only the citation.
     */
    public function deleteForContract(TenantContext $ctx, int $clauseId): void
    {
        $existing   = $this->contractClauseOrFail($ctx, $clauseId);
        $contractId = (int) $existing['contract_id'];

        // Audited before the delete: afterwards there is no row to reference,
        // and the audit table is append-only so the record survives.
        $this->audit->log($ctx, 'contract_clause', $clauseId, 'clause.removed', $contractId, [
            'heading'   => ['from' => $existing['heading'], 'to' => null],
            'body_text' => ['from' => $existing['body_text'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM contract_clauses WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$clauseId, $ctx->environment, $ctx->cmpId]);

        $this->activity->record(
            $ctx,
            $contractId,
            'clause.removed',
            sprintf('Clause "%s" removed', self::clauseLabel($existing['heading'] ?? null, $existing['clause_number'] ?? null))
        );
    }

    // -----------------------------------------------------------------------
    // The clause library
    // -----------------------------------------------------------------------

    /**
     * The clause categories, each with the size of its shelf.
     *
     * The count excludes archived wording, because it is read as "what is here
     * to draft with" — a category offering nine clauses and delivering none is
     * a worse answer than an empty one.
     *
     * @return list<array<string,mixed>>
     */
    public function categories(TenantContext $ctx): array
    {
        // The join repeats the tenant filter rather than trusting the
        // category's: `category_id` alone would let a clause filed against
        // another company's category be counted here.
        $st = $this->pdo->prepare(
            'SELECT cat.id, cat.code, cat.name, cat.description, cat.risk_weight,
                    cat.is_system, cat.sort_order, cat.created_at,
                    COUNT(lib.id) AS clause_count
             FROM clause_categories cat
             LEFT JOIN clause_library lib
                    ON lib.category_id = cat.id
                   AND lib.environment = cat.environment
                   AND lib.cmp_id = cat.cmp_id
                   AND lib.archived_at IS NULL
             WHERE cat.environment = ? AND cat.cmp_id = ?
             GROUP BY cat.id
             ORDER BY cat.sort_order ASC, cat.name ASC'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        return array_map(static function (array $row): array {
            $row['is_system'] = ContractService::toBool($row['is_system'] ?? false);

            foreach (['id', 'risk_weight', 'sort_order', 'clause_count'] as $key) {
                $row[$key] = (int) $row[$key];
            }

            return $row;
        }, $st->fetchAll() ?: []);
    }

    /**
     * Add a category to the shelf.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createCategory(TenantContext $ctx, array $body): array
    {
        $v      = new Validator($body);
        $code   = self::readCategoryCode($v);
        $name   = $v->requiredString('name', 160);
        $fields = [
            'description' => $v->optionalText('description', 4000),
            'risk_weight' => $v->optionalInt('risk_weight', 0, 10, 5) ?? 5,
            'sort_order'  => $v->optionalInt('sort_order', 0, 100000, 100) ?? 100,
        ];
        $v->assert();

        try {
            $st = $this->pdo->prepare(
                'INSERT INTO clause_categories
                 (environment, cmp_id, code, name, description, risk_weight, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
            );
            $st->execute([
                $ctx->environment, $ctx->cmpId, $code, $name,
                $fields['description'], $fields['risk_weight'], $fields['sort_order'],
            ]);
        } catch (PDOException $e) {
            // uq_clause_categories_code. Reported against the field the user
            // typed rather than as a server error, which is what it would
            // otherwise become.
            throw self::asDuplicateCode($e);
        }

        $id = (int) $st->fetchColumn();

        $this->audit->log($ctx, 'clause_category', $id, 'clause_category.created', null, [
            'code' => ['from' => null, 'to' => $code],
            'name' => ['from' => null, 'to' => $name],
        ]);

        return $this->categoryOrFail($ctx, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateCategory(TenantContext $ctx, int $categoryId, array $body): array
    {
        $existing = $this->categoryOrFail($ctx, $categoryId);

        $v    = new Validator($body);
        $name = $v->has('name') ? $v->requiredString('name', 160) : (string) $existing['name'];

        // A seeded category's code is a key other seeds and the deviation
        // engine look it up by; renaming it is safe, recoding it is not.
        $code = $v->has('code') && $existing['is_system'] !== true
            ? self::readCategoryCode($v)
            : (string) $existing['code'];

        $fields = [
            'description' => $v->has('description') ? $v->optionalText('description', 4000) : $existing['description'],
            'risk_weight' => $v->optionalInt('risk_weight', 0, 10, (int) $existing['risk_weight']) ?? 5,
            'sort_order'  => $v->optionalInt('sort_order', 0, 100000, (int) $existing['sort_order']) ?? 100,
        ];
        $v->assert();

        try {
            $this->pdo->prepare(
                'UPDATE clause_categories
                 SET code = ?, name = ?, description = ?, risk_weight = ?, sort_order = ?
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([
                $code, $name, $fields['description'], $fields['risk_weight'], $fields['sort_order'],
                $categoryId, $ctx->environment, $ctx->cmpId,
            ]);
        } catch (PDOException $e) {
            throw self::asDuplicateCode($e);
        }

        $updated = $this->categoryOrFail($ctx, $categoryId);

        $this->audit->logChanges(
            $ctx,
            'clause_category',
            $categoryId,
            $existing,
            $updated,
            ['code', 'name', 'description', 'risk_weight', 'sort_order'],
            null,
            'clause_category.updated'
        );

        return $updated;
    }

    /**
     * Delete a category nothing is filed under.
     *
     * Every reference to a category is ON DELETE SET NULL, so deleting one that
     * is in use would silently unfile the clauses in it and leave the playbook
     * measuring against nothing. Refusing is the only way the user finds out.
     */
    public function deleteCategory(TenantContext $ctx, int $categoryId): void
    {
        $existing = $this->categoryOrFail($ctx, $categoryId);

        if ($existing['is_system'] === true) {
            throw DomainException::conflict(
                'This is a built-in category and cannot be deleted. Rename it instead.',
                'CATEGORY_IS_SYSTEM'
            );
        }

        foreach ([
            'clause_library'   => 'library clauses',
            'contract_clauses' => 'clauses on contracts',
            'playbook_rules'   => 'playbook rules',
        ] as $table => $label) {
            // The table name comes from the map above, never from a caller.
            // The tenant filter is repeated on each probe rather than left to
            // the category's own scoping: none of these foreign keys carries
            // the tenant, so an unfiltered probe reads across companies.
            $st = $this->pdo->prepare(
                "SELECT 1 FROM {$table}
                 WHERE category_id = ? AND environment = ? AND cmp_id = ? LIMIT 1"
            );
            $st->execute([$categoryId, $ctx->environment, $ctx->cmpId]);

            if ($st->fetchColumn() !== false) {
                throw DomainException::conflict(
                    sprintf('This category still has %s filed under it.', $label),
                    'CATEGORY_IN_USE'
                );
            }
        }

        $this->audit->log($ctx, 'clause_category', $categoryId, 'clause_category.deleted', null, [
            'code' => ['from' => $existing['code'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM clause_categories WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$categoryId, $ctx->environment, $ctx->cmpId]);
    }

    /**
     * A page of the library.
     *
     * Ordered by name and then id: a drafter scans this alphabetically, and a
     * tie broken by anything mutable would move rows between pages while
     * somebody is paging through them.
     *
     * @param array<string,mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function search(TenantContext $ctx, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::buildLibraryWhere($ctx, $filters);

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM clause_library cl {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $st = $this->pdo->prepare(
            "SELECT cl.*, cat.name AS category_name, cat.code AS category_code
             FROM clause_library cl
             LEFT JOIN clause_categories cat ON cat.id = cl.category_id
             {$where}
             ORDER BY cl.name ASC, cl.id ASC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        return [
            'items' => array_map(static fn (array $r): array => self::hydrateLibraryClause($r), $st->fetchAll() ?: []),
            'total' => $total,
        ];
    }

    /**
     * Add wording to the library.
     *
     * A clause created as published gets its version 1 row immediately: it can
     * be attached to a contract from this moment, and the copy has to have
     * something to have been taken from.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed> the created clause
     */
    public function create(TenantContext $ctx, array $body): array
    {
        $v      = new Validator($body);
        $fields = $this->readLibraryFields($v, $ctx, true, null);
        $v->assert();

        $this->assertCategory($ctx, $fields['category_id']);

        $published = in_array($fields['approval_status'], self::PUBLISHED_STATUSES, true);
        $approving = $fields['approval_status'] === 'approved';

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $fields, $published, $approving): array {
            $st = $pdo->prepare(
                'INSERT INTO clause_library
                 (environment, cmp_id, category_id, name, description, standard_text, fallback_text,
                  prohibited_wording, risk_classification, applicable_types, jurisdiction,
                  version, approval_status, effective_from, effective_to,
                  author_uuid, approver_uuid, approved_at)
                 VALUES (:env, :cmp, :category, :name, :descr, :standard, :fallback,
                         :prohibited, :risk, :types::jsonb, :jurisdiction,
                         1, :status, :from, :to,
                         :author, :approver, CASE WHEN CAST(:approving AS BOOLEAN) THEN CURRENT_TIMESTAMP END)
                 RETURNING id'
            );
            $st->execute([
                'env'          => $ctx->environment,
                'cmp'          => $ctx->cmpId,
                'category'     => $fields['category_id'],
                'name'         => $fields['name'],
                'descr'        => $fields['description'],
                'standard'     => $fields['standard_text'],
                'fallback'     => $fields['fallback_text'],
                'prohibited'   => $fields['prohibited_wording'],
                'risk'         => $fields['risk_classification'],
                'types'        => json_encode($fields['applicable_types'], JSON_UNESCAPED_SLASHES),
                'jurisdiction' => $fields['jurisdiction'],
                'status'       => $fields['approval_status'],
                'from'         => $fields['effective_from'],
                'to'           => $fields['effective_to'],
                'author'       => $ctx->uuid,
                'approver'     => $approving ? $ctx->uuid : null,
                'approving'    => $approving ? 'true' : 'false',
            ]);

            $id = (int) $st->fetchColumn();

            if ($published) {
                self::writeVersion(
                    $pdo,
                    $id,
                    1,
                    $fields['standard_text'],
                    $fields['fallback_text'],
                    $fields['change_note'],
                    $ctx->uuid
                );
            }

            $this->audit->log($ctx, 'clause', $id, 'clause.library_created', null, [
                'name'            => ['from' => null, 'to' => $fields['name']],
                'approval_status' => ['from' => null, 'to' => $fields['approval_status']],
            ]);

            return $this->libraryClauseOrFail($ctx, $id);
        });
    }

    /**
     * Change library wording.
     *
     * Rewording an approved clause supersedes it rather than overwriting it:
     * the outgoing text is kept under the version it was published as, the
     * clause moves to the next version, and the caller's change note is filed
     * against the version this save creates. A draft is edited in place — no
     * contract has been reviewed against it, so there is no history to protect,
     * and versioning every keystroke of a first draft would leave the history
     * unreadable by the time it mattered.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $clauseId, array $body): array
    {
        $existing = $this->libraryClauseOrFail($ctx, $clauseId);

        $v      = new Validator($body);
        $fields = $this->readLibraryFields($v, $ctx, false, $existing);
        $v->assert();

        $this->assertCategory($ctx, $fields['category_id']);

        $wasPublished = in_array((string) $existing['approval_status'], self::PUBLISHED_STATUSES, true);
        $isPublished  = in_array($fields['approval_status'], self::PUBLISHED_STATUSES, true);

        $reworded = $fields['standard_text'] !== (string) $existing['standard_text']
            || ($fields['fallback_text'] ?? '') !== (string) ($existing['fallback_text'] ?? '');

        $supersedes = $wasPublished && $reworded;
        $version    = $supersedes ? (int) $existing['version'] + 1 : (int) $existing['version'];
        $approving  = $fields['approval_status'] === 'approved'
            && (string) $existing['approval_status'] !== 'approved';

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $clauseId,
            $existing,
            $fields,
            $version,
            $supersedes,
            $wasPublished,
            $isPublished,
            $approving
        ): array {
            // Backfill the wording being edited away. Clauses seeded by
            // CompanyBootstrapService were published without ever passing
            // through here, so the version they are leaving has no row of its
            // own — and without one the contracts drafted from it lose the text
            // they were reviewed against.
            if ($supersedes) {
                self::writeVersion(
                    $pdo,
                    $clauseId,
                    (int) $existing['version'],
                    (string) $existing['standard_text'],
                    $existing['fallback_text'],
                    null,
                    $existing['author_uuid']
                );
            }

            $st = $pdo->prepare(
                'UPDATE clause_library SET
                    category_id = :category, name = :name, description = :descr,
                    standard_text = :standard, fallback_text = :fallback,
                    prohibited_wording = :prohibited, risk_classification = :risk,
                    applicable_types = :types::jsonb, jurisdiction = :jurisdiction,
                    version = :version, approval_status = :status,
                    effective_from = :from, effective_to = :to,
                    approver_uuid = COALESCE(:approver, approver_uuid),
                    approved_at = CASE WHEN CAST(:approving AS BOOLEAN) THEN CURRENT_TIMESTAMP ELSE approved_at END,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            );
            $st->execute([
                'category'     => $fields['category_id'],
                'name'         => $fields['name'],
                'descr'        => $fields['description'],
                'standard'     => $fields['standard_text'],
                'fallback'     => $fields['fallback_text'],
                'prohibited'   => $fields['prohibited_wording'],
                'risk'         => $fields['risk_classification'],
                'types'        => json_encode($fields['applicable_types'], JSON_UNESCAPED_SLASHES),
                'jurisdiction' => $fields['jurisdiction'],
                'version'      => $version,
                'status'       => $fields['approval_status'],
                'from'         => $fields['effective_from'],
                'to'           => $fields['effective_to'],
                'approver'     => $approving ? $ctx->uuid : null,
                'approving'    => $approving ? 'true' : 'false',
                'id'           => $clauseId,
                'env'          => $ctx->environment,
                'cmp'          => $ctx->cmpId,
            ]);

            // Either a new version, or a draft becoming wording other people
            // may now take a copy of. Both need the text on record under the
            // number a contract will cite.
            if ($supersedes || ($isPublished && ! $wasPublished)) {
                self::writeVersion(
                    $pdo,
                    $clauseId,
                    $version,
                    $fields['standard_text'],
                    $fields['fallback_text'],
                    $fields['change_note'],
                    $ctx->uuid
                );
            }

            $updated = $this->libraryClauseOrFail($ctx, $clauseId);

            $this->audit->logChanges(
                $ctx,
                'clause',
                $clauseId,
                $existing,
                $updated,
                self::AUDITED_LIBRARY_FIELDS,
                null,
                'clause.library_updated'
            );

            return $updated;
        });
    }

    /**
     * Retire a clause from the library.
     *
     * Archived, never deleted. `contract_clauses.library_clause_id` is ON
     * DELETE SET NULL, so removing the row would quietly cut every contract's
     * copy loose from the wording it was taken from — and that link is what the
     * deviation report reads to recognise a standard clause. Archiving takes
     * the clause out of the drafting screens and leaves the record whole.
     *
     * A seeded clause stays gone: CompanyBootstrapService looks for the name
     * whether or not it is archived, so nothing reinstates it behind the user.
     */
    public function delete(TenantContext $ctx, int $clauseId): void
    {
        $existing = $this->libraryClauseOrFail($ctx, $clauseId);

        // Asking for a state the row is already in is not a failure, but it is
        // not an event either — a second audit row would claim a retirement
        // that did not happen.
        if ($existing['archived_at'] !== null) {
            return;
        }

        $this->pdo->prepare(
            'UPDATE clause_library SET archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$clauseId, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'clause', $clauseId, 'clause.library_archived', null, [
            'name'        => ['from' => $existing['name'], 'to' => $existing['name']],
            'archived_at' => ['from' => null, 'to' => 'now'],
        ]);
    }

    /**
     * The wording a drafter may reach for on this kind of contract.
     *
     * Approved, current, and either applicable to every contract type or to
     * this one — an empty `applicable_types` reads as "any", which is the same
     * convention `contract_risk_rules.applies_to_types` uses. One rule across
     * the product rather than two that look alike and differ.
     *
     * @return list<array<string,mixed>>
     */
    public function applicableFor(TenantContext $ctx, ?int $contractTypeId, ?int $categoryId = null): array
    {
        return $this->search($ctx, [
            'contract_type_id' => $contractTypeId,
            'category_id'      => $categoryId,
            'approval_status'  => 'approved',
            'in_date'          => true,
        ], 200, 0)['items'];
    }

    /**
     * Publish the wording.
     *
     * Approval is what makes a clause offerable to a drafter, so the version a
     * contract will cite has to exist from this moment — the write is
     * conflict-tolerant because approving twice is a double click, not an
     * event.
     *
     * @return array<string,mixed>
     */
    public function approve(TenantContext $ctx, int $clauseId): array
    {
        $existing = $this->libraryClauseOrFail($ctx, $clauseId);

        if ((string) $existing['approval_status'] === 'approved') {
            return $existing;
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $clauseId, $existing): array {
            $pdo->prepare(
                "UPDATE clause_library
                 SET approval_status = 'approved', approver_uuid = ?, approved_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?"
            )->execute([$ctx->uuid, $clauseId, $ctx->environment, $ctx->cmpId]);

            self::writeVersion(
                $pdo,
                $clauseId,
                (int) $existing['version'],
                (string) $existing['standard_text'],
                $existing['fallback_text'],
                null,
                $existing['author_uuid']
            );

            $this->audit->log($ctx, 'clause', $clauseId, 'clause.approved', null, [
                'approval_status' => ['from' => $existing['approval_status'], 'to' => 'approved'],
            ]);

            return $this->libraryClauseOrFail($ctx, $clauseId);
        });
    }

    /**
     * Stop offering this wording without disowning it.
     *
     * Deprecated wording stays readable and keeps its versions: contracts were
     * drafted from it, and the deviation report still has to recognise it.
     *
     * @return array<string,mixed>
     */
    public function deprecate(TenantContext $ctx, int $clauseId): array
    {
        $existing = $this->libraryClauseOrFail($ctx, $clauseId);

        if ((string) $existing['approval_status'] === 'deprecated') {
            return $existing;
        }

        $this->pdo->prepare(
            "UPDATE clause_library
             SET approval_status = 'deprecated', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?"
        )->execute([$clauseId, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'clause', $clauseId, 'clause.deprecated', null, [
            'approval_status' => ['from' => $existing['approval_status'], 'to' => 'deprecated'],
        ]);

        return $this->libraryClauseOrFail($ctx, $clauseId);
    }

    /**
     * The wording this clause has carried, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function versions(TenantContext $ctx, int $clauseId): array
    {
        // `clause_versions` carries no tenant columns; it is reachable only
        // through a clause, and the lookup above has just established that this
        // tenant owns that one.
        $this->libraryClauseOrFail($ctx, $clauseId);

        $st = $this->pdo->prepare(
            'SELECT id, clause_id, version, standard_text, fallback_text,
                    change_note, author_uuid, created_at
             FROM clause_versions
             WHERE clause_id = ?
             ORDER BY version DESC, id DESC'
        );
        $st->execute([$clauseId]);

        return array_map(static function (array $row): array {
            foreach (['id', 'clause_id', 'version'] as $key) {
                $row[$key] = (int) $row[$key];
            }

            return $row;
        }, $st->fetchAll() ?: []);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * One clause row, or null when it does not exist *for this tenant*.
     *
     * @return array<string,mixed>|null
     */
    private function findContractClause(TenantContext $ctx, int $clauseId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT cc.*, cat.name AS category_name, cat.code AS category_code,
                    lib.name AS library_clause_name
             FROM contract_clauses cc
             LEFT JOIN clause_categories cat ON cat.id = cc.category_id
             LEFT JOIN clause_library lib ON lib.id = cc.library_clause_id
             WHERE cc.id = ? AND cc.environment = ? AND cc.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$clauseId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrateContractClause($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    private function contractClauseOrFail(TenantContext $ctx, int $clauseId): array
    {
        $row = $this->findContractClause($ctx, $clauseId);
        if ($row === null) {
            throw DomainException::notFound('Clause not found.');
        }

        return $row;
    }

    /** @return array<string,mixed> @throws DomainException */
    private function libraryClauseOrFail(TenantContext $ctx, int $clauseId): array
    {
        $st = $this->pdo->prepare(
            'SELECT cl.*, cat.name AS category_name, cat.code AS category_code
             FROM clause_library cl
             LEFT JOIN clause_categories cat ON cat.id = cl.category_id
             WHERE cl.id = ? AND cl.environment = ? AND cl.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$clauseId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Clause not found.');
        }

        return self::hydrateLibraryClause($row);
    }

    /** @return array<string,mixed> @throws DomainException */
    private function categoryOrFail(TenantContext $ctx, int $categoryId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, code, name, description, risk_weight, is_system, sort_order, created_at
             FROM clause_categories
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$categoryId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Clause category not found.');
        }

        $row['is_system'] = ContractService::toBool($row['is_system'] ?? false);
        foreach (['id', 'risk_weight', 'sort_order'] as $key) {
            $row[$key] = (int) $row[$key];
        }

        return $row;
    }

    /**
     * A category code.
     *
     * Constrained to an identifier because it is what the seeds, the playbook
     * and the deviation report look a category up by; a code with a space in it
     * would work everywhere except the places that matter.
     */
    private static function readCategoryCode(Validator $v): string
    {
        $code = strtolower($v->requiredString('code', 64));

        if ($code !== '' && preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1) {
            $v->fail('code', 'Use lowercase letters, digits and underscores, starting with a letter.');
        }

        return $code;
    }

    /** A unique-violation on a category code, as the field error a form can show. */
    private static function asDuplicateCode(PDOException $e): \Throwable
    {
        if ($e->getCode() === '23505') {
            return new ValidationFailed(['code' => 'Another category already uses that code.']);
        }

        return $e;
    }

    /**
     * The contract exists for this tenant.
     *
     * By id only, and answered as "not found": a caller walking contract ids
     * must not be able to tell another company's contract from one that was
     * never created.
     */
    private function assertContract(TenantContext $ctx, int $contractId): void
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw DomainException::notFound('Contract not found.');
        }
    }

    /** @throws ValidationFailed */
    private function assertCategory(TenantContext $ctx, ?int $categoryId): void
    {
        if ($categoryId === null) {
            return;
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM clause_categories WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$categoryId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['category_id' => 'Choose a clause category from this company.']);
        }
    }

    /**
     * Write one version row.
     *
     * Never an UPDATE. The row is what a contract was reviewed against, so a
     * collision on (clause_id, version) is left exactly as it stands rather
     * than restated — rewriting it is the one thing this table exists to
     * prevent.
     */
    private static function writeVersion(
        PDO $pdo,
        int $clauseId,
        int $version,
        string $standardText,
        ?string $fallbackText,
        ?string $changeNote,
        ?string $authorUuid
    ): void {
        $pdo->prepare(
            'INSERT INTO clause_versions
             (clause_id, version, standard_text, fallback_text, change_note, author_uuid)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (clause_id, version) DO NOTHING'
        )->execute([$clauseId, $version, $standardText, $fallbackText, $changeNote, $authorUuid]);
    }

    /**
     * The fields of a contract's own clause.
     *
     * On an update only the keys the caller sent come back, which is what lets
     * updateForContract write a partial change without blanking the rest.
     *
     * @return array<string,mixed>
     */
    private function readContractFields(Validator $v, bool $creating): array
    {
        $out = [];

        if ($creating || $v->has('body_text')) {
            $out['body_text'] = $v->requiredString('body_text', 20000);
        }
        if ($creating || $v->has('category_id')) {
            $out['category_id'] = $v->optionalId('category_id');
        }
        if ($creating || $v->has('clause_number')) {
            $out['clause_number'] = $v->optionalString('clause_number', 48);
        }
        if ($creating || $v->has('heading')) {
            $out['heading'] = $v->optionalString('heading', 255);
        }
        if ($creating || $v->has('source_excerpt')) {
            $out['source_excerpt'] = $v->optionalText('source_excerpt', 4000);
        }
        // Bounded by the SMALLINT the column is, so an implausible page number
        // fails as a validation error the user can read rather than as a
        // database range error they cannot.
        if ($creating || $v->has('source_page')) {
            $out['source_page'] = $v->optionalInt('source_page', 1, 32767);
        }
        if ($creating || $v->has('source_offset')) {
            $out['source_offset'] = $v->optionalInt('source_offset', 0, 2000000000);
        }
        if ($creating || $v->has('verification_state')) {
            $out['verification_state'] = $v->optionalEnum(
                'verification_state',
                Enums::VERIFICATION_STATES,
                'human_verified'
            ) ?? 'human_verified';
        }

        return $out;
    }

    /**
     * The fields of a library clause, defaulted from the existing row on an
     * update so a form posting one field cannot blank the rest.
     *
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readLibraryFields(Validator $v, TenantContext $ctx, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $name = $creating || $v->has('name')
            ? $v->requiredString('name', 200)
            : (string) $fallback('name', '');

        $standard = $creating || $v->has('standard_text')
            ? $v->requiredString('standard_text', 20000)
            : (string) $fallback('standard_text', '');

        $from = $v->optionalDate('effective_from', $fallback('effective_from'));
        $to   = $v->optionalDate('effective_to', $fallback('effective_to'));

        // Mirrors ck_clause_library_dates, so the impossible window is refused
        // as a field error rather than as a constraint violation.
        if ($from !== null && $to !== null && $to < $from) {
            $v->fail('effective_to', 'The end date cannot be before the start date.');
        }

        $categoryId = $v->has('category_id')
            ? $v->optionalId('category_id')
            : ($fallback('category_id') === null ? null : (int) $fallback('category_id'));

        $types = $v->has('applicable_types')
            ? $this->readApplicableTypes($v, $ctx)
            : self::decodeIdList($fallback('applicable_types'));

        return [
            'name'                => $name,
            'standard_text'       => $standard,
            'category_id'         => $categoryId,
            'applicable_types'    => $types,
            'description'         => $v->has('description')
                ? $v->optionalText('description', 4000)
                : self::blankToNull($fallback('description')),
            'fallback_text'       => $v->has('fallback_text')
                ? $v->optionalText('fallback_text', 20000)
                : self::blankToNull($fallback('fallback_text')),
            'prohibited_wording'  => $v->has('prohibited_wording')
                ? $v->optionalText('prohibited_wording', 20000)
                : self::blankToNull($fallback('prohibited_wording')),
            'risk_classification' => $v->has('risk_classification')
                ? $v->requiredEnum('risk_classification', Enums::RISK_LEVELS)
                : (string) $fallback('risk_classification', 'medium'),
            'jurisdiction'        => $v->has('jurisdiction')
                ? $v->optionalString('jurisdiction', 120)
                : self::blankToNull($fallback('jurisdiction')),
            'approval_status'     => $v->has('approval_status')
                ? $v->requiredEnum('approval_status', Enums::CLAUSE_APPROVAL_STATUSES)
                : (string) $fallback('approval_status', 'draft'),
            'effective_from'      => $from,
            'effective_to'        => $to,
            // Not a column on the clause: the note describes one save, and it
            // is filed against the version that save creates.
            'change_note'         => $v->optionalText('change_note', 4000),
        ];
    }

    /**
     * The contract types this wording applies to, as ids this company owns.
     *
     * Checked rather than stored as given: an id from another company, or one
     * of a type since deleted, would silently narrow the library to nothing on
     * the drafting screen and read as an empty shelf rather than as a mistake.
     *
     * @return list<int>
     */
    private function readApplicableTypes(Validator $v, TenantContext $ctx): array
    {
        $ids = [];
        foreach ($v->optionalArray('applicable_types', 100) as $value) {
            if (is_int($value) || (is_string($value) && preg_match('/^\d{1,19}$/', trim($value)) === 1)) {
                $id = (int) $value;
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }

        $ids = array_keys($ids);
        if ($ids === []) {
            return [];
        }

        $names  = [];
        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];
        foreach (array_values($ids) as $i => $id) {
            $names[]              = ':type' . $i;
            $params['type' . $i]  = $id;
        }

        $st = $this->pdo->prepare(
            'SELECT id FROM contract_types
             WHERE environment = :env AND cmp_id = :cmp AND id IN (' . implode(', ', $names) . ')'
        );
        $st->execute($params);

        $found = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (count($found) !== count($ids)) {
            $v->fail('applicable_types', 'Choose contract types from this company.');

            return [];
        }

        sort($found);

        return $found;
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0: string, 1: array<string,mixed>}
     */
    private static function buildLibraryWhere(TenantContext $ctx, array $f): array
    {
        $clauses = ['cl.environment = :env', 'cl.cmp_id = :cmp'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        if (($f['archived'] ?? null) === 'only') {
            $clauses[] = 'cl.archived_at IS NOT NULL';
        } elseif (($f['archived'] ?? null) !== 'all') {
            $clauses[] = 'cl.archived_at IS NULL';
        }

        if (! empty($f['category_id'])) {
            $clauses[]            = 'cl.category_id = :category';
            $params['category']   = (int) $f['category_id'];
        }
        if (! empty($f['approval_status'])) {
            $clauses[]        = 'cl.approval_status = :status';
            $params['status'] = (string) $f['approval_status'];
        }
        if (! empty($f['risk'])) {
            $clauses[]      = 'cl.risk_classification = :risk';
            $params['risk'] = (string) $f['risk'];
        }

        if (! empty($f['contract_type_id'])) {
            // Containment against a one-element array rather than a cast of the
            // bound value: the parameter goes to PostgreSQL as text either way,
            // and this needs no inference about what type it should have been.
            $clauses[]           = '(jsonb_array_length(cl.applicable_types) = 0
                                     OR cl.applicable_types @> :type_json::jsonb)';
            $params['type_json'] = json_encode([(int) $f['contract_type_id']]);
        }

        // "Current" means the window the company set has opened and has not
        // closed. Wording that expired last month is history, not something to
        // offer a drafter today.
        if (! empty($f['in_date'])) {
            $clauses[] = '(cl.effective_from IS NULL OR cl.effective_from <= CURRENT_DATE)';
            $clauses[] = '(cl.effective_to IS NULL OR cl.effective_to >= CURRENT_DATE)';
        }

        if (is_string($f['q'] ?? null) && trim($f['q']) !== '') {
            // Three placeholders for one term: PDO runs native prepares here,
            // and a named parameter used twice in a statement is not a name it
            // can bind.
            $like = '%' . trim($f['q']) . '%';

            $clauses[]          = '(cl.name ILIKE :q_name OR cl.description ILIKE :q_descr OR cl.standard_text ILIKE :q_text)';
            $params['q_name']   = $like;
            $params['q_descr']  = $like;
            $params['q_text']   = $like;
        }

        return ['WHERE ' . implode("\n  AND ", $clauses), $params];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateContractClause(array $row): array
    {
        $row['is_ai_extracted'] = ContractService::toBool($row['is_ai_extracted'] ?? false);

        foreach (['id', 'cmp_id', 'contract_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['category_id', 'library_clause_id', 'version_id', 'source_page', 'source_offset'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateLibraryClause(array $row): array
    {
        $row['applicable_types'] = self::decodeIdList($row['applicable_types'] ?? null);
        $row['is_system']        = ContractService::toBool($row['is_system'] ?? false);

        foreach (['id', 'cmp_id', 'version'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (array_key_exists('category_id', $row)) {
            $row['category_id'] = $row['category_id'] === null ? null : (int) $row['category_id'];
        }

        return $row;
    }

    /**
     * A jsonb list of contract type ids, as ids.
     *
     * @return list<int>
     */
    private static function decodeIdList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_int($value) || (is_string($value) && preg_match('/^\d{1,19}$/', trim($value)) === 1)) {
                $id = (int) $value;
                if ($id > 0) {
                    $out[$id] = true;
                }
            }
        }

        return array_keys($out);
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /** What to call a clause in a sentence a person will read on the timeline. */
    private static function clauseLabel(?string $heading, ?string $number): string
    {
        $heading = $heading === null ? '' : trim($heading);
        if ($heading !== '') {
            return $heading;
        }

        $number = $number === null ? '' : trim($number);

        return $number === '' ? 'Untitled clause' : 'Clause ' . $number;
    }
}
