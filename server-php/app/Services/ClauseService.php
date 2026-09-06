<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use App\Support\Validator;
use App\Support\ValidationFailed;
use PDO;
use PDOException;

/**
 * The clause library, and the clauses standing in one contract.
 *
 * Two tables that look alike and mean opposite things. `clause_library` is what
 * the company wants its contracts to say; `contract_clauses` is what one
 * contract actually says. The gap between them is the deviation report, and it
 * only exists because a clause copied out of the library keeps
 * `library_clause_id` — paste the same words in as free text and the report can
 * no longer tell "our standard clause, unchanged" from "wording nobody has
 * reviewed".
 *
 * Library wording is versioned rather than overwritten. A contract signed last
 * year was reviewed against the text as it stood last year, and a library that
 * only holds today's answer cannot support that conversation.
 */
final class ClauseService
{
    /** Columns a caller may sort by. Anything else is ignored rather than interpolated. */
    private const SORTABLE = [
        'name'       => 'l.name',
        'updated_at' => 'l.updated_at',
        'created_at' => 'l.created_at',
        'risk'       => 'l.risk_classification',
        'status'     => 'l.approval_status',
    ];

    /** Library fields whose change is worth an audit row. */
    private const AUDITED_FIELDS = [
        'name', 'description', 'category_id', 'standard_text', 'fallback_text',
        'prohibited_wording', 'risk_classification', 'jurisdiction', 'version',
        'approval_status', 'effective_from', 'effective_to',
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
    // Categories
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function categories(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT c.id, c.code, c.name, c.description, c.risk_weight, c.is_system, c.sort_order,
                    (SELECT COUNT(*) FROM clause_library l
                      WHERE l.category_id = c.id AND l.archived_at IS NULL) AS clause_count
             FROM clause_categories c
             WHERE c.environment = ? AND c.cmp_id = ?
             ORDER BY c.sort_order, c.name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        return array_map(
            static function (array $row): array {
                $row['id']           = (int) $row['id'];
                $row['risk_weight']  = (int) $row['risk_weight'];
                $row['sort_order']   = (int) $row['sort_order'];
                $row['clause_count'] = (int) $row['clause_count'];
                $row['is_system']    = ContractService::toBool($row['is_system']);

                return $row;
            },
            $st->fetchAll() ?: []
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createCategory(TenantContext $ctx, array $body): array
    {
        $v      = new Validator($body);
        $code   = $this->readCategoryCode($v);
        $name   = $v->requiredString('name', 160);
        $fields = [
            'description' => $v->optionalString('description', 2000),
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
            // Reported against the field the user typed rather than as a 500:
            // a duplicate code is a form problem, not a server fault.
            throw self::duplicateCode($e, 'That code is already used by another category.');
        }

        $id = (int) $st->fetchColumn();
        $this->audit->log($ctx, 'clause_category', $id, 'clause_category.created', null, [
            'code' => ['from' => null, 'to' => $code],
        ]);

        return $this->categoryOrFail($ctx, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateCategory(TenantContext $ctx, int $id, array $body): array
    {
        $existing = $this->categoryOrFail($ctx, $id);

        $v    = new Validator($body);
        $name = $v->has('name') ? $v->requiredString('name', 160) : (string) $existing['name'];
        $code = $v->has('code') && ! $existing['is_system'] ? $this->readCategoryCode($v) : (string) $existing['code'];
        $data = [
            'description' => $v->optionalString('description', 2000, $existing['description']),
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
                $code, $name, $data['description'], $data['risk_weight'], $data['sort_order'],
                $id, $ctx->environment, $ctx->cmpId,
            ]);
        } catch (PDOException $e) {
            throw self::duplicateCode($e, 'That code is already used by another category.');
        }

        $updated = $this->categoryOrFail($ctx, $id);
        $this->audit->logChanges($ctx, 'clause_category', $id, $existing, $updated, ['code', 'name', 'description', 'risk_weight', 'sort_order']);

        return $updated;
    }

    /**
     * Delete a category nothing is filed under.
     *
     * The foreign keys would null the reference instead of refusing, which
     * would quietly unfile every clause in the category and leave the deviation
     * report measuring against nothing.
     */
    public function deleteCategory(TenantContext $ctx, int $id): void
    {
        $existing = $this->categoryOrFail($ctx, $id);

        if ($existing['is_system'] === true) {
            throw DomainException::conflict('A built-in clause category cannot be deleted.', 'CATEGORY_IS_SYSTEM');
        }

        foreach (['clause_library' => 'clauses', 'contract_clauses' => 'contract clauses', 'playbook_rules' => 'playbook rules'] as $table => $label) {
            $st = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE category_id = ? LIMIT 1");
            $st->execute([$id]);
            if ($st->fetchColumn() !== false) {
                throw DomainException::conflict(
                    sprintf('This category still has %s filed under it.', $label),
                    'CATEGORY_IN_USE'
                );
            }
        }

        $this->audit->log($ctx, 'clause_category', $id, 'clause_category.deleted', null, [
            'code' => ['from' => $existing['code'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM clause_categories WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$id, $ctx->environment, $ctx->cmpId]);
    }

    // -----------------------------------------------------------------------
    // The library
    // -----------------------------------------------------------------------

    /**
     * One library clause.
     *
     * Throws rather than returning null, for the same reason TemplateService
     * does: a miss and another company's clause must be the same answer.
     *
     * @return array<string,mixed>
     */
    public function find(TenantContext $ctx, int $id): array
    {
        $row = $this->lookup($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Clause not found.');
        }

        return $row;
    }

    /**
     * A page of the library.
     *
     * @param array<string,mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function search(TenantContext $ctx, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($ctx, $filters);

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM clause_library l {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'name';
        $column  = self::SORTABLE[$sortKey] ?? self::SORTABLE['name'];
        $dir     = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $st = $this->pdo->prepare(
            "SELECT l.id, l.uuid, l.category_id, l.name, l.description, l.standard_text,
                    l.fallback_text, l.risk_classification, l.applicable_types, l.jurisdiction,
                    l.version, l.approval_status, l.effective_from, l.effective_to,
                    l.is_system, l.archived_at, l.created_at, l.updated_at,
                    c.code AS category_code, c.name AS category_name
             FROM clause_library l
             LEFT JOIN clause_categories c ON c.id = l.category_id
             {$where}
             ORDER BY {$column} {$dir} NULLS LAST, l.id {$dir}
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        return [
            'items' => array_map(self::hydrateLibrary(...), $st->fetchAll() ?: []),
            'total' => $total,
        ];
    }

    /**
     * The wording a drafter may reach for on this kind of contract.
     *
     * Approved only, in date, and either applicable to every type or to this
     * one. An empty `applicable_types` means "any", which matches how
     * contract_risk_rules reads the same shape — one convention across the
     * product rather than two that look alike.
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
     * Superseded wording, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function versions(TenantContext $ctx, int $clauseId): array
    {
        $this->find($ctx, $clauseId);

        $st = $this->pdo->prepare(
            'SELECT id, version, standard_text, fallback_text, change_note, author_uuid, created_at
             FROM clause_versions WHERE clause_id = ? ORDER BY version DESC'
        );
        $st->execute([$clauseId]);

        return array_map(
            static function (array $row): array {
                $row['id']      = (int) $row['id'];
                $row['version'] = (int) $row['version'];

                return $row;
            },
            $st->fetchAll() ?: []
        );
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(TenantContext $ctx, array $body): array
    {
        $v      = new Validator($body);
        $fields = $this->readLibraryFields($ctx, $v, true);
        $v->assert();

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $fields, $body): array {
            $st = $pdo->prepare(
                'INSERT INTO clause_library
                 (environment, cmp_id, category_id, name, description, standard_text, fallback_text,
                  prohibited_wording, risk_classification, applicable_types, jurisdiction,
                  version, approval_status, effective_from, effective_to, author_uuid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, 1, ?, ?, ?, ?)
                 RETURNING id'
            );
            $st->execute([
                $ctx->environment, $ctx->cmpId, $fields['category_id'], $fields['name'],
                $fields['description'], $fields['standard_text'], $fields['fallback_text'],
                $fields['prohibited_wording'], $fields['risk_classification'],
                self::json($fields['applicable_types']), $fields['jurisdiction'],
                $fields['approval_status'], $fields['effective_from'], $fields['effective_to'],
                $ctx->uuid,
            ]);

            $id = (int) $st->fetchColumn();

            // Version 1 exists from the first save, so the history never starts
            // halfway through a clause's life.
            $this->writeVersion($pdo, $ctx, $id, 1, $fields['standard_text'], $fields['fallback_text'], self::changeNote($body));

            $this->audit->log($ctx, 'clause', $id, 'clause.created', null, [
                'name' => ['from' => null, 'to' => $fields['name']],
            ]);

            return $this->find($ctx, $id);
        });
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $id, array $body): array
    {
        $existing = $this->find($ctx, $id);

        $v      = new Validator($body);
        $fields = $this->readLibraryFields($ctx, $v, false, $existing);
        $v->assert();

        $wordingChanged = $fields['standard_text'] !== (string) $existing['standard_text']
            || $fields['fallback_text'] !== $existing['fallback_text'];
        $version = (int) $existing['version'] + ($wordingChanged ? 1 : 0);

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $id,
            $existing,
            $fields,
            $body,
            $wordingChanged,
            $version
        ): array {
            $pdo->prepare(
                'UPDATE clause_library SET
                    category_id = ?, name = ?, description = ?, standard_text = ?,
                    fallback_text = ?, prohibited_wording = ?, risk_classification = ?,
                    applicable_types = ?::jsonb, jurisdiction = ?, version = ?,
                    approval_status = ?, effective_from = ?, effective_to = ?,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([
                $fields['category_id'], $fields['name'], $fields['description'],
                $fields['standard_text'], $fields['fallback_text'], $fields['prohibited_wording'],
                $fields['risk_classification'], self::json($fields['applicable_types']),
                $fields['jurisdiction'], $version, $fields['approval_status'],
                $fields['effective_from'], $fields['effective_to'],
                $id, $ctx->environment, $ctx->cmpId,
            ]);

            if ($wordingChanged) {
                $this->writeVersion($pdo, $ctx, $id, $version, $fields['standard_text'], $fields['fallback_text'], self::changeNote($body));
            }

            $updated = $this->find($ctx, $id);
            $this->audit->logChanges($ctx, 'clause', $id, $existing, $updated, self::AUDITED_FIELDS);

            return $updated;
        });
    }

    /**
     * Sign off the wording.
     *
     * Approval is what makes a clause offerable to a drafter; until then it is
     * a proposal Legal is still writing.
     *
     * @return array<string,mixed>
     */
    public function approve(TenantContext $ctx, int $id): array
    {
        $existing = $this->find($ctx, $id);

        if ((string) $existing['approval_status'] === 'approved') {
            return $existing;
        }

        $this->pdo->prepare(
            "UPDATE clause_library
             SET approval_status = 'approved', approver_uuid = ?, approved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?"
        )->execute([$ctx->uuid, $id, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'clause', $id, 'clause.approved', null, [
            'approval_status' => ['from' => $existing['approval_status'], 'to' => 'approved'],
        ]);

        return $this->find($ctx, $id);
    }

    /** @return array<string,mixed> */
    public function deprecate(TenantContext $ctx, int $id): array
    {
        $existing = $this->find($ctx, $id);

        $this->pdo->prepare(
            "UPDATE clause_library
             SET approval_status = 'deprecated', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?"
        )->execute([$id, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'clause', $id, 'clause.deprecated', null, [
            'approval_status' => ['from' => $existing['approval_status'], 'to' => 'deprecated'],
        ]);

        return $this->find($ctx, $id);
    }

    /**
     * Delete a clause nobody has used.
     *
     * A clause a contract was drafted from is provenance: the copy in the
     * contract keeps its own wording, but the link explaining where it came
     * from would be lost. Those are deprecated instead.
     */
    public function delete(TenantContext $ctx, int $id): void
    {
        $existing = $this->find($ctx, $id);

        if ($existing['is_system'] === true) {
            throw DomainException::conflict(
                'A built-in clause cannot be deleted. Deprecate it instead.',
                'CLAUSE_IS_SYSTEM'
            );
        }

        $st = $this->pdo->prepare('SELECT 1 FROM contract_clauses WHERE library_clause_id = ? LIMIT 1');
        $st->execute([$id]);
        if ($st->fetchColumn() !== false) {
            throw DomainException::conflict(
                'This clause is used by at least one contract. Deprecate it instead of deleting it.',
                'CLAUSE_IN_USE'
            );
        }

        $this->audit->log($ctx, 'clause', $id, 'clause.deleted', null, [
            'name' => ['from' => $existing['name'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM clause_library WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$id, $ctx->environment, $ctx->cmpId]);
    }

    // -----------------------------------------------------------------------
    // Clauses standing in one contract
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $this->contractOrFail($ctx, $contractId);

        $st = $this->pdo->prepare(
            'SELECT cc.id, cc.contract_id, cc.version_id, cc.category_id, cc.library_clause_id,
                    cc.clause_number, cc.heading, cc.body_text, cc.source_page, cc.source_offset,
                    cc.source_excerpt, cc.is_ai_extracted, cc.ai_confidence, cc.verification_state,
                    cc.verified_by, cc.verified_at, cc.created_at, cc.updated_at,
                    cat.code AS category_code, cat.name AS category_name,
                    lib.name AS library_clause_name, lib.version AS library_clause_version
             FROM contract_clauses cc
             LEFT JOIN clause_categories cat ON cat.id = cc.category_id
             LEFT JOIN clause_library lib ON lib.id = cc.library_clause_id
             WHERE cc.contract_id = ? AND cc.environment = ? AND cc.cmp_id = ?
             ORDER BY cc.clause_number NULLS LAST, cc.id'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_map(self::hydrateContractClause(...), $st->fetchAll() ?: []);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createForContract(TenantContext $ctx, int $contractId, array $body): array
    {
        $this->assertContractOpen($ctx, $contractId);

        $v      = new Validator($body);
        $fields = $this->readContractClauseFields($ctx, $v, true);
        $v->assert();

        $st = $this->pdo->prepare(
            'INSERT INTO contract_clauses
             (environment, cmp_id, contract_id, category_id, clause_number, heading, body_text,
              source_page, source_offset, source_excerpt, is_ai_extracted, verification_state,
              verified_by, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE, ?, ?, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        $st->execute([
            $ctx->environment, $ctx->cmpId, $contractId, $fields['category_id'],
            $fields['clause_number'], $fields['heading'], $fields['body_text'],
            $fields['source_page'], $fields['source_offset'], $fields['source_excerpt'],
            $fields['verification_state'], $ctx->uuid,
        ]);

        $id = (int) $st->fetchColumn();

        $this->audit->log($ctx, 'contract_clause', $id, 'contract_clause.created', $contractId, [
            'heading' => ['from' => null, 'to' => $fields['heading']],
        ]);
        $this->activity->record($ctx, $contractId, 'clause.added', sprintf(
            'Clause added: %s',
            $fields['heading'] ?? 'untitled'
        ));

        return $this->contractClauseOrFail($ctx, $id);
    }

    /**
     * Copy the library's wording onto a contract.
     *
     * The copy carries the words, not a reference to them: the library moves on
     * and this contract must keep saying what it said the day it was drafted.
     * `library_clause_id` records where it came from, which is what lets the
     * deviation report distinguish standard wording from negotiated wording.
     *
     * @return array<string,mixed>
     */
    public function attachToContract(TenantContext $ctx, int $contractId, int $clauseId): array
    {
        $this->assertContractOpen($ctx, $contractId);
        $clause = $this->find($ctx, $clauseId);

        if ((string) $clause['approval_status'] === 'deprecated') {
            throw DomainException::conflict(
                'That clause has been deprecated. Choose the wording that replaced it.',
                'CLAUSE_DEPRECATED'
            );
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_clauses
             WHERE contract_id = ? AND library_clause_id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $clauseId, $ctx->environment, $ctx->cmpId]);
        if ($st->fetchColumn() !== false) {
            throw DomainException::conflict('That clause is already on this contract.', 'CLAUSE_ALREADY_ATTACHED');
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO contract_clauses
             (environment, cmp_id, contract_id, category_id, library_clause_id, heading, body_text,
              is_ai_extracted, verification_state, verified_by, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, FALSE, ?, ?, CURRENT_TIMESTAMP)
             RETURNING id'
        );
        $insert->execute([
            $ctx->environment, $ctx->cmpId, $contractId, $clause['category_id'], $clauseId,
            mb_substr((string) $clause['name'], 0, 255), (string) $clause['standard_text'],
            'human_verified', $ctx->uuid,
        ]);

        $id = (int) $insert->fetchColumn();

        $this->audit->log($ctx, 'contract_clause', $id, 'contract_clause.attached', $contractId, [
            'library_clause_id' => ['from' => null, 'to' => $clauseId],
            'version'           => ['from' => null, 'to' => $clause['version']],
        ]);
        $this->activity->record(
            $ctx,
            $contractId,
            'clause.added',
            sprintf('Standard clause added from the library: %s (v%d)', $clause['name'], (int) $clause['version']),
            ['library_clause_id' => $clauseId]
        );

        return $this->contractClauseOrFail($ctx, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateForContract(TenantContext $ctx, int $clauseId, array $body): array
    {
        $existing   = $this->contractClauseOrFail($ctx, $clauseId);
        $contractId = (int) $existing['contract_id'];
        $this->assertContractOpen($ctx, $contractId);

        $v      = new Validator($body);
        $fields = $this->readContractClauseFields($ctx, $v, false, $existing);
        $v->assert();

        $this->pdo->prepare(
            'UPDATE contract_clauses SET
                category_id = ?, clause_number = ?, heading = ?, body_text = ?,
                source_page = ?, source_offset = ?, source_excerpt = ?,
                verification_state = ?, verified_by = ?, verified_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $fields['category_id'], $fields['clause_number'], $fields['heading'], $fields['body_text'],
            $fields['source_page'], $fields['source_offset'], $fields['source_excerpt'],
            $fields['verification_state'], $ctx->uuid,
            $clauseId, $ctx->environment, $ctx->cmpId,
        ]);

        $updated = $this->contractClauseOrFail($ctx, $clauseId);
        $this->audit->logChanges(
            $ctx,
            'contract_clause',
            $clauseId,
            $existing,
            $updated,
            ['category_id', 'clause_number', 'heading', 'body_text', 'verification_state'],
            $contractId
        );

        return $updated;
    }

    public function deleteForContract(TenantContext $ctx, int $clauseId): void
    {
        $existing   = $this->contractClauseOrFail($ctx, $clauseId);
        $contractId = (int) $existing['contract_id'];
        $this->assertContractOpen($ctx, $contractId);

        $this->audit->log($ctx, 'contract_clause', $clauseId, 'contract_clause.deleted', $contractId, [
            'heading' => ['from' => $existing['heading'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM contract_clauses WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$clauseId, $ctx->environment, $ctx->cmpId]);

        $this->activity->record($ctx, $contractId, 'clause.removed', sprintf(
            'Clause removed: %s',
            $existing['heading'] ?? 'untitled'
        ));
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $f
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildWhere(TenantContext $ctx, array $f): array
    {
        $clauses = ['l.environment = :env', 'l.cmp_id = :cmp'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        if (($f['archived'] ?? null) === 'only') {
            $clauses[] = 'l.archived_at IS NOT NULL';
        } elseif (($f['archived'] ?? null) !== 'all') {
            $clauses[] = 'l.archived_at IS NULL';
        }

        if (! empty($f['category_id'])) {
            $clauses[]             = 'l.category_id = :category_id';
            $params['category_id'] = (int) $f['category_id'];
        }
        if (Enums::isValid($f['approval_status'] ?? null, Enums::CLAUSE_APPROVAL_STATUSES)) {
            $clauses[]        = 'l.approval_status = :status';
            $params['status'] = (string) $f['approval_status'];
        }
        if (Enums::isValid($f['risk'] ?? null, Enums::RISK_LEVELS)) {
            $clauses[]      = 'l.risk_classification = :risk';
            $params['risk'] = (string) $f['risk'];
        }
        if (! empty($f['jurisdiction'])) {
            $clauses[]              = 'l.jurisdiction ILIKE :jurisdiction';
            $params['jurisdiction'] = '%' . $f['jurisdiction'] . '%';
        }
        if (! empty($f['in_date'])) {
            $clauses[] = '(l.effective_from IS NULL OR l.effective_from <= CURRENT_DATE)';
            $clauses[] = '(l.effective_to IS NULL OR l.effective_to >= CURRENT_DATE)';
        }

        if (! empty($f['contract_type_id'])) {
            $code = $this->typeCode($ctx, (int) $f['contract_type_id']);
            if ($code === null) {
                // A type this company does not own matches nothing rather than
                // silently widening the list to every clause.
                $clauses[] = 'FALSE';
            } else {
                $clauses[] = '(jsonb_array_length(l.applicable_types) = 0
                               OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(l.applicable_types) AS t(code)
                                          WHERE lower(t.code) = lower(:type_code)))';
                $params['type_code'] = $code;
            }
        }

        if (! empty($f['q'])) {
            $clauses[]   = '(l.name ILIKE :q OR l.standard_text ILIKE :q2 OR l.description ILIKE :q3)';
            $params['q']  = '%' . $f['q'] . '%';
            $params['q2'] = '%' . $f['q'] . '%';
            $params['q3'] = '%' . $f['q'] . '%';
        }

        return ['WHERE ' . implode("\n  AND ", $clauses), $params];
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readLibraryFields(TenantContext $ctx, Validator $v, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $name = $creating || $v->has('name')
            ? $v->requiredString('name', 200)
            : (string) $fallback('name', '');

        $standard = $creating || $v->has('standard_text')
            ? $v->requiredString('standard_text', 50000)
            : (string) $fallback('standard_text', '');

        $from = $v->optionalDate('effective_from', $fallback('effective_from'));
        $to   = $v->optionalDate('effective_to', $fallback('effective_to'));
        if ($from !== null && $to !== null && $to < $from) {
            $v->fail('effective_to', 'The end date cannot be before the start date.');
        }

        $categoryId = $v->optionalId('category_id') ?? ($v->has('category_id') ? null : $fallback('category_id'));
        if ($categoryId !== null) {
            $this->assertCategoryBelongsToTenant($ctx, (int) $categoryId, $v);
        }

        return [
            'name'                => $name,
            'description'         => $v->optionalString('description', 5000, $fallback('description')),
            'category_id'         => $categoryId === null ? null : (int) $categoryId,
            'standard_text'       => $standard,
            'fallback_text'       => $v->optionalString('fallback_text', 50000, $fallback('fallback_text')),
            'prohibited_wording'  => $v->optionalString('prohibited_wording', 5000, $fallback('prohibited_wording')),
            'risk_classification' => $v->optionalEnum('risk_classification', Enums::RISK_LEVELS, (string) $fallback('risk_classification', 'medium')) ?? 'medium',
            'applicable_types'    => $v->has('applicable_types')
                ? $this->readApplicableTypes($ctx, $v)
                : self::decodeList($fallback('applicable_types')),
            'jurisdiction'        => $v->optionalString('jurisdiction', 120, $fallback('jurisdiction')),
            'approval_status'     => $v->optionalEnum('approval_status', Enums::CLAUSE_APPROVAL_STATUSES, (string) $fallback('approval_status', 'draft')) ?? 'draft',
            'effective_from'      => $from,
            'effective_to'        => $to,
        ];
    }

    /**
     * Contract type codes this clause is offered for.
     *
     * Codes rather than ids so the list survives a company re-seeding its
     * types, and validated here so a typo becomes a form error rather than a
     * clause that silently never appears.
     *
     * @return list<string>
     */
    private function readApplicableTypes(TenantContext $ctx, Validator $v): array
    {
        $known = $this->typeCodes($ctx);
        $out   = [];

        foreach ($v->optionalArray('applicable_types', 50) as $raw) {
            if (! is_string($raw) && ! is_numeric($raw)) {
                continue;
            }
            $code = strtolower(trim((string) $raw));
            if ($code === '') {
                continue;
            }
            if (! isset($known[$code])) {
                $v->fail('applicable_types', sprintf('"%s" is not one of your contract types.', mb_substr($code, 0, 64)));

                continue;
            }
            $out[$code] = true;
        }

        return array_keys($out);
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readContractClauseFields(TenantContext $ctx, Validator $v, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $bodyText = $creating || $v->has('body_text')
            ? $v->requiredString('body_text', 50000)
            : (string) $fallback('body_text', '');

        $categoryId = $v->optionalId('category_id') ?? ($v->has('category_id') ? null : $fallback('category_id'));
        if ($categoryId !== null) {
            $this->assertCategoryBelongsToTenant($ctx, (int) $categoryId, $v);
        }

        return [
            'category_id'   => $categoryId === null ? null : (int) $categoryId,
            'clause_number' => $v->optionalString('clause_number', 48, $fallback('clause_number')),
            'heading'       => $v->optionalString('heading', 255, $fallback('heading')),
            'body_text'     => $bodyText,
            'source_page'   => $v->optionalInt('source_page', 1, 10000, $fallback('source_page') === null ? null : (int) $fallback('source_page')),
            'source_offset' => $v->optionalInt('source_offset', 0, 100000000, $fallback('source_offset') === null ? null : (int) $fallback('source_offset')),
            'source_excerpt' => $v->optionalString('source_excerpt', 4000, $fallback('source_excerpt')),
            // A clause a person typed or edited is verified by definition: they
            // are the source, so recording it as AI-extracted would misstate
            // where the wording came from.
            'verification_state' => $v->optionalEnum('verification_state', Enums::VERIFICATION_STATES, $creating ? 'human_verified' : 'human_edited') ?? 'human_verified',
        ];
    }

    /** @return array<string,mixed>|null */
    private function lookup(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT l.*, c.code AS category_code, c.name AS category_name
             FROM clause_library l
             LEFT JOIN clause_categories c ON c.id = l.category_id
             WHERE l.id = ? AND l.environment = ? AND l.cmp_id = ? LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrateLibrary($row) : null;
    }

    /** @return array<string,mixed> */
    private function categoryOrFail(TenantContext $ctx, int $id): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM clause_categories WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Clause category not found.');
        }

        $row['id']          = (int) $row['id'];
        $row['risk_weight'] = (int) $row['risk_weight'];
        $row['sort_order']  = (int) $row['sort_order'];
        $row['is_system']   = ContractService::toBool($row['is_system']);

        return $row;
    }

    /** @return array<string,mixed> */
    private function contractClauseOrFail(TenantContext $ctx, int $clauseId): array
    {
        $st = $this->pdo->prepare(
            'SELECT cc.*, cat.code AS category_code, cat.name AS category_name,
                    lib.name AS library_clause_name, lib.version AS library_clause_version
             FROM contract_clauses cc
             LEFT JOIN clause_categories cat ON cat.id = cc.category_id
             LEFT JOIN clause_library lib ON lib.id = cc.library_clause_id
             WHERE cc.id = ? AND cc.environment = ? AND cc.cmp_id = ? LIMIT 1'
        );
        $st->execute([$clauseId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Clause not found.');
        }

        return self::hydrateContractClause($row);
    }

    /**
     * The contract, seen through the caller's own visibility.
     *
     * Delegated to ContractService rather than queried here so a user who
     * cannot open a contract cannot reach its clauses either — two copies of
     * that predicate would eventually disagree.
     *
     * @return array<string,mixed>
     */
    private function contractOrFail(TenantContext $ctx, int $contractId): array
    {
        return (new ContractService($this->pdo))->findOrFail($ctx, $contractId);
    }

    /**
     * Refuse clause edits on a contract that has ended.
     *
     * The clauses of a terminated or expired agreement are the record of what
     * was agreed; changing them in place would rewrite history rather than
     * amend it.
     */
    private function assertContractOpen(TenantContext $ctx, int $contractId): void
    {
        $contract = $this->contractOrFail($ctx, $contractId);

        if (in_array((string) $contract['status'], ['terminated', 'expired'], true)) {
            throw DomainException::conflict(
                'This contract has ended. Record an amendment instead of editing its clauses.',
                'CONTRACT_CLOSED'
            );
        }
        if ($contract['archived_at'] !== null) {
            throw DomainException::conflict(
                'Restore this contract from the archive before editing its clauses.',
                'CONTRACT_ARCHIVED'
            );
        }
    }

    private function assertCategoryBelongsToTenant(TenantContext $ctx, int $categoryId, Validator $v): void
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM clause_categories WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$categoryId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            $v->fail('category_id', 'Choose a clause category from your own list.');
        }
    }

    private function typeCode(TenantContext $ctx, int $typeId): ?string
    {
        $st = $this->pdo->prepare(
            'SELECT code FROM contract_types WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$typeId, $ctx->environment, $ctx->cmpId]);
        $code = $st->fetchColumn();

        return is_string($code) ? $code : null;
    }

    /** @return array<string,true> */
    private function typeCodes(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare('SELECT code FROM contract_types WHERE environment = ? AND cmp_id = ?');
        $st->execute([$ctx->environment, $ctx->cmpId]);

        $codes = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $codes[strtolower((string) $row['code'])] = true;
        }

        return $codes;
    }

    private function readCategoryCode(Validator $v): string
    {
        $code = strtolower($v->requiredString('code', 64));
        if ($code !== '' && ! preg_match('/^[a-z][a-z0-9_]*$/', $code)) {
            $v->fail('code', 'Use lowercase letters, digits and underscores, starting with a letter.');
        }

        return $code;
    }

    private function writeVersion(
        PDO $pdo,
        TenantContext $ctx,
        int $clauseId,
        int $version,
        string $standardText,
        ?string $fallbackText,
        ?string $changeNote
    ): void {
        $pdo->prepare(
            'INSERT INTO clause_versions (clause_id, version, standard_text, fallback_text, change_note, author_uuid)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$clauseId, $version, $standardText, $fallbackText, $changeNote, $ctx->uuid]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateLibrary(array $row): array
    {
        if (array_key_exists('applicable_types', $row)) {
            $row['applicable_types'] = self::decodeList($row['applicable_types']);
        }
        foreach (['id', 'cmp_id', 'category_id', 'version'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (array_key_exists('is_system', $row)) {
            $row['is_system'] = ContractService::toBool($row['is_system']);
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateContractClause(array $row): array
    {
        foreach (['id', 'cmp_id', 'contract_id', 'version_id', 'category_id', 'library_clause_id', 'source_page', 'source_offset', 'library_clause_version'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (array_key_exists('is_ai_extracted', $row)) {
            $row['is_ai_extracted'] = ContractService::toBool($row['is_ai_extracted']);
        }

        return $row;
    }

    /** @param array<string,mixed> $body */
    private static function changeNote(array $body): ?string
    {
        $note = $body['change_note'] ?? null;

        return is_string($note) && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null;
    }

    private static function json(mixed $value): string
    {
        return json_encode($value === null ? [] : array_values((array) $value), JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /** @return list<string> */
    private static function decodeList(mixed $raw): array
    {
        if (is_array($raw)) {
            $values = $raw;
        } elseif (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $values  = is_array($decoded) ? $decoded : [];
        } else {
            $values = [];
        }

        return array_values(array_map(static fn (mixed $v): string => (string) $v, array_filter($values, static fn (mixed $v): bool => is_scalar($v))));
    }

    /** A unique-violation on a code, turned into a field error the form can show. */
    private static function duplicateCode(PDOException $e, string $message): \Throwable
    {
        if (($e->getCode() === '23505') || str_contains($e->getMessage(), 'uq_clause_categories_code')) {
            return new ValidationFailed(['code' => $message]);
        }

        return $e;
    }
}
