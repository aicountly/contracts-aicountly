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
 * Contract milestones: the dated, one-off commitments an agreement hangs on —
 * go-live, first delivery, the acceptance certificate, the final payment.
 *
 * A milestone is not an obligation with a frequency of one. An obligation is a
 * standing rule that produces occurrences and carries a compliance history; a
 * milestone is a single point on the contract's timeline that either happened
 * or did not, and that other milestones can be made to wait for. Modelling them
 * separately is what lets the timeline view read as a plan rather than as a
 * list of reporting duties.
 *
 * Every query filters `environment` AND `cmp_id` from the TenantContext, never
 * from request input. `refreshDueStatuses` takes them as arguments instead,
 * because the sweep that calls it has no acting user.
 */
final class MilestoneService
{
    /** Fields whose change is worth an audit row. */
    private const AUDITED_FIELDS = [
        'title', 'description', 'milestone_type', 'clause_id', 'due_date',
        'owner_uuid', 'amount', 'currency', 'status', 'depends_on_id', 'reminder_days',
    ];

    /** Statuses a milestone is still live in — everything else is an outcome. */
    private const OPEN_STATUSES = ['pending', 'in_progress'];

    /**
     * How far a dependency chain is walked when looking for a cycle.
     *
     * A real contract plan is nowhere near this deep; the cap exists so a chain
     * corrupted by a concurrent edit cannot spin the request forever.
     */
    private const MAX_DEPENDENCY_DEPTH = 32;

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
     * One milestone, or null when it does not exist *for this tenant*.
     *
     * @return array<string,mixed>|null
     */
    public function find(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT m.*,
                    c.contract_number,
                    c.title AS contract_title,
                    d.title AS depends_on_title,
                    d.status AS depends_on_status,
                    d.due_date AS depends_on_due_date
             FROM contract_milestones m
             JOIN contracts c ON c.id = m.contract_id
             LEFT JOIN contract_milestones d ON d.id = m.depends_on_id
             WHERE m.id = ? AND m.environment = ? AND m.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $row = $this->find($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Milestone not found.');
        }

        return $row;
    }

