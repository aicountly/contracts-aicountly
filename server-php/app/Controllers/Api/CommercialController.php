<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Database;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\ContractService;
use App\Support\DomainException;
use App\Support\Permissions;
use App\Support\Validator;
use PDO;

/**
 * Commercial terms and the payment schedule.
 *
 * The queries live in the controller rather than behind a service because there
 * is no logic to hold: one row per contract, one flat list beside it, and the
 * only rule is that reading and writing them are separately granted. A service
 * here would be a pass-through with a longer call stack.
 */
final class CommercialController extends BaseController
{
    /** Columns whose change is worth an audit row. */
    private const AUDITED = [
        'currency', 'total_value', 'recurring_amount', 'billing_frequency',
        'payment_terms_days', 'value_direction', 'advance_amount', 'advance_percent',
        'retention_percent', 'security_deposit', 'performance_guarantee',
        'late_payment_interest', 'escalation_percent', 'escalation_basis',
        'discount_percent', 'minimum_purchase', 'maximum_purchase',
        'minimum_revenue_commitment', 'committed_quantity', 'unit_rate',
        'termination_charge', 'notice_charge',
    ];

    public function show(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::COMMERCIALS_VIEW);
        $contractId = $this->intId($id);
        $pdo        = $this->db();

        $this->run(fn () => (new ContractService($pdo))->findOrFail($ctx, $contractId));

        $terms = $pdo->prepare(
            'SELECT * FROM contract_commercial_terms
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $terms->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $terms->fetch();

        $schedules = $pdo->prepare(
            'SELECT * FROM contract_payment_schedules
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY sequence_no, due_date NULLS LAST, id'
        );
        $schedules->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        Response::success([
            'terms'             => is_array($row) ? self::hydrate($row) : null,
            'payment_schedules' => $schedules->fetchAll() ?: [],
        ]);
    }

    public function update(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::COMMERCIALS_EDIT);
        $contractId = $this->intId($id);

