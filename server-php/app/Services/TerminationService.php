<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Dates;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use App\Support\Validator;
use PDO;

/**
 * Ending a contract early: the record, its approval, the notice served on the
 * counterparty, and the closure.
 *
 * Termination is a staged process rather than a status flip because each stage
 * is separately disputable. Who approved ending the agreement, on what date
 * notice was served, and what was settled are the three questions a termination
 * is argued over, and none of them can be reconstructed from a contract whose
 * status simply became `terminated`.
 *
 * complete() is the only place the contract itself changes, and it goes through
 * ContractService::changeStatus so the transition rules, the audit entry and the
 * timeline are the same ones every other status change gets.
 */
final class TerminationService
{
    /** Statuses a termination can still be edited in. */
    private const EDITABLE_STATUSES = ['draft', 'pending_approval', 'approved', 'notice_issued'];

    /** Statuses a caller may set directly; the rest belong to approve/issueNotice/complete. */
    private const SETTABLE_STATUSES = ['draft', 'pending_approval', 'cancelled'];

    /** A termination in one of these is still in play, and a contract may only have one. */
    private const OPEN_STATUSES = ['draft', 'pending_approval', 'approved', 'notice_issued'];

    private const INITIATING_PARTIES = ['company', 'counterparty', 'mutual'];

    private AuditService $audit;

    private ActivityService $activity;

    private ContractService $contracts;

    private RenewalService $renewals;