    /**
     * A contract's milestones in plan order.
     *
     * @return list<array<string,mixed>>
     */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT m.*, d.title AS depends_on_title, d.status AS depends_on_status
             FROM contract_milestones m
             LEFT JOIN contract_milestones d ON d.id = m.depends_on_id
             WHERE m.environment = ? AND m.cmp_id = ? AND m.contract_id = ?
             ORDER BY m.due_date ASC, m.id ASC'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        return array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []);
    }

    /**
     * Milestone counts by status for one contract.
     *
     * @return array<string,int>
     */
    public function summaryForContract(TenantContext $ctx, int $contractId): array
    {
        $summary = array_fill_keys(Enums::MILESTONE_STATUSES, 0);

        $st = $this->pdo->prepare(
            'SELECT status, COUNT(*) AS n
             FROM contract_milestones
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

        $summary['total'] = $total;

        return $summary;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed> the created milestone
     */
    public function create(TenantContext $ctx, int $contractId, array $body): array
    {
        $this->contractOrFail($ctx, $contractId);

        $v      = new Validator($body);
        $fields = $this->readFields($v, $ctx, true, null);
        $v->assert();

        $this->assertClauseBelongsToContract($ctx, $contractId, $fields['clause_id']);
        $this->assertDependencyUsable($ctx, $contractId, null, $fields['depends_on_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $fields): array {
            $st = $pdo->prepare(
                'INSERT INTO contract_milestones
                 (environment, cmp_id, contract_id, clause_id, title, description, milestone_type,
                  due_date, owner_uuid, amount, currency, status, depends_on_id,
                  reminder_days, is_ai_extracted, created_by)
                 VALUES
                 (:env, :cmp, :contract, :clause, :title, :descr, :type,
                  :due, :owner, :amount, :currency, :status, :depends,
                  :reminders, :ai, :actor)
                 RETURNING id'
            );
            $st->execute([
                'env'       => $ctx->environment,
                'cmp'       => $ctx->cmpId,
                'contract'  => $contractId,
                'clause'    => $fields['clause_id'],
                'title'     => $fields['title'],
                'descr'     => $fields['description'],
                'type'      => $fields['milestone_type'],
                'due'       => $fields['due_date'],
                'owner'     => $fields['owner_uuid'],
                'amount'    => $fields['amount'],
                'currency'  => $fields['currency'],
                'status'    => $fields['status'],
                'depends'   => $fields['depends_on_id'],
                'reminders' => $fields['reminder_days'],
                'ai'        => $fields['is_ai_extracted'] ? 'true' : 'false',
                'actor'     => $ctx->uuid,
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'milestone', $id, 'milestone.created', $contractId, [
                'title'    => ['from' => null, 'to' => $fields['title']],
                'due_date' => ['from' => null, 'to' => $fields['due_date']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'milestone.created',
                sprintf('Milestone "%s" set for %s', $fields['title'], $fields['due_date']),
                ['milestone_id' => $id]
            );

            $created = $this->find($ctx, $id);
            if ($created === null) {
                throw new DomainException('The milestone was created but could not be read back.', 'CREATE_FAILED', 500);
            }

            return $created;
        });
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(TenantContext $ctx, int $milestoneId, array $body): array
    {
        $existing   = $this->findOrFail($ctx, $milestoneId);
        $contractId = (int) $existing['contract_id'];

        $v      = new Validator($body);
        $fields = $this->readFields($v, $ctx, false, $existing);
        $v->assert();

        $this->assertClauseBelongsToContract($ctx, $contractId, $fields['clause_id']);
        $this->assertDependencyUsable($ctx, $contractId, $milestoneId, $fields['depends_on_id']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $milestoneId, $contractId, $existing, $fields): array {
            $st = $pdo->prepare(
                'UPDATE contract_milestones SET
                    clause_id = :clause, title = :title, description = :descr,
                    milestone_type = :type, due_date = :due, owner_uuid = :owner,
                    amount = :amount, currency = :currency, status = :status,
                    depends_on_id = :depends, reminder_days = :reminders,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            );
            $st->execute([
                'clause'    => $fields['clause_id'],
                'title'     => $fields['title'],
                'descr'     => $fields['description'],
                'type'      => $fields['milestone_type'],
                'due'       => $fields['due_date'],
                'owner'     => $fields['owner_uuid'],
                'amount'    => $fields['amount'],
                'currency'  => $fields['currency'],
                'status'    => $fields['status'],
                'depends'   => $fields['depends_on_id'],
                'reminders' => $fields['reminder_days'],
                'id'        => $milestoneId,
                'env'       => $ctx->environment,
                'cmp'       => $ctx->cmpId,
            ]);

            $updated = $this->findOrFail($ctx, $milestoneId);

            $this->audit->logChanges($ctx, 'milestone', $milestoneId, $existing, $updated, self::AUDITED_FIELDS, $contractId);
            $this->activity->record(
                $ctx,
                $contractId,
                'milestone.updated',
                sprintf('Milestone "%s" updated', (string) $updated['title']),
                ['milestone_id' => $milestoneId]
            );

            return $updated;
        });
    }

    /**
     * Delete a milestone that never completed.
     *
     * A completed milestone is a fact about the contract — the acceptance date
     * a payment was released against — and removing it would leave the payment
     * unexplained. Cancelling records the same intent without the erasure.
     */
    public function delete(TenantContext $ctx, int $milestoneId): void
    {
        $existing   = $this->findOrFail($ctx, $milestoneId);
        $contractId = (int) $existing['contract_id'];

        if ((string) $existing['status'] === 'completed') {
            throw DomainException::conflict(
                'This milestone has been completed. Cancel it instead of deleting it.',
                'DELETE_NOT_ALLOWED'
            );
        }

        // Audited before the delete: afterwards there is no row to reference,
        // and the audit table is append-only so the record survives.
        $this->audit->log($ctx, 'milestone', $milestoneId, 'milestone.deleted', $contractId, [
            'title' => ['from' => $existing['title'], 'to' => null],
        ]);

        // Dependants are released rather than cascaded: the schema's ON DELETE
        // SET NULL does this, and it is the right answer — a later milestone
        // does not stop existing because the one before it was removed.
        $this->pdo->prepare('DELETE FROM contract_milestones WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$milestoneId, $ctx->environment, $ctx->cmpId]);

        $this->activity->record(
            $ctx,
            $contractId,
            'milestone.deleted',
            sprintf('Milestone "%s" removed', (string) $existing['title'])
        );
    }

    /**
     * Mark a milestone reached.
     *
     * @param array<string,mixed> $body completion_note (recorded on the timeline)
     *                                  and an optional completed_on date for a
     *                                  milestone being recorded after the fact
     * @return array<string,mixed>
     */
    public function complete(TenantContext $ctx, int $milestoneId, array $body = []): array
    {
        $existing   = $this->findOrFail($ctx, $milestoneId);
        $contractId = (int) $existing['contract_id'];

        if ((string) $existing['status'] === 'completed') {
            throw DomainException::conflict('This milestone is already recorded as completed.', 'ALREADY_COMPLETED');
        }
        if ((string) $existing['status'] === 'cancelled') {
            throw DomainException::conflict('A cancelled milestone cannot be completed.', 'MILESTONE_CANCELLED');
        }

        // The dependency is a plan constraint, not decoration: signing off
        // acceptance before delivery is recorded is exactly the sequence error
        // the field exists to catch.
        if ($existing['depends_on_id'] !== null && (string) ($existing['depends_on_status'] ?? '') !== 'completed') {
            throw DomainException::conflict(
                sprintf('Complete "%s" first — this milestone depends on it.', (string) ($existing['depends_on_title'] ?? 'the milestone before it')),
                'DEPENDENCY_INCOMPLETE'
            );
        }

        $v           = new Validator($body);
        $note        = $v->optionalString('completion_note', 20000);
        $completedOn = $v->optionalDate('completed_on');
        $v->assert();

        $from = (string) $existing['status'];

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $milestoneId, $contractId, $existing, $from, $note, $completedOn): array {
            $pdo->prepare(
                "UPDATE contract_milestones
                 SET status = 'completed',
                     completed_at = COALESCE(CAST(? AS TIMESTAMP), CURRENT_TIMESTAMP),
                     completed_by = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?"
            )->execute([$completedOn, $ctx->uuid, $milestoneId, $ctx->environment, $ctx->cmpId]);

            $this->audit->log($ctx, 'milestone', $milestoneId, 'milestone.completed', $contractId, [
                'status' => ['from' => $from, 'to' => 'completed'],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'milestone.completed',
                sprintf('Milestone "%s" completed', (string) $existing['title']),
                array_filter(['milestone_id' => $milestoneId, 'note' => $note])
            );

            return $this->findOrFail($ctx, $milestoneId);
        });
    }

    // -----------------------------------------------------------------------
    // The sweep
    // -----------------------------------------------------------------------

    /**
     * Age milestones whose date has gone by into `missed`.
     *
     * One set-based statement that writes nothing but the status, so running it
     * hourly costs what running it nightly costs. `in_progress` is deliberately
     * left alone: somebody is working on it and saying they missed it while
     * they are mid-delivery is a worse answer than saying nothing.
     *
     * @param int|null $cmpId narrow to one company; null sweeps the environment
     * @return array{missed: int}
     */
    public function refreshDueStatuses(string $environment, ?int $cmpId = null): array
    {
        // The company clause is appended as fixed SQL rather than bound as a
        // nullable parameter: `:cmp IS NULL OR cmp_id = :cmp` leaves PostgreSQL
        // unable to infer the parameter's type, and nothing caller-supplied
        // reaches the string.
        $sql = "UPDATE contract_milestones
                SET status = 'missed', updated_at = CURRENT_TIMESTAMP
                WHERE environment = :env
                  AND status = 'pending'
                  AND due_date < CURRENT_DATE";

        $params = ['env' => $environment];

        if ($cmpId !== null) {
            $sql          .= ' AND cmp_id = :cmp';
            $params['cmp'] = $cmpId;
        }

        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return ['missed' => $st->rowCount()];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readFields(Validator $v, TenantContext $ctx, bool $creating, ?array $existing): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $intFallback = static fn (string $key): ?int => isset($existing[$key]) && $existing[$key] !== null
            ? (int) $existing[$key]
            : null;

        $title = $creating || $v->has('title')
            ? $v->requiredString('title', 255)
            : (string) $fallback('title', '');

        $due = $creating || $v->has('due_date')
            ? $v->requiredDate('due_date')
            : (string) $fallback('due_date', '');

        $amount   = $v->optionalDecimal('amount', 2, $fallback('amount') === null ? null : (string) $fallback('amount'));
        $currency = $v->optionalString('currency', 3, $fallback('currency') === null ? null : (string) $fallback('currency'));

        if ($currency !== null) {
            $currency = strtoupper($currency);
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                $v->fail('currency', 'Enter a 3-letter currency code, such as INR.');
            }
        }
        // A payment milestone carries money; a delivery milestone does not, so
        // the company currency is only filled in where there is a figure for it
        // to denominate.
        if ($amount !== null && $currency === null) {
            $currency = $ctx->currency();
        }

        $reminders = $v->optionalString('reminder_days', 64, (string) $fallback('reminder_days', '14,7,1')) ?? '14,7,1';

        return [
            'title'           => $title,
            'description'     => $v->optionalString('description', 20000, $fallback('description') === null ? null : (string) $fallback('description')),
            'milestone_type'  => $v->optionalString('milestone_type', 48, (string) $fallback('milestone_type', 'general')) ?? 'general',
            'clause_id'       => $v->optionalId('clause_id') ?? ($v->has('clause_id') ? null : $intFallback('clause_id')),
            'due_date'        => $due,
            'owner_uuid'      => $v->optionalString('owner_uuid', 64, $fallback('owner_uuid') === null ? null : (string) $fallback('owner_uuid')),
            'amount'          => $amount,
            'currency'        => $currency,
            'status'          => $v->optionalEnum('status', Enums::MILESTONE_STATUSES, (string) $fallback('status', 'pending')) ?? 'pending',
            'depends_on_id'   => $v->optionalId('depends_on_id') ?? ($v->has('depends_on_id') ? null : $intFallback('depends_on_id')),
            'reminder_days'   => implode(',', Dates::reminderLadder($reminders, [14, 7, 1])),
            'is_ai_extracted' => $v->optionalBool('is_ai_extracted', isset($existing['is_ai_extracted']) ? ContractService::toBool($existing['is_ai_extracted']) : false) ?? false,
        ];
    }

    /**
     * @return array<string,mixed>
     * @throws DomainException
     */
    private function contractOrFail(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, status FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Contract not found.');
        }

        return $row;
    }

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

    /**
     * A milestone may only wait on one from the same contract, and never on
     * something that is already waiting on it.
     *
     * ck_milestones_self_dep catches the direct case, but a two-hop cycle would
     * satisfy every constraint in the schema and leave both milestones
     * permanently uncompletable.
     */
    private function assertDependencyUsable(TenantContext $ctx, int $contractId, ?int $milestoneId, ?int $dependsOnId): void
    {
        if ($dependsOnId === null) {
            return;
        }

        if ($milestoneId !== null && $dependsOnId === $milestoneId) {
            throw new ValidationFailed(['depends_on_id' => 'A milestone cannot depend on itself.']);
        }

        $st = $this->pdo->prepare(
            'SELECT depends_on_id FROM contract_milestones
             WHERE id = ? AND environment = ? AND cmp_id = ? AND contract_id = ?
             LIMIT 1'
        );
        $st->execute([$dependsOnId, $ctx->environment, $ctx->cmpId, $contractId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw new ValidationFailed(['depends_on_id' => 'Choose a milestone from this contract.']);
        }

        if ($milestoneId === null) {
            return;
        }

        $cursor = $row['depends_on_id'] === null ? null : (int) $row['depends_on_id'];
        for ($hop = 0; $cursor !== null && $hop < self::MAX_DEPENDENCY_DEPTH; $hop++) {
            if ($cursor === $milestoneId) {
                throw new ValidationFailed(['depends_on_id' => 'That would make two milestones wait on each other.']);
            }

            $st->execute([$cursor, $ctx->environment, $ctx->cmpId, $contractId]);
            $next   = $st->fetch();
            $cursor = is_array($next) && $next['depends_on_id'] !== null ? (int) $next['depends_on_id'] : null;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        if (array_key_exists('is_ai_extracted', $row)) {
            $row['is_ai_extracted'] = ContractService::toBool($row['is_ai_extracted']);
        }

        foreach (['id', 'cmp_id', 'contract_id', 'clause_id', 'depends_on_id', 'last_reminder_days'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        $row['reminder_ladder'] = Dates::reminderLadder($row['reminder_days'] ?? null, [14, 7, 1]);
        $row['days_to_due']     = Dates::daysUntil($row['due_date'] ?? null);

        // Computed rather than read off `status`, so the timeline is right
        // between sweeps instead of a day behind the cron.
        $row['is_overdue'] = in_array((string) ($row['status'] ?? ''), self::OPEN_STATUSES, true)
            && Dates::isPast($row['due_date'] ?? null);

        return $row;
    }
}