        $this->respond(function () use ($ctx, $contractId): array {
            $pdo = $this->db();
            (new ContractService($pdo))->findOrFail($ctx, $contractId);

            $body = $this->body();
            $v    = new Validator($body);

            $fields = [
                'currency'                   => $v->optionalCurrency('currency', $ctx->currency()),
                'total_value'                => $v->optionalDecimal('total_value'),
                'recurring_amount'           => $v->optionalDecimal('recurring_amount'),
                'billing_frequency'          => $v->optionalString('billing_frequency', 24),
                'payment_terms_days'         => $v->optionalInt('payment_terms_days', 0, 3650),
                'payment_terms_note'         => $v->optionalString('payment_terms_note', 255),
                'value_direction'            => $v->optionalEnum('value_direction', ['receivable', 'payable', 'both', 'none'], 'receivable') ?? 'receivable',
                'advance_amount'             => $v->optionalDecimal('advance_amount'),
                'advance_percent'            => $v->optionalDecimal('advance_percent', 3),
                'retention_percent'          => $v->optionalDecimal('retention_percent', 3),
                'security_deposit'           => $v->optionalDecimal('security_deposit'),
                'performance_guarantee'      => $v->optionalDecimal('performance_guarantee'),
                'penalty_note'               => $v->optionalText('penalty_note', 4000),
                'late_payment_interest'      => $v->optionalDecimal('late_payment_interest', 3),
                'escalation_percent'         => $v->optionalDecimal('escalation_percent', 3),
                'escalation_basis'           => $v->optionalString('escalation_basis', 64),
                'escalation_frequency'       => $v->optionalString('escalation_frequency', 24),
                'discount_percent'           => $v->optionalDecimal('discount_percent', 3),
                'rebate_note'                => $v->optionalString('rebate_note', 255),
                'minimum_purchase'           => $v->optionalDecimal('minimum_purchase'),
                'maximum_purchase'           => $v->optionalDecimal('maximum_purchase'),
                'minimum_revenue_commitment' => $v->optionalDecimal('minimum_revenue_commitment'),
                'committed_quantity'         => $v->optionalDecimal('committed_quantity', 3),
                'quantity_unit'              => $v->optionalString('quantity_unit', 32),
                'unit_rate'                  => $v->optionalDecimal('unit_rate', 4),
                'termination_charge'         => $v->optionalDecimal('termination_charge'),
                'notice_charge'              => $v->optionalDecimal('notice_charge'),
                'renewal_pricing_note'       => $v->optionalText('renewal_pricing_note', 4000),
            ];

            // The database CHECK would catch this too, but a constraint
            // violation reads as a 500 to the user; naming the field lets the
            // form point at it.
            if ($fields['minimum_purchase'] !== null && $fields['maximum_purchase'] !== null
                && (float) $fields['maximum_purchase'] < (float) $fields['minimum_purchase']) {
                $v->fail('maximum_purchase', 'The maximum cannot be below the minimum.');
            }

            $v->assert();

            return Database::transaction($pdo, function (PDO $pdo) use ($ctx, $contractId, $fields, $body): array {
                $existing = $pdo->prepare(
                    'SELECT * FROM contract_commercial_terms WHERE contract_id = ? AND environment = ? AND cmp_id = ?'
                );
                $existing->execute([$contractId, $ctx->environment, $ctx->cmpId]);
                $before = $existing->fetch() ?: [];

                $columns = array_keys($fields);
                $extra   = isset($body['extra']) && is_array($body['extra']) ? $body['extra'] : [];

                if ($before === []) {
                    $sql = 'INSERT INTO contract_commercial_terms
                            (environment, cmp_id, contract_id, ' . implode(', ', $columns) . ', extra, updated_by)
                            VALUES (?, ?, ?, ' . implode(', ', array_fill(0, count($columns), '?')) . ', ?::jsonb, ?)';
                    $st = $pdo->prepare($sql);
                    $st->execute(array_merge(
                        [$ctx->environment, $ctx->cmpId, $contractId],
                        array_values($fields),
                        [json_encode($extra), $ctx->uuid]
                    ));
                } else {
                    $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", $columns));
                    $st = $pdo->prepare(
                        "UPDATE contract_commercial_terms
                         SET {$assignments}, extra = ?::jsonb, updated_by = ?, updated_at = CURRENT_TIMESTAMP
                         WHERE contract_id = ? AND environment = ? AND cmp_id = ?"
                    );
                    $st->execute(array_merge(
                        array_values($fields),
                        [json_encode($extra), $ctx->uuid, $contractId, $ctx->environment, $ctx->cmpId]
                    ));
                }

                // The contract row carries a denormalised value so the
                // repository list needs no join; keeping the two in step here
                // is cheaper than a trigger and easier to follow.
                $pdo->prepare(
                    'UPDATE contracts SET total_value = ?, recurring_value = ?, currency = ?,
                                          billing_frequency = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND environment = ? AND cmp_id = ?'
                )->execute([
                    $fields['total_value'], $fields['recurring_amount'], $fields['currency'],
                    $fields['billing_frequency'], $contractId, $ctx->environment, $ctx->cmpId,
                ]);

                $after = $pdo->prepare(
                    'SELECT * FROM contract_commercial_terms WHERE contract_id = ? AND environment = ? AND cmp_id = ?'
                );
                $after->execute([$contractId, $ctx->environment, $ctx->cmpId]);
                $row = $after->fetch() ?: [];

                (new AuditService($pdo))->logChanges(
                    $ctx,
                    'commercial_terms',
                    (int) ($row['id'] ?? 0),
                    $before,
                    $row,
                    self::AUDITED,
                    $contractId,
                    'commercials.updated'
                );

                return ['terms' => self::hydrate($row)];
            });
        });
    }

    public function storeSchedule(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::COMMERCIALS_EDIT);
        $contractId = $this->intId($id);

        $this->respond(function () use ($ctx, $contractId): array {
            $pdo = $this->db();
            (new ContractService($pdo))->findOrFail($ctx, $contractId);

            $v      = new Validator($this->body());
            $label  = $v->requiredString('label', 200);
            $amount = $v->optionalDecimal('amount');
            if ($amount === null) {
                $v->fail('amount', 'An amount is required.');
            }
            $fields = [
                'sequence_no'      => $v->optionalInt('sequence_no', 1, 999, 1) ?? 1,
                'due_date'         => $v->optionalDate('due_date'),
                'percent_of_total' => $v->optionalDecimal('percent_of_total', 3),
                'currency'         => $v->optionalCurrency('currency', $ctx->currency()),
                'direction'        => $v->optionalEnum('direction', ['receivable', 'payable'], 'receivable') ?? 'receivable',
                'notes'            => $v->optionalText('notes', 2000),
            ];
            $v->assert();

            $st = $pdo->prepare(
                'INSERT INTO contract_payment_schedules
                 (environment, cmp_id, contract_id, sequence_no, label, due_date, amount,
                  percent_of_total, currency, direction, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING *'
            );
            $st->execute([
                $ctx->environment, $ctx->cmpId, $contractId, $fields['sequence_no'], $label,
                $fields['due_date'], $amount, $fields['percent_of_total'],
                $fields['currency'], $fields['direction'], $fields['notes'],
            ]);

            $row = $st->fetch() ?: [];
            (new AuditService($pdo))->log($ctx, 'payment_schedule', (int) ($row['id'] ?? 0), 'payment_schedule.created', $contractId);

            return $row;
        }, 201);
    }

    public function updateSchedule(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::COMMERCIALS_EDIT);
        $scheduleId = $this->intId($id);

        $this->respond(function () use ($ctx, $scheduleId): array {
            $pdo  = $this->db();
            $v    = new Validator($this->body());
            $body = $this->body();

            $st = $pdo->prepare(
                'UPDATE contract_payment_schedules
                 SET label = COALESCE(?, label),
                     due_date = ?,
                     amount = COALESCE(?, amount),
                     percent_of_total = ?,
                     direction = COALESCE(?, direction),
                     status = COALESCE(?, status),
                     notes = ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?
                 RETURNING *'
            );
            $st->execute([
                $v->optionalString('label', 200),
                $v->optionalDate('due_date'),
                $v->optionalDecimal('amount'),
                $v->optionalDecimal('percent_of_total', 3),
                $v->optionalEnum('direction', ['receivable', 'payable']),
                $v->optionalEnum('status', ['scheduled', 'invoiced', 'part_paid', 'settled', 'waived', 'overdue']),
                $v->optionalText('notes', 2000),
                $scheduleId, $ctx->environment, $ctx->cmpId,
            ]);
            $v->assert();

            $row = $st->fetch();
            if (! is_array($row)) {
                throw DomainException::notFound('Payment schedule row not found.');
            }

            (new AuditService($pdo))->log(
                $ctx,
                'payment_schedule',
                $scheduleId,
                'payment_schedule.updated',
                (int) $row['contract_id']
            );

            return $row;
        });
    }

    public function destroySchedule(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::COMMERCIALS_EDIT);
        $scheduleId = $this->intId($id);
        $pdo        = $this->db();

        $st = $pdo->prepare(
            'DELETE FROM contract_payment_schedules
             WHERE id = ? AND environment = ? AND cmp_id = ? RETURNING contract_id'
        );
        $st->execute([$scheduleId, $ctx->environment, $ctx->cmpId]);
        $contractId = $st->fetchColumn();

        if ($contractId === false) {
            Response::notFound('Payment schedule row not found.');
        }

        (new AuditService($pdo))->log($ctx, 'payment_schedule', $scheduleId, 'payment_schedule.deleted', (int) $contractId);

        Response::success(['deleted' => true]);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        if (isset($row['extra']) && is_string($row['extra'])) {
            $row['extra'] = json_decode($row['extra'], true) ?: [];
        }
        if (array_key_exists('is_ai_extracted', $row)) {
            $row['is_ai_extracted'] = ContractService::toBool($row['is_ai_extracted']);
        }

        return $row;
    }
}
