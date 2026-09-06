<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use App\Support\Validator;
use PDO;

/**
 * Contract requests: the intake queue that runs ahead of a draft.
 *
 * A request is the business asking for an agreement; a contract is the
 * agreement. They are separate records because the request has to survive the
 * conversion — six months later "who asked for this, and what did they say it
 * was for" is a question only the request can answer, and folding it into the
 * contract would let the first edit erase it.
 *
 * Every query filters `environment` AND `cmp_id` from the TenantContext, never
 * from request input.
 */
final class ContractRequestService
{
    /** Columns a caller may sort by. Anything else is ignored rather than interpolated. */
    private const SORTABLE = [
        'updated_at'       => 'r.updated_at',
        'created_at'       => 'r.created_at',
        'title'            => 'r.title',
        'request_number'   => 'r.request_number',
        'status'           => 'r.status',
        'required_by_date' => 'r.required_by_date',
        'estimated_value'  => 'r.estimated_value',
    ];

    /** Fields whose change is worth an audit row. */
    private const AUDITED_FIELDS = [
        'title', 'contract_type_id', 'department_id', 'reviewer_uuid',
        'required_by_date', 'counterparty_name', 'contact_ref_id', 'purpose',
        'business_justification', 'estimated_value', 'currency',
        'preferred_template_id', 'status', 'notes',
    ];

    /**
     * What a reviewer's verdict does to the request.
     *
     * Named actions rather than raw statuses: the reviewer is choosing an
     * outcome, and letting the caller post a status directly would make every
     * status in the enum reachable from the review screen.
     */
    private const DECISIONS = [
        'review'    => 'under_review',
        'approve'   => 'approved_for_drafting',
        'more_info' => 'more_info_required',
        'reject'    => 'rejected',
    ];

    private AuditService $audit;

    private ActivityService $activity;

    private NumberingService $numbering;

