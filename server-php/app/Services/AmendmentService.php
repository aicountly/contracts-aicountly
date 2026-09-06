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
 * Amendments: the only sanctioned way an executed contract's terms change.
 *
 * The rule the whole class exists to hold: applying an amendment writes the new
 * values onto the contract *and* keeps the amendment row, with
 * `affected_fields` recording {field: {from, to}} and an audit entry per field.
 * The contract row is therefore only ever the current position — "what did we
 * agree to in March, before amendment 2" is answered from the amendment chain
 * and the audit trail, and neither is ever overwritten.
 *
 * Only the fields in AMENDABLE can be changed this way, and that list is also
 * what makes the UPDATE safe: the column names it interpolates come from the
 * constant, never from the caller's JSON keys.
 */
final class AmendmentService
{
    /**
     * Contract columns an amendment may change.
     *
     * Deliberately the negotiated terms only. Ownership, department and status
     * are operational facts about how the company runs the contract, not things
     * the counterparty agreed to, and moving them through an amendment would
     * put internal admin into the legal record.
     */
    private const AMENDABLE = [
        'title', 'description', 'effective_date', 'commencement_date', 'expiry_date',
        'renewal_type', 'renewal_frequency', 'auto_renewal', 'notice_period_days',
        'governing_law', 'jurisdiction', 'currency', 'total_value', 'recurring_value',
        'payment_frequency', 'billing_frequency', 'commercial_summary',
        'counterparty_name', 'notes',
    ];

    /** Statuses an amendment can still be edited in. */
    private const EDITABLE_STATUSES = ['draft', 'under_review', 'awaiting_approval', 'awaiting_signature'];

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

    /** @return array<string,mixed>|null */
    public function find(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT a.*, c.contract_number, c.title AS contract_title, c.status AS contract_status
             FROM contract_amendments a
             JOIN contracts c ON c.id = a.contract_id
             WHERE a.id = ? AND a.environment = ? AND a.cmp_id = ?
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
            throw DomainException::notFound('Amendment not found.');
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_amendments
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY amendment_no ASC'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_map(fn (array $r): array => $this->hydrate($r), $st->fetchAll() ?: []);
    }

