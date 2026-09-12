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

/**
 * Contract templates: the wording a company starts from, and the history of
 * every wording it has started from before.
 *
 * Two rules hold this class together.
 *
 * A body is never overwritten in place. Every save that changes it writes a
 * `contract_template_versions` row, because a contract drafted last March was
 * drafted from the template as it stood last March, and a dispute about that
 * contract is a question about that text. Losing it would make the answer
 * unavailable rather than merely inconvenient.
 *
 * A body may only reference merge variables in the company's registry, checked
 * when the template is saved and again when it is rendered. Checking at save
 * time is what turns a typo into an error message in the editor instead of a
 * blank in a document somebody has already sent.
 */
final class TemplateService
{
    /** Columns a caller may sort by. Anything else is ignored rather than interpolated. */
    private const SORTABLE = [
        'updated_at' => 't.updated_at',
        'created_at' => 't.created_at',
        'name'       => 't.name',
        'status'     => 't.status',
        'version'    => 't.version',
    ];

    /** Fields whose change is worth an audit row. */
    private const AUDITED_FIELDS = [
        'name', 'description', 'contract_type_id', 'body', 'header_html', 'footer_html',
        'status', 'approval_status', 'owner_uuid', 'version',
    ];

    private AuditService $audit;

    private ActivityService $activity;

    private TemplateRenderer $renderer;

