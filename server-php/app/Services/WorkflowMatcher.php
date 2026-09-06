<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Dates;

/**
 * Which approval workflow routes a record.
 *
 * Every method here is pure: a contract row and a list of conditions in, a
 * boolean out. Routing is the decision an auditor is most likely to challenge
 * six months later ("why did this go to the CFO and that one not"), and a rule
 * engine that can only be exercised through a database is a rule engine nobody
 * exercises. ApprovalService reads the workflows; this class decides.
 *
 * Conditions are company-authored data, so an unknown field or operator is
 * treated as an unmatched condition rather than an error. A settings screen
 * that has drifted ahead of the server must route a contract to the next
 * workflow, not refuse to accept it — and nothing in a condition is ever
 * evaluated as code.
 */
final class WorkflowMatcher
{
    /**
     * The attributes a condition may name, and how each is compared.
     *
     * `duration_months`, `has_non_standard_clauses` and `has_data_processing`
     * are not columns on `contracts` — the caller supplies them alongside the
     * row. They are here because they are the three questions an approval
     * policy actually asks that the contract record cannot answer by itself.
     */
    public const FIELDS = [
        'contract_type_id'         => 'number',
        'department_id'            => 'number',
        'total_value'              => 'number',
        'currency'                 => 'text',
        'risk_level'               => 'text',
        'ai_risk_score'            => 'number',
        'auto_renewal'             => 'bool',
        'governing_law'            => 'text',
        'notice_period_days'       => 'number',
        'duration_months'          => 'number',
        'has_non_standard_clauses' => 'bool',
        'has_data_processing'      => 'bool',
    ];