    /**
     * The contract as it currently stands, with the amendment behind each change.
     *
     * Amendments are ordered by effective date and then by number: two
     * amendments effective the same day still have to overlay in a defined
     * order, and the number is the order they were agreed in.
     *
     * @return array{contract_id: int, base: array<string,mixed>, current: array<string,mixed>,
     *               overrides: array<string,array<string,mixed>>, amendments: list<array<string,mixed>>}
     */
    public function effectivePosition(TenantContext $ctx, int $contractId): array
    {
        $contract = $this->contractOrFail($ctx, $contractId);

        $base = [];
        foreach (self::AMENDABLE as $field) {
            $base[$field] = $contract[$field] ?? null;
        }
        $base['auto_renewal'] = ContractService::toBool($contract['auto_renewal'] ?? false);

        $st = $this->pdo->prepare(
            'SELECT id, amendment_no, title, effective_date, execution_date, applied_at, affected_fields
             FROM contract_amendments
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND status = \'executed\'
             ORDER BY effective_date ASC, amendment_no ASC'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $executed = $st->fetchAll() ?: [];

        $current    = $base;
        $overrides  = [];
        $amendments = [];

        foreach ($executed as $row) {
            $fields = self::decodeJson($row['affected_fields'] ?? null);
            $names  = [];

            foreach ($fields as $field => $change) {
                if (! in_array($field, self::AMENDABLE, true) || ! is_array($change)) {
                    continue;
                }

                $current[$field]   = $change['to'] ?? null;
                $names[]           = $field;
                // Last writer wins, and the loop is in effect order, so the
                // entry left standing is the amendment that actually decides
                // the field today.
                $overrides[$field] = [
                    'amendment_id'   => (int) $row['id'],
                    'amendment_no'   => (int) $row['amendment_no'],
                    'title'          => $row['title'],
                    'effective_date' => $row['effective_date'],
                    'from'           => $change['from'] ?? null,
                    'to'             => $change['to'] ?? null,
                ];
            }

            $amendments[] = [
                'id'             => (int) $row['id'],
                'amendment_no'   => (int) $row['amendment_no'],
                'title'          => $row['title'],
                'effective_date' => $row['effective_date'],
                'execution_date' => $row['execution_date'],
                'applied_at'     => $row['applied_at'],
                'fields'         => $names,
            ];
        }

        return [
            'contract_id' => $contractId,
            'base'        => $base,
            'current'     => $current,
            'overrides'   => $overrides,
            'amendments'  => $amendments,
        ];
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
        $fields   = $this->readFields(new Validator($body), $body, true);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $contract, $fields): array {
            $number = $this->allocateNumber($ctx, $contractId);

            $st = $pdo->prepare(
                'INSERT INTO contract_amendments
                 (environment, cmp_id, contract_id, amendment_no, title, description,
                  effective_date, execution_date, status, affected_fields, affected_clauses,
                  affected_commercials, affected_obligations, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, ?)
                 RETURNING id'
            );
            $st->execute([
                $ctx->environment,
                $ctx->cmpId,
                $contractId,
                $number,
                $fields['title'],
                $fields['description'],
                $fields['effective_date'],
                $fields['execution_date'],
                $fields['status'],
                self::encodeObject($this->stampOriginals($contract, $fields['affected_fields'])),
                self::encodeList($fields['affected_clauses']),
                self::encodeObject($fields['affected_commercials']),
                self::encodeList($fields['affected_obligations']),
                $ctx->uuid,
            ]);

            $id = (int) $st->fetchColumn();

            $this->audit->log($ctx, 'amendment', $id, 'amendment.created', $contractId, [
                'amendment_no' => ['from' => null, 'to' => $number],
                'title'        => ['from' => null, 'to' => $fields['title']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'amendment.created',
                sprintf('Amendment %d drafted: %s', $number, $fields['title']),
                ['amendment_no' => $number]
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

        // An executed amendment has already been written onto the contract;
        // editing it afterwards would leave the record and the contract saying
        // different things about the same agreement.
        if (! in_array((string) $existing['status'], self::EDITABLE_STATUSES, true)) {
            throw DomainException::conflict(
                'This amendment has been executed or cancelled and can no longer be edited.',
                'AMENDMENT_LOCKED'
            );
        }

        $contract = $this->contractOrFail($ctx, (int) $existing['contract_id']);
        $fields   = $this->readFields(new Validator($body), $body, false, $existing);

        $this->pdo->prepare(
            'UPDATE contract_amendments
             SET title = ?, description = ?, effective_date = ?, execution_date = ?,
                 status = ?, affected_fields = ?::jsonb, affected_clauses = ?::jsonb,
                 affected_commercials = ?::jsonb, affected_obligations = ?::jsonb,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $fields['title'],
            $fields['description'],
            $fields['effective_date'],
            $fields['execution_date'],
            $fields['status'],
            self::encodeObject($this->stampOriginals($contract, $fields['affected_fields'])),
            self::encodeList($fields['affected_clauses']),
            self::encodeObject($fields['affected_commercials']),
            self::encodeList($fields['affected_obligations']),
            $id,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        $updated = $this->findOrFail($ctx, $id);

        $this->audit->logChanges(
            $ctx,
            'amendment',
            $id,
            $existing,
            $updated,
            ['title', 'description', 'effective_date', 'execution_date', 'status'],
            (int) $existing['contract_id'],
            'amendment.updated'
        );

        return $updated;
    }

    /**
     * Delete a draft amendment.
     *
     * Only a draft. Anything that has been through review is part of the
     * negotiation record; cancelling it says so, deleting it pretends the
     * conversation never happened.
     */
    public function delete(TenantContext $ctx, int $id): void
    {
        $existing = $this->findOrFail($ctx, $id);

        if ((string) $existing['status'] !== 'draft') {
            throw DomainException::conflict(
                'Only a draft amendment can be deleted. Cancel this one instead.',
                'DELETE_NOT_ALLOWED'
            );
        }

        // Audited before the delete: afterwards there is no row to reference,
        // and the audit table is append-only so the record survives.
        $this->audit->log($ctx, 'amendment', $id, 'amendment.deleted', (int) $existing['contract_id'], [
            'amendment_no' => ['from' => $existing['amendment_no'], 'to' => null],
            'title'        => ['from' => $existing['title'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM contract_amendments WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$id, $ctx->environment, $ctx->cmpId]);
    }

    /**
     * Execute an amendment: write its terms onto the contract.
     *
     * `from` is re-read from the live contract inside the transaction rather
     * than trusted from the draft. A draft written in March and executed in June
     * would otherwise record a "from" value that stopped being true in April,
     * and that value is what a dispute reads as the previous position.
     *
     * @return array<string,mixed> the executed amendment, `affected_fields` carrying every from/to
     */
    public function apply(TenantContext $ctx, int $amendmentId): array
    {
        $amendment = $this->findOrFail($ctx, $amendmentId);

        if ((string) $amendment['status'] === 'executed') {
            throw DomainException::conflict('This amendment has already been applied.', 'AMENDMENT_ALREADY_APPLIED');
        }
        if ((string) $amendment['status'] === 'cancelled') {
            throw DomainException::conflict('A cancelled amendment cannot be applied.', 'AMENDMENT_CANCELLED');
        }
        if ($amendment['effective_date'] === null) {
            throw new ValidationFailed(['effective_date' => 'An amendment needs an effective date before it can be applied.']);
        }

        $changes = $amendment['affected_fields'];
        if (! is_array($changes) || $changes === []) {
            throw DomainException::conflict(
                'This amendment does not change any contract field.',
                'AMENDMENT_EMPTY'
            );
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $amendmentId, $amendment, $changes): array {
            $contractId = (int) $amendment['contract_id'];
            $contract   = $this->contractOrFail($ctx, $contractId, true);

            $status = (string) $contract['status'];
            if (in_array($status, ['terminated', 'cancelled'], true)) {
                throw DomainException::conflict(
                    'This contract has ended; an amendment cannot be applied to it.',
                    'CONTRACT_CLOSED'
                );
            }

            $assignments = [];
            $params      = [];
            $applied     = [];

            foreach ($changes as $field => $change) {
                if (! in_array($field, self::AMENDABLE, true)) {
                    throw new ValidationFailed(['affected_fields' => "'{$field}' is not a field an amendment can change."]);
                }

                $to   = is_array($change) ? ($change['to'] ?? null) : $change;
                $from = $contract[$field] ?? null;
                if ($field === 'auto_renewal') {
                    $from = ContractService::toBool($from);
                }

                $assignments[]        = "{$field} = :f_{$field}";
                $params["f_{$field}"] = $field === 'auto_renewal'
                    ? ($to === true ? 'true' : 'false')
                    : $to;

                $applied[$field] = ['from' => $from, 'to' => $to];
            }

            $effectiveExpiry = array_key_exists('expiry_date', $applied)
                ? $applied['expiry_date']['to']
                : self::asDate($contract['expiry_date'] ?? null);
            $effectiveNotice = array_key_exists('notice_period_days', $applied)
                ? $applied['notice_period_days']['to']
                : $contract['notice_period_days'];

            // notice_deadline is derived and indexed, and the renewal sweep
            // reads it. An amendment that moves the expiry date without moving
            // it would leave the sweep counting down to a date that no longer
            // exists in the agreement.
            $assignments[]              = 'notice_deadline = :f_notice_deadline';
            $params['f_notice_deadline'] = Dates::noticeDeadline(
                is_string($effectiveExpiry) ? $effectiveExpiry : null,
                $effectiveNotice === null ? null : (int) $effectiveNotice
            );

            $sql = 'UPDATE contracts SET ' . implode(', ', $assignments)
                 . ', updated_by = :actor, updated_at = CURRENT_TIMESTAMP'
                 . ' WHERE id = :id AND environment = :env AND cmp_id = :cmp';

            $pdo->prepare($sql)->execute($params + [
                'actor' => $ctx->uuid,
                'id'    => $contractId,
                'env'   => $ctx->environment,
                'cmp'   => $ctx->cmpId,
            ]);

            $pdo->prepare(
                'UPDATE contract_amendments
                 SET status = \'executed\', applied_at = CURRENT_TIMESTAMP, applied_by = ?,
                     affected_fields = ?::jsonb, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$ctx->uuid, self::encodeObject($applied), $amendmentId, $ctx->environment, $ctx->cmpId]);

            // One audit row per field, on the contract, so the contract's own
            // trail reads as the sequence of values it has held — the amendment
            // summary below says which document did it.
            foreach ($applied as $field => $change) {
                $this->audit->log(
                    $ctx,
                    'contract',
                    $contractId,
                    'contract.amended',
                    $contractId,
                    null,
                    $field,
                    $change['from'],
                    $change['to']
                );
            }

            $this->audit->log($ctx, 'amendment', $amendmentId, 'amendment.applied', $contractId, $applied);
            $this->activity->record(
                $ctx,
                $contractId,
                'amendment.applied',
                sprintf(
                    'Amendment %d applied, effective %s (%s)',
                    (int) $amendment['amendment_no'],
                    (string) $amendment['effective_date'],
                    implode(', ', array_keys($applied))
                ),
                ['amendment_no' => (int) $amendment['amendment_no'], 'fields' => array_keys($applied)]
            );

            return $this->findOrFail($ctx, $amendmentId);
        });
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Take the next amendment number for a contract.
     *
     * The contract row is locked first: `uq_amendment_no` would catch two
     * simultaneous creates, but as a failed request rather than as amendments 3
     * and 4, and losing a drafter's work to a race is not a fair trade for
     * skipping one lock.
     */
    private function allocateNumber(TenantContext $ctx, int $contractId): int
    {
        $this->pdo->prepare(
            'SELECT id FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? FOR UPDATE'
        )->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        $st = $this->pdo->prepare(
            'SELECT COALESCE(MAX(amendment_no), 0) + 1 FROM contract_amendments
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return (int) $st->fetchColumn();
    }

    /**
     * @param array<string,mixed>      $body
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readFields(Validator $v, array $body, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $title = $creating || $v->has('title')
            ? $v->requiredString('title', 255)
            : (string) $fallback('title', '');

        $status = $v->optionalEnum('status', Enums::AMENDMENT_STATUSES, (string) $fallback('status', 'draft')) ?? 'draft';

        // `executed` is reached by apply(), which is what writes the terms onto
        // the contract. Allowing it as a plain status edit would produce an
        // amendment the record says is in force and the contract has never seen.
        if ($status === 'executed') {
            $v->fail('status', 'Apply the amendment to execute it.');
        }

        $fields = [
            'title'                => $title,
            'description'          => $v->optionalText('description', 20000, $fallback('description')),
            'effective_date'       => $v->optionalDate('effective_date', $fallback('effective_date')),
            'execution_date'       => $v->optionalDate('execution_date', $fallback('execution_date')),
            'status'               => $status,
            'affected_clauses'     => $v->has('affected_clauses') ? $v->optionalArray('affected_clauses') : self::decodeList($fallback('affected_clauses')),
            'affected_commercials' => $v->has('affected_commercials') ? $v->optionalObject('affected_commercials') : self::decodeJson($fallback('affected_commercials')),
            'affected_obligations' => $v->has('affected_obligations') ? $v->optionalArray('affected_obligations') : self::decodeList($fallback('affected_obligations')),
            'affected_fields'      => [],
        ];

        $v->assert();

        $raw = array_key_exists('affected_fields', $body)
            ? (is_array($body['affected_fields']) ? $body['affected_fields'] : null)
            : self::decodeJson($fallback('affected_fields'));

        if ($raw === null) {
            throw new ValidationFailed(['affected_fields' => 'Expected an object of {field: {to: value}}.']);
        }

        $fields['affected_fields'] = $this->normaliseChanges($raw);

        return $fields;
    }

    /**
     * Normalise `{field: value}` and `{field: {from, to}}` to the stored shape.
     *
     * Each value goes through the same Validator the contract itself uses, so an
     * amendment cannot put a date into `expiry_date` that a direct edit would
     * have refused, and money stays a string all the way to NUMERIC.
     *
     * @param array<string,mixed> $raw
     * @return array<string,array{from: mixed, to: mixed}>
     */
    private function normaliseChanges(array $raw): array
    {
        $out    = [];
        $errors = [];

        foreach ($raw as $field => $change) {
            $field = (string) $field;
            if (! in_array($field, self::AMENDABLE, true)) {
                $errors['affected_fields.' . $field] = 'This field cannot be changed by an amendment.';
                continue;
            }

            $to = is_array($change) && array_key_exists('to', $change) ? $change['to'] : $change;

            try {
                $out[$field] = ['from' => null, 'to' => self::castField($field, $to)];
            } catch (ValidationFailed $e) {
                foreach ($e->errors as $message) {
                    $errors['affected_fields.' . $field] = $message;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $out;
    }

    /** Coerce one amendable value to the shape its contract column stores. */
    private static function castField(string $field, mixed $value): mixed
    {
        $v = new Validator([$field => $value]);

        $cast = match ($field) {
            'title'                                                        => $v->requiredString($field, 255),
            'description', 'notes'                                         => $v->optionalText($field, 20000),
            'commercial_summary'                                           => $v->optionalText($field, 5000),
            'effective_date', 'commencement_date', 'expiry_date'           => $v->optionalDate($field),
            'renewal_type'                                                 => $v->optionalEnum($field, Enums::RENEWAL_TYPES),
            'renewal_frequency'                                            => $v->optionalEnum($field, Enums::RENEWAL_FREQUENCIES),
            'auto_renewal'                                                 => $v->optionalBool($field, false) ?? false,
            'notice_period_days'                                           => $v->optionalInt($field, 0, 3650),
            'governing_law', 'jurisdiction'                                => $v->optionalString($field, 120),
            'currency'                                                     => $v->optionalCurrency($field),
            'total_value', 'recurring_value'                               => $v->optionalDecimal($field),
            'payment_frequency', 'billing_frequency'                       => $v->optionalString($field, 24),
            'counterparty_name'                                            => $v->optionalString($field, 255),
            default                                                        => null,
        };

        $v->assert();

        return $cast;
    }

    /**
     * Fill in `from` from the contract as it stands now.
     *
     * On a draft this is a convenience for the reviewer reading the diff; the
     * value that matters is re-stamped by apply(), which is the only moment the
     * "previous position" is a fact rather than a forecast.
     *
     * @param array<string,mixed>                     $contract
     * @param array<string,array{from: mixed, to: mixed}> $changes
     * @return array<string,array{from: mixed, to: mixed}>
     */
    private function stampOriginals(array $contract, array $changes): array
    {
        foreach ($changes as $field => $change) {
            $from = $contract[$field] ?? null;
            $changes[$field]['from'] = $field === 'auto_renewal' ? ContractService::toBool($from) : $from;
        }

        return $changes;
    }

    /** @return array<string,mixed> @throws DomainException */
    private function contractOrFail(TenantContext $ctx, int $contractId, bool $lock = false): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
            . ($lock ? ' FOR UPDATE' : '')
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Contract not found.');
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrate(array $row): array
    {
        foreach (['id', 'cmp_id', 'contract_id', 'amendment_no'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        foreach (['affected_fields', 'affected_commercials'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::decodeJson($row[$key]);
            }
        }
        foreach (['affected_clauses', 'affected_obligations'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = self::decodeList($row[$key]);
            }
        }

        return $row;
    }

    /**
     * An empty PHP array encodes as `[]`, and a reader of `affected_fields`
     * expects an object. The two encoders keep the column's shape stable whether
     * or not anything is in it.
     */
    private static function encodeObject(mixed $value): string
    {
        return json_encode($value === [] ? new \stdClass() : $value, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private static function encodeList(mixed $value): string
    {
        return json_encode(is_array($value) ? array_values($value) : [], JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /** @return array<string,mixed> */
    private static function decodeJson(mixed $raw): array
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

    /** @return list<mixed> */
    private static function decodeList(mixed $raw): array
    {
        return array_values(self::decodeJson($raw));
    }

    private static function asDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return substr(trim($value), 0, 10);
    }
}