    public function __construct(private PDO $pdo)
    {
        $this->audit    = new AuditService($pdo);
        $this->activity = new ActivityService($pdo);
        $this->renderer = new TemplateRenderer();
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
     * One template.
     *
     * Throws rather than returning null: the controller answers with whatever
     * comes back, and a null there would be a 200 for a template the caller
     * cannot see. A miss and another company's template are the same answer,
     * so neither can be used to probe for the other.
     *
     * @return array<string,mixed>
     */
    public function find(TenantContext $ctx, int $id): array
    {
        $row = $this->lookup($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Template not found.');
        }

        return $row;
    }

    /**
     * A page of templates.
     *
     * @param array<string,mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function search(TenantContext $ctx, array $filters, int $limit, int $offset): array
    {
        $clauses = ['t.environment = :env', 't.cmp_id = :cmp'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        if (($filters['archived'] ?? null) === 'only') {
            $clauses[] = 't.archived_at IS NOT NULL';
        } elseif (($filters['archived'] ?? null) !== 'all') {
            $clauses[] = 't.archived_at IS NULL';
        }

        if (Enums::isValid($filters['status'] ?? null, Enums::TEMPLATE_STATUSES)) {
            $clauses[]        = 't.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (! empty($filters['contract_type_id'])) {
            $clauses[]         = 't.contract_type_id = :type_id';
            $params['type_id'] = (int) $filters['contract_type_id'];
        }
        if (! empty($filters['q'])) {
            $clauses[]      = '(t.name ILIKE :q OR t.description ILIKE :q2)';
            $params['q']    = '%' . $filters['q'] . '%';
            $params['q2']   = '%' . $filters['q'] . '%';
        }

        $where = 'WHERE ' . implode("\n  AND ", $clauses);

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM contract_templates t {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'updated_at';
        $column  = self::SORTABLE[$sortKey] ?? self::SORTABLE['updated_at'];
        $dir     = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $st = $this->pdo->prepare(
            "SELECT t.id, t.uuid, t.name, t.description, t.contract_type_id, t.status,
                    t.version, t.approval_status, t.owner_uuid, t.variables, t.tags,
                    t.archived_at, t.created_at, t.updated_at,
                    ct.name AS contract_type_name, ct.code AS contract_type_code,
                    (SELECT COUNT(*) FROM contracts c WHERE c.template_id = t.id) AS contract_count
             FROM contract_templates t
             LEFT JOIN contract_types ct ON ct.id = t.contract_type_id
             {$where}
             ORDER BY {$column} {$dir} NULLS LAST, t.id {$dir}
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        return [
            'items' => array_map(self::hydrate(...), $st->fetchAll() ?: []),
            'total' => $total,
        ];
    }

    /**
     * Every body this template has ever had, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function versions(TenantContext $ctx, int $templateId): array
    {
        $this->find($ctx, $templateId);

        $st = $this->pdo->prepare(
            'SELECT id, version, body, variables, change_note, author_uuid, created_at
             FROM contract_template_versions
             WHERE template_id = ?
             ORDER BY version DESC'
        );
        $st->execute([$templateId]);

        return array_map(
            static function (array $row): array {
                $row['id']        = (int) $row['id'];
                $row['version']   = (int) $row['version'];
                $row['variables'] = self::decodeJson($row['variables']);

                return $row;
            },
            $st->fetchAll() ?: []
        );
    }

    /**
     * The merge variables this company's templates may use.
     *
     * @return list<array<string,mixed>>
     */
    public function variables(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, var_key, label, source, source_path, data_type, example, is_system
             FROM template_variables
             WHERE environment = ? AND cmp_id = ?
             ORDER BY source, var_key'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        return array_map(
            static function (array $row): array {
                $row['id']        = (int) $row['id'];
                $row['is_system'] = ContractService::toBool($row['is_system']);

                return $row;
            },
            $st->fetchAll() ?: []
        );
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(TenantContext $ctx, array $body): array
    {
        $v      = new Validator($body);
        $fields = $this->readFields($ctx, $v, true);
        $v->assert();

        $this->assertTypeBelongsToTenant($ctx, $fields['contract_type_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $fields, $body): array {
            $st = $pdo->prepare(
                'INSERT INTO contract_templates
                 (environment, cmp_id, contract_type_id, name, description, body,
                  header_html, footer_html, status, version, owner_uuid, variables,
                  optional_clauses, signature_blocks, schedules, tags)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb)
                 RETURNING id'
            );
            $st->execute([
                $ctx->environment,
                $ctx->cmpId,
                $fields['contract_type_id'],
                $fields['name'],
                $fields['description'],
                $fields['body'],
                $fields['header_html'],
                $fields['footer_html'],
                $fields['status'],
                $fields['owner_uuid'],
                self::json($fields['variables']),
                self::json($fields['optional_clauses']),
                self::json($fields['signature_blocks']),
                self::json($fields['schedules']),
                self::json($fields['tags']),
            ]);

            $id = (int) $st->fetchColumn();

            // Version 1 is written even for an empty body, so the history is
            // complete from the first save rather than starting at the first
            // edit.
            $this->writeVersion($pdo, $ctx, $id, 1, $fields['body'], $fields['variables'], self::changeNote($body));

            $this->audit->log($ctx, 'contract_template', $id, 'template.created', null, [
                'name'   => ['from' => null, 'to' => $fields['name']],
                'status' => ['from' => null, 'to' => $fields['status']],
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
        $fields = $this->readFields($ctx, $v, false, $existing);
        $v->assert();

        $this->assertTypeBelongsToTenant($ctx, $fields['contract_type_id']);

        $bodyChanged = $fields['body'] !== (string) $existing['body'];
        $version     = (int) $existing['version'] + ($bodyChanged ? 1 : 0);

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $id,
            $existing,
            $fields,
            $body,
            $bodyChanged,
            $version
        ): array {
            $pdo->prepare(
                'UPDATE contract_templates SET
                    contract_type_id = ?, name = ?, description = ?, body = ?,
                    header_html = ?, footer_html = ?, status = ?, owner_uuid = ?,
                    version = ?, variables = ?::jsonb, optional_clauses = ?::jsonb,
                    signature_blocks = ?::jsonb, schedules = ?::jsonb, tags = ?::jsonb,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([
                $fields['contract_type_id'],
                $fields['name'],
                $fields['description'],
                $fields['body'],
                $fields['header_html'],
                $fields['footer_html'],
                $fields['status'],
                $fields['owner_uuid'],
                $version,
                self::json($fields['variables']),
                self::json($fields['optional_clauses']),
                self::json($fields['signature_blocks']),
                self::json($fields['schedules']),
                self::json($fields['tags']),
                $id,
                $ctx->environment,
                $ctx->cmpId,
            ]);

            if ($bodyChanged) {
                $this->writeVersion($pdo, $ctx, $id, $version, $fields['body'], $fields['variables'], self::changeNote($body));
            }

            $updated = $this->find($ctx, $id);
            $this->audit->logChanges($ctx, 'contract_template', $id, $existing, $updated, self::AUDITED_FIELDS);

            return $updated;
        });
    }

    /**
     * Publish a template.
     *
     * A template with no body cannot be activated: the first drafter to pick it
     * would get an empty document and would have no way to tell that from a
     * rendering failure.
     *
     * @return array<string,mixed>
     */
    public function activate(TenantContext $ctx, int $id): array
    {
        $existing = $this->find($ctx, $id);

        if (trim((string) $existing['body']) === '') {
            throw DomainException::conflict('Write the template body before activating it.', 'TEMPLATE_EMPTY');
        }
        if ($existing['archived_at'] !== null) {
            throw DomainException::conflict('Restore this template from the archive before activating it.', 'TEMPLATE_ARCHIVED');
        }

        $this->pdo->prepare(
            "UPDATE contract_templates
             SET status = 'active', approval_status = 'approved', approved_by = ?,
                 approved_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?"
        )->execute([$ctx->uuid, $id, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'contract_template', $id, 'template.activated', null, [
            'status' => ['from' => $existing['status'], 'to' => 'active'],
        ]);

        return $this->find($ctx, $id);
    }

    /**
     * Retire a template without deleting it.
     *
     * Contracts already drafted from it keep pointing at it, which is the whole
     * reason deprecating exists as a separate act from deleting.
     *
     * @return array<string,mixed>
     */
    public function deprecate(TenantContext $ctx, int $id): array
    {
        $existing = $this->find($ctx, $id);

        $this->pdo->prepare(
            "UPDATE contract_templates
             SET status = 'deprecated', approval_status = 'deprecated', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?"
        )->execute([$id, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'contract_template', $id, 'template.deprecated', null, [
            'status' => ['from' => $existing['status'], 'to' => 'deprecated'],
        ]);

        return $this->find($ctx, $id);
    }

    /**
     * Delete a template nothing was drafted from.
     *
     * A template a contract points at is part of that contract's provenance —
     * the foreign key would quietly null the link and the answer to "what did
     * we start from" would be lost. Those are deprecated instead.
     */
    public function delete(TenantContext $ctx, int $id): void
    {
        $existing = $this->find($ctx, $id);

        if ((int) $existing['contract_count'] > 0) {
            throw DomainException::conflict(
                'Contracts have been drafted from this template. Deprecate it instead of deleting it.',
                'TEMPLATE_IN_USE'
            );
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_types
             WHERE default_template_id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        if ($st->fetchColumn() !== false) {
            throw DomainException::conflict(
                'A contract type uses this template by default. Point that type elsewhere first.',
                'TEMPLATE_IN_USE'
            );
        }

        // Audited before the delete: afterwards there is no row to reference,
        // and the audit table is append-only so the record survives.
        $this->audit->log($ctx, 'contract_template', $id, 'template.deleted', null, [
            'name' => ['from' => $existing['name'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM contract_templates WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$id, $ctx->environment, $ctx->cmpId]);
    }

    // -----------------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------------

    /**
     * Render a template, optionally against a real contract.
     *
     * `contract_id` is resolved under the caller's own tenant and visibility,
     * so a preview cannot be used to read a contract the caller could not open
     * directly.
     *
     * @return array<string,mixed>
     */
    public function preview(TenantContext $ctx, int $templateId, ?int $contractId = null): array
    {
        $template = $this->find($ctx, $templateId);
        $contract = $contractId === null
            ? null
            : (new ContractService($this->pdo))->findOrFail($ctx, $contractId);

        $rendered = $this->renderFor($ctx, (string) $template['body'], $contract);

        return [
            'template_id' => (int) $template['id'],
            'name'        => $template['name'],
            'version'     => (int) $template['version'],
            'contract_id' => $contract === null ? null : (int) $contract['id'],
            'html'        => $rendered['html'],
            'header_html' => $template['header_html'],
            'footer_html' => $template['footer_html'],
            'missing'     => $rendered['missing'],
            'used'        => $rendered['used'],
        ];
    }

    /**
     * Draft a contract from a template.
     *
     * The contract and the document text are produced together inside one
     * transaction: a render that fails on a bad variable must not leave a bare
     * contract record behind that nobody asked for.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function createContractFromTemplate(TenantContext $ctx, int $templateId, array $body): array
    {
        $template = $this->find($ctx, $templateId);

        if ((string) $template['status'] !== 'active') {
            throw DomainException::conflict(
                'Only an active template can be used to draft a contract.',
                'TEMPLATE_NOT_ACTIVE'
            );
        }
        if (trim((string) $template['body']) === '') {
            throw DomainException::conflict('This template has no body to render.', 'TEMPLATE_EMPTY');
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $template, $body): array {
            $intent = $body;
            $intent['title'] ??= $template['name'];
            $intent['contract_type_id'] ??= $template['contract_type_id'];
            $intent['source']      = 'from_template';
            $intent['template_id'] = (int) $template['id'];

            $contract = (new ContractService($pdo))->create($ctx, $intent);
            $rendered = $this->renderFor($ctx, (string) $template['body'], $contract);

            $this->audit->log($ctx, 'contract_template', (int) $template['id'], 'template.applied', (int) $contract['id'], [
                'template' => ['from' => null, 'to' => $template['name']],
            ]);
            $this->activity->record(
                $ctx,
                (int) $contract['id'],
                'template.applied',
                sprintf('Drafted from template "%s" (v%d)', $template['name'], (int) $template['version']),
                ['template_id' => (int) $template['id'], 'missing_variables' => $rendered['missing']]
            );

            return [
                'contract'      => $contract,
                'document_text' => $rendered['html'],
                'missing'       => $rendered['missing'],
                'used'          => $rendered['used'],
                'template'      => [
                    'id'      => (int) $template['id'],
                    'name'    => $template['name'],
                    'version' => (int) $template['version'],
                ],
            ];
        });
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $contract
     * @return array{html: string, missing: list<string>, used: list<string>}
     */
    private function renderFor(TenantContext $ctx, string $body, ?array $contract): array
    {
        $registry = $this->registry($ctx);

        $bag = $this->renderer->buildBag(
            $ctx,
            $contract,
            $contract === null ? null : $this->counterpartySnapshot($ctx, (int) $contract['id']),
            $contract === null ? null : $this->commercialTerms($ctx, (int) $contract['id'])
        );

        return $this->renderer->render($body, $bag, $registry);
    }

    /**
     * The variable registry, keyed by the key a template writes.
     *
     * @return array<string,array<string,mixed>>
     */
    private function registry(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT var_key, label, source, source_path, data_type
             FROM template_variables
             WHERE environment = ? AND cmp_id = ?'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        $indexed = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $indexed[(string) $row['var_key']] = $row;
        }

        return $indexed;
    }

    /** @return array<string,mixed>|null */
    private function counterpartySnapshot(TenantContext $ctx, int $contractId): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT s.*
             FROM contract_party_snapshots s
             JOIN contract_parties p ON p.id = s.party_id
             WHERE s.contract_id = ? AND s.environment = ? AND s.cmp_id = ?
               AND p.party_role <> 'company'
             ORDER BY p.is_primary DESC, s.captured_at DESC, s.id DESC
             LIMIT 1"
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function commercialTerms(TenantContext $ctx, int $contractId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_commercial_terms
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function lookup(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT t.*, ct.name AS contract_type_name, ct.code AS contract_type_code,
                    (SELECT COUNT(*) FROM contracts c WHERE c.template_id = t.id) AS contract_count
             FROM contract_templates t
             LEFT JOIN contract_types ct ON ct.id = t.contract_type_id
             WHERE t.id = ? AND t.environment = ? AND t.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readFields(TenantContext $ctx, Validator $v, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $name = $creating || $v->has('name')
            ? $v->requiredString('name', 200)
            : (string) $fallback('name', '');

        $body = $this->readBody($v, $creating ? '' : (string) $fallback('body', ''));

        return [
            'name'             => $name,
            'description'      => $v->optionalString('description', 5000, $fallback('description')),
            'contract_type_id' => $v->optionalId('contract_type_id') ?? ($v->has('contract_type_id') ? null : $fallback('contract_type_id')),
            'body'             => $body,
            'header_html'      => $v->optionalString('header_html', 20000, $fallback('header_html')),
            'footer_html'      => $v->optionalString('footer_html', 20000, $fallback('footer_html')),
            'status'           => $v->optionalEnum('status', Enums::TEMPLATE_STATUSES, (string) $fallback('status', 'draft')) ?? 'draft',
            'owner_uuid'       => $v->optionalString('owner_uuid', 64, $fallback('owner_uuid', $ctx->uuid)),
            'variables'        => $this->registeredVariables($ctx, $v, $body),
            'optional_clauses' => $v->has('optional_clauses') ? $v->optionalArray('optional_clauses') : self::decodeList($fallback('optional_clauses')),
            'signature_blocks' => $v->has('signature_blocks') ? $v->optionalArray('signature_blocks') : self::decodeList($fallback('signature_blocks')),
            'schedules'        => $v->has('schedules') ? $v->optionalArray('schedules') : self::decodeList($fallback('schedules')),
            'tags'             => $v->has('tags') ? $v->optionalArray('tags', 50) : self::decodeList($fallback('tags')),
        ];
    }

    /**
     * The template body.
     *
     * Read outside the Validator's string helpers because it is measured in
     * bytes against the renderer's own cap: the two limits have to be the same
     * number, or a body that saves is a body that will not render.
     */
    private function readBody(Validator $v, string $fallback): string
    {
        if (! $v->has('body')) {
            return $fallback;
        }

        $raw = $v->raw('body');
        if ($raw === null) {
            return '';
        }
        if (! is_string($raw)) {
            $v->fail('body', 'The template body must be text.');

            return $fallback;
        }
        if (strlen($raw) > TemplateRenderer::MAX_BODY_BYTES) {
            $v->fail('body', sprintf('A template body may not exceed %d KB.', (int) (TemplateRenderer::MAX_BODY_BYTES / 1024)));

            return $fallback;
        }

        return $raw;
    }

    /**
     * The variables a body uses, refusing any the company has not registered.
     *
     * Refused at save time rather than tolerated until render: an unknown
     * variable is always a mistake, and the person who can fix it is the one
     * looking at the editor right now.
     *
     * @return list<string>
     */
    private function registeredVariables(TenantContext $ctx, Validator $v, string $body): array
    {
        $used    = $this->renderer->extractVariables($body);
        $unknown = array_values(array_diff($used, array_keys($this->registry($ctx))));

        if ($unknown !== []) {
            $v->fail('body', sprintf(
                'Unknown merge %s: %s. Add %s to the variable list before using %s in a template.',
                count($unknown) === 1 ? 'variable' : 'variables',
                implode(', ', array_map(static fn (string $k): string => '{{ ' . $k . ' }}', array_slice($unknown, 0, 10))),
                count($unknown) === 1 ? 'it' : 'them',
                count($unknown) === 1 ? 'it' : 'them'
            ));
        }

        return $used;
    }

    /** @param list<string> $variables */
    private function writeVersion(
        PDO $pdo,
        TenantContext $ctx,
        int $templateId,
        int $version,
        string $body,
        array $variables,
        ?string $changeNote
    ): void {
        $pdo->prepare(
            'INSERT INTO contract_template_versions
             (template_id, version, body, variables, change_note, author_uuid)
             VALUES (?, ?, ?, ?::jsonb, ?, ?)'
        )->execute([$templateId, $version, $body, self::json($variables), $changeNote, $ctx->uuid]);
    }

    /**
     * A foreign key catches a contract type that does not exist; only this
     * catches one that exists and belongs to another company.
     */
    private function assertTypeBelongsToTenant(TenantContext $ctx, ?int $typeId): void
    {
        if ($typeId === null) {
            return;
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_types WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$typeId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['contract_type_id' => 'Choose a contract type from your own list.']);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        foreach (['variables', 'optional_clauses', 'signature_blocks', 'schedules', 'tags'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::decodeList($row[$key]);
            }
        }

        foreach (['id', 'cmp_id', 'contract_type_id', 'version', 'contract_count'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
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
        return json_encode($value === null ? [] : $value, JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /** @return list<mixed> */
    private static function decodeList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private static function decodeJson(mixed $raw): array
    {
        return self::decodeList($raw);
    }
}
