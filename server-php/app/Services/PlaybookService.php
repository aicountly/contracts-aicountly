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
use PDOException;

/**
 * The company playbook, and what a contract's clauses look like measured
 * against it.
 *
 * A risk rule says "this is dangerous". A playbook rule says "this is not how
 * we do it here" — a mandatory clause absent, a prohibited phrase present, a
 * payment term past what Finance agreed. The two are separate because the
 * answers are: risk goes to a reviewer, a deviation goes to a negotiator with
 * the wording the company would rather see.
 *
 * Evaluation is deterministic and re-runnable. Re-running never resurrects a
 * deviation somebody has already accepted or resolved: burying a reviewer's
 * decision under a fresh duplicate is how a review queue stops being read.
 */
final class PlaybookService
{
    /** Mirrors ck_playbook_rules_type in 004_library.sql. */
    public const RULE_TYPES = [
        'mandatory_clause', 'prohibited_clause', 'preferred_wording', 'max_numeric',
        'min_numeric', 'allowed_list', 'prohibited_list', 'boolean_flag', 'date_window',
    ];

    /** Mirrors ck_clause_deviations_review. */
    public const REVIEW_STATUSES = ['open', 'accepted', 'rejected', 'negotiating', 'resolved'];

    /**
     * Which measurable quantity a rule's clause category refers to.
     *
     * A numeric rule filed under "Payment Terms" means the payment term in
     * days; one filed under "Termination" means the notice period. Without this
     * map a numeric rule would have to name a column, and naming a column in a
     * user-editable row is how a settings screen turns into an expression
     * evaluator.
     */
    private const NUMERIC_SUBJECTS = [
        'payment_terms'           => 'payment_terms',
        'late_payment'            => 'payment_terms',
        'termination'             => 'notice_period',
        'termination_convenience' => 'notice_period',
        'renewal'                 => 'notice_period',
        'limitation_liability'    => 'liability_cap',
    ];

    private const TEXT_SUBJECTS = [
        'governing_law'      => 'governing_law',
        'jurisdiction'       => 'jurisdiction',
        'arbitration'        => 'jurisdiction',
        'dispute_resolution' => 'jurisdiction',
    ];

    private const BOOLEAN_SUBJECTS = [
        'renewal'                 => 'auto_renewal',
        'sla'                     => 'sla_defined',
        'service_credits'         => 'sla_defined',
        'indemnity'               => 'indemnity',
        'insurance'               => 'insurance',
        'data_protection'         => 'data_protection',
        'termination'             => 'termination_right',
        'termination_convenience' => 'termination_right',
    ];

    /** Severity, worst first — a fixed expression, never built from caller input. */
    private const SEVERITY_ORDER = "CASE d.severity
            WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3
            WHEN 'low' THEN 4 ELSE 5 END";

    /** Fields whose change is worth an audit row. */
    private const AUDITED_FIELDS = [
        'rule_key', 'category_id', 'rule_type', 'label', 'description', 'operator',
        'expected_value', 'expected_numeric', 'expected_list', 'severity',
        'risk_category', 'recommendation', 'is_active', 'sort_order',
    ];

    private const EXCERPT_LENGTH = 400;

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
    // Playbooks and their rules
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function playbooks(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT p.*, t.name AS contract_type_name,
                    (SELECT COUNT(*) FROM playbook_rules r WHERE r.playbook_id = p.id AND r.is_active) AS active_rules
             FROM contract_playbooks p
             LEFT JOIN contract_types t ON t.id = p.contract_type_id
             WHERE p.environment = ? AND p.cmp_id = ?
             ORDER BY p.is_default DESC, p.name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        return array_map(
            static function (array $row): array {
                $row['id']           = (int) $row['id'];
                $row['active_rules'] = (int) $row['active_rules'];
                $row['is_default']   = ContractService::toBool($row['is_default']);
                $row['is_active']    = ContractService::toBool($row['is_active']);

                return $row;
            },
            $st->fetchAll() ?: []
        );
    }