    public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'is_true', 'is_false'];

    /**
     * The workflow that routes this subject, or null when none does.
     *
     * Sorted here rather than trusted from the caller: two workflows on the
     * same priority must still resolve the same way on every run, and an
     * ORDER BY that someone later edits out of a query would otherwise change
     * routing silently.
     *
     * @param array<string,mixed>       $subject
     * @param list<array<string,mixed>> $workflows rows from `approval_workflows`
     * @return array<string,mixed>|null
     */
    public static function firstMatch(array $subject, array $workflows): ?array
    {
        usort($workflows, static function (array $a, array $b): int {
            return ((int) ($a['priority'] ?? 100)) <=> ((int) ($b['priority'] ?? 100))
                ?: ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });

        foreach ($workflows as $workflow) {
            $conditions = self::decodeConditions($workflow['conditions'] ?? []);
            $mode       = is_string($workflow['match_mode'] ?? null) ? $workflow['match_mode'] : 'all';

            if (self::matches($subject, $conditions, $mode)) {
                return $workflow;
            }
        }

        return null;
    }

    /**
     * Does this subject satisfy these conditions?
     *
     * An empty condition list matches everything under either mode. That is how
     * a catch-all workflow is authored — an admin who states no conditions means
     * "this one applies unless something more specific took it first", and
     * reading "any of nothing" as false would leave most companies with no
     * fallback route at all.
     *
     * @param array<string,mixed> $subject
     * @param list<mixed>         $conditions
     */
    public static function matches(array $subject, array $conditions, string $matchMode): bool
    {
        if ($conditions === []) {
            return true;
        }

        $any = $matchMode === 'any';

        foreach ($conditions as $condition) {
            $result = is_array($condition) && self::matchesCondition($subject, $condition);

            if ($any && $result) {
                return true;
            }
            if (! $any && ! $result) {
                return false;
            }
        }

        return ! $any;
    }

    /** @param array<string,mixed> $subject @param array<string,mixed> $condition */
    public static function matchesCondition(array $subject, array $condition): bool
    {
        $field    = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;

        if (! is_string($field) || ! isset(self::FIELDS[$field])) {
            return false;
        }
        if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
            return false;
        }

        $type   = self::FIELDS[$field];
        $actual = self::actual($subject, $field, $type);

        if ($type === 'bool') {
            return self::compareBool($actual === null ? false : ContractService::toBool($actual), $operator, $condition['value'] ?? null);
        }

        // A condition about a value the contract does not carry cannot be
        // satisfied. Reading a missing total_value as zero would route every
        // unpriced draft down the "under 100k" path, which is exactly the
        // contract nobody has looked at yet.
        if ($actual === null || $actual === '') {
            return false;
        }

        return match ($operator) {
            'eq', 'ne'         => self::compareEquality($actual, $operator, $condition['value'] ?? null, $type),
            'in', 'not_in'     => self::compareMembership($actual, $operator, $condition['value'] ?? null, $type),
            'is_true'          => ContractService::toBool($actual),
            'is_false'         => ! ContractService::toBool($actual),
            default            => self::compareOrder($actual, $operator, $condition['value'] ?? null, $type),
        };
    }

    /**
     * Whole months between two dates, for a `duration_months` condition.
     *
     * Whole months, not days divided by thirty: a twelve-month term is twelve
     * months whether or not February falls inside it, and a policy written as
     * "longer than 12 months" must not trip on a leap year.
     */
    public static function durationMonths(?string $from, ?string $to): ?int
    {
        $start = Dates::parse($from);
        $end   = Dates::parse($to);

        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        $diff = $start->diff($end);

        return ($diff->y * 12) + $diff->m;
    }

    /**
     * Conditions as stored: JSONB arrives from PDO as a string.
     *
     * @return list<mixed>
     */
    public static function decodeConditions(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $subject */
    private static function actual(array $subject, string $field, string $type): mixed
    {
        if (array_key_exists($field, $subject)) {
            return $subject[$field];
        }

        // The only derivable field: a caller passing a plain contract row still
        // gets term-length routing rather than a silently unmatched condition.
        if ($field === 'duration_months') {
            return self::durationMonths(
                self::text($subject['effective_date'] ?? $subject['commencement_date'] ?? null),
                self::text($subject['expiry_date'] ?? null)
            );
        }

        return null;
    }

    private static function compareBool(bool $actual, string $operator, mixed $value): bool
    {
        $list = self::boolList($value);

        return match ($operator) {
            'is_true'  => $actual,
            'is_false' => ! $actual,
            'eq'       => $actual === ContractService::toBool($value),
            'ne'       => $actual !== ContractService::toBool($value),
            'in'       => in_array($actual, $list, true),
            'not_in'   => $list !== [] && ! in_array($actual, $list, true),
            // Ordering a boolean is meaningless, and inventing an answer would
            // hide a mis-authored rule instead of skipping it.
            default    => false,
        };
    }

    private static function compareEquality(mixed $actual, string $operator, mixed $value, string $type): bool
    {
        $equal = self::equals($actual, $value, $type);

        return $operator === 'eq' ? $equal : ! $equal;
    }

    private static function compareMembership(mixed $actual, string $operator, mixed $value, string $type): bool
    {
        // A malformed list is unmatched under both operators. `not_in` against
        // nonsense quietly passing would be a rule that always fires.
        if (! is_array($value) && ! is_scalar($value)) {
            return false;
        }

        $list  = is_array($value) ? array_values($value) : [$value];
        $found = false;
        foreach ($list as $candidate) {
            if (self::equals($actual, $candidate, $type)) {
                $found = true;
                break;
            }
        }

        return $operator === 'in' ? $found : ! $found;
    }

    private static function compareOrder(mixed $actual, string $operator, mixed $value, string $type): bool
    {
        $order = self::order($actual, $value, $type);
        if ($order === null) {
            return false;
        }

        return match ($operator) {
            'gt'    => $order > 0,
            'gte'   => $order >= 0,
            'lt'    => $order < 0,
            'lte'   => $order <= 0,
            default => false,
        };
    }

    private static function equals(mixed $actual, mixed $value, string $type): bool
    {
        if ($type === 'number') {
            $a = self::numeric($actual);
            $b = self::numeric($value);

            return $a !== null && $b !== null && $a === $b;
        }

        $a = self::text($actual);
        $b = self::text($value);

        return $a !== null && $b !== null && strcasecmp(trim($a), trim($b)) === 0;
    }

    /** -1, 0, 1, or null when the two sides are not comparable. */
    private static function order(mixed $actual, mixed $value, string $type): ?int
    {
        $a = self::numeric($actual);
        $b = self::numeric($value);

        if ($a !== null && $b !== null) {
            return $a <=> $b;
        }

        // A numeric field compared against something non-numeric is a
        // mis-authored rule; comparing "1200000" to "large" as text would
        // produce an answer, just not a meaningful one.
        if ($type === 'number') {
            return null;
        }

        $as = self::text($actual);
        $bs = self::text($value);

        return $as === null || $bs === null ? null : (strcasecmp(trim($as), trim($bs)) <=> 0);
    }

    private static function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return null;
    }

    /** @return list<bool> */
    private static function boolList(mixed $value): array
    {
        if (! is_array($value)) {
            return is_scalar($value) ? [ContractService::toBool($value)] : [];
        }

        return array_values(array_unique(array_map(
            static fn (mixed $v): bool => ContractService::toBool($v),
            array_filter($value, static fn (mixed $v): bool => is_scalar($v))
        )));
    }
}