    public function __construct(private PDO $pdo)
    {
        $this->audit     = new AuditService($pdo);
        $this->activity  = new ActivityService($pdo);
        $this->contracts = new ContractService($pdo);
        $this->renewals  = new RenewalService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT t.*, c.contract_number, c.title AS contract_title, c.status AS contract_status,
                    c.counterparty_name, c.expiry_date
             FROM contract_terminations t
             JOIN contracts c ON c.id = t.contract_id
             WHERE t.id = ? AND t.environment = ? AND t.cmp_id = ?
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
            throw DomainException::notFound('Termination not found.');
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_terminations
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY created_at DESC, id DESC'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []);
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
        $contract = $this->contractOrFail($ctx, $contractId);

        if (in_array((string) $contract['status'], ['terminated', 'cancelled'], true)) {
            throw DomainException::conflict('This contract has already ended.', 'CONTRACT_CLOSED');
        }

        // Two live terminations of one contract means two notice dates and two
        // settlements for the same ending, and nothing downstream can tell which
        // one is real.
        if ($this->openTerminationId($ctx, $contractId) !== null) {
            throw DomainException::conflict(
                'This contract already has a termination in progress.',
                'TERMINATION_IN_PROGRESS'
            );
        }

        $fields = $this->readFields(new Validator($body), $ctx);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $fields): array {
            $st = $pdo->prepare(
                'INSERT INTO contract_terminations
                 (environment, cmp_id, contract_id, termination_type, reason, initiating_party,
                  requested_date, effective_date, applicable_clause_id, notice_required_days,
                  settlement_amount, settlement_currency, outstanding_obligations,
                  asset_return_required, confidentiality_survives, data_deletion_required,
                  closure_checklist, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)
                 RETURNING id'
            );
            $st->execute([
                $ctx->environment,
                $ctx->cmpId,
                $contractId,
                $fields['termination_type'],
                $fields['reason'],
                $fields['initiating_party'],
                $fields['requested_date'],
                $fields['effective_date'],
                $fields['applicable_clause_id'],
                $fields['notice_required_days'],
                $fields['settlement_amount'],
                $fields['settlement_currency'],
                $fields['outstanding_obligations'],
                $fields['asset_return_required'] ? 'true' : 'false',
                $fields['confidentiality_survives'] ? 'true' : 'false',
                $fields['data_deletion_required'] ? 'true' : 'false',
                json_encode($fields['closure_checklist'], JSON_UNESCAPED_SLASHES),
                $fields['status'],
                $ctx->uuid,
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'termination', $id, 'termination.created', $contractId, [
                'termination_type' => ['from' => null, 'to' => $fields['termination_type']],
                'effective_date'   => ['from' => null, 'to' => $fields['effective_date']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'termination.created',
                sprintf('Termination raised (%s)', Enums::label($fields['termination_type'])),
                ['termination_type' => $fields['termination_type']]
            );

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

        if (! in_array((string) $existing['status'], self::EDITABLE_STATUSES, true)) {
            throw DomainException::conflict(
                'A completed or cancelled termination can no longer be edited.',
                'TERMINATION_LOCKED'
            );
        }

        $fields = $this->readFields(new Validator($body), $ctx, $existing);

        $this->pdo->prepare(
            'UPDATE contract_terminations SET
                termination_type = ?, reason = ?, initiating_party = ?,
                requested_date = ?, effective_date = ?, applicable_clause_id = ?,
                notice_required_days = ?, settlement_amount = ?, settlement_currency = ?,
                outstanding_obligations = ?, asset_return_required = ?,
                confidentiality_survives = ?, data_deletion_required = ?,
                closure_checklist = ?::jsonb, status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $fields['termination_type'],
            $fields['reason'],
            $fields['initiating_party'],
            $fields['requested_date'],
            $fields['effective_date'],
            $fields['applicable_clause_id'],
            $fields['notice_required_days'],
            $fields['settlement_amount'],
            $fields['settlement_currency'],
            $fields['outstanding_obligations'],
            $fields['asset_return_required'] ? 'true' : 'false',
            $fields['confidentiality_survives'] ? 'true' : 'false',
            $fields['data_deletion_required'] ? 'true' : 'false',
            json_encode($fields['closure_checklist'], JSON_UNESCAPED_SLASHES),
            $fields['status'],
            $id,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        $updated = $this->findOrFail($ctx, $id);

        $this->audit->logChanges(
            $ctx,
            'termination',
            $id,
            $existing,
            $updated,
            [
                'termination_type', 'reason', 'initiating_party', 'requested_date',
                'effective_date', 'notice_required_days', 'settlement_amount',
                'settlement_currency', 'status',
            ],
            (int) $existing['contract_id'],
            'termination.updated'
        );

        return $updated;
    }

    /**
     * Approve the decision to terminate.
     *
     * Kept separate from issuing notice: approval is the internal authority to
     * end the agreement, notice is the external act that starts the clock. A
     * company that serves notice it has not approved has a governance problem
     * the record should show.
     *
     * @return array<string,mixed>
     */
    public function approve(TenantContext $ctx, int $id, ?string $note = null): array
    {
        $existing = $this->findOrFail($ctx, $id);
        $status   = (string) $existing['status'];

        if (! in_array($status, ['draft', 'pending_approval'], true)) {
            throw DomainException::conflict(
                'Only a draft or pending termination can be approved.',
                'TERMINATION_NOT_PENDING'
            );
        }

        $this->pdo->prepare(
            'UPDATE contract_terminations
             SET status = \'approved\', approved_by = ?, approved_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$ctx->uuid, $id, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'termination', $id, 'termination.approved', (int) $existing['contract_id'], [
            'status' => ['from' => $status, 'to' => 'approved'],
        ]);
        $this->activity->record(
            $ctx,
            (int) $existing['contract_id'],
            'termination.approved',
            'Termination approved',
            array_filter(['note' => $note])
        );

        return $this->findOrFail($ctx, $id);
    }

    /**
     * Record that notice has been served on the counterparty.
     *
     * The issue date is what every later date is measured from, so it is stored
     * as given rather than as "today" — notice served by post on Friday and
     * recorded on Monday is still Friday's notice.
     *
     * @param array<string,mixed> $body notice_issued_date, notice_document_id
     * @return array<string,mixed>
     */
    public function issueNotice(TenantContext $ctx, int $id, array $body = []): array
    {
        $existing = $this->findOrFail($ctx, $id);
        $status   = (string) $existing['status'];

        if ($status !== 'approved') {
            throw DomainException::conflict(
                'Approve the termination before serving notice.',
                'TERMINATION_NOT_APPROVED'
            );
        }

        $v          = new Validator($body);
        $issuedDate = $v->optionalDate('notice_issued_date', Dates::today());
        $documentId = $v->optionalId('notice_document_id');
        $v->assert();

        $this->assertDocumentBelongsToTenant($ctx, $documentId);

        $noticeDays    = $existing['notice_required_days'] === null ? null : (int) $existing['notice_required_days'];
        $effectiveDate = $existing['effective_date'];
        if ($effectiveDate === null && $noticeDays !== null && $issuedDate !== null) {
            // Where the contract states a notice period and nobody has set an
            // end date, the end date is a consequence of the notice rather than
            // a choice, so it is derived here instead of left blank for someone
            // to guess later.
            $effectiveDate = Dates::addDays($issuedDate, $noticeDays);
        }

        $this->pdo->prepare(
            'UPDATE contract_terminations
             SET status = \'notice_issued\', notice_issued_date = ?, notice_document_id = ?,
                 effective_date = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$issuedDate, $documentId, $effectiveDate, $id, $ctx->environment, $ctx->cmpId]);

        $this->audit->log($ctx, 'termination', $id, 'termination.notice_issued', (int) $existing['contract_id'], [
            'status'             => ['from' => $status, 'to' => 'notice_issued'],
            'notice_issued_date' => ['from' => $existing['notice_issued_date'], 'to' => $issuedDate],
            'effective_date'     => ['from' => $existing['effective_date'], 'to' => $effectiveDate],
        ]);
        $this->activity->record(
            $ctx,
            (int) $existing['contract_id'],
            'termination.notice_issued',
            sprintf('Termination notice issued on %s', (string) $issuedDate),
            ['notice_issued_date' => $issuedDate, 'effective_date' => $effectiveDate]
        );

        return $this->findOrFail($ctx, $id);
    }

    /**
     * Close the termination and end the contract.
     *
     * The contract's status moves through ContractService::changeStatus, never
     * by writing the column here: that method owns the transition graph, the
     * lifecycle stage and the audit entry, and a second path into `terminated`
     * would be a second set of rules to keep in step.
     *
     * @param array<string,mixed> $body effective_date, note
     * @return array<string,mixed>
     */
    public function complete(TenantContext $ctx, int $id, array $body = []): array
    {
        $existing = $this->findOrFail($ctx, $id);
        $status   = (string) $existing['status'];

        if (! in_array($status, ['approved', 'notice_issued'], true)) {
            throw DomainException::conflict(
                'Only an approved termination can be completed.',
                'TERMINATION_NOT_APPROVED'
            );
        }

        $noticeDays = $existing['notice_required_days'] === null ? null : (int) $existing['notice_required_days'];
        if ($noticeDays !== null && $noticeDays > 0 && $existing['notice_issued_date'] === null) {
            throw DomainException::conflict(
                'This termination requires notice; record the notice before completing it.',
                'TERMINATION_NOTICE_REQUIRED'
            );
        }

        $v    = new Validator($body);
        $date = $v->optionalDate('effective_date', $existing['effective_date'] ?? Dates::today());
        $note = $v->optionalText('note', 2000);
        $v->assert();

        $contractId = (int) $existing['contract_id'];

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $contractId, $status, $date, $note, $existing): array {
            $pdo->prepare(
                'UPDATE contract_terminations
                 SET status = \'completed\', effective_date = ?, completed_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$date, $id, $ctx->environment, $ctx->cmpId]);

            $this->contracts->changeStatus(
                $ctx,
                $contractId,
                'terminated',
                $note ?? sprintf('Terminated (%s)', Enums::label((string) $existing['termination_type']))
            );

            // A terminated contract must leave the renewal queue: an item that
            // can never be actioned is the fastest way to teach people to
            // ignore the queue.
            $this->renewals->closeOpenCycles(
                $ctx,
                $contractId,
                sprintf('Contract terminated on %s.', (string) $date)
            );

            $this->audit->log($ctx, 'termination', $id, 'termination.completed', $contractId, [
                'status'         => ['from' => $status, 'to' => 'completed'],
                'effective_date' => ['from' => $existing['effective_date'], 'to' => $date],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'termination.completed',
                sprintf('Contract terminated with effect from %s', (string) $date),
                array_filter(['note' => $note])
            );

            return $this->findOrFail($ctx, $id);
        });
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readFields(Validator $v, TenantContext $ctx, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $type = $existing === null || $v->has('termination_type')
            ? $v->requiredEnum('termination_type', Enums::TERMINATION_TYPES)
            : (string) $fallback('termination_type');

        $requested = $v->optionalDate('requested_date', $fallback('requested_date'));
        $effective = $v->optionalDate('effective_date', $fallback('effective_date'));

        if ($requested !== null && $effective !== null && $effective < $requested) {
            $v->fail('effective_date', 'The termination cannot take effect before it was requested.');
        }

        $amount   = $v->optionalDecimal('settlement_amount', 2, $fallback('settlement_amount'));
        $currency = $v->optionalString('settlement_currency', 3, $fallback('settlement_currency'));
        if ($amount !== null && $currency === null) {
            $currency = $ctx->currency();
        }
        if ($currency !== null && preg_match('/^[A-Z]{3}$/', strtoupper($currency)) !== 1) {
            $v->fail('settlement_currency', 'Enter a 3-letter currency code, such as INR.');
        }

        $status   = $v->optionalEnum('status', self::SETTABLE_STATUSES, (string) $fallback('status', 'draft')) ?? 'draft';
        $clauseId = $v->optionalId('applicable_clause_id') ?? ($v->has('applicable_clause_id') ? null : $fallback('applicable_clause_id'));
        $existingDays = $fallback('notice_required_days');

        $fields = [
            'termination_type'         => $type,
            'reason'                   => $v->optionalText('reason', 10000, $fallback('reason')),
            'initiating_party'         => $v->optionalEnum('initiating_party', self::INITIATING_PARTIES, (string) $fallback('initiating_party', 'company')) ?? 'company',
            'requested_date'           => $requested,
            'effective_date'           => $effective,
            'applicable_clause_id'     => $clauseId === null ? null : (int) $clauseId,
            'notice_required_days'     => $v->optionalInt('notice_required_days', 0, 3650, $existingDays === null ? null : (int) $existingDays),
            'settlement_amount'        => $amount,
            'settlement_currency'      => $currency === null ? null : strtoupper($currency),
            'outstanding_obligations'  => $v->optionalText('outstanding_obligations', 10000, $fallback('outstanding_obligations')),
            'asset_return_required'    => $v->optionalBool('asset_return_required', ContractService::toBool($fallback('asset_return_required', false))) ?? false,
            'confidentiality_survives' => $v->optionalBool('confidentiality_survives', ContractService::toBool($fallback('confidentiality_survives', true))) ?? true,
            'data_deletion_required'   => $v->optionalBool('data_deletion_required', ContractService::toBool($fallback('data_deletion_required', false))) ?? false,
            'closure_checklist'        => $v->has('closure_checklist') ? $v->optionalArray('closure_checklist') : self::decodeList($fallback('closure_checklist')),
            'status'                   => $status,
        ];

        // Asserted only once every rule above has run, so a caller with three
        // bad fields is told about three, not about the first one three times.
        $v->assert();

        $this->assertClauseBelongsToTenant($ctx, $fields['applicable_clause_id']);

        return $fields;
    }

    private function openTerminationId(TenantContext $ctx, int $contractId): ?int
    {
        $names  = [];
        $params = [$contractId, $ctx->environment, $ctx->cmpId];
        foreach (self::OPEN_STATUSES as $status) {
            $names[]  = '?';
            $params[] = $status;
        }

        $st = $this->pdo->prepare(
            'SELECT id FROM contract_terminations
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
               AND status IN (' . implode(', ', $names) . ')
             LIMIT 1'
        );
        $st->execute($params);
        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<string,mixed> @throws DomainException */
    private function contractOrFail(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, status, expiry_date, notice_period_days, currency, archived_at
             FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
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
     * Checking the tenant is what stops one company citing another company's
     * termination clause as its authority to terminate.
     */
    private function assertClauseBelongsToTenant(TenantContext $ctx, ?int $clauseId): void
    {
        if ($clauseId === null) {
            return;
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_clauses WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$clauseId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['applicable_clause_id' => 'Choose a clause from this contract.']);
        }
    }

    private function assertDocumentBelongsToTenant(TenantContext $ctx, ?int $documentId): void
    {
        if ($documentId === null) {
            return;
        }

        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_documents WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$documentId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed(['notice_document_id' => 'Choose a document from this contract.']);
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id', 'cmp_id', 'contract_id', 'applicable_clause_id', 'notice_required_days', 'notice_document_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        foreach (['asset_return_required', 'confidentiality_survives', 'data_deletion_required'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = ContractService::toBool($row[$key]);
            }
        }

        if (array_key_exists('closure_checklist', $row)) {
            $row['closure_checklist'] = self::decodeList($row['closure_checklist']);
        }

        $row['days_to_effective'] = Dates::daysUntil($row['effective_date'] ?? null);

        return $row;
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
}