    /**
     * The rules of one playbook, or of every playbook the company has.
     *
     * @return list<array<string,mixed>>
     */
    public function rules(TenantContext $ctx, ?int $playbookId = null): array
    {
        $sql = 'SELECT r.*, c.code AS category_code, c.name AS category_name, p.name AS playbook_name
                FROM playbook_rules r
                JOIN contract_playbooks p ON p.id = r.playbook_id
                LEFT JOIN clause_categories c ON c.id = r.category_id
                WHERE r.environment = ? AND r.cmp_id = ?';
        $params = [$ctx->environment, $ctx->cmpId];

        if ($playbookId !== null) {
            $sql .= ' AND r.playbook_id = ?';
            $params[] = $playbookId;
        }

        $st = $this->pdo->prepare($sql . ' ORDER BY r.sort_order, r.id');
        $st->execute($params);

        return array_map(fn (array $r): array => $this->hydrateRule($r), $st->fetchAll() ?: []);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws DomainException|ValidationFailed
     */
    public function createRule(TenantContext $ctx, int $playbookId, array $body): array
    {
        $this->playbookOrFail($ctx, $playbookId);

        $v      = new Validator($body);
        $fields = $this->readRuleFields($v, $ctx, true);
        $v->assert();

        try {
            $st = $this->pdo->prepare(
                'INSERT INTO playbook_rules
                 (playbook_id, environment, cmp_id, rule_key, category_id, rule_type, label,
                  description, operator, expected_value, expected_numeric, expected_list,
                  severity, risk_category, recommendation, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?)
                 RETURNING id'
            );
            $st->execute([
                $playbookId,
                $ctx->environment,
                $ctx->cmpId,
                $fields['rule_key'],
                $fields['category_id'],
                $fields['rule_type'],
                $fields['label'],
                $fields['description'],
                $fields['operator'],
                $fields['expected_value'],
                $fields['expected_numeric'],
                json_encode($fields['expected_list'], JSON_UNESCAPED_SLASHES),
                $fields['severity'],
                $fields['risk_category'],
                $fields['recommendation'],
                $fields['is_active'] ? 'true' : 'false',
                $fields['sort_order'],
            ]);
            $id = (int) $st->fetchColumn();
        } catch (PDOException $e) {
            throw self::asDuplicate($e);
        }

        $this->audit->log($ctx, 'playbook_rule', $id, 'playbook.rule_created', null, [
            'rule_key' => ['from' => null, 'to' => $fields['rule_key']],
            'label'    => ['from' => null, 'to' => $fields['label']],
        ]);

        return $this->ruleOrFail($ctx, $id);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws DomainException|ValidationFailed
     */
    public function updateRule(TenantContext $ctx, int $ruleId, array $body): array
    {
        $existing = $this->ruleOrFail($ctx, $ruleId);

        $v      = new Validator($body);
        $fields = $this->readRuleFields($v, $ctx, false, $existing);
        $v->assert();

        try {
            $this->pdo->prepare(
                'UPDATE playbook_rules SET
                    rule_key = :key, category_id = :category, rule_type = :type, label = :label,
                    description = :descr, operator = :operator, expected_value = :expected,
                    expected_numeric = :numeric, expected_list = :list::jsonb,
                    severity = :severity, risk_category = :risk, recommendation = :recommendation,
                    is_active = :active, sort_order = :sort, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            )->execute([
                'key'            => $fields['rule_key'],
                'category'       => $fields['category_id'],
                'type'           => $fields['rule_type'],
                'label'          => $fields['label'],
                'descr'          => $fields['description'],
                'operator'       => $fields['operator'],
                'expected'       => $fields['expected_value'],
                'numeric'        => $fields['expected_numeric'],
                'list'           => json_encode($fields['expected_list'], JSON_UNESCAPED_SLASHES),
                'severity'       => $fields['severity'],
                'risk'           => $fields['risk_category'],
                'recommendation' => $fields['recommendation'],
                'active'         => $fields['is_active'] ? 'true' : 'false',
                'sort'           => $fields['sort_order'],
                'id'             => $ruleId,
                'env'            => $ctx->environment,
                'cmp'            => $ctx->cmpId,
            ]);
        } catch (PDOException $e) {
            throw self::asDuplicate($e);
        }

        $updated = $this->ruleOrFail($ctx, $ruleId);
        $this->audit->logChanges($ctx, 'playbook_rule', $ruleId, $existing, $updated, self::AUDITED_FIELDS, null, 'playbook.rule_updated');

        return $updated;
    }

    /** @throws DomainException */
    public function deleteRule(TenantContext $ctx, int $ruleId): void
    {
        $existing = $this->ruleOrFail($ctx, $ruleId);

        // Audited before the delete: afterwards there is no row to reference,
        // and the deviations it raised keep their history because
        // clause_deviations.playbook_rule_id is ON DELETE SET NULL.
        $this->audit->log($ctx, 'playbook_rule', $ruleId, 'playbook.rule_deleted', null, [
            'rule_key' => ['from' => $existing['rule_key'], 'to' => null],
            'label'    => ['from' => $existing['label'], 'to' => null],
        ]);

        $this->pdo->prepare('DELETE FROM playbook_rules WHERE id = ? AND environment = ? AND cmp_id = ?')
            ->execute([$ruleId, $ctx->environment, $ctx->cmpId]);
    }

    // -----------------------------------------------------------------------
    // Evaluation
    // -----------------------------------------------------------------------

    /**
     * Measure one contract against the playbook that applies to it.
     *
     * Returns every rules-detected deviation standing on the contract
     * afterwards, not only the ones this pass raised — the negotiation panel
     * needs the accepted and resolved ones beside the open ones to be readable.
     *
     * @return list<array<string,mixed>>
     * @throws DomainException
     */
    public function evaluate(TenantContext $ctx, int $contractId): array
    {
        $contract = (new ContractService($this->pdo))->findOrFail($ctx, $contractId);
        $playbook = $this->playbookForContract($ctx, $contract);

        if ($playbook === null) {
            return $this->deviations($ctx, $contractId);
        }

        $subject  = (new RiskEngine($this->pdo))->buildSubject($ctx, $contract);
        $standard = $this->standardWordingByCategory($ctx);
        $raised   = [];

        foreach ($this->activeRules($ctx, (int) $playbook['id']) as $rule) {
            $deviation = self::measure($rule, $subject, $contract, $standard);
            if ($deviation !== null) {
                $raised[] = $deviation;
            }
        }

        Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $raised): void {
            $decided = $this->decidedRuleIds($ctx, $contractId);

            // Open deviations are rebuilt from scratch each run so a term that
            // has since been fixed stops being reported. Reviewed ones are left
            // exactly as they are.
            $pdo->prepare(
                "DELETE FROM clause_deviations
                 WHERE contract_id = ? AND environment = ? AND cmp_id = ?
                   AND detected_by = 'rules' AND review_status = 'open'"
            )->execute([$contractId, $ctx->environment, $ctx->cmpId]);

            $insert = $pdo->prepare(
                'INSERT INTO clause_deviations
                 (environment, cmp_id, contract_id, clause_id, playbook_rule_id, category_id,
                  contract_wording, preferred_wording, deviation_summary, severity,
                  recommendation, detected_by, review_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $written = 0;
            foreach ($raised as $deviation) {
                // A deviation somebody has accepted, rejected or resolved is not
                // raised again; the decision stands until the rule or the
                // contract changes enough for someone to reopen it deliberately.
                if (in_array((int) $deviation['playbook_rule_id'], $decided, true)) {
                    continue;
                }

                $insert->execute([
                    $ctx->environment,
                    $ctx->cmpId,
                    $contractId,
                    $deviation['clause_id'],
                    $deviation['playbook_rule_id'],
                    $deviation['category_id'],
                    $deviation['contract_wording'],
                    $deviation['preferred_wording'],
                    $deviation['deviation_summary'],
                    $deviation['severity'],
                    $deviation['recommendation'],
                    'rules',
                    'open',
                ]);
                $written++;
            }

            $this->audit->log($ctx, 'contract', $contractId, 'playbook.evaluated', $contractId, [
                'deviations' => ['from' => null, 'to' => $written],
            ]);
            $this->activity->record($ctx, $contractId, 'playbook.evaluated', sprintf(
                'Playbook check raised %d deviation%s',
                $written,
                $written === 1 ? '' : 's'
            ), ['deviations' => $written]);
        });

        return $this->deviations($ctx, $contractId);
    }

    /**
     * Decide whether one playbook rule is breached, without touching a
     * database. Pure, for the same reason RiskEngine::evaluateRule is.
     *
     * @param array<string,mixed>  $rule     a playbook_rules row, with category_code
     * @param array<string,mixed>  $subject  from RiskEngine::buildSubject()
     * @param array<string,mixed>  $contract
     * @param array<int,string>    $standard category_id → the library's preferred wording
     * @return array<string,mixed>|null
     */
    public static function measure(array $rule, array $subject, array $contract, array $standard = []): ?array
    {
        $type = (string) ($rule['rule_type'] ?? '');
        $code = strtolower((string) ($rule['category_code'] ?? ''));

        $breach = match ($type) {
            'mandatory_clause'  => self::measureMandatory($rule, $subject, $code),
            'prohibited_clause' => self::measureProhibited($rule, $subject, $code),
            'preferred_wording' => self::measurePreferred($rule, $subject, $code),
            'max_numeric',
            'min_numeric'       => self::measureNumeric($rule, $subject, $code, $type),
            'allowed_list',
            'prohibited_list'   => self::measureList($rule, $subject, $code, $type),
            'boolean_flag'      => self::measureBoolean($rule, $subject, $code),
            'date_window'       => self::measureDateWindow($rule, $contract),
            default             => null,
        };

        if ($breach === null) {
            return null;
        }

        $categoryId = isset($rule['category_id']) && $rule['category_id'] !== null ? (int) $rule['category_id'] : null;

        return [
            'playbook_rule_id'  => isset($rule['id']) ? (int) $rule['id'] : null,
            'category_id'       => $categoryId,
            'clause_id'         => $breach['clause_id'] ?? null,
            'contract_wording'  => $breach['contract_wording'] ?? null,
            'preferred_wording' => $breach['preferred_wording']
                ?? self::blankToNull($rule['expected_value'] ?? null)
                ?? ($categoryId !== null ? ($standard[$categoryId] ?? null) : null),
            'deviation_summary' => mb_substr($breach['summary'], 0, 2000),
            'severity'          => Enums::isValid($rule['severity'] ?? null, Enums::RISK_SEVERITIES)
                ? (string) $rule['severity']
                : 'medium',
            'recommendation'    => self::blankToNull($rule['recommendation'] ?? null),
        ];
    }

    /**
     * Deviations recorded against one contract.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function deviations(TenantContext $ctx, int $contractId, array $filters = []): array
    {
        $where  = ['d.environment = :env', 'd.cmp_id = :cmp', 'd.contract_id = :contract'];
        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'contract' => $contractId];

        if (Enums::isValid($filters['review_status'] ?? null, self::REVIEW_STATUSES)) {
            $where[]          = 'd.review_status = :review';
            $params['review'] = (string) $filters['review_status'];
        }
        if (($filters['open_only'] ?? false) === true) {
            $where[] = "d.review_status = 'open'";
        }
        if (Enums::isValid($filters['severity'] ?? null, Enums::RISK_SEVERITIES)) {
            $where[]            = 'd.severity = :severity';
            $params['severity'] = (string) $filters['severity'];
        }

        $st = $this->pdo->prepare(
            'SELECT d.*, c.code AS category_code, c.name AS category_name,
                    r.rule_key, r.label AS rule_label, r.rule_type,
                    cl.heading AS clause_heading, cl.clause_number
             FROM clause_deviations d
             LEFT JOIN clause_categories c ON c.id = d.category_id
             LEFT JOIN playbook_rules r ON r.id = d.playbook_rule_id
             LEFT JOIN contract_clauses cl ON cl.id = d.clause_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . self::SEVERITY_ORDER . ', d.id'
        );
        $st->execute($params);

        return array_map(fn (array $r): array => $this->hydrateDeviation($r), $st->fetchAll() ?: []);
    }

    /**
     * Record what a negotiator decided about one deviation.
     *
     * @return array<string,mixed>
     * @throws DomainException
     */
    public function reviewDeviation(TenantContext $ctx, int $deviationId, string $status, ?string $notes = null): array
    {
        if (! Enums::isValid($status, self::REVIEW_STATUSES)) {
            throw DomainException::badRequest('Unknown deviation status.');
        }

        $existing = $this->deviationById($ctx, $deviationId);
        if ($existing === null) {
            throw DomainException::notFound('Deviation not found.');
        }

        $contractId = (int) $existing['contract_id'];

        $this->pdo->prepare(
            'UPDATE clause_deviations
             SET review_status = ?, review_notes = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $status,
            $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 4000),
            $ctx->uuid,
            $deviationId,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        $this->audit->log($ctx, 'clause_deviation', $deviationId, 'playbook.deviation_reviewed', $contractId, [
            'review_status' => ['from' => $existing['review_status'], 'to' => $status],
        ]);
        $this->activity->record($ctx, $contractId, 'playbook.deviation_reviewed', sprintf(
            'Deviation "%s" marked as %s',
            mb_substr((string) $existing['deviation_summary'], 0, 120),
            Enums::label($status)
        ), array_filter(['notes' => $notes]));

        $updated = $this->deviationById($ctx, $deviationId);
        if ($updated === null) {
            throw new DomainException('The deviation was updated but could not be read back.', 'REVIEW_FAILED', 500);
        }

        return $updated;
    }

    // -----------------------------------------------------------------------
    // Rule measurement
    // -----------------------------------------------------------------------

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $subject
     * @return array<string,mixed>|null
     */
    private static function measureMandatory(array $rule, array $subject, string $code): ?array
    {
        // A mandatory-clause rule with no category names nothing, so there is
        // nothing to look for. Skipped rather than treated as always breached.
        if ($code === '' || self::hasCategory($subject, $code)) {
            return null;
        }

        return [
            'summary' => sprintf(
                'No %s clause was found. The playbook requires one in every contract.',
                self::categoryLabel($rule, $code)
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function measureProhibited(array $rule, array $subject, string $code): ?array
    {
        $phrase = self::blankToNull($rule['expected_value'] ?? null);

        if ($phrase !== null) {
            $clause = self::firstClauseContaining($subject, $phrase);
            if ($clause === null) {
                return null;
            }

            return [
                'clause_id'        => isset($clause['id']) ? (int) $clause['id'] : null,
                'contract_wording' => self::excerpt((string) $clause['body_text']),
                'summary'          => sprintf('The contract contains prohibited wording: "%s".', mb_substr($phrase, 0, 200)),
            ];
        }

        if ($code === '' || ! self::hasCategory($subject, $code)) {
            return null;
        }

        $clause = self::firstClauseInCategory($subject, $code);

        return [
            'clause_id'        => $clause !== null && isset($clause['id']) ? (int) $clause['id'] : null,
            'contract_wording' => $clause !== null ? self::excerpt((string) $clause['body_text']) : null,
            'summary'          => sprintf('The contract contains a %s clause, which the playbook prohibits.', self::categoryLabel($rule, $code)),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function measurePreferred(array $rule, array $subject, string $code): ?array
    {
        $wanted = self::blankToNull($rule['expected_value'] ?? null);
        if ($wanted === null || $code === '') {
            return null;
        }

        $clause = self::firstClauseInCategory($subject, $code);

        // A missing clause is a mandatory_clause matter. Reporting it here as
        // well would put the same gap in the queue twice under two headings.
        if ($clause === null) {
            return null;
        }

        $body = (string) $clause['body_text'];
        if (mb_stripos($body, $wanted) !== false) {
            return null;
        }

        return [
            'clause_id'         => isset($clause['id']) ? (int) $clause['id'] : null,
            'contract_wording'  => self::excerpt($body),
            'preferred_wording' => $wanted,
            'summary'           => sprintf(
                'The %s clause does not use the company preferred wording.',
                self::categoryLabel($rule, $code)
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function measureNumeric(array $rule, array $subject, string $code, string $type): ?array
    {
        $key = self::NUMERIC_SUBJECTS[$code] ?? null;
        if ($key === null || ! is_numeric($rule['expected_numeric'] ?? null)) {
            return null;
        }

        $actual = $subject[$key] ?? null;
        if (! is_numeric($actual)) {
            return null;
        }

        $limit    = (float) $rule['expected_numeric'];
        $breached = $type === 'max_numeric' ? (float) $actual > $limit : (float) $actual < $limit;
        if (! $breached) {
            return null;
        }

        return [
            'summary' => sprintf(
                '%s is %s, and the playbook allows %s %s.',
                self::categoryLabel($rule, $code),
                self::trimNumber((string) $actual),
                $type === 'max_numeric' ? 'at most' : 'at least',
                self::trimNumber((string) $rule['expected_numeric'])
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function measureList(array $rule, array $subject, string $code, string $type): ?array
    {
        $key    = self::TEXT_SUBJECTS[$code] ?? null;
        $values = self::decodeList($rule['expected_list'] ?? null);
        if ($key === null || $values === []) {
            return null;
        }

        $actual = $subject[$key] ?? null;
        if ($actual === null || trim((string) $actual) === '') {
            return null;
        }

        $present = false;
        foreach ($values as $candidate) {
            if (mb_strtolower(trim($candidate)) === mb_strtolower(trim((string) $actual))) {
                $present = true;
                break;
            }
        }

        $breached = $type === 'allowed_list' ? ! $present : $present;
        if (! $breached) {
            return null;
        }

        return [
            'summary' => sprintf(
                '%s is "%s", which is %s the playbook list (%s).',
                self::categoryLabel($rule, $code),
                mb_substr(trim((string) $actual), 0, 120),
                $type === 'allowed_list' ? 'not on' : 'on',
                mb_substr(implode(', ', $values), 0, 200)
            ),
        ];
    }

    /** @return array<string,mixed>|null */
    private static function measureBoolean(array $rule, array $subject, string $code): ?array
    {
        $key = self::BOOLEAN_SUBJECTS[$code] ?? null;
        if ($key === null || ! array_key_exists($key, $subject)) {
            return null;
        }

        $expected = ContractService::toBool($rule['expected_value'] ?? 'true');
        $actual   = ContractService::toBool($subject[$key]);
        if ($actual === $expected) {
            return null;
        }

        return [
            'summary' => sprintf(
                '%s: the playbook expects %s and this contract is %s.',
                self::categoryLabel($rule, $code),
                $expected ? 'yes' : 'no',
                $actual ? 'yes' : 'no'
            ),
        ];
    }

    /**
     * How far a contract may be backdated.
     *
     * A term starting well before the day it was signed is the one date test
     * worth having: it is how an agreement acquires obligations nobody approved
     * at the time, and it is invisible in every other view.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $contract
     * @return array<string,mixed>|null
     */
    private static function measureDateWindow(array $rule, array $contract): ?array
    {
        if (! is_numeric($rule['expected_numeric'] ?? null)) {
            return null;
        }

        $days = Dates::daysBetween(
            self::blankToNull($contract['effective_date'] ?? null),
            self::blankToNull($contract['execution_date'] ?? null)
        );
        if ($days === null) {
            return null;
        }

        $allowed = (int) $rule['expected_numeric'];
        if ($days <= $allowed) {
            return null;
        }

        return [
            'summary' => sprintf(
                'The term began %d days before the contract was signed; the playbook allows %d.',
                $days,
                $allowed
            ),
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The playbook that governs one contract: the one written for its type if
     * there is one, otherwise the company default.
     *
     * @param array<string,mixed> $contract
     * @return array<string,mixed>|null
     */
    private function playbookForContract(TenantContext $ctx, array $contract): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_playbooks
             WHERE environment = ? AND cmp_id = ? AND is_active
               AND (contract_type_id = ? OR contract_type_id IS NULL)
             ORDER BY (contract_type_id IS NOT NULL) DESC, is_default DESC, id
             LIMIT 1'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contract['contract_type_id'] ?? null]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function activeRules(TenantContext $ctx, int $playbookId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.*, c.code AS category_code, c.name AS category_name
             FROM playbook_rules r
             LEFT JOIN clause_categories c ON c.id = r.category_id
             WHERE r.playbook_id = ? AND r.environment = ? AND r.cmp_id = ? AND r.is_active
             ORDER BY r.sort_order, r.id'
        );
        $st->execute([$playbookId, $ctx->environment, $ctx->cmpId]);

        return array_values($st->fetchAll() ?: []);
    }

    /** Rules whose deviation on this contract someone has already ruled on. @return list<int> */
    private function decidedRuleIds(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            "SELECT DISTINCT playbook_rule_id FROM clause_deviations
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
               AND review_status <> 'open' AND playbook_rule_id IS NOT NULL"
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * The library's approved wording per clause category, so a deviation can
     * show the reader what the company would rather see.
     *
     * @return array<int,string>
     */
    private function standardWordingByCategory(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            "SELECT DISTINCT ON (category_id) category_id, standard_text
             FROM clause_library
             WHERE environment = ? AND cmp_id = ? AND category_id IS NOT NULL
               AND archived_at IS NULL AND approval_status = 'approved'
             ORDER BY category_id, version DESC, id"
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        $map = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $map[(int) $row['category_id']] = (string) $row['standard_text'];
        }

        return $map;
    }

    /**
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function readRuleFields(Validator $v, TenantContext $ctx, bool $creating, ?array $existing = null): array
    {
        $fallback = static fn (string $key, mixed $default = null): mixed => $existing[$key] ?? $default;

        $ruleKey = $creating || $v->has('rule_key')
            ? strtolower($v->requiredString('rule_key', 64))
            : (string) $fallback('rule_key', '');
        if ($ruleKey !== '' && preg_match('/^[a-z0-9][a-z0-9_]*$/', $ruleKey) !== 1) {
            $v->fail('rule_key', 'Use lower-case letters, digits and underscores.');
        }

        $type = $creating || $v->has('rule_type')
            ? $v->requiredEnum('rule_type', self::RULE_TYPES)
            : (string) $fallback('rule_type', 'mandatory_clause');

        $label = $creating || $v->has('label')
            ? $v->requiredString('label', 200)
            : (string) $fallback('label', '');

        $categoryId = $v->has('category_id')
            ? $v->optionalId('category_id')
            : ($fallback('category_id') === null ? null : (int) $fallback('category_id'));

        $expectedList = $v->has('expected_list')
            ? self::cleanList($v->optionalArray('expected_list', 100))
            : self::decodeList($fallback('expected_list'));

        $fields = [
            'rule_key'         => $ruleKey,
            'rule_type'        => $type,
            'label'            => $label,
            'category_id'      => $categoryId,
            'description'      => $v->has('description')
                ? $v->optionalText('description', 4000)
                : self::blankToNull($fallback('description')),
            'operator'         => $v->has('operator')
                ? $v->optionalEnum('operator', RiskEngine::OPERATORS)
                : self::blankToNull($fallback('operator')),
            'expected_value'   => $v->has('expected_value')
                ? $v->optionalText('expected_value', 4000)
                : self::blankToNull($fallback('expected_value')),
            'expected_numeric' => $v->has('expected_numeric')
                ? $v->optionalDecimal('expected_numeric', 2)
                : self::blankToNull($fallback('expected_numeric')),
            'expected_list'    => $expectedList,
            'severity'         => $v->optionalEnum('severity', Enums::RISK_SEVERITIES, (string) $fallback('severity', 'medium')) ?? 'medium',
            'risk_category'    => $v->optionalEnum('risk_category', Enums::RISK_CATEGORIES, (string) $fallback('risk_category', 'legal')) ?? 'legal',
            'recommendation'   => $v->has('recommendation')
                ? $v->optionalText('recommendation', 4000)
                : self::blankToNull($fallback('recommendation')),
            'is_active'        => $v->optionalBool('is_active', ContractService::toBool($fallback('is_active', true))) ?? true,
            'sort_order'       => $v->optionalInt('sort_order', 0, 100000, (int) $fallback('sort_order', 100)) ?? 100,
        ];

        $this->assertRuleIsMeasurable($v, $ctx, $fields);

        return $fields;
    }

    /**
     * Refuse a rule the engine could never act on.
     *
     * The CHECK constraints keep the vocabulary honest; this keeps the rule
     * meaningful. A max_numeric rule with no number, or one filed under a
     * category nothing measurable maps to, would sit in the playbook forever
     * looking like a control and never firing — which is worse than no rule,
     * because somebody believes it is there.
     *
     * @param array<string,mixed> $fields
     */
    private function assertRuleIsMeasurable(Validator $v, TenantContext $ctx, array $fields): void
    {
        $type = (string) $fields['rule_type'];
        $code = $this->categoryCode($ctx, $fields['category_id']);

        if ($fields['category_id'] !== null && $code === null) {
            $v->fail('category_id', 'Choose a clause category from your own list.');

            return;
        }

        if (in_array($type, ['mandatory_clause', 'preferred_wording'], true) && $code === null) {
            $v->fail('category_id', 'This rule needs the clause category it is about.');
        }
        if ($type === 'preferred_wording' && $fields['expected_value'] === null) {
            $v->fail('expected_value', 'Give the wording this clause is expected to contain.');
        }
        if ($type === 'prohibited_clause' && $fields['expected_value'] === null && $code === null) {
            $v->fail('expected_value', 'Give either the prohibited wording or the clause category.');
        }
        if (in_array($type, ['max_numeric', 'min_numeric'], true)) {
            if ($fields['expected_numeric'] === null) {
                $v->fail('expected_numeric', 'Give the limit this rule compares against.');
            }
            if ($code === null || ! isset(self::NUMERIC_SUBJECTS[$code])) {
                $v->fail('category_id', 'A numeric rule needs a category the engine can measure: '
                    . implode(', ', array_keys(self::NUMERIC_SUBJECTS)) . '.');
            }
        }
        if (in_array($type, ['allowed_list', 'prohibited_list'], true)) {
            if ($fields['expected_list'] === []) {
                $v->fail('expected_list', 'Give at least one value for this list.');
            }
            if ($code === null || ! isset(self::TEXT_SUBJECTS[$code])) {
                $v->fail('category_id', 'A list rule needs a category the engine can measure: '
                    . implode(', ', array_keys(self::TEXT_SUBJECTS)) . '.');
            }
        }
        if ($type === 'boolean_flag') {
            if ($fields['expected_value'] === null) {
                $v->fail('expected_value', 'Give the expected answer, true or false.');
            }
            if ($code === null || ! isset(self::BOOLEAN_SUBJECTS[$code])) {
                $v->fail('category_id', 'A yes/no rule needs a category the engine can measure: '
                    . implode(', ', array_keys(self::BOOLEAN_SUBJECTS)) . '.');
            }
        }
        if ($type === 'date_window' && $fields['expected_numeric'] === null) {
            $v->fail('expected_numeric', 'Give the number of days this window allows.');
        }
    }

    private function categoryCode(TenantContext $ctx, ?int $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        $st = $this->pdo->prepare(
            'SELECT code FROM clause_categories WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$categoryId, $ctx->environment, $ctx->cmpId]);
        $code = $st->fetchColumn();

        return $code === false ? null : strtolower((string) $code);
    }

    /** @return array<string,mixed> @throws DomainException */
    private function playbookOrFail(TenantContext $ctx, int $playbookId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_playbooks WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$playbookId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Playbook not found.');
        }

        return $row;
    }

    /** @return array<string,mixed> @throws DomainException */
    private function ruleOrFail(TenantContext $ctx, int $ruleId): array
    {
        $st = $this->pdo->prepare(
            'SELECT r.*, c.code AS category_code, c.name AS category_name
             FROM playbook_rules r
             LEFT JOIN clause_categories c ON c.id = r.category_id
             WHERE r.id = ? AND r.environment = ? AND r.cmp_id = ? LIMIT 1'
        );
        $st->execute([$ruleId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Playbook rule not found.');
        }

        return $this->hydrateRule($row);
    }

    /** @return array<string,mixed>|null */
    private function deviationById(TenantContext $ctx, int $deviationId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT d.*, c.code AS category_code, c.name AS category_name,
                    r.rule_key, r.label AS rule_label, r.rule_type,
                    cl.heading AS clause_heading, cl.clause_number
             FROM clause_deviations d
             LEFT JOIN clause_categories c ON c.id = d.category_id
             LEFT JOIN playbook_rules r ON r.id = d.playbook_rule_id
             LEFT JOIN contract_clauses cl ON cl.id = d.clause_id
             WHERE d.id = ? AND d.environment = ? AND d.cmp_id = ? LIMIT 1'
        );
        $st->execute([$deviationId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrateDeviation($row) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateRule(array $row): array
    {
        $row['expected_list'] = self::decodeList($row['expected_list'] ?? null);
        $row['is_active']     = ContractService::toBool($row['is_active'] ?? true);

        foreach (['id', 'playbook_id', 'cmp_id', 'sort_order'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['category_id'] = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateDeviation(array $row): array
    {
        foreach (['id', 'cmp_id', 'contract_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['clause_id', 'playbook_rule_id', 'category_id'] as $key) {
            $row[$key] = isset($row[$key]) && $row[$key] !== null ? (int) $row[$key] : null;
        }

        return $row;
    }

    /**
     * A unique-key violation is the caller's mistake, not a server error, and
     * the field it belongs to is the one they typed.
     */
    private static function asDuplicate(PDOException $e): \RuntimeException
    {
        if ($e->getCode() === '23505') {
            return new ValidationFailed(['rule_key' => 'A rule with this key already exists in this playbook.']);
        }

        return $e;
    }

    // -----------------------------------------------------------------------
    // Small value helpers
    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $subject */
    private static function hasCategory(array $subject, string $code): bool
    {
        $present = is_array($subject['clause_categories'] ?? null) ? $subject['clause_categories'] : [];

        foreach ($present as $candidate) {
            if (strtolower((string) $candidate) === $code) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $subject @return array<string,mixed>|null */
    private static function firstClauseInCategory(array $subject, string $code): ?array
    {
        foreach (is_array($subject['clauses'] ?? null) ? $subject['clauses'] : [] as $clause) {
            if (strtolower((string) ($clause['category_code'] ?? '')) === $code) {
                return $clause;
            }
        }

        return null;
    }

    /**
     * Only the first match is reported. A prohibited phrase appearing in four
     * clauses is one negotiation, not four queue items.
     *
     * @param array<string,mixed> $subject
     * @return array<string,mixed>|null
     */
    private static function firstClauseContaining(array $subject, string $phrase): ?array
    {
        foreach (is_array($subject['clauses'] ?? null) ? $subject['clauses'] : [] as $clause) {
            $body = (string) ($clause['body_text'] ?? '');
            if ($body !== '' && mb_stripos($body, $phrase) !== false) {
                return $clause;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $rule */
    private static function categoryLabel(array $rule, string $code): string
    {
        $name = self::blankToNull($rule['category_name'] ?? null);

        return $name ?? Enums::label($code);
    }

    private static function excerpt(string $body): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        return mb_strlen($clean) > self::EXCERPT_LENGTH
            ? mb_substr($clean, 0, self::EXCERPT_LENGTH) . '…'
            : $clean;
    }

    private static function trimNumber(string $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') : $value;
    }

    /** @return list<string> */
    private static function decodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? self::cleanList($raw) : [];
    }

    /** @param array<int,mixed> $values @return list<string> */
    private static function cleanList(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $out[] = mb_substr($text, 0, 200);
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