    public function __construct(private PDO $pdo)
    {
        $this->audit     = new AuditService($pdo);
        $this->activity  = new ActivityService($pdo);
        $this->numbering = new NumberingService($pdo);
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
     * One request, or null when it does not exist *for this tenant*.
     *
     * @return array<string,mixed>|null
     */
    public function find(TenantContext $ctx, int $id): ?array
    {
        [$visibility, $visibilityParams] = $this->visibilityPredicate($ctx);

        $st = $this->pdo->prepare(
            'SELECT r.*, ct.name AS contract_type_name, d.name AS department_name,
                    c.contract_number AS converted_contract_number
             FROM contract_requests r
             LEFT JOIN contract_types ct ON ct.id = r.contract_type_id
             LEFT JOIN contract_departments d ON d.id = r.department_id
             LEFT JOIN contracts c ON c.id = r.converted_contract_id
             WHERE r.id = :id AND r.environment = :env AND r.cmp_id = :cmp' . $visibility . '
             LIMIT 1'
        );
        $st->execute(array_merge(
            ['id' => $id, 'env' => $ctx->environment, 'cmp' => $ctx->cmpId],
            $visibilityParams
        ));
        $row = $st->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $row = $this->find($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Contract request not found.');
        }

        return $row;
    }

    /**
     * Which requests this user may see inside their own company.
     *
     * A reviewer needs the whole queue or they cannot work it. Everyone else
     * sees what they raised or were named on — applied here as well as in
     * search(), because enforcing it only on the list would leave the request
     * readable by walking the id in the URL.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function visibilityPredicate(TenantContext $ctx): array
    {
        if ($ctx->hasAny([Permissions::REQUEST_REVIEW, Permissions::CONTRACT_VIEW_ALL])) {
            return ['', []];
        }

        return [
            ' AND (r.requester_uuid = :vis_self OR r.reviewer_uuid = :vis_self2)',
            ['vis_self' => $ctx->uuid, 'vis_self2' => $ctx->uuid],
        ];
    }

    /**
     * A page of requests matching the intake filters.
     *
     * @param array<string,mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function search(TenantContext $ctx, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($ctx, $filters);

        $sortKey = is_string($filters['sort'] ?? null) ? $filters['sort'] : 'updated_at';
        $column  = self::SORTABLE[$sortKey] ?? self::SORTABLE['updated_at'];
        $dir     = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM contract_requests r {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $st = $this->pdo->prepare(
            "SELECT r.id, r.uuid, r.request_number, r.title, r.status,
                    r.requester_uuid, r.reviewer_uuid, r.required_by_date,
                    r.counterparty_name, r.estimated_value, r.currency,
                    r.contract_type_id, r.department_id, r.converted_contract_id,
                    r.converted_at, r.decided_at, r.created_at, r.updated_at,
                    ct.name AS contract_type_name,
                    dep.name AS department_name,
                    c.contract_number AS converted_contract_number
             FROM contract_requests r
             LEFT JOIN contract_types ct ON ct.id = r.contract_type_id
             LEFT JOIN contract_departments dep ON dep.id = r.department_id
             LEFT JOIN contracts c ON c.id = r.converted_contract_id
             {$where}
             ORDER BY {$column} {$dir} NULLS LAST, r.id {$dir}
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        return [
            'items' => array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []),
            'total' => $total,
        ];
    }

    /**
     * The request's own timeline.
     *
     * Reads the same table the contract timeline reads, filtered on
     * `request_id` — which is why conversion writes a row on each side: the
     * link is then visible whichever record the user opened.
     *
     * @return list<array<string,mixed>>
     */
    public function activityFor(TenantContext $ctx, int $requestId, int $limit = 50): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, actor_uuid, actor_label, event_type, summary, icon, metadata, created_at
             FROM contract_activity_logs
             WHERE environment = ? AND cmp_id = ? AND request_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ?'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $requestId, $limit]);

        return $st->fetchAll() ?: [];
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
        $fields = $this->readFields($v, $ctx, true);
        $v->assert();

        $this->assertTypeBelongsToTenant($ctx, $fields['contract_type_id']);
        $this->assertDepartmentBelongsToTenant($ctx, $fields['department_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $fields): array {
            $number = $this->numbering->nextRequestNumber($ctx);

            $st = $pdo->prepare(
                'INSERT INTO contract_requests
                 (environment, cmp_id, bo_id, request_number, title, contract_type_id,
                  department_id, requester_uuid, reviewer_uuid, required_by_date,
                  counterparty_name, contact_ref_id, purpose, business_justification,
                  estimated_value, currency, preferred_template_id, status, notes, metadata)
                 VALUES
                 (:env, :cmp, :bo, :num, :title, :type_id,
                  :dept_id, :requester, :reviewer, :required_by,
                  :counterparty, :contact, :purpose, :justification,
                  :value, :currency, :template_id, :status, :notes, :metadata::jsonb)
                 RETURNING id'
            );
            $st->execute([
                'env'           => $ctx->environment,
                'cmp'           => $ctx->cmpId,
                'bo'            => $ctx->boId,
                'num'           => $number,
                'title'         => $fields['title'],
                'type_id'       => $fields['contract_type_id'],
                'dept_id'       => $fields['department_id'],
                // The requester is the caller, never a body field: a request
                // raised in someone else's name is an accountability hole.
                'requester'     => $ctx->uuid,
                'reviewer'      => $fields['reviewer_uuid'],
                'required_by'   => $fields['required_by_date'],
                'counterparty'  => $fields['counterparty_name'],
                'contact'       => $fields['contact_ref_id'],
                'purpose'       => $fields['purpose'],
                'justification' => $fields['business_justification'],
                'value'         => $fields['estimated_value'],
                'currency'      => $fields['currency'],
                'template_id'   => $fields['preferred_template_id'],
                'status'        => 'draft',
                'notes'         => $fields['notes'],
                'metadata'      => json_encode($fields['metadata'], JSON_UNESCAPED_SLASHES),
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'contract_request', $id, 'request.created', null, [
                'request_number' => ['from' => null, 'to' => $number],
                'title'          => ['from' => null, 'to' => $fields['title']],
            ]);
            $this->recordOnRequest($ctx, $id, 'request.created', sprintf('Request %s raised', $number), [
                'request_number' => $number,
            ]);

            return $this->findOrFail($ctx, $id);
        });
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $id, array $body): array
    {
        $existing = $this->findOrFail($ctx, $id);
        $this->assertMayAmend($ctx, $existing);
        $this->assertEditable($existing);

        $v      = new Validator($body);
        $fields = $this->readFields($v, $ctx, false, $existing);
        $v->assert();

        $this->assertTypeBelongsToTenant($ctx, $fields['contract_type_id']);
        $this->assertDepartmentBelongsToTenant($ctx, $fields['department_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $existing, $fields): array {
            $st = $pdo->prepare(
                'UPDATE contract_requests SET
                    title = :title, contract_type_id = :type_id, department_id = :dept_id,
                    reviewer_uuid = :reviewer, required_by_date = :required_by,
                    counterparty_name = :counterparty, contact_ref_id = :contact,
                    purpose = :purpose, business_justification = :justification,
                    estimated_value = :value, currency = :currency,
                    preferred_template_id = :template_id, notes = :notes,
                    metadata = :metadata::jsonb, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            );
            $st->execute([
                'title'         => $fields['title'],
                'type_id'       => $fields['contract_type_id'],
                'dept_id'       => $fields['department_id'],
                'reviewer'      => $fields['reviewer_uuid'],
                'required_by'   => $fields['required_by_date'],
                'counterparty'  => $fields['counterparty_name'],
                'contact'       => $fields['contact_ref_id'],
                'purpose'       => $fields['purpose'],
                'justification' => $fields['business_justification'],
                'value'         => $fields['estimated_value'],
                'currency'      => $fields['currency'],
                'template_id'   => $fields['preferred_template_id'],
                'notes'         => $fields['notes'],
                'metadata'      => json_encode($fields['metadata'], JSON_UNESCAPED_SLASHES),
                'id'            => $id,
                'env'           => $ctx->environment,
                'cmp'           => $ctx->cmpId,
            ]);

            $updated = $this->findOrFail($ctx, $id);

            $this->audit->logChanges($ctx, 'contract_request', $id, $existing, $updated, self::AUDITED_FIELDS, null, 'request.updated');
            $this->recordOnRequest($ctx, $id, 'request.updated', 'Request details updated');

            return $updated;
        });
    }

    /**
     * Hand the request to the reviewers.
     *
     * @return array<string,mixed>
     */
    public function submit(TenantContext $ctx, int $id): array
    {
        $existing = $this->findOrFail($ctx, $id);
        $this->assertMayAmend($ctx, $existing);

        // A reviewer cannot judge what the request is for from a title alone,
        // and a request that comes back for more information is exactly the
        // cost of letting it through empty.
        if (trim((string) ($existing['purpose'] ?? '')) === '') {
            throw new ValidationFailed(['purpose' => 'Describe what this contract is for before submitting.']);
        }

        return $this->transition(
            $ctx,
            $existing,
            'submitted',
            'request.submitted',
            sprintf('Request %s submitted for review', (string) $existing['request_number'])
        );
    }

    /**
     * Record a reviewer's verdict.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function decide(TenantContext $ctx, int $id, string $decision, array $body): array
    {
        $key = strtolower(trim($decision));
        if (! isset(self::DECISIONS[$key])) {
            throw DomainException::badRequest('Unknown review decision.');
        }

        $existing = $this->findOrFail($ctx, $id);

        $v     = new Validator($body);
        $notes = $v->optionalText('notes', 4000);
        if ($key === 'reject' && ($notes === null || $notes === '')) {
            // A rejection with no reason is a request the business cannot act
            // on, and the requester will simply raise it again.
            $v->fail('notes', 'Say why the request is being rejected.');
        }
        $v->assert();

        return $this->transition(
            $ctx,
            $existing,
            self::DECISIONS[$key],
            'request.' . $key,
            sprintf(
                'Request %s: %s',
                (string) $existing['request_number'],
                Enums::label(self::DECISIONS[$key])
            ),
            $notes,
            // Picking a request up is not a verdict. Stamping decided_by there
            // would make the queue look reviewed the moment someone opened it.
            $key !== 'review'
        );
    }

    /**
     * Turn an approved request into a contract.
     *
     * The contract is created through ContractService so it picks up numbering,
     * validation and its own audit trail rather than a second, divergent insert
     * path. Both records then carry a timeline entry naming the other, so the
     * link reads the same whichever side the user opened.
     *
     * @param array<string,mixed> $body
     * @return array{request: array<string,mixed>, contract: array<string,mixed>}
     */
    public function convert(TenantContext $ctx, int $requestId, array $body): array
    {
        $request = $this->findOrFail($ctx, $requestId);
        $from    = (string) $request['status'];

        if ($from === 'converted') {
            throw DomainException::conflict(
                'This request has already been converted to a contract.',
                'REQUEST_ALREADY_CONVERTED'
            );
        }
        if (! self::transitionAllowed($from, 'converted')) {
            throw DomainException::conflict(
                sprintf('A request must be approved for drafting before it becomes a contract (it is %s).', Enums::label($from)),
                'INVALID_STATUS_TRANSITION'
            );
        }

        $v     = new Validator($body);
        $title = $v->has('title')
            ? $v->requiredString('title', 255)
            : (string) $request['title'];
        $typeId = $v->optionalId('contract_type_id') ?? $request['contract_type_id'];
        $v->assert();

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $requestId, $request, $title, $typeId, $body): array {
            $contract = (new ContractService($pdo))->create($ctx, array_merge($body, [
                'title'             => $title,
                'contract_type_id'  => $typeId,
                'department_id'     => $request['department_id'],
                'counterparty_name' => $request['counterparty_name'],
                'currency'          => $request['currency'],
                'total_value'       => $request['estimated_value'],
                'source'            => 'from_request',
                'request_id'        => $requestId,
                'status'            => 'draft',
                // The requester keeps sight of what they asked for: without
                // ownership a requester who lacks view_all would lose the
                // contract the moment it was drafted for them.
                'owner_uuid'        => $request['requester_uuid'],
            ]));

            $contractId = (int) $contract['id'];

            $pdo->prepare(
                "UPDATE contract_requests
                 SET status = 'converted', converted_contract_id = ?, converted_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?"
            )->execute([$contractId, $requestId, $ctx->environment, $ctx->cmpId]);

            $this->audit->log($ctx, 'contract_request', $requestId, 'request.converted', $contractId, [
                'status'                => ['from' => (string) $request['status'], 'to' => 'converted'],
                'converted_contract_id' => ['from' => null, 'to' => $contractId],
            ]);

            $this->recordOnRequest(
                $ctx,
                $requestId,
                'request.converted',
                sprintf('Converted to contract %s', (string) $contract['contract_number']),
                ['contract_id' => $contractId, 'contract_number' => $contract['contract_number']]
            );
            $this->activity->record(
                $ctx,
                $contractId,
                'contract.created_from_request',
                sprintf('Created from request %s', (string) $request['request_number']),
                ['request_id' => $requestId, 'request_number' => $request['request_number']]
            );

            return [
                'request'  => $this->findOrFail($ctx, $requestId),
                'contract' => $contract,
            ];
        });
    }

    // -----------------------------------------------------------------------
    // The status graph
    // -----------------------------------------------------------------------

    /**
     * Read as: from this status, you may go to any of these.
     *
     * `rejected` and `converted` lead nowhere on purpose. Reopening a rejected
     * request would erase the decision that was recorded against it, and a
     * second conversion would produce two contracts for one approval.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            'draft'                 => ['submitted'],
            'submitted'             => ['under_review', 'more_info_required', 'approved_for_drafting', 'rejected'],
            'under_review'          => ['more_info_required', 'approved_for_drafting', 'rejected'],
            'more_info_required'    => ['submitted'],
            'approved_for_drafting' => ['converted', 'rejected'],
            'rejected'              => [],
            'converted'             => [],
        ];
    }

    public static function transitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    /** The decisions a reviewer may record. @return list<string> */
    public static function decisions(): array
    {
        return array_keys(self::DECISIONS);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Move a request to a new status, refusing anything the graph does not allow.
     *
     * @param array<string,mixed> $existing
     * @return array<string,mixed>
     */
    private function transition(
        TenantContext $ctx,
        array $existing,
        string $to,
        string $eventType,
        string $summary,
        ?string $notes = null,
        bool $isDecision = false
    ): array {
        $id   = (int) $existing['id'];
        $from = (string) $existing['status'];

        if (! self::transitionAllowed($from, $to)) {
            throw DomainException::conflict(
                sprintf('A request cannot move from %s to %s.', Enums::label($from), Enums::label($to)),
                'INVALID_STATUS_TRANSITION'
            );
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $from, $to, $eventType, $summary, $notes, $isDecision): array {
            // The decision columns are written only by a verdict. A submission
            // that touched decided_by would credit the requester with the
            // reviewer's call.
            $assignments = ['status = ?'];
            $values      = [$to];

            if ($isDecision) {
                $assignments[] = 'decision_notes = ?';
                $assignments[] = 'decided_by = ?';
                $assignments[] = 'decided_at = CURRENT_TIMESTAMP';
                $values[]      = $notes;
                $values[]      = $ctx->uuid;
            }

            $pdo->prepare(
                'UPDATE contract_requests SET ' . implode(', ', $assignments) . ', updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute(array_merge($values, [$id, $ctx->environment, $ctx->cmpId]));

            $this->audit->log($ctx, 'contract_request', $id, $eventType, null, [
                'status' => ['from' => $from, 'to' => $to],
            ]);
            $this->recordOnRequest($ctx, $id, $eventType, $summary, array_filter(['notes' => $notes]));

            return $this->findOrFail($ctx, $id);
        });
    }

    /** Timeline entry anchored to the request rather than to a contract. */
    private function recordOnRequest(TenantContext $ctx, int $requestId, string $eventType, string $summary, array $metadata = []): void
    {
        $this->activity->record($ctx, null, $eventType, $summary, $metadata, null, $requestId);
    }

    /**
     * Only the requester edits their own request; a reviewer may also correct it.
     *
     * @param array<string,mixed> $request
     */
    private function assertMayAmend(TenantContext $ctx, array $request): void
    {
        if ((string) $request['requester_uuid'] === $ctx->uuid || $ctx->has(Permissions::REQUEST_REVIEW)) {
            return;
        }

        throw DomainException::forbidden('Only the requester can change this request.');
    }

    /**
     * Refuse edits once the request is with a reviewer.
     *
     * Editing a submitted request would change the thing being reviewed under
     * the reviewer's feet — the request comes back as `more_info_required`
     * first, which is a state both sides can see.
     *
     * @param array<string,mixed> $request
     */
    private function assertEditable(array $request): void
    {
        $status = (string) $request['status'];
        if (in_array($status, ['draft', 'more_info_required'], true)) {
            return;
        }

        throw DomainException::conflict(
            sprintf('A request under review cannot be edited (it is %s).', Enums::label($status)),
            'REQUEST_NOT_EDITABLE'
        );
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readFields(Validator $v, TenantContext $ctx, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        return [
            'title' => $creating || $v->has('title')
                ? $v->requiredString('title', 255)
                : (string) $fallback('title', ''),
            'contract_type_id'       => $v->optionalId('contract_type_id') ?? ($v->has('contract_type_id') ? null : $fallback('contract_type_id')),
            'department_id'          => $v->optionalId('department_id') ?? ($v->has('department_id') ? null : $fallback('department_id')),
            'reviewer_uuid'          => $v->optionalString('reviewer_uuid', 64, $fallback('reviewer_uuid')),
            'required_by_date'       => $v->optionalDate('required_by_date', $fallback('required_by_date')),
            'counterparty_name'      => $v->optionalString('counterparty_name', 255, $fallback('counterparty_name')),
            'contact_ref_id'         => $v->optionalString('contact_ref_id', 64, $fallback('contact_ref_id')),
            // optionalString rather than optionalText: only the former takes a
            // default, and a partial update that dropped the purpose would send
            // the request back to the reviewer with the reason for it missing.
            'purpose'                => $v->optionalString('purpose', 20000, $fallback('purpose')),
            'business_justification' => $v->optionalString('business_justification', 20000, $fallback('business_justification')),
            'estimated_value'        => $v->optionalDecimal('estimated_value', 2, $fallback('estimated_value')),
            'currency'               => $v->optionalCurrency('currency', (string) $fallback('currency', $ctx->currency())),
            'preferred_template_id'  => $v->optionalId('preferred_template_id') ?? ($v->has('preferred_template_id') ? null : $fallback('preferred_template_id')),
            'notes'                  => $v->optionalString('notes', 20000, $fallback('notes')),
            'metadata'               => $v->has('metadata') ? $v->optionalObject('metadata') : $this->decodeJson($fallback('metadata')),
        ];
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildWhere(TenantContext $ctx, array $f): array
    {
        $clauses = ['r.environment = :env', 'r.cmp_id = :cmp'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        [$visibility, $visibilityParams] = $this->visibilityPredicate($ctx);
        if ($visibility !== '') {
            // The fragment is written as an " AND (...)" tail for find(); here
            // it joins a clause list, so the leading AND is trimmed.
            $clauses[] = '(' . trim(substr($visibility, strlen(' AND '))) . ')';
            $params    = array_merge($params, $visibilityParams);
        }

        if (! empty($f['status']) && is_array($f['status'])) {
            $valid = array_values(array_filter(
                $f['status'],
                static fn ($s): bool => Enums::isValid($s, Enums::REQUEST_STATUSES)
            ));
            if ($valid !== []) {
                $names = [];
                foreach ($valid as $i => $status) {
                    $names[]           = ':st' . $i;
                    $params['st' . $i] = $status;
                }
                $clauses[] = 'r.status IN (' . implode(', ', $names) . ')';
            }
        }

        if (! empty($f['requester'])) {
            $clauses[]           = 'r.requester_uuid = :requester';
            $params['requester'] = (string) $f['requester'];
        }
        if (! empty($f['reviewer'])) {
            $clauses[]          = 'r.reviewer_uuid = :reviewer';
            $params['reviewer'] = (string) $f['reviewer'];
        }
        if (! empty($f['contract_type_id'])) {
            $clauses[]         = 'r.contract_type_id = :type_id';
            $params['type_id'] = (int) $f['contract_type_id'];
        }
        if (! empty($f['department_id'])) {
            $clauses[]         = 'r.department_id = :dept_id';
            $params['dept_id'] = (int) $f['department_id'];
        }
        if (! empty($f['required_by'])) {
            $clauses[]             = 'r.required_by_date <= :required_by';
            $params['required_by'] = (string) $f['required_by'];
        }
        if (! empty($f['q'])) {
            $query     = trim((string) $f['q']);
            $clauses[] = '(r.title ILIKE :q_like OR r.request_number ILIKE :q_like2 OR r.counterparty_name ILIKE :q_like3)';
            $params['q_like']  = '%' . $query . '%';
            $params['q_like2'] = '%' . $query . '%';
            $params['q_like3'] = '%' . $query . '%';
        }

        return ['WHERE ' . implode("\n  AND ", $clauses), $params];
    }

    /**
     * A foreign key catches a type from another company only if it does not
     * exist at all. Checking the tenant here is what stops one company pointing
     * a request at another company's contract type.
     */
    private function assertTypeBelongsToTenant(TenantContext $ctx, ?int $typeId): void
    {
        if ($typeId === null) {
            return;
        }

        $st = $this->pdo->prepare('SELECT 1 FROM contract_types WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1');
        $st->execute([$typeId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['contract_type_id' => 'Choose a contract type from your own list.']);
        }
    }

    private function assertDepartmentBelongsToTenant(TenantContext $ctx, ?int $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        $st = $this->pdo->prepare('SELECT 1 FROM contract_departments WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1');
        $st->execute([$departmentId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['department_id' => 'Choose a department from your own list.']);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        if (isset($row['metadata']) && is_string($row['metadata'])) {
            $row['metadata'] = $this->decodeJson($row['metadata']);
        }

        foreach (['id', 'cmp_id', 'bo_id', 'contract_type_id', 'department_id', 'preferred_template_id', 'converted_contract_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
