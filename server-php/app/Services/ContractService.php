<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Dates;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\Validator;
use PDO;

/**
 * The contract record: create, read, update, list, and the status transitions
 * that hold its lifecycle together.
 *
 * Every query in this class filters on `environment` AND `cmp_id` from the
 * TenantContext, never from request input. That is the single rule the product
 * cannot afford to get wrong, and CrossTenantIsolationTest exercises it.
 */
final class ContractService
{
    /** Columns a caller may sort by. Anything else is ignored rather than interpolated. */
    private const SORTABLE = [
        'updated_at'      => 'c.updated_at',
        'created_at'      => 'c.created_at',
        'title'           => 'c.title',
        'contract_number' => 'c.contract_number',
        'counterparty'    => 'c.counterparty_name',
        'effective_date'  => 'c.effective_date',
        'expiry_date'     => 'c.expiry_date',
        'total_value'     => 'c.total_value',
        'risk'            => 'c.ai_risk_score',
        'status'          => 'c.status',
    ];

    /** Fields whose change is worth an audit row. */
    private const AUDITED_FIELDS = [
        'title', 'description', 'contract_type_id', 'department_id', 'owner_uuid',
        'status', 'lifecycle_stage', 'effective_date', 'commencement_date',
        'execution_date', 'expiry_date', 'renewal_type', 'renewal_frequency',
        'auto_renewal', 'notice_period_days', 'notice_deadline', 'governing_law',
        'jurisdiction', 'currency', 'total_value', 'recurring_value',
        'payment_frequency', 'billing_frequency', 'commercial_summary',
        'risk_level', 'counterparty_name', 'notes',
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
     * One contract, or null when it does not exist *for this tenant*.
     *
     * Null rather than a distinguishable "forbidden" is deliberate: a caller
     * walking ids must not be able to tell another company's contract from a
     * gap in the sequence.
     *
     * @return array<string,mixed>|null
     */
    public function find(TenantContext $ctx, int $id): ?array
    {
        // The row-level visibility predicate is applied here as well as in
        // search(). Enforcing it only on the list would leave a plain IDOR: a
        // user without view_all could not see a colleague's contract in the
        // repository but could open it by walking the id in the URL.
        [$visibility, $visibilityParams] = $this->visibilityPredicate($ctx);

        $st = $this->pdo->prepare(
            'SELECT c.*, ct.name AS contract_type_name, ct.code AS contract_type_code,
                    d.name AS department_name
             FROM contracts c
             LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
             LEFT JOIN contract_departments d ON d.id = c.department_id
             WHERE c.id = :id AND c.environment = :env AND c.cmp_id = :cmp' . $visibility . '
             LIMIT 1'
        );
        $st->execute(array_merge(
            ['id' => $id, 'env' => $ctx->environment, 'cmp' => $ctx->cmpId],
            $visibilityParams
        ));
        $row = $st->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Which contracts this user may see inside their own company.
     *
     * A user holding CONTRACT_VIEW_ALL sees everything the company has. Anyone
     * else sees only what they own, created, or has been routed to them for
     * approval — the cases where they are demonstrably involved.
     *
     * Returned as a fragment rather than applied in place because find() and
     * search() must agree exactly; two copies of this SQL would eventually not.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function visibilityPredicate(TenantContext $ctx): array
    {
        if ($ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            return ['', []];
        }

        $sql = ' AND (c.owner_uuid = :vis_self
                      OR c.created_by = :vis_self2
                      OR EXISTS (
                          SELECT 1
                          FROM contract_approval_assignments a
                          JOIN contract_approval_instances i ON i.id = a.instance_id
                          WHERE i.contract_id = c.id AND a.approver_uuid = :vis_self3
                      ))';

        return [$sql, [
            'vis_self'  => $ctx->uuid,
            'vis_self2' => $ctx->uuid,
            'vis_self3' => $ctx->uuid,
        ]];
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $row = $this->find($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Contract not found.');
        }

        return $row;
    }

    /**
     * A page of contracts matching the repository filters.
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

        // NULLS LAST: a contract with no expiry date should not head the list
        // when sorting by expiry — the ones with a real deadline are the point.
        $order = "{$column} {$dir} NULLS LAST, c.id {$dir}";

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM contracts c {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $sql = "SELECT c.id, c.uuid, c.contract_number, c.title, c.status, c.lifecycle_stage,
                       c.counterparty_name, c.effective_date, c.expiry_date, c.notice_deadline,
                       c.renewal_type, c.auto_renewal, c.currency, c.total_value,
                       c.risk_level, c.ai_risk_score, c.health_score, c.owner_uuid,
                       c.approval_status, c.signing_status, c.archived_at,
                       c.created_at, c.updated_at, c.contract_type_id, c.department_id,
                       ct.name AS contract_type_name,
                       dep.name AS department_name,
                       (fav.contract_id IS NOT NULL) AS is_favourite
                FROM contracts c
                LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
                LEFT JOIN contract_departments dep ON dep.id = c.department_id
                LEFT JOIN contract_favourites fav
                       ON fav.contract_id = c.id AND fav.user_uuid = :fav_user
                {$where}
                ORDER BY {$order}
                LIMIT :lim OFFSET :off";

        $st = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':fav_user', $ctx->uuid);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        $items = array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Build the shared WHERE clause for listing, counting and exporting.
     *
     * Every value is bound; the only caller-influenced SQL text is the sort
     * column, which is looked up in a fixed map above rather than interpolated.
     *
     * @param array<string,mixed> $f
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildWhere(TenantContext $ctx, array $f): array
    {
        $clauses = ['c.environment = :env', 'c.cmp_id = :cmp'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        // A user without view_all sees only contracts they own or are a party
        // to. This is the row-level half of RBAC; the permission check in the
        // controller is the endpoint-level half.
        [$visibility, $visibilityParams] = $this->visibilityPredicate($ctx);
        if ($visibility !== '') {
            // The fragment is written as an " AND (...)" tail for find(); here
            // it joins a clause list, so the leading AND is trimmed.
            $clauses[] = '(' . trim(substr($visibility, strlen(' AND '))) . ')';
            $params    = array_merge($params, $visibilityParams);
        }

        if (($f['archived'] ?? null) === 'only') {
            $clauses[] = 'c.archived_at IS NOT NULL';
        } elseif (($f['archived'] ?? null) !== 'all') {
            $clauses[] = 'c.archived_at IS NULL';
        }

        if (! empty($f['status']) && is_array($f['status'])) {
            $valid = array_values(array_filter(
                $f['status'],
                static fn ($s): bool => Enums::isValid($s, Enums::CONTRACT_STATUSES)
            ));
            if ($valid !== []) {
                $names = [];
                foreach ($valid as $i => $status) {
                    $names[]              = ':st' . $i;
                    $params['st' . $i]    = $status;
                }
                $clauses[] = 'c.status IN (' . implode(', ', $names) . ')';
            }
        }

        if (! empty($f['contract_type_id'])) {
            $clauses[]                 = 'c.contract_type_id = :type_id';
            $params['type_id']         = (int) $f['contract_type_id'];
        }
        if (! empty($f['department_id'])) {
            $clauses[]                 = 'c.department_id = :dept_id';
            $params['dept_id']         = (int) $f['department_id'];
        }
        if (! empty($f['owner_uuid'])) {
            $clauses[]                 = 'c.owner_uuid = :owner';
            $params['owner']           = (string) $f['owner_uuid'];
        }
        if (! empty($f['counterparty'])) {
            $clauses[]                 = 'c.counterparty_name ILIKE :cp';
            $params['cp']              = '%' . $f['counterparty'] . '%';
        }
        if (! empty($f['risk_level'])) {
            $clauses[]                 = 'c.risk_level = :risk';
            $params['risk']            = (string) $f['risk_level'];
        }
        if (! empty($f['currency'])) {
            $clauses[]                 = 'c.currency = :cur';
            $params['cur']             = strtoupper((string) $f['currency']);
        }
        if (isset($f['auto_renewal']) && $f['auto_renewal'] !== null) {
            $clauses[]                 = 'c.auto_renewal = :autoren';
            $params['autoren']         = $f['auto_renewal'] ? 'true' : 'false';
        }
        if (! empty($f['approval_status'])) {
            $clauses[]                 = 'c.approval_status = :appr';
            $params['appr']            = (string) $f['approval_status'];
        }
        if (! empty($f['signing_status'])) {
            $clauses[]                 = 'c.signing_status = :sign';
            $params['sign']            = (string) $f['signing_status'];
        }
        if (! empty($f['effective_from'])) {
            $clauses[]                 = 'c.effective_date >= :eff_from';
            $params['eff_from']        = (string) $f['effective_from'];
        }
        if (! empty($f['effective_to'])) {
            $clauses[]                 = 'c.effective_date <= :eff_to';
            $params['eff_to']          = (string) $f['effective_to'];
        }
        if (! empty($f['expiry_from'])) {
            $clauses[]                 = 'c.expiry_date >= :exp_from';
            $params['exp_from']        = (string) $f['expiry_from'];
        }
        if (! empty($f['expiry_to'])) {
            $clauses[]                 = 'c.expiry_date <= :exp_to';
            $params['exp_to']          = (string) $f['expiry_to'];
        }
        if (isset($f['value_min']) && $f['value_min'] !== null && $f['value_min'] !== '') {
            $clauses[]                 = 'c.total_value >= :vmin';
            $params['vmin']            = (string) $f['value_min'];
        }
        if (isset($f['value_max']) && $f['value_max'] !== null && $f['value_max'] !== '') {
            $clauses[]                 = 'c.total_value <= :vmax';
            $params['vmax']            = (string) $f['value_max'];
        }
        if (! empty($f['tag_id'])) {
            $clauses[]                 = 'EXISTS (SELECT 1 FROM contract_tag_map m WHERE m.contract_id = c.id AND m.tag_id = :tag)';
            $params['tag']             = (int) $f['tag_id'];
        }
        if (! empty($f['favourites_only'])) {
            $clauses[]                 = 'EXISTS (SELECT 1 FROM contract_favourites f WHERE f.contract_id = c.id AND f.user_uuid = :fav)';
            $params['fav']             = $ctx->uuid;
        }
        if (! empty($f['expiring_within_days'])) {
            $clauses[]                 = 'c.expiry_date IS NOT NULL AND c.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :within)';
            $params['within']          = (int) $f['expiring_within_days'];
        }
        if (! empty($f['obligation_status'])) {
            $clauses[]                 = 'EXISTS (SELECT 1 FROM obligation_occurrences o WHERE o.contract_id = c.id AND o.status = :obl)';
            $params['obl']             = (string) $f['obligation_status'];
        }

        if (! empty($f['q'])) {
            $query = trim((string) $f['q']);
            // Two-pronged: the tsvector answers word queries with ranking, and
            // ILIKE catches partial words ("acme" inside "Acmecorp") that a
            // lexeme match would miss. plainto_tsquery, not to_tsquery, so a
            // user typing "a & b" gets a search rather than a syntax error.
            $clauses[] = '(c.search_vector @@ plainto_tsquery(\'english\', :q_ts)
                           OR c.title ILIKE :q_like
                           OR c.contract_number ILIKE :q_like2
                           OR c.counterparty_name ILIKE :q_like3)';
            $params['q_ts']    = $query;
            $params['q_like']  = '%' . $query . '%';
            $params['q_like2'] = '%' . $query . '%';
            $params['q_like3'] = '%' . $query . '%';
        }

        return ['WHERE ' . implode("\n  AND ", $clauses), $params];
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> the created contract
     */
    public function create(TenantContext $ctx, array $body): array
    {
        $v      = new Validator($body);
        $fields = $this->readFields($v, $ctx, true);
        $v->assert();

        $this->assertTypeBelongsToTenant($ctx, $fields['contract_type_id']);
        $this->assertDepartmentBelongsToTenant($ctx, $fields['department_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $fields, $body): array {
            $number = $this->numbering->nextContractNumber($ctx);

            $st = $pdo->prepare(
                'INSERT INTO contracts
                 (environment, cmp_id, bo_id, fy_id, contract_number, title, description,
                  contract_type_id, department_id, owner_uuid, status, lifecycle_stage, source,
                  effective_date, commencement_date, execution_date, expiry_date,
                  renewal_type, renewal_frequency, auto_renewal, notice_period_days, notice_deadline,
                  governing_law, jurisdiction, currency, total_value, recurring_value,
                  payment_frequency, billing_frequency, commercial_summary, counterparty_name,
                  notes, custom_fields, request_id, template_id, verification_state,
                  created_by, updated_by)
                 VALUES
                 (:env, :cmp, :bo, :fy, :num, :title, :descr,
                  :type_id, :dept_id, :owner, :status, :stage, :source,
                  :effective, :commencement, :execution, :expiry,
                  :ren_type, :ren_freq, :auto_ren, :notice_days, :notice_deadline,
                  :law, :jurisdiction, :currency, :total, :recurring,
                  :pay_freq, :bill_freq, :summary, :counterparty,
                  :notes, :custom::jsonb, :request_id, :template_id, :verification,
                  :actor, :actor2)
                 RETURNING id'
            );
            $st->execute([
                'env'             => $ctx->environment,
                'cmp'             => $ctx->cmpId,
                'bo'              => $ctx->boId,
                'fy'              => $ctx->fyId,
                'num'             => $number,
                'title'           => $fields['title'],
                'descr'           => $fields['description'],
                'type_id'         => $fields['contract_type_id'],
                'dept_id'         => $fields['department_id'],
                'owner'           => $fields['owner_uuid'] ?? $ctx->uuid,
                'status'          => $fields['status'],
                'stage'           => $fields['lifecycle_stage'],
                'source'          => $fields['source'],
                'effective'       => $fields['effective_date'],
                'commencement'    => $fields['commencement_date'],
                'execution'       => $fields['execution_date'],
                'expiry'          => $fields['expiry_date'],
                'ren_type'        => $fields['renewal_type'],
                'ren_freq'        => $fields['renewal_frequency'],
                'auto_ren'        => $fields['auto_renewal'] ? 'true' : 'false',
                'notice_days'     => $fields['notice_period_days'],
                'notice_deadline' => $fields['notice_deadline'],
                'law'             => $fields['governing_law'],
                'jurisdiction'    => $fields['jurisdiction'],
                'currency'        => $fields['currency'],
                'total'           => $fields['total_value'],
                'recurring'       => $fields['recurring_value'],
                'pay_freq'        => $fields['payment_frequency'],
                'bill_freq'       => $fields['billing_frequency'],
                'summary'         => $fields['commercial_summary'],
                'counterparty'    => $fields['counterparty_name'],
                'notes'           => $fields['notes'],
                'custom'          => json_encode($fields['custom_fields'], JSON_UNESCAPED_SLASHES),
                'request_id'      => $fields['request_id'],
                'template_id'     => $fields['template_id'],
                'verification'    => $fields['verification_state'],
                'actor'           => $ctx->uuid,
                'actor2'          => $ctx->uuid,
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'contract', $id, 'contract.created', $id, [
                'contract_number' => ['from' => null, 'to' => $number],
                'title'           => ['from' => null, 'to' => $fields['title']],
            ]);
            $this->activity->record($ctx, $id, 'contract.created', sprintf('Contract %s created', $number), [
                'contract_number' => $number,
            ]);

            $created = $this->find($ctx, $id);
            if ($created === null) {
                // Only reachable if the row vanished between INSERT and SELECT
                // inside one transaction, which would mean something far worse
                // than a failed create.
                throw new DomainException('Contract was created but could not be read back.', 'CREATE_FAILED', 500);
            }

            return $created;
        });
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $id, array $body): array
    {
        $existing = $this->findOrFail($ctx, $id);
        $this->assertEditable($existing);

        $v      = new Validator($body);
        $fields = $this->readFields($v, $ctx, false, $existing);
        $v->assert();

        $this->assertTypeBelongsToTenant($ctx, $fields['contract_type_id']);
        $this->assertDepartmentBelongsToTenant($ctx, $fields['department_id']);

        // Commercial figures are gated separately: Finance may see and change
        // them where a contract owner may only see them.
        if (! $ctx->has(Permissions::COMMERCIALS_EDIT)) {
            foreach (['total_value', 'recurring_value', 'currency', 'payment_frequency', 'billing_frequency'] as $field) {
                $fields[$field] = $existing[$field];
            }
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $existing, $fields): array {
            $st = $pdo->prepare(
                'UPDATE contracts SET
                    title = :title, description = :descr, contract_type_id = :type_id,
                    department_id = :dept_id, owner_uuid = :owner,
                    effective_date = :effective, commencement_date = :commencement,
                    execution_date = :execution, expiry_date = :expiry,
                    renewal_type = :ren_type, renewal_frequency = :ren_freq,
                    auto_renewal = :auto_ren, notice_period_days = :notice_days,
                    notice_deadline = :notice_deadline,
                    governing_law = :law, jurisdiction = :jurisdiction,
                    currency = :currency, total_value = :total, recurring_value = :recurring,
                    payment_frequency = :pay_freq, billing_frequency = :bill_freq,
                    commercial_summary = :summary, counterparty_name = :counterparty,
                    notes = :notes, custom_fields = :custom::jsonb,
                    updated_by = :actor, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            );
            $st->execute([
                'title'           => $fields['title'],
                'descr'           => $fields['description'],
                'type_id'         => $fields['contract_type_id'],
                'dept_id'         => $fields['department_id'],
                'owner'           => $fields['owner_uuid'],
                'effective'       => $fields['effective_date'],
                'commencement'    => $fields['commencement_date'],
                'execution'       => $fields['execution_date'],
                'expiry'          => $fields['expiry_date'],
                'ren_type'        => $fields['renewal_type'],
                'ren_freq'        => $fields['renewal_frequency'],
                'auto_ren'        => $fields['auto_renewal'] ? 'true' : 'false',
                'notice_days'     => $fields['notice_period_days'],
                'notice_deadline' => $fields['notice_deadline'],
                'law'             => $fields['governing_law'],
                'jurisdiction'    => $fields['jurisdiction'],
                'currency'        => $fields['currency'],
                'total'           => $fields['total_value'],
                'recurring'       => $fields['recurring_value'],
                'pay_freq'        => $fields['payment_frequency'],
                'bill_freq'       => $fields['billing_frequency'],
                'summary'         => $fields['commercial_summary'],
                'counterparty'    => $fields['counterparty_name'],
                'notes'           => $fields['notes'],
                'custom'          => json_encode($fields['custom_fields'], JSON_UNESCAPED_SLASHES),
                'actor'           => $ctx->uuid,
                'id'              => $id,
                'env'             => $ctx->environment,
                'cmp'             => $ctx->cmpId,
            ]);

            $updated = $this->findOrFail($ctx, $id);

            $this->audit->logChanges($ctx, 'contract', $id, $existing, $updated, self::AUDITED_FIELDS, $id);
            $this->activity->record($ctx, $id, 'contract.updated', 'Contract details updated');

            return $updated;
        });
    }

    /**
     * Move a contract to a new status.
     *
     * Transitions are checked against a fixed map rather than allowed freely:
     * a contract that jumps from draft to active has skipped approval and
     * signature, and no report afterwards can tell that it did.
     *
     * @return array<string,mixed>
     */
    public function changeStatus(TenantContext $ctx, int $id, string $status, ?string $note = null): array
    {
        $existing = $this->findOrFail($ctx, $id);

        if (! Enums::isValid($status, Enums::CONTRACT_STATUSES)) {
            throw DomainException::badRequest('Unknown contract status.');
        }

        $from = (string) $existing['status'];
        if ($from === $status) {
            return $existing;
        }

        if (! self::transitionAllowed($from, $status)) {
            throw DomainException::conflict(
                sprintf('A contract cannot move from %s to %s.', Enums::label($from), Enums::label($status)),
                'INVALID_STATUS_TRANSITION'
            );
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $existing, $from, $status, $note): array {
            $stage = self::stageForStatus($status);

            $pdo->prepare(
                'UPDATE contracts
                 SET status = ?, lifecycle_stage = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP,
                     archived_at = CASE WHEN ? = \'archived\' THEN CURRENT_TIMESTAMP ELSE archived_at END
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$status, $stage, $ctx->uuid, $status, $id, $ctx->environment, $ctx->cmpId]);

            $this->audit->log($ctx, 'contract', $id, 'contract.status_changed', $id, [
                'status' => ['from' => $from, 'to' => $status],
            ]);
            $this->activity->record(
                $ctx,
                $id,
                'contract.status.' . $status,
                sprintf('Status changed from %s to %s', Enums::label($from), Enums::label($status)),
                array_filter(['note' => $note])
            );

            // Becoming active is the moment obligations start counting and the
            // renewal clock starts, so both are set up here rather than left to
            // a nightly sweep that would be a day late.
            if ($status === 'active') {
                (new ObligationService($pdo))->generateForContract($ctx, $id);
                (new RenewalService($pdo))->ensureCycle($ctx, $id);
            }

            return $this->findOrFail($ctx, $id);
        });
    }

    public function archive(TenantContext $ctx, int $id, bool $archived): array
    {
        $existing = $this->findOrFail($ctx, $id);

        $this->pdo->prepare(
            'UPDATE contracts SET archived_at = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $archived ? date('Y-m-d H:i:s') : null,
            $ctx->uuid,
            $id,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        $this->audit->log($ctx, 'contract', $id, $archived ? 'contract.archived' : 'contract.unarchived', $id, [
            'archived_at' => ['from' => $existing['archived_at'], 'to' => $archived ? 'now' : null],
        ]);
        $this->activity->record($ctx, $id, $archived ? 'contract.archived' : 'contract.restored',
            $archived ? 'Contract archived' : 'Contract restored from the archive');

        return $this->findOrFail($ctx, $id);
    }

    /**
     * Delete a draft.
     *
     * Only a draft, and only one that was never executed. Anything past draft
     * is business history — the archive is how those leave the working set.
     */
    public function deleteDraft(TenantContext $ctx, int $id): void
    {
        $existing = $this->findOrFail($ctx, $id);

        if ((string) $existing['status'] !== 'draft') {
            throw DomainException::conflict(
                'Only a draft can be deleted. Archive this contract instead.',
                'DELETE_NOT_ALLOWED'
            );
        }
        if (! empty($existing['execution_date'])) {
            throw DomainException::conflict('This contract has been executed and cannot be deleted.', 'DELETE_NOT_ALLOWED');
        }

        // Audited before the delete: afterwards there is no row to reference,
        // and the audit table is append-only so the record survives.
        $this->audit->log($ctx, 'contract', $id, 'contract.deleted', $id, [
            'contract_number' => ['from' => $existing['contract_number'], 'to' => null],
            'title'           => ['from' => $existing['title'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$id, $ctx->environment, $ctx->cmpId]);
    }

    public function touchRecentView(TenantContext $ctx, int $contractId): void
    {
        $this->pdo->prepare(
            'INSERT INTO contract_recent_views (environment, cmp_id, user_uuid, contract_id, viewed_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
             ON CONFLICT (user_uuid, contract_id) DO UPDATE SET viewed_at = CURRENT_TIMESTAMP'
        )->execute([$ctx->environment, $ctx->cmpId, $ctx->uuid, $contractId]);
    }

    public function setFavourite(TenantContext $ctx, int $contractId, bool $favourite): void
    {
        $this->findOrFail($ctx, $contractId);

        if ($favourite) {
            $this->pdo->prepare(
                'INSERT INTO contract_favourites (environment, cmp_id, user_uuid, contract_id)
                 VALUES (?, ?, ?, ?) ON CONFLICT (user_uuid, contract_id) DO NOTHING'
            )->execute([$ctx->environment, $ctx->cmpId, $ctx->uuid, $contractId]);

            return;
        }

        $this->pdo->prepare('DELETE FROM contract_favourites WHERE user_uuid = ? AND contract_id = ?')
            ->execute([$ctx->uuid, $contractId]);
    }

    // -----------------------------------------------------------------------
    // Lifecycle rules
    // -----------------------------------------------------------------------

    /**
     * The allowed status graph.
     *
     * Read as: from this status, you may go to any of these. Anything not
     * listed is refused — including going backwards from a terminal state,
     * which would let a terminated contract quietly become active again.
     *
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            'draft'              => ['under_review', 'awaiting_approval', 'negotiation', 'awaiting_signature', 'active', 'cancelled', 'archived'],
            'under_review'       => ['draft', 'awaiting_approval', 'negotiation', 'approved', 'cancelled', 'archived'],
            'awaiting_approval'  => ['approved', 'under_review', 'draft', 'negotiation', 'cancelled', 'archived'],
            'approved'           => ['negotiation', 'awaiting_signature', 'active', 'cancelled', 'archived'],
            'negotiation'        => ['under_review', 'awaiting_approval', 'approved', 'awaiting_signature', 'cancelled', 'archived'],
            'awaiting_signature' => ['active', 'negotiation', 'approved', 'cancelled', 'archived'],
            'active'             => ['renewal_review', 'expired', 'terminated', 'archived'],
            'renewal_review'     => ['active', 'expired', 'terminated', 'archived'],
            'expired'            => ['renewal_review', 'active', 'archived'],
            'terminated'         => ['archived'],
            'cancelled'          => ['draft', 'archived'],
            'archived'           => ['draft', 'active', 'expired', 'terminated'],
        ];
    }

    public static function transitionAllowed(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    public static function stageForStatus(string $status): string
    {
        return match ($status) {
            'draft'              => 'draft',
            'under_review'       => 'internal_review',
            'awaiting_approval'  => 'approval',
            'approved'           => 'approval',
            'negotiation'        => 'negotiation',
            'awaiting_signature' => 'signature',
            'active'             => 'active',
            'renewal_review'     => 'renewal',
            default              => 'closed',
        };
    }

    /**
     * Refuse metadata edits on an executed contract.
     *
     * Changing the value or the expiry date of a signed agreement in place
     * would silently rewrite what the company believes it agreed to. Those
     * changes go through an amendment, which keeps the original readable.
     *
     * @param array<string,mixed> $contract
     */
    private function assertEditable(array $contract): void
    {
        $status = (string) $contract['status'];

        if (in_array($status, ['terminated', 'expired'], true)) {
            throw DomainException::conflict(
                'This contract has ended. Record an amendment instead of editing it.',
                'CONTRACT_CLOSED'
            );
        }

        if ($contract['archived_at'] !== null) {
            throw DomainException::conflict('Restore this contract from the archive before editing it.', 'CONTRACT_ARCHIVED');
        }
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readFields(Validator $v, TenantContext $ctx, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $title = $creating || $v->has('title')
            ? $v->requiredString('title', 255)
            : (string) $fallback('title', '');

        $expiry    = $v->optionalDate('expiry_date', $fallback('expiry_date'));
        $effective = $v->optionalDate('effective_date', $fallback('effective_date'));
        $notice    = $v->optionalInt('notice_period_days', 0, 3650, $fallback('notice_period_days'));

        if ($expiry !== null && $effective !== null && $expiry < $effective) {
            $v->fail('expiry_date', 'The expiry date cannot be before the effective date.');
        }

        $status = $creating
            ? ($v->optionalEnum('status', Enums::CONTRACT_STATUSES, 'draft') ?? 'draft')
            : (string) $fallback('status', 'draft');

        return [
            'title'              => $title,
            'description'        => $v->optionalText('description', 20000, $fallback('description')),
            'contract_type_id'   => $v->optionalId('contract_type_id') ?? ($v->has('contract_type_id') ? null : $fallback('contract_type_id')),
            'department_id'      => $v->optionalId('department_id') ?? ($v->has('department_id') ? null : $fallback('department_id')),
            'owner_uuid'         => $v->optionalString('owner_uuid', 64, $fallback('owner_uuid', $ctx->uuid)),
            'status'             => $status,
            'lifecycle_stage'    => self::stageForStatus($status),
            'source'             => $v->optionalEnum('source', Enums::CONTRACT_SOURCES, $fallback('source', 'drafted')) ?? 'drafted',
            'effective_date'     => $effective,
            'commencement_date'  => $v->optionalDate('commencement_date', $fallback('commencement_date')),
            'execution_date'     => $v->optionalDate('execution_date', $fallback('execution_date')),
            'expiry_date'        => $expiry,
            'renewal_type'       => $v->optionalEnum('renewal_type', Enums::RENEWAL_TYPES, $fallback('renewal_type', 'fixed_term')) ?? 'fixed_term',
            'renewal_frequency'  => $v->optionalEnum('renewal_frequency', Enums::RENEWAL_FREQUENCIES, $fallback('renewal_frequency')),
            'auto_renewal'       => $v->optionalBool('auto_renewal', (bool) $fallback('auto_renewal', false)) ?? false,
            'notice_period_days' => $notice,
            'notice_deadline'    => Dates::noticeDeadline($expiry, $notice),
            'governing_law'      => $v->optionalString('governing_law', 120, $fallback('governing_law')),
            'jurisdiction'       => $v->optionalString('jurisdiction', 120, $fallback('jurisdiction')),
            'currency'           => $v->optionalCurrency('currency', (string) $fallback('currency', $ctx->currency())),
            'total_value'        => $v->optionalDecimal('total_value', 2, $fallback('total_value')),
            'recurring_value'    => $v->optionalDecimal('recurring_value', 2, $fallback('recurring_value')),
            'payment_frequency'  => $v->optionalString('payment_frequency', 24, $fallback('payment_frequency')),
            'billing_frequency'  => $v->optionalString('billing_frequency', 24, $fallback('billing_frequency')),
            'commercial_summary' => $v->optionalText('commercial_summary', 5000, $fallback('commercial_summary')),
            'counterparty_name'  => $v->optionalString('counterparty_name', 255, $fallback('counterparty_name')),
            'notes'              => $v->optionalText('notes', 20000, $fallback('notes')),
            'custom_fields'      => $v->has('custom_fields') ? $v->optionalObject('custom_fields') : $this->decodeJson($fallback('custom_fields')),
            'request_id'         => $v->optionalId('request_id') ?? $fallback('request_id'),
            'template_id'        => $v->optionalId('template_id') ?? $fallback('template_id'),
            'verification_state' => $v->optionalEnum('verification_state', Enums::VERIFICATION_STATES, $fallback('verification_state', 'human_verified')) ?? 'human_verified',
        ];
    }

    /**
     * A foreign key would catch a type from another company only if it did not
     * exist at all. Checking the tenant here is what stops one company pointing
     * a contract at another company's contract type.
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
            throw new \App\Support\ValidationFailed(['contract_type_id' => 'Choose a contract type from your own list.']);
        }
    }

    private function assertDepartmentBelongsToTenant(TenantContext $ctx, ?int $departmentId): void
    {
        if ($departmentId === null) {
            return;
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_departments WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$departmentId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new \App\Support\ValidationFailed(['department_id' => 'Choose a department from your own list.']);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        foreach (['custom_fields', 'metadata'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $row[$key] = $this->decodeJson($row[$key]);
            }
        }

        foreach (['auto_renewal', 'is_favourite'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::toBool($row[$key]);
            }
        }

        foreach (['id', 'cmp_id', 'contract_type_id', 'department_id', 'ai_risk_score', 'health_score', 'notice_period_days', 'parent_contract_id', 'request_id', 'template_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        // The search vector is an internal index artefact and only bloats every
        // API response it appears in.
        unset($row['search_vector']);

        $row['days_to_expiry'] = Dates::daysUntil($row['expiry_date'] ?? null);
        $row['days_to_notice'] = Dates::daysUntil($row['notice_deadline'] ?? null);

        return $row;
    }

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

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        // PostgreSQL hands booleans back as 't'/'f' through PDO, and a plain
        // (bool) cast makes 'f' true.
        return in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
