<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Dates;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use App\Support\Validator;
use App\Support\ValidationFailed;
use PDO;

/**
 * Obligations — what a contract requires someone to do — and the occurrences
 * they fall due as.
 *
 * The obligation is the rule ("submit a quarterly SLA report by the 15th"); an
 * occurrence is one instance of it falling due. Keeping them apart is what lets
 * a recurring obligation carry a compliance history instead of a single mutable
 * due date that is overwritten every quarter and remembers nothing.
 *
 * Occurrences are materialised rows rather than dates computed on read. A
 * computed schedule cannot be completed, waived, disputed, or hold evidence,
 * and none of the reports the product exists to produce can be written against
 * one. Generation is bounded (a horizon and a row ceiling) and idempotent
 * (UNIQUE (obligation_id, due_date) plus ON CONFLICT DO NOTHING), so the cron
 * that calls it may run as often as it likes.
 *
 * Every query filters `environment` AND `cmp_id` from the TenantContext, never
 * from request input. The cron entry points take those as explicit arguments
 * because a sweep has no acting user.
 */
final class ObligationService
{
    /**
     * How far ahead one run will materialise occurrences.
     *
     * A perpetual obligation on an evergreen contract has no natural end, so
     * without this the first generation would try to fill the calendar forever.
     */
    private const HORIZON_MONTHS = 24;

    /** Ceiling on rows one obligation may add in a single run. */
    private const MAX_OCCURRENCES = 200;

    /** Fields whose change is worth an audit row. */
    private const AUDITED_FIELDS = [
        'title', 'description', 'obligation_type', 'clause_id', 'responsible_party',
        'owner_uuid', 'frequency', 'custom_interval_days', 'start_date', 'end_date',
        'first_due_date', 'grace_period_days', 'amount', 'currency', 'evidence_required',
        'reminder_days', 'escalation_days', 'escalate_to_uuid', 'status',
        'verification_state', 'is_active',
    ];

    /**
     * Changing any of these changes which dates the obligation falls due on,
     * so the occurrences generated from the old shape no longer describe it.
     */
    private const RECURRENCE_FIELDS = [
        'frequency', 'custom_interval_days', 'start_date', 'end_date', 'first_due_date',
    ];

    /** Statuses an occurrence is still live in — everything else is an outcome. */
    private const LIVE_STATUSES = ['upcoming', 'due', 'overdue'];

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
    // Obligations — reading
    // -----------------------------------------------------------------------

    /**
     * One obligation, or null when it does not exist *for this tenant*.
     *
     * The contract's own dates come along because they are what a schedule with
     * no explicit start date anchors on, and reading them here saves the
     * generator a second round trip per obligation.
     *
     * @return array<string,mixed>|null
     */
    public function find(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT o.*,
                    c.contract_number,
                    c.title            AS contract_title,
                    c.status           AS contract_status,
                    c.effective_date   AS contract_effective_date,
                    c.commencement_date AS contract_commencement_date,
                    c.expiry_date      AS contract_expiry_date
             FROM contract_obligations o
             JOIN contracts c ON c.id = o.contract_id
             WHERE o.id = ? AND o.environment = ? AND o.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrateObligation($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $row = $this->find($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Obligation not found.');
        }

        return $row;
    }

    /**
     * Every obligation on a contract, soonest live deadline first.
     *
     * @return list<array<string,mixed>>
     */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            "SELECT o.*,
                    (SELECT MIN(x.due_date) FROM obligation_occurrences x
                      WHERE x.obligation_id = o.id AND x.status IN ('upcoming','due','overdue')) AS next_due_date,
                    (SELECT COUNT(*) FROM obligation_occurrences x
                      WHERE x.obligation_id = o.id) AS occurrence_count,
                    (SELECT COUNT(*) FROM obligation_occurrences x
                      WHERE x.obligation_id = o.id AND x.status = 'overdue') AS overdue_count,
                    (SELECT COUNT(*) FROM obligation_occurrences x
                      WHERE x.obligation_id = o.id AND x.status = 'completed') AS completed_count
             FROM contract_obligations o
             WHERE o.environment = ? AND o.cmp_id = ? AND o.contract_id = ?
             ORDER BY o.is_active DESC, next_due_date ASC NULLS LAST, o.id ASC"
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        return array_map(fn (array $r): array => $this->hydrateObligation($r), $st->fetchAll() ?: []);
    }

    /**
     * Occurrence counts by status for one contract.
     *
     * Every status is present with a zero rather than omitted, so a dashboard
     * can render the row without deciding what a missing key means.
     *
     * @return array<string,int>
     */
    public function summaryForContract(TenantContext $ctx, int $contractId): array
    {
        $summary = array_fill_keys(Enums::OBLIGATION_STATUSES, 0);

        $st = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n
             FROM obligation_occurrences
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
             GROUP BY status'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        $total = 0;
        foreach ($st->fetchAll() ?: [] as $row) {
            $status = (string) $row['status'];
            $count  = (int) $row['n'];
            $total += $count;
            if (array_key_exists($status, $summary)) {
                $summary[$status] = $count;
            }
        }

        $obligations = $this->pdo->prepare(
            'SELECT COUNT(*) FROM contract_obligations
             WHERE environment = ? AND cmp_id = ? AND contract_id = ? AND is_active = TRUE'
        );
        $obligations->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        $summary['total']       = $total;
        $summary['obligations'] = (int) $obligations->fetchColumn();

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Obligations — writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> the created obligation
     */
    public function create(TenantContext $ctx, int $contractId, array $body): array
    {
        $contract = $this->contractOrFail($ctx, $contractId);

        $v      = new Validator($body);
        $fields = $this->readFields($v, $ctx, true, null, $contract);
        $v->assert();

        $this->assertClauseBelongsToContract($ctx, $contractId, $fields['clause_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $contract, $fields): array {
            $st = $pdo->prepare(
                'INSERT INTO contract_obligations
                 (environment, cmp_id, contract_id, clause_id, obligation_type, title, description,
                  responsible_party, owner_uuid, frequency, custom_interval_days,
                  start_date, end_date, first_due_date, grace_period_days,
                  amount, currency, evidence_required, reminder_days,
                  escalation_days, escalate_to_uuid, status,
                  is_ai_extracted, ai_confidence, verification_state, is_active, created_by)
                 VALUES
                 (:env, :cmp, :contract, :clause, :type, :title, :descr,
                  :party, :owner, :freq, :interval,
                  :start, :end, :first_due, :grace,
                  :amount, :currency, :evidence, :reminders,
                  :escalation, :escalate_to, :status,
                  :ai, :confidence, :verification, :active, :actor)
                 RETURNING id'
            );
            $st->execute([
                'env'          => $ctx->environment,
                'cmp'          => $ctx->cmpId,
                'contract'     => $contractId,
                'clause'       => $fields['clause_id'],
                'type'         => $fields['obligation_type'],
                'title'        => $fields['title'],
                'descr'        => $fields['description'],
                'party'        => $fields['responsible_party'],
                'owner'        => $fields['owner_uuid'],
                'freq'         => $fields['frequency'],
                'interval'     => $fields['custom_interval_days'],
                'start'        => $fields['start_date'],
                'end'          => $fields['end_date'],
                'first_due'    => $fields['first_due_date'],
                'grace'        => $fields['grace_period_days'],
                'amount'       => $fields['amount'],
                'currency'     => $fields['currency'],
                'evidence'     => $fields['evidence_required'] ? 'true' : 'false',
                'reminders'    => $fields['reminder_days'],
                'escalation'   => $fields['escalation_days'],
                'escalate_to'  => $fields['escalate_to_uuid'],
                'status'       => $fields['status'],
                'ai'           => $fields['is_ai_extracted'] ? 'true' : 'false',
                'confidence'   => $fields['ai_confidence'],
                'verification' => $fields['verification_state'],
                'active'       => $fields['is_active'] ? 'true' : 'false',
                'actor'        => $ctx->uuid,
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'obligation', $id, 'obligation.created', $contractId, [
                'title'     => ['from' => null, 'to' => $fields['title']],
                'frequency' => ['from' => null, 'to' => $fields['frequency']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'obligation.created',
                sprintf('Obligation "%s" added', $fields['title']),
                ['obligation_id' => $id, 'frequency' => $fields['frequency']]
            );

            // An obligation added to a contract that is already running has to
            // start counting now. Waiting for the next activation would mean it
            // never generates at all — activation has already happened.
            if ($fields['is_active'] && in_array((string) $contract['status'], Enums::ACTIVE_STATUSES, true)) {
                $this->generateOccurrences($ctx, $id);
            }

            $created = $this->find($ctx, $id);
            if ($created === null) {
                throw new DomainException('The obligation was created but could not be read back.', 'CREATE_FAILED', 500);
            }

            return $created;
        });
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $obligationId, array $body): array
    {
        $existing   = $this->findOrFail($ctx, $obligationId);
        $contractId = (int) $existing['contract_id'];
        $contract   = $this->contractOrFail($ctx, $contractId);

        $v      = new Validator($body);
        $fields = $this->readFields($v, $ctx, false, $existing, $contract);
        $v->assert();

        $this->assertClauseBelongsToContract($ctx, $contractId, $fields['clause_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $obligationId, $contractId, $contract, $existing, $fields): array {
            $st = $pdo->prepare(
                'UPDATE contract_obligations SET
                    clause_id = :clause, obligation_type = :type, title = :title, description = :descr,
                    responsible_party = :party, owner_uuid = :owner,
                    frequency = :freq, custom_interval_days = :interval,
                    start_date = :start, end_date = :end, first_due_date = :first_due,
                    grace_period_days = :grace, amount = :amount, currency = :currency,
                    evidence_required = :evidence, reminder_days = :reminders,
                    escalation_days = :escalation, escalate_to_uuid = :escalate_to,
                    status = :status, ai_confidence = :confidence,
                    verification_state = :verification, is_active = :active,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            );
            $st->execute([
                'clause'       => $fields['clause_id'],
                'type'         => $fields['obligation_type'],
                'title'        => $fields['title'],
                'descr'        => $fields['description'],
                'party'        => $fields['responsible_party'],
                'owner'        => $fields['owner_uuid'],
                'freq'         => $fields['frequency'],
                'interval'     => $fields['custom_interval_days'],
                'start'        => $fields['start_date'],
                'end'          => $fields['end_date'],
                'first_due'    => $fields['first_due_date'],
                'grace'        => $fields['grace_period_days'],
                'amount'       => $fields['amount'],
                'currency'     => $fields['currency'],
                'evidence'     => $fields['evidence_required'] ? 'true' : 'false',
                'reminders'    => $fields['reminder_days'],
                'escalation'   => $fields['escalation_days'],
                'escalate_to'  => $fields['escalate_to_uuid'],
                'status'       => $fields['status'],
                'confidence'   => $fields['ai_confidence'],
                'verification' => $fields['verification_state'],
                'active'       => $fields['is_active'] ? 'true' : 'false',
                'id'           => $obligationId,
                'env'          => $ctx->environment,
                'cmp'          => $ctx->cmpId,
            ]);

            $updated = $this->findOrFail($ctx, $obligationId);

            $this->audit->logChanges($ctx, 'obligation', $obligationId, $existing, $updated, self::AUDITED_FIELDS, $contractId);
            $this->activity->record(
                $ctx,
                $contractId,
                'obligation.updated',
                sprintf('Obligation "%s" updated', (string) $updated['title']),
                ['obligation_id' => $obligationId]
            );

            $this->resyncOccurrences($ctx, $existing, $updated, $contract);

            return $updated;
        });
    }

    /**
     * Delete an obligation that never produced an outcome.
     *
     * Once an occurrence has been completed, waived or disputed, the obligation
     * is part of the compliance record and deleting it would remove the only
     * explanation for the evidence attached to it. Deactivating stops it
     * generating without erasing what happened.
     */
    public function delete(TenantContext $ctx, int $obligationId): void
    {
        $existing   = $this->findOrFail($ctx, $obligationId);
        $contractId = (int) $existing['contract_id'];

        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM obligation_occurrences
             WHERE obligation_id = ? AND environment = ? AND cmp_id = ?
               AND status NOT IN ('upcoming','due','overdue')"
        );
        $st->execute([$obligationId, $ctx->environment, $ctx->cmpId]);

        if ((int) $st->fetchColumn() > 0) {
            throw DomainException::conflict(
                'This obligation already has a recorded outcome. Deactivate it instead of deleting it.',
                'DELETE_NOT_ALLOWED'
            );
        }

        // Audited before the delete: afterwards there is no row to reference,
        // and the audit table is append-only so the record survives.
        $this->audit->log($ctx, 'obligation', $obligationId, 'obligation.deleted', $contractId, [
            'title' => ['from' => $existing['title'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM contract_obligations WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$obligationId, $ctx->environment, $ctx->cmpId]);

        $this->activity->record(
            $ctx,
            $contractId,
            'obligation.deleted',
            sprintf('Obligation "%s" removed', (string) $existing['title'])
        );
    }

    // -----------------------------------------------------------------------
    // Occurrence generation
    // -----------------------------------------------------------------------

    /**
     * Materialise occurrences for every active obligation on a contract.
     *
     * Called by ContractService when a contract goes active: that is the moment
     * the obligations start counting, and leaving it to the nightly sweep would
     * make every one of them a day late on its first day.
     *
     * @return int occurrences inserted
     */
    public function generateForContract(TenantContext $ctx, int $contractId): int
    {
        $this->contractOrFail($ctx, $contractId);

        $st = $this->pdo->prepare(
            'SELECT id FROM contract_obligations
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND is_active = TRUE
             ORDER BY id'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        $inserted = 0;
        foreach ($st->fetchAll() ?: [] as $row) {
            $inserted += $this->generateOccurrences($ctx, (int) $row['id']);
        }

        return $inserted;
    }

    /**
     * Materialise the occurrences one obligation is missing.
     *
     * Idempotent twice over: the walk only emits dates after the last one
     * already stored, and the insert is ON CONFLICT DO NOTHING against
     * UNIQUE (obligation_id, due_date). Re-running can add work that the
     * horizon has newly brought into range; it can never duplicate a due date.
     *
     * @param string|null $horizonDate stop generating past this date; clamped to
     *                                 the standing horizon, so a caller may
     *                                 shorten the window but never widen it
     * @return int occurrences inserted
     */
    public function generateOccurrences(TenantContext $ctx, int $obligationId, ?string $horizonDate = null): int
    {
        $obligation = $this->findOrFail($ctx, $obligationId);

        if (! ContractService::toBool($obligation['is_active'])) {
            return 0;
        }

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) AS n, MAX(due_date) AS last_due
             FROM obligation_occurrences
             WHERE obligation_id = ? AND environment = ? AND cmp_id = ?'
        );
        $st->execute([$obligationId, $ctx->environment, $ctx->cmpId]);
        $state = $st->fetch();

        $rows = self::schedule(
            $obligation,
            $horizonDate,
            is_array($state) ? (int) $state['n'] : 0,
            is_array($state) && $state['last_due'] !== null ? (string) $state['last_due'] : null
        );

        if ($rows === []) {
            return 0;
        }

        $grace  = (int) $obligation['grace_period_days'];
        $insert = $this->pdo->prepare(
            'INSERT INTO obligation_occurrences
             (obligation_id, contract_id, environment, cmp_id, sequence_no,
              due_date, grace_until, period_start, period_end, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (obligation_id, due_date) DO NOTHING
             RETURNING id'
        );

        $inserted = 0;
        foreach ($rows as $row) {
            $insert->execute([
                $obligationId,
                (int) $obligation['contract_id'],
                $ctx->environment,
                $ctx->cmpId,
                $row['sequence_no'],
                $row['due_date'],
                Dates::addDays($row['due_date'], $grace),
                $row['period_start'],
                $row['due_date'],
                // Born at the status the date deserves. A contract activated
                // three months late must show its missed reports immediately,
                // not read clean until the next sweep runs.
                self::statusForDueDate($row['due_date'], $grace),
            ]);

            if ($insert->fetchColumn() !== false) {
                $inserted++;
            }
            $insert->closeCursor();
        }

        return $inserted;
    }

    /**
     * The due dates an obligation still owes, as insertable rows.
     *
     * @param array<string,mixed> $obligation
     * @param string|null         $lastDue    the latest due date already stored
     * @return list<array{sequence_no: int, due_date: string, period_start: string|null}>
     */
    private static function schedule(array $obligation, ?string $horizonDate, int $existingCount, ?string $lastDue): array
    {
        $anchor = self::anchorDate($obligation);
        if ($anchor === null) {
            return [];
        }

        $frequency = (string) $obligation['frequency'];
        $interval  = $obligation['custom_interval_days'] === null ? null : (int) $obligation['custom_interval_days'];

        // Measured from whichever is later, today or the anchor: a schedule
        // that starts three years out still gets its opening window, and one
        // that started years ago does not get three extra years of future.
        $ceiling = Dates::addMonths(max(Dates::today(), $anchor), self::HORIZON_MONTHS) ?? $anchor;
        $horizon = $horizonDate === null ? $ceiling : min($horizonDate, $ceiling);

        // A recurring duty belongs to the term it was agreed in. Materialising
        // it past the contract's expiry invents work for a period nobody has
        // committed to; when a renewal moves the expiry date, the next run
        // extends the series instead. An obligation that states its own
        // end_date overrides this, which is how a duty that deliberately
        // outlives the contract says so.
        $endDate = $obligation['end_date'] ?? $obligation['contract_expiry_date'] ?? null;
        $endDate = $endDate === null ? null : substr((string) $endDate, 0, 10);

        // Month-based recurrences are computed from the anchor rather than from
        // the previous date. Chaining would drift: 31 Jan clamps to 28 Feb, and
        // a second step from 28 Feb lands on 28 Mar instead of the 31st the
        // agreement actually says.
        $months = Dates::frequencyMonths($frequency);

        $start    = $obligation['start_date'] === null ? null : (string) $obligation['start_date'];
        $previous = $start !== null && $start <= $anchor ? $start : null;

        // The walk is bounded by what is already stored plus one run's worth,
        // so a daily obligation with years of history still advances rather
        // than re-walking the same first 200 dates forever.
        $walkLimit = $existingCount + self::MAX_OCCURRENCES;

        $rows   = [];
        $cursor = $anchor;
        $index  = 0;

        while ($cursor !== null && $index < $walkLimit) {
            // The end date stops the repetition, never the opening occurrence:
            // a return-of-materials duty falling due after expiry is exactly
            // what that obligation is for, and generating nothing at all would
            // hide it rather than bound it.
            if ($cursor > $horizon || ($endDate !== null && $cursor > $endDate && $index > 0)) {
                break;
            }

            $index++;

            if ($lastDue === null || $cursor > $lastDue) {
                $rows[] = [
                    'sequence_no'  => $index,
                    'due_date'     => $cursor,
                    'period_start' => $previous,
                ];

                if (count($rows) >= self::MAX_OCCURRENCES) {
                    break;
                }
            }

            $previous = $cursor;
            $next     = $months !== null
                ? Dates::addMonths($anchor, $months * $index)
                : Dates::advance($cursor, $frequency, $interval);

            // A frequency that does not move the date forward — 'one_time', or
            // anything unparseable — ends the series rather than spinning.
            $cursor = $next !== null && $next > $cursor ? $next : null;
        }

        return $rows;
    }

    /**
     * Where the series starts.
     *
     * `first_due_date` wins because an agreement often states a first deadline
     * that is not the start of the term ("the first report is due 45 days after
     * commencement, quarterly thereafter").
     *
     * @param array<string,mixed> $obligation
     */
    private static function anchorDate(array $obligation): ?string
    {
        foreach (['first_due_date', 'start_date', 'contract_commencement_date', 'contract_effective_date'] as $key) {
            $value = $obligation[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return substr($value, 0, 10);
            }
        }

        return null;
    }

    private static function statusForDueDate(string $dueDate, int $graceDays): string
    {
        $today = Dates::today();

        if ($dueDate > $today) {
            return 'upcoming';
        }

        return (Dates::addDays($dueDate, $graceDays) ?? $dueDate) < $today ? 'overdue' : 'due';
    }

    /**
     * Bring the occurrence set back in line after the obligation was edited.
     *
     * Only untouched future work is dropped. An occurrence that is already due,
     * overdue or has an outcome recorded against it is history — rewriting the
     * recurrence must not make a missed report disappear.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $contract
     */
    private function resyncOccurrences(TenantContext $ctx, array $before, array $after, array $contract): void
    {
        $shapeChanged = false;
        foreach (self::RECURRENCE_FIELDS as $field) {
            if ((string) ($before[$field] ?? '') !== (string) ($after[$field] ?? '')) {
                $shapeChanged = true;
                break;
            }
        }

        $stillActive = ContractService::toBool($after['is_active']);

        if ($shapeChanged || ! $stillActive) {
            $this->pdo->prepare(
                "DELETE FROM obligation_occurrences
                 WHERE obligation_id = ? AND environment = ? AND cmp_id = ?
                   AND status = 'upcoming' AND due_date > CURRENT_DATE"
            )->execute([(int) $after['id'], $ctx->environment, $ctx->cmpId]);
        }

        if ((string) $before['grace_period_days'] !== (string) $after['grace_period_days']) {
            $this->pdo->prepare(
                "UPDATE obligation_occurrences
                 SET grace_until = due_date + CAST(? AS INTEGER), updated_at = CURRENT_TIMESTAMP
                 WHERE obligation_id = ? AND environment = ? AND cmp_id = ?
                   AND status IN ('upcoming','due','overdue')"
            )->execute([(int) $after['grace_period_days'], (int) $after['id'], $ctx->environment, $ctx->cmpId]);
        }

        if ($shapeChanged && $stillActive && in_array((string) $contract['status'], Enums::ACTIVE_STATUSES, true)) {
            $this->generateOccurrences($ctx, (int) $after['id']);
        }
    }

    // -----------------------------------------------------------------------
    // Occurrences — reading
    // -----------------------------------------------------------------------

    /**
     * A page of occurrences across the tenant.
     *
     * @param array<string,mixed> $filters status, due_from, due_to, contract_id,
     *                                     owner_uuid, responsible_party, overdue_only
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function listOccurrences(TenantContext $ctx, array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildOccurrenceWhere($ctx, $filters);

        $countSt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM obligation_occurrences occ
             JOIN contract_obligations o ON o.id = occ.obligation_id
             {$where}"
        );
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $st = $this->pdo->prepare(
            "SELECT occ.*,
                    o.title            AS obligation_title,
                    o.obligation_type,
                    o.responsible_party,
                    o.owner_uuid,
                    o.frequency,
                    o.evidence_required,
                    o.grace_period_days,
                    o.currency,
                    c.contract_number,
                    c.title            AS contract_title
             FROM obligation_occurrences occ
             JOIN contract_obligations o ON o.id = occ.obligation_id
             JOIN contracts c ON c.id = occ.contract_id
             {$where}
             ORDER BY occ.due_date ASC, occ.id ASC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();

        $items = array_map(fn (array $r): array => $this->hydrateOccurrence($r), $st->fetchAll() ?: []);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildOccurrenceWhere(TenantContext $ctx, array $f): array
    {
        $clauses = ['occ.environment = :env', 'occ.cmp_id = :cmp'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        $statuses = $f['status'] ?? null;
        if (is_string($statuses) && $statuses !== '') {
            $statuses = [$statuses];
        }
        if (is_array($statuses)) {
            $valid = array_values(array_filter(
                $statuses,
                static fn ($s): bool => Enums::isValid($s, Enums::OBLIGATION_STATUSES)
            ));
            if ($valid !== []) {
                $names = [];
                foreach ($valid as $i => $status) {
                    $names[]           = ':st' . $i;
                    $params['st' . $i] = $status;
                }
                $clauses[] = 'occ.status IN (' . implode(', ', $names) . ')';
            }
        }

        if (! empty($f['due_from'])) {
            $clauses[]           = 'occ.due_date >= :due_from';
            $params['due_from']  = (string) $f['due_from'];
        }
        if (! empty($f['due_to'])) {
            $clauses[]           = 'occ.due_date <= :due_to';
            $params['due_to']    = (string) $f['due_to'];
        }
        if (! empty($f['contract_id'])) {
            $clauses[]           = 'occ.contract_id = :contract';
            $params['contract']  = (int) $f['contract_id'];
        }
        if (! empty($f['owner_uuid'])) {
            $clauses[]           = 'o.owner_uuid = :owner';
            $params['owner']     = (string) $f['owner_uuid'];
        }
        if (! empty($f['responsible_party'])) {
            $clauses[]           = 'o.responsible_party = :party';
            $params['party']     = (string) $f['responsible_party'];
        }
        if (! empty($f['overdue_only'])) {
            // Compared against the date as well as the stored status, so the
            // list is right between sweeps — an occurrence that fell past its
            // grace an hour ago is overdue whether or not the cron has run.
            $clauses[] = "(occ.status = 'overdue'
                           OR (occ.status IN ('upcoming','due')
                               AND COALESCE(occ.grace_until, occ.due_date) < CURRENT_DATE))";
        }

        return ['WHERE ' . implode("\n  AND ", $clauses), $params];
    }

    /** @return array<string,mixed>|null */
    public function findOccurrence(TenantContext $ctx, int $occurrenceId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT occ.*,
                    o.title            AS obligation_title,
                    o.obligation_type,
                    o.responsible_party,
                    o.owner_uuid,
                    o.frequency,
                    o.evidence_required,
                    o.grace_period_days,
                    o.currency,
                    c.contract_number,
                    c.title            AS contract_title
             FROM obligation_occurrences occ
             JOIN contract_obligations o ON o.id = occ.obligation_id
             JOIN contracts c ON c.id = occ.contract_id
             WHERE occ.id = ? AND occ.environment = ? AND occ.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$occurrenceId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrateOccurrence($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOccurrenceOrFail(TenantContext $ctx, int $occurrenceId): array
    {
        $row = $this->findOccurrence($ctx, $occurrenceId);
        if ($row === null) {
            throw DomainException::notFound('Obligation occurrence not found.');
        }

        return $row;
    }

    /**
     * Evidence recorded against one occurrence.
     *
     * @return list<array<string,mixed>>
     */
    public function listEvidence(TenantContext $ctx, int $occurrenceId): array
    {
        $st = $this->pdo->prepare(
            'SELECT e.id, e.occurrence_id, e.obligation_id, e.document_id, e.note,
                    e.external_ref, e.uploaded_by, e.created_at, d.title AS document_title
             FROM obligation_evidence e
             LEFT JOIN contract_documents d ON d.id = e.document_id
             WHERE e.occurrence_id = ? AND e.environment = ? AND e.cmp_id = ?
             ORDER BY e.created_at ASC, e.id ASC'
        );
        $st->execute([$occurrenceId, $ctx->environment, $ctx->cmpId]);

        return array_map(static function (array $r): array {
            foreach (['id', 'occurrence_id', 'obligation_id', 'document_id'] as $key) {
                if (isset($r[$key])) {
                    $r[$key] = (int) $r[$key];
                }
            }

            return $r;
        }, $st->fetchAll() ?: []);
    }

    // -----------------------------------------------------------------------
    // Occurrences — writing
    // -----------------------------------------------------------------------

    /**
     * Record that an occurrence was met.
     *
     * @param array<string,mixed> $body completion_note, amount, and the evidence
     *                                  fields document_id, evidence_note (or
     *                                  note) and external_ref
     * @return array<string,mixed> the completed occurrence
     */
    public function completeOccurrence(TenantContext $ctx, int $occurrenceId, array $body): array
    {
        $occurrence = $this->findOccurrenceOrFail($ctx, $occurrenceId);

        if ((string) $occurrence['status'] === 'completed') {
            throw DomainException::conflict('This obligation is already recorded as completed.', 'ALREADY_COMPLETED');
        }

        $v            = new Validator($body);
        $note         = $v->optionalString('completion_note', 20000);
        $amount       = $v->optionalDecimal('amount', 2, $occurrence['amount'] === null ? null : (string) $occurrence['amount']);
        $documentId   = $v->optionalId('document_id');
        $evidenceNote = $v->has('evidence_note') ? $v->optionalString('evidence_note', 20000) : $v->optionalString('note', 20000);
        $externalRef  = $v->optionalString('external_ref', 255);
        $v->assert();

        $hasEvidence = $documentId !== null || $evidenceNote !== null || $externalRef !== null;

        if (ContractService::toBool($occurrence['evidence_required']) && ! $hasEvidence) {
            throw DomainException::conflict(
                'This obligation needs evidence before it can be marked complete.',
                'EVIDENCE_REQUIRED'
            );
        }

        if ($documentId !== null) {
            $this->assertDocumentUsable($ctx, $documentId, (int) $occurrence['contract_id']);
        }

        $contractId   = (int) $occurrence['contract_id'];
        $obligationId = (int) $occurrence['obligation_id'];

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $occurrenceId,
            $obligationId,
            $contractId,
            $occurrence,
            $note,
            $amount,
            $documentId,
            $evidenceNote,
            $externalRef,
            $hasEvidence
        ): array {
            $pdo->prepare(
                "UPDATE obligation_occurrences
                 SET status = 'completed', completed_at = CURRENT_TIMESTAMP, completed_by = ?,
                     completion_note = ?, amount = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?"
            )->execute([$ctx->uuid, $note, $amount, $occurrenceId, $ctx->environment, $ctx->cmpId]);

            if ($hasEvidence) {
                $pdo->prepare(
                    'INSERT INTO obligation_evidence
                     (occurrence_id, obligation_id, environment, cmp_id, document_id, note, external_ref, uploaded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $occurrenceId,
                    $obligationId,
                    $ctx->environment,
                    $ctx->cmpId,
                    $documentId,
                    $evidenceNote,
                    $externalRef,
                    $ctx->uuid,
                ]);
            }

            // The obligation itself closes only when nothing of it is still
            // outstanding — a quarterly report is not finished because this
            // quarter's was filed.
            $pdo->prepare(
                "UPDATE contract_obligations
                 SET status = 'completed', updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?
                   AND status IN ('upcoming','due','overdue')
                   AND NOT EXISTS (
                       SELECT 1 FROM obligation_occurrences x
                       WHERE x.obligation_id = ? AND x.status IN ('upcoming','due','overdue')
                   )"
            )->execute([$obligationId, $ctx->environment, $ctx->cmpId, $obligationId]);

            $this->audit->log($ctx, 'obligation_occurrence', $occurrenceId, 'obligation.completed', $contractId, [
                'status' => ['from' => $occurrence['status'], 'to' => 'completed'],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'obligation.completed',
                sprintf(
                    'Obligation "%s" completed for %s',
                    (string) $occurrence['obligation_title'],
                    (string) $occurrence['due_date']
                ),
                ['occurrence_id' => $occurrenceId, 'obligation_id' => $obligationId, 'evidence' => $hasEvidence]
            );

            return $this->findOccurrenceOrFail($ctx, $occurrenceId);
        });
    }

    /**
     * Move an occurrence to a status other than completed.
     *
     * @return array<string,mixed>
     */
    public function updateOccurrenceStatus(TenantContext $ctx, int $occurrenceId, string $status, ?string $note): array
    {
        $occurrence = $this->findOccurrenceOrFail($ctx, $occurrenceId);

        if (! Enums::isValid($status, Enums::OBLIGATION_STATUSES)) {
            throw DomainException::badRequest('Unknown obligation status.');
        }

        if ($status === 'completed') {
            // completeOccurrence is the only way to reach 'completed', because
            // it is the only path that enforces the evidence rule and writes
            // the evidence row that a later audit will ask for.
            throw DomainException::badRequest(
                'Record the completion with its evidence rather than setting the status directly.',
                'USE_COMPLETION'
            );
        }

        $from       = (string) $occurrence['status'];
        $contractId = (int) $occurrence['contract_id'];

        $this->pdo->prepare(
            'UPDATE obligation_occurrences
             SET status = ?, completion_note = COALESCE(?, completion_note),
                 completed_at = NULL, completed_by = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$status, $note, $occurrenceId, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'obligation_occurrence', $occurrenceId, 'obligation.status_changed', $contractId, [
            'status' => ['from' => $from, 'to' => $status],
        ]);
        $this->activity->record(
            $ctx,
            $contractId,
            'obligation.status.' . $status,
            sprintf(
                'Obligation "%s" for %s marked %s',
                (string) $occurrence['obligation_title'],
                (string) $occurrence['due_date'],
                Enums::label($status)
            ),
            array_filter(['note' => $note, 'occurrence_id' => $occurrenceId])
        );

        return $this->findOccurrenceOrFail($ctx, $occurrenceId);
    }

    // -----------------------------------------------------------------------
    // The sweep
    // -----------------------------------------------------------------------

    /**
     * Age occurrences into `due` and `overdue`.
     *
     * Written as two set-based statements over the whole environment rather
     * than a row-by-row pass, and it writes no activity or notification rows,
     * so running it hourly costs the same as running it nightly. Only live
     * statuses are touched: a completed, waived or disputed occurrence has an
     * outcome and the passage of time must not overwrite it.
     *
     * @param int|null $cmpId narrow to one company; null sweeps the environment
     * @return array{due: int, overdue: int}
     */
    public function refreshDueStatuses(string $environment, ?int $cmpId = null): array
    {
        // Overdue runs first so that a date already past its grace goes
        // straight there, instead of being counted as a due transition on its
        // way past. The two counts are then genuine, non-overlapping moves.
        $overdue = $this->sweep(
            $environment,
            $cmpId,
            "UPDATE obligation_occurrences
             SET status = 'overdue', updated_at = CURRENT_TIMESTAMP
             WHERE environment = :env
               AND status IN ('upcoming','due')
               AND COALESCE(grace_until, due_date) < CURRENT_DATE"
        );

        $due = $this->sweep(
            $environment,
            $cmpId,
            "UPDATE obligation_occurrences
             SET status = 'due', updated_at = CURRENT_TIMESTAMP
             WHERE environment = :env
               AND status = 'upcoming'
               AND due_date <= CURRENT_DATE"
        );

        $this->rollUpObligationStatuses($environment, $cmpId);

        return ['due' => $due, 'overdue' => $overdue];
    }

    /**
     * Run one sweep statement, optionally narrowed to a company.
     *
     * The company clause is appended as fixed SQL rather than bound as a
     * nullable parameter: `:cmp IS NULL OR cmp_id = :cmp` leaves PostgreSQL
     * unable to infer the parameter's type, and nothing caller-supplied reaches
     * the string.
     */
    private function sweep(string $environment, ?int $cmpId, string $sql): int
    {
        $params = ['env' => $environment];

        if ($cmpId !== null) {
            $sql            .= ' AND cmp_id = :cmp';
            $params['cmp']   = $cmpId;
        }

        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return $st->rowCount();
    }

    /**
     * Carry the worst live occurrence status up onto the obligation row.
     *
     * The repository list shows an obligation's status without opening it, and
     * an obligation reading "upcoming" while one of its occurrences is three
     * weeks overdue is the kind of quiet wrong answer this product cannot
     * afford. Obligations whose status is already an outcome are left alone.
     */
    private function rollUpObligationStatuses(string $environment, ?int $cmpId): void
    {
        $sql = "UPDATE contract_obligations o
                SET status = s.worst, updated_at = CURRENT_TIMESTAMP
                FROM (
                    SELECT obligation_id,
                           CASE
                               WHEN bool_or(status = 'overdue') THEN 'overdue'
                               WHEN bool_or(status = 'due')     THEN 'due'
                               ELSE 'upcoming'
                           END AS worst
                    FROM obligation_occurrences
                    WHERE environment = :env AND status IN ('upcoming','due','overdue')
                    GROUP BY obligation_id
                ) s
                WHERE o.id = s.obligation_id
                  AND o.environment = :env_outer
                  AND o.status IN ('upcoming','due','overdue')
                  AND o.status <> s.worst";

        // Two names for one value: PDO's native prepare turns each named
        // placeholder into its own positional parameter, so reusing :env would
        // be an invalid parameter count rather than a shared binding.
        $params = ['env' => $environment, 'env_outer' => $environment];

        if ($cmpId !== null) {
            $sql          .= ' AND o.cmp_id = :cmp';
            $params['cmp'] = $cmpId;
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed>      $contract
     * @return array<string,mixed>
     */
    private function readFields(Validator $v, TenantContext $ctx, bool $creating, ?array $existing, array $contract): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $intFallback = static fn (string $key): ?int => isset($existing[$key]) && $existing[$key] !== null
            ? (int) $existing[$key]
            : null;

        $boolFallback = static fn (string $key, bool $default): bool => isset($existing[$key])
            ? ContractService::toBool($existing[$key])
            : $default;

        $title = $creating || $v->has('title')
            ? $v->requiredString('title', 255)
            : (string) $fallback('title', '');

        $frequency = $v->optionalEnum('frequency', Enums::OBLIGATION_FREQUENCIES, (string) $fallback('frequency', 'one_time'))
            ?? 'one_time';
        $interval  = $v->optionalInt('custom_interval_days', 1, 3650, $intFallback('custom_interval_days'));

        if ($frequency === 'custom' && $interval === null) {
            // Mirrors ck_obligations_custom_interval. Caught here so the caller
            // gets a field-level message instead of a constraint violation, and
            // so an obligation that would silently never fire cannot be saved.
            $v->fail('custom_interval_days', 'Tell us how many days apart this repeats.');
        }
        if ($frequency !== 'custom') {
            $interval = null;
        }

        $start   = $v->optionalDate('start_date', $fallback('start_date') === null ? null : (string) $fallback('start_date'));
        $end     = $v->optionalDate('end_date', $fallback('end_date') === null ? null : (string) $fallback('end_date'));
        $firstDue = $v->optionalDate('first_due_date', $fallback('first_due_date') === null ? null : (string) $fallback('first_due_date'));

        if ($start !== null && $end !== null && $end < $start) {
            $v->fail('end_date', 'The end date cannot be before the start date.');
        }
        if ($firstDue !== null && $end !== null && $firstDue > $end) {
            $v->fail('first_due_date', 'The first due date is after the obligation ends.');
        }

        $amount   = $v->optionalDecimal('amount', 2, $fallback('amount') === null ? null : (string) $fallback('amount'));
        $currency = $v->optionalString('currency', 3, $fallback('currency') === null ? null : (string) $fallback('currency'));

        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $v->fail('currency', 'Enter a 3-letter currency code, such as INR.');
            }
        }
        // Currency is only meaningful next to an amount, so it is defaulted from
        // the company only when there is a figure for it to denominate — a
        // stray 'INR' on a reporting obligation reads as money that is not owed.
        if ($amount !== null && $currency === null) {
            $currency = $ctx->currency();
        }

        $confidence = $v->optionalDecimal('ai_confidence', 3, $fallback('ai_confidence') === null ? null : (string) $fallback('ai_confidence'));
        if ($confidence !== null && ((float) $confidence < 0.0 || (float) $confidence > 1.0)) {
            $v->fail('ai_confidence', 'Confidence is a value between 0 and 1.');
        }

        $reminders = $v->optionalString('reminder_days', 64, (string) $fallback('reminder_days', '14,7,1')) ?? '14,7,1';

        return [
            'title'                => $title,
            'description'          => $v->optionalString('description', 20000, $fallback('description') === null ? null : (string) $fallback('description')),
            'obligation_type'      => $v->optionalString('obligation_type', 48, (string) $fallback('obligation_type', 'general')) ?? 'general',
            'clause_id'            => $v->optionalId('clause_id') ?? ($v->has('clause_id') ? null : $intFallback('clause_id')),
            'responsible_party'    => $v->optionalEnum('responsible_party', Enums::OBLIGATION_RESPONSIBLE, (string) $fallback('responsible_party', 'company')) ?? 'company',
            'owner_uuid'           => $v->optionalString('owner_uuid', 64, $fallback('owner_uuid') === null ? null : (string) $fallback('owner_uuid')),
            'frequency'            => $frequency,
            'custom_interval_days' => $interval,
            'start_date'           => $start,
            'end_date'             => $end,
            'first_due_date'       => $firstDue,
            'grace_period_days'    => $v->optionalInt('grace_period_days', 0, 3650, $intFallback('grace_period_days')) ?? 0,
            'amount'               => $amount,
            'currency'             => $currency,
            'evidence_required'    => $v->optionalBool('evidence_required', $boolFallback('evidence_required', false)) ?? false,
            'reminder_days'        => implode(',', Dates::reminderLadder($reminders, [14, 7, 1])),
            'escalation_days'      => $v->optionalInt('escalation_days', 0, 3650, $intFallback('escalation_days')),
            'escalate_to_uuid'     => $v->optionalString('escalate_to_uuid', 64, $fallback('escalate_to_uuid') === null ? null : (string) $fallback('escalate_to_uuid')),
            'status'               => $v->optionalEnum('status', Enums::OBLIGATION_STATUSES, (string) $fallback('status', 'upcoming')) ?? 'upcoming',
            'is_ai_extracted'      => $v->optionalBool('is_ai_extracted', $boolFallback('is_ai_extracted', false)) ?? false,
            'ai_confidence'        => $confidence,
            'verification_state'   => $v->optionalEnum('verification_state', Enums::VERIFICATION_STATES, (string) $fallback('verification_state', 'human_verified')) ?? 'human_verified',
            'is_active'            => $v->optionalBool('is_active', $boolFallback('is_active', true)) ?? true,
        ];
    }

    /**
     * @return array<string,mixed>
     * @throws DomainException
     */
    private function contractOrFail(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, status, effective_date, commencement_date, expiry_date
             FROM contracts
             WHERE id = ? AND environment = ? AND cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Contract not found.');
        }

        return $row;
    }

    /**
     * A foreign key would only catch a clause that does not exist at all.
     * Checking the tenant and the contract here is what stops an obligation
     * citing a clause from somebody else's agreement.
     */
    private function assertClauseBelongsToContract(TenantContext $ctx, int $contractId, ?int $clauseId): void
    {
        if ($clauseId === null) {
            return;
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_clauses
             WHERE id = ? AND environment = ? AND cmp_id = ? AND contract_id = ?
             LIMIT 1'
        );
        $st->execute([$clauseId, $ctx->environment, $ctx->cmpId, $contractId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['clause_id' => 'Choose a clause from this contract.']);
        }
    }

    private function assertDocumentUsable(TenantContext $ctx, int $documentId, int $contractId): void
    {
        // A document filed against a request or an amendment has no contract_id
        // yet and is still legitimate evidence, so the contract match is a
        // filter on documents that name one rather than a requirement.
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_documents
             WHERE id = ? AND environment = ? AND cmp_id = ?
               AND (contract_id IS NULL OR contract_id = ?)
             LIMIT 1'
        );
        $st->execute([$documentId, $ctx->environment, $ctx->cmpId, $contractId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['document_id' => 'Choose a document from this contract.']);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateObligation(array $row): array
    {
        foreach (['evidence_required', 'is_ai_extracted', 'is_active'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = ContractService::toBool($row[$key]);
            }
        }

        foreach ([
            'id', 'cmp_id', 'contract_id', 'clause_id', 'custom_interval_days',
            'grace_period_days', 'escalation_days', 'occurrence_count',
            'overdue_count', 'completed_count',
        ] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        $row['reminder_ladder'] = Dates::reminderLadder($row['reminder_days'] ?? null, [14, 7, 1]);

        if (array_key_exists('next_due_date', $row)) {
            $row['days_to_next_due'] = Dates::daysUntil($row['next_due_date']);
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateOccurrence(array $row): array
    {
        if (array_key_exists('evidence_required', $row)) {
            $row['evidence_required'] = ContractService::toBool($row['evidence_required']);
        }

        foreach (['id', 'cmp_id', 'obligation_id', 'contract_id', 'sequence_no', 'last_reminder_days', 'grace_period_days'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        $row['days_to_due'] = Dates::daysUntil($row['due_date'] ?? null);

        // Computed rather than read off `status`, so a list rendered between
        // sweeps still tells the truth about what has slipped.
        $row['is_overdue'] = in_array((string) ($row['status'] ?? ''), self::LIVE_STATUSES, true)
            && Dates::isPast((string) ($row['grace_until'] ?? $row['due_date'] ?? ''));

        return $row;
    }
}
