<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Dates;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use PDO;

/**
 * The deterministic half of contract risk.
 *
 * Nothing in this file calls a model. Every finding it produces is a fact about
 * the structured record — a rule the company wrote, evaluated against columns
 * and extracted clauses — which is what makes an assessment reproducible: the
 * same contract and the same rule set give the same score today and in a
 * dispute two years from now. Model-written findings live in the same table
 * with `detected_by = 'ai'` and a confidence, so a reviewer can weigh
 * "unlimited liability, because rule X matched clause 14" differently from
 * "this indemnity reads broadly".
 *
 * Rules are data, never expressions. `subject` names a branch below and
 * `operator` names a comparison; a company admin composing a rule in the
 * settings screen can therefore never compose code.
 *
 * Every query filters `environment` AND `cmp_id` from the TenantContext.
 */
final class RiskEngine
{
    /** Bumped when a change here would give an old contract a different score. */
    public const ENGINE_VERSION = '1';

    /** What a rule may look at. Mirrors ck_risk_rules_subject in 009_risk.sql. */
    public const SUBJECTS = [
        'clause_text', 'clause_missing', 'liability_cap', 'auto_renewal', 'notice_period',
        'governing_law', 'jurisdiction', 'payment_terms', 'contract_value', 'duration_months',
        'termination_right', 'indemnity', 'data_protection', 'insurance', 'sla_defined',
        'expiry_date', 'counterparty_missing', 'signature_missing', 'document_missing',
    ];

    /** How a rule may compare. Mirrors ck_risk_rules_operator. */
    public const OPERATORS = [
        'contains', 'not_contains', 'equals', 'not_equals', 'greater_than', 'less_than',
        'in_list', 'not_in_list', 'is_true', 'is_false', 'is_null', 'is_not_null', 'regex',
    ];

    /** Mirrors ck_risk_findings_review. */
    public const REVIEW_STATUSES = ['open', 'accepted', 'mitigated', 'false_positive', 'resolved'];

    /**
     * How much a rule's own weight counts once its severity is taken into
     * account. A weight of 10 means something different on an informational
     * note than on a critical one, and a company that tunes weights should not
     * have to encode that difference in every row.
     */
    /**
     * The floor a single finding of each severity puts under the overall score.
     *
     * A contract with unlimited liability is a critical contract however tidy
     * the rest of it is. Without a floor, diminishing returns would let that one
     * finding beside an otherwise clean sheet read as "medium", which is exactly
     * the contract a reviewer most needs to be told about.
     *
     * A single *high* finding gets a gentler floor, into the upper medium band
     * rather than the high one. The severity of a finding is not the risk of the
     * contract: one long notice period on an otherwise sound agreement is a flag
     * worth reviewing, not a crisis. Two or three of them climb into the high
     * band on their own, which is the right way to get there.
     */
    private const SEVERITY_FLOOR = [
        'informational' => 0,
        'low'           => 0,
        'medium'        => 0,
        'high'          => 50,
        'critical'      => 80,
    ];

    /** Ordering, so the worst severity present can be found in one pass. */
    private const SEVERITY_RANK = [
        'informational' => 0,
        'low'           => 1,
        'medium'        => 2,
        'high'          => 3,
        'critical'      => 4,
    ];

    private const SEVERITY_MULTIPLIER = [
        'informational' => 0.2,
        'low'           => 0.5,
        'medium'        => 1.0,
        'high'          => 1.4,
        'critical'      => 1.8,
    ];

    /** Score at or above which a contract carries each level. Checked highest first. */
    private const LEVEL_THRESHOLDS = ['critical' => 80, 'high' => 60, 'medium' => 40];

    /**
     * A rule author is not an attacker, but `(a+)+$` against a long clause is
     * still an outage. The pattern is capped, the subject is capped, and the
     * backtrack limit is lowered for the duration of the match, so the worst a
     * bad pattern can do is fail to fire.
     */
    private const MAX_REGEX_PATTERN = 200;

    private const MAX_REGEX_SUBJECT = 20000;

    private const REGEX_BACKTRACK_LIMIT = 100000;

    /** Delimiters tried in order; the first one absent from the pattern wins. */
    private const REGEX_DELIMITERS = ['#', '~', '%', '!', '@', ';', '|'];

    private const EXCERPT_LENGTH = 240;

    /** Ceiling on one recalculateAll pass, so a cron run stays bounded. */
    private const RECALCULATE_CEILING = 1000;

    /** Clause text is joined for whole-document tests; long contracts are cut here. */
    private const MAX_JOINED_TEXT = 400000;

    /** Severity, worst first — a fixed expression, never built from caller input. */
    private const SEVERITY_ORDER = "CASE f.severity
            WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3
            WHEN 'low' THEN 4 ELSE 5 END";

    private AuditService $audit;

    private ActivityService $activity;

    private HealthScoreService $health;

    public function __construct(private PDO $pdo)
    {
        $this->audit    = new AuditService($pdo);
        $this->activity = new ActivityService($pdo);
        $this->health   = new HealthScoreService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Assessment
    // -----------------------------------------------------------------------

    /**
     * Run every active rule against one contract and record the result.
     *
     * One assessment row per run, the previous one demoted rather than deleted:
     * "the risk was high when we signed and medium after the addendum" is the
     * question this table exists to answer, and it needs both rows.
     *
     * @return array<string,mixed> the assessment, with its findings
     * @throws DomainException
     */
    public function assess(TenantContext $ctx, int $contractId, ?int $versionId = null): array
    {
        $contract = (new ContractService($this->pdo))->findOrFail($ctx, $contractId);

        if ($versionId !== null) {
            $this->assertVersionBelongsToContract($ctx, $contractId, $versionId);
        }

        $subject  = $this->buildSubject($ctx, $contract, $versionId);
        $findings = [];

        foreach ($this->activeRules($ctx) as $rule) {
            if (! self::appliesToType($rule, $subject['contract_type_code'] ?? null)) {
                continue;
            }
            $finding = self::evaluateRule($rule, $subject);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        $score  = self::scoreFromFindings($findings);
        $health = $this->health->scoreForContract($ctx, $contract, $findings);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contract, $contractId, $versionId, $findings, $score, $health): array {
            // Demote first: uq_risk_assessment_current is a partial unique index
            // on (contract_id) WHERE is_current, so inserting a second current
            // row before the old one steps down is a constraint violation.
            $pdo->prepare(
                'UPDATE contract_risk_assessments SET is_current = FALSE
                 WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND is_current'
            )->execute([$contractId, $ctx->environment, $ctx->cmpId]);

            $counts = self::severityCounts($findings);

            $st = $pdo->prepare(
                'INSERT INTO contract_risk_assessments
                 (environment, cmp_id, contract_id, version_id, overall_score, risk_level,
                  category_scores, health_score, health_breakdown, findings_count,
                  critical_count, high_count, engine_version, ai_used, summary,
                  is_current, generated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?::jsonb, ?, ?, ?, ?, FALSE, ?, TRUE, ?)
                 RETURNING id'
            );
            $st->execute([
                $ctx->environment,
                $ctx->cmpId,
                $contractId,
                $versionId,
                $score['score'],
                $score['level'],
                json_encode($score['categories'], JSON_UNESCAPED_SLASHES),
                $health['overall'],
                json_encode($health, JSON_UNESCAPED_SLASHES),
                count($findings),
                $counts['critical'],
                $counts['high'],
                self::ENGINE_VERSION,
                self::summarise($score, $counts, count($findings)),
                $ctx->uuid,
            ]);
            $assessmentId = (int) $st->fetchColumn();

            $insert = $pdo->prepare(
                'INSERT INTO contract_risk_findings
                 (assessment_id, contract_id, environment, cmp_id, rule_id, rule_key, clause_id,
                  risk_category, severity, title, detail, source_excerpt, source_page,
                  recommendation, detected_by, score_impact, review_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($findings as $finding) {
                $insert->execute([
                    $assessmentId,
                    $contractId,
                    $ctx->environment,
                    $ctx->cmpId,
                    $finding['rule_id'],
                    $finding['rule_key'],
                    $finding['clause_id'],
                    $finding['risk_category'],
                    $finding['severity'],
                    $finding['title'],
                    $finding['detail'],
                    $finding['source_excerpt'],
                    $finding['source_page'],
                    $finding['recommendation'],
                    $finding['detected_by'],
                    $finding['score_impact'],
                    $finding['review_status'],
                ]);
            }

            // updated_at is deliberately left alone. A nightly recalculation
            // across the estate would otherwise reorder every "recently
            // updated" list in the product for reasons no user did anything to
            // cause.
            $pdo->prepare(
                'UPDATE contracts SET risk_level = ?, ai_risk_score = ?, health_score = ?
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([
                $score['level'], $score['score'], $health['overall'],
                $contractId, $ctx->environment, $ctx->cmpId,
            ]);

            $this->audit->log($ctx, 'risk_assessment', $assessmentId, 'risk.assessed', $contractId, [
                'risk_level'    => ['from' => $contract['risk_level'] ?? null, 'to' => $score['level']],
                'ai_risk_score' => ['from' => $contract['ai_risk_score'] ?? null, 'to' => $score['score']],
                'health_score'  => ['from' => $contract['health_score'] ?? null, 'to' => $health['overall']],
            ]);
            $this->activity->record($ctx, $contractId, 'risk.assessed', sprintf(
                'Risk assessed as %s (%d) with %d finding%s',
                Enums::label($score['level']),
                $score['score'],
                count($findings),
                count($findings) === 1 ? '' : 's'
            ), ['findings' => count($findings), 'score' => $score['score']]);

            $assessment = $this->assessmentById($ctx, $assessmentId);
            if ($assessment === null) {
                throw new DomainException('The assessment was written but could not be read back.', 'ASSESS_FAILED', 500);
            }

            return $assessment;
        });
    }

    /**
     * Decide whether one rule fires against one prepared subject bag.
     *
     * Pure: no database, no clock, no tenant. This is the whole of the engine's
     * judgement, which is why it is testable on its own and why a rule change
     * can be reasoned about without a contract in front of you.
     *
     * @param array<string,mixed> $rule    a contract_risk_rules row
     * @param array<string,mixed> $subject from buildSubject()
     * @return array<string,mixed>|null    a contract_risk_findings payload, or null
     */
    public static function evaluateRule(array $rule, array $subject): ?array
    {
        $subjectKey = is_string($rule['subject'] ?? null) ? $rule['subject'] : '';
        $operator   = is_string($rule['operator'] ?? null) ? $rule['operator'] : '';

        // A row the CHECK constraints would have refused is treated as inert
        // rather than fatal: one malformed rule must not stop the other
        // seventeen from being evaluated.
        if (! in_array($subjectKey, self::SUBJECTS, true) || ! in_array($operator, self::OPERATORS, true)) {
            return null;
        }

        $match = match ($subjectKey) {
            'clause_missing' => self::matchClauseMissing($rule, $subject),
            'clause_text'    => self::matchClauseText($rule, $subject),
            default          => self::applyOperator($operator, $subject[$subjectKey] ?? null, $rule)
                ? ['actual' => $subject[$subjectKey] ?? null, 'clause_id' => null, 'excerpt' => null, 'page' => null]
                : null,
        };

        return $match === null ? null : self::finding($rule, $match);
    }

    /**
     * Assemble everything the rules may look at, once per assessment.
     *
     * Built in one place rather than per rule so that eighteen rules asking
     * about the same contract cost one pass over its clauses, and so that
     * "does this contract have an indemnity" means exactly one thing.
     *
     * $versionId is optional here (the signature callers rely on takes two
     * arguments) and narrows the clause set to one document version, falling
     * back to every extracted clause when that version has none of its own.
     *
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    public function buildSubject(TenantContext $ctx, array $contract, ?int $versionId = null): array
    {
        $contractId = (int) $contract['id'];
        $clauses    = $this->loadClauses($ctx, $contractId, $versionId);
        $terms      = $this->loadCommercialTerms($ctx, $contractId);
        $presence   = $this->loadPresenceCounts($ctx, $contractId);

        $categories = [];
        $bodies     = [];
        foreach ($clauses as $clause) {
            if (is_string($clause['category_code']) && $clause['category_code'] !== '') {
                $categories[strtolower($clause['category_code'])] = true;
            }
            $bodies[] = (string) $clause['body_text'];
        }
        $joined = mb_substr(implode("\n\n", $bodies), 0, self::MAX_JOINED_TEXT);

        $noticeDays = $contract['notice_period_days'] ?? null;
        $paymentDays = $terms['payment_terms_days'] ?? null;

        $value = $terms['total_value'] ?? null;
        if ($value === null || $value === '') {
            $value = $contract['total_value'] ?? null;
        }

        $counterpartyMissing = ((int) $presence['counterparties']) === 0
            && trim((string) ($contract['counterparty_name'] ?? '')) === '';

        // "Signed" means evidence exists: an executed file, a completed
        // signature request, or a recorded execution date. Any one of those is
        // enough; a status field alone is a claim, not evidence.
        $signatureMissing = ((int) $presence['executed_versions']) === 0
            && ((int) $presence['completed_signatures']) === 0
            && ($contract['execution_date'] ?? null) === null;

        return [
            'contract_type_code'  => $contract['contract_type_code'] ?? null,
            'clauses'             => $clauses,
            'clause_text'         => $joined === '' ? null : $joined,
            'clause_categories'   => array_keys($categories),
            'liability_cap'       => self::readLiabilityCap($clauses),
            'auto_renewal'        => ContractService::toBool($contract['auto_renewal'] ?? false)
                || in_array((string) ($contract['renewal_type'] ?? ''), ['auto_renew', 'evergreen'], true),
            'notice_period'       => $noticeDays === null ? null : (int) $noticeDays,
            'governing_law'       => self::nullIfBlank($contract['governing_law'] ?? null),
            'jurisdiction'        => self::nullIfBlank($contract['jurisdiction'] ?? null),
            'payment_terms'       => $paymentDays === null ? null : (int) $paymentDays,
            'contract_value'      => self::nullIfBlank($value),
            'duration_months'     => self::durationMonths($contract['effective_date'] ?? null, $contract['expiry_date'] ?? null),
            'termination_right'   => self::hasClause($clauses, ['termination_convenience'], ['for convenience', 'without cause']),
            'indemnity'           => self::hasClause($clauses, ['indemnity'], ['indemnif']),
            'data_protection'     => self::hasClause($clauses, ['data_protection'], ['personal data', 'data protection', 'data processing']),
            'insurance'           => self::hasClause($clauses, ['insurance'], ['insurance', 'insured']),
            'sla_defined'         => self::hasClause($clauses, ['sla', 'service_credits'], ['service level', 'uptime', 'service credit']),
            'expiry_date'         => self::nullIfBlank($contract['expiry_date'] ?? null),
            'counterparty_missing' => $counterpartyMissing,
            'signature_missing'   => $signatureMissing,
            'document_missing'    => ((int) $presence['documents']) === 0,
        ];
    }

    /**
     * Turn a set of findings into a score, a level and a per-category split.
     *
     * Pure, and deliberately a plain sum of severity-weighted rule weights
     * capped at 100 rather than anything cleverer: a user who asks why a
     * contract scores 62 gets an answer they can add up themselves.
     *
     * @param list<array<string,mixed>> $findings
     * @return array{score: int, level: string, categories: array<string,int>}
     */
    /**
     * Combine findings into a 0-100 score.
     *
     * Not a sum. A linear total saturates: with seventeen seeded rules, any
     * contract missing a document and a few standard clauses adds past 100, and
     * every incomplete contract then reads "critical 100" — a score that never
     * discriminates is worse than no score, because people stop looking at it.
     *
     * Instead each finding takes a share of the *remaining* clean headroom:
     *
     *     combined = combined + (1 - combined) x impact
     *
     * Ten ten-point findings reach 65, not 100. Twenty reach 88. The scale stays
     * usable across a real portfolio, and adding a twentieth minor gap moves the
     * number less than adding the first serious one — which matches how a
     * reviewer actually weighs them.
     *
     * Severity then sets a floor, because a contract with unlimited liability is
     * high risk regardless of how tidy the rest of it is, and diminishing
     * returns alone would let one critical finding read as "medium" beside a
     * clean sheet.
     *
     * @param list<array<string,mixed>> $findings
     * @return array{score: int, level: string, categories: array<string,int>}
     */
    public static function scoreFromFindings(array $findings): array
    {
        $categoryFractions = [];
        $combined          = 0.0;
        $worst             = null;

        foreach ($findings as $finding) {
            // A finding a reviewer has dismissed stops counting. Leaving it in
            // would mean the only way to clear a false positive is to delete the
            // evidence that it was ever raised.
            if ((string) ($finding['review_status'] ?? 'open') === 'false_positive') {
                continue;
            }

            $severity = Enums::isValid($finding['severity'] ?? null, Enums::RISK_SEVERITIES)
                ? (string) $finding['severity']
                : 'medium';

            $impact = isset($finding['score_impact'])
                ? (int) $finding['score_impact']
                : self::impact((int) ($finding['score_weight'] ?? 0), $severity);

            if ($impact <= 0) {
                continue;
            }

            $fraction = min(1.0, $impact / 100);
            $combined = $combined + (1 - $combined) * $fraction;

            $category = Enums::isValid($finding['risk_category'] ?? null, Enums::RISK_CATEGORIES)
                ? (string) $finding['risk_category']
                : 'legal';

            $running = $categoryFractions[$category] ?? 0.0;
            $categoryFractions[$category] = $running + (1 - $running) * $fraction;

            if ($worst === null || self::SEVERITY_RANK[$severity] > self::SEVERITY_RANK[$worst]) {
                $worst = $severity;
            }
        }

        $score = (int) round($combined * 100);
        $score = max($score, self::SEVERITY_FLOOR[$worst] ?? 0);
        $score = max(0, min(100, $score));

        $categories = [];
        foreach ($categoryFractions as $category => $fraction) {
            $categories[$category] = max(0, min(100, (int) round($fraction * 100)));
        }

        // Sorted so the stored JSON of two identical assessments is identical.
        ksort($categories);

        return ['score' => $score, 'level' => self::levelForScore($score), 'categories' => $categories];
    }

    public static function levelForScore(int $score): string
    {
        foreach (self::LEVEL_THRESHOLDS as $level => $floor) {
            if ($score >= $floor) {
                return $level;
            }
        }

        return 'low';
    }

    // -----------------------------------------------------------------------
    // Reading and reviewing
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null the assessment in force for this contract */
    public function currentAssessment(TenantContext $ctx, int $contractId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_risk_assessments
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND is_current
             LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            return null;
        }

        $assessment             = $this->hydrateAssessment($row);
        $assessment['findings'] = $this->listFindings($ctx, $contractId, ['current_only' => true]);

        return $assessment;
    }

    /**
     * Findings for one contract.
     *
     * Defaults to the current assessment: a panel that showed every finding
     * ever raised would show the same risk three times over after three runs.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listFindings(TenantContext $ctx, int $contractId, array $filters = []): array
    {
        $where  = ['f.environment = :env', 'f.cmp_id = :cmp', 'f.contract_id = :contract'];
        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'contract' => $contractId];

        if (($filters['current_only'] ?? true) !== false) {
            $where[] = 'a.is_current';
        }

        if (Enums::isValid($filters['review_status'] ?? null, self::REVIEW_STATUSES)) {
            $where[]                  = 'f.review_status = :review';
            $params['review']         = (string) $filters['review_status'];
        }
        if (($filters['open_only'] ?? false) === true) {
            $where[] = "f.review_status = 'open'";
        }
        if (Enums::isValid($filters['severity'] ?? null, Enums::RISK_SEVERITIES)) {
            $where[]                  = 'f.severity = :severity';
            $params['severity']       = (string) $filters['severity'];
        }
        if (Enums::isValid($filters['risk_category'] ?? null, Enums::RISK_CATEGORIES)) {
            $where[]                  = 'f.risk_category = :category';
            $params['category']       = (string) $filters['risk_category'];
        }
        if (in_array($filters['detected_by'] ?? null, ['rules', 'ai', 'manual'], true)) {
            $where[]                  = 'f.detected_by = :detected';
            $params['detected']       = (string) $filters['detected_by'];
        }

        $st = $this->pdo->prepare(
            'SELECT f.*, c.heading AS clause_heading, c.clause_number
             FROM contract_risk_findings f
             JOIN contract_risk_assessments a ON a.id = f.assessment_id
             LEFT JOIN contract_clauses c ON c.id = f.clause_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ' . self::SEVERITY_ORDER . ', f.id'
        );
        $st->execute($params);

        return array_map(fn (array $r): array => $this->hydrateFinding($r), $st->fetchAll() ?: []);
    }

    /**
     * Record a reviewer's decision on one finding.
     *
     * The assessment is rescored afterwards because dismissing a false positive
     * that still counts toward the score would leave the reviewer looking at a
     * number their own decision contradicts.
     *
     * @return array<string,mixed>
     * @throws DomainException
     */
    public function reviewFinding(TenantContext $ctx, int $findingId, string $status, ?string $notes = null): array
    {
        if (! Enums::isValid($status, self::REVIEW_STATUSES)) {
            throw DomainException::badRequest('Unknown review status.');
        }

        $existing = $this->findingById($ctx, $findingId);
        if ($existing === null) {
            throw DomainException::notFound('Risk finding not found.');
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $findingId, $existing, $status, $notes): array {
            $pdo->prepare(
                'UPDATE contract_risk_findings
                 SET review_status = ?, review_notes = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([
                $status,
                $notes === null || trim($notes) === '' ? null : mb_substr(trim($notes), 0, 4000),
                $ctx->uuid,
                $findingId,
                $ctx->environment,
                $ctx->cmpId,
            ]);

            $contractId = (int) $existing['contract_id'];

            $this->audit->log($ctx, 'risk_finding', $findingId, 'risk.finding_reviewed', $contractId, [
                'review_status' => ['from' => $existing['review_status'], 'to' => $status],
            ]);
            $this->activity->record($ctx, $contractId, 'risk.finding_reviewed', sprintf(
                '%s marked as %s',
                (string) $existing['title'],
                Enums::label($status)
            ), array_filter(['notes' => $notes]));

            $this->rescoreAssessment($ctx, (int) $existing['assessment_id']);

            $updated = $this->findingById($ctx, $findingId);
            if ($updated === null) {
                throw new DomainException('The finding was updated but could not be read back.', 'REVIEW_FAILED', 500);
            }

            return $updated;
        });
    }

    /**
     * Reassess every live contract for the company.
     *
     * Used by the settings screen after a rule change and by the nightly sweep;
     * bounded either way, because "every contract" is a number that only grows.
     */
    public function recalculateAll(TenantContext $ctx, ?int $limit = null): int
    {
        $ceiling = $limit === null ? self::RECALCULATE_CEILING : max(1, min($limit, self::RECALCULATE_CEILING));

        $st = $this->pdo->prepare(
            'SELECT id FROM contracts
             WHERE environment = ? AND cmp_id = ? AND archived_at IS NULL
             ORDER BY id
             LIMIT ?'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $ceiling]);

        $done = 0;
        foreach ($st->fetchAll() ?: [] as $row) {
            $this->assess($ctx, (int) $row['id']);
            $done++;
        }

        return $done;
    }

    // -----------------------------------------------------------------------
    // Operators
    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $rule */
    private static function applyOperator(string $operator, mixed $value, array $rule): bool
    {
        // An unknown value cannot satisfy a positive test. Without this an
        // empty draft fires nearly every rule the company owns, and the one
        // finding that matters — that the record is empty — is lost in the
        // noise. `is_null` is the operator that asks about absence.
        if ($value === null || $value === '') {
            return $operator === 'is_null';
        }

        $needle    = self::needle($rule);
        $threshold = self::threshold($rule);
        $text      = self::text($value);
        $numeric   = is_numeric($value) ? (float) $value : null;

        return match ($operator) {
            'contains'     => $needle !== '' && mb_stripos($text, $needle) !== false,
            'not_contains' => $needle !== '' && mb_stripos($text, $needle) === false,
            'equals'       => $needle !== '' && self::sameText($text, $needle),
            'not_equals'   => $needle !== '' && ! self::sameText($text, $needle),
            // A non-numeric subject cannot be greater or smaller than anything.
            // Casting "capped at 12 months fees" to 0.0 and comparing would
            // silently turn a real cap into a breach of the threshold.
            'greater_than' => $numeric !== null && $threshold !== null && $numeric > $threshold,
            'less_than'    => $numeric !== null && $threshold !== null && $numeric < $threshold,
            'in_list'      => self::inList($text, $rule),
            'not_in_list'  => self::listValues($rule) !== [] && ! self::inList($text, $rule),
            'is_true'      => ContractService::toBool($value),
            'is_false'     => ! ContractService::toBool($value),
            'is_null'      => false,
            'is_not_null'  => true,
            'regex'        => self::regexMatch($text, $rule) !== null,
            default        => false,
        };
    }

    /**
     * Compile an admin-authored pattern, or refuse it.
     *
     * No delimiter is added by escaping: escaping the delimiter would corrupt a
     * pattern that already escapes it, so a delimiter absent from the pattern
     * is chosen instead and a pattern containing all of them is refused.
     */
    public static function compileRegex(?string $pattern): ?string
    {
        if ($pattern === null) {
            return null;
        }
        $pattern = trim($pattern);
        if ($pattern === '' || strlen($pattern) > self::MAX_REGEX_PATTERN) {
            return null;
        }

        $delimiter = null;
        foreach (self::REGEX_DELIMITERS as $candidate) {
            if (! str_contains($pattern, $candidate)) {
                $delimiter = $candidate;
                break;
            }
        }
        if ($delimiter === null) {
            return null;
        }

        $compiled = $delimiter . $pattern . $delimiter . 'iu';

        // Compile once against an empty subject: an invalid pattern must be
        // discovered here, not as a warning during an assessment.
        if (@preg_match($compiled, '') === false) {
            return null;
        }

        return $compiled;
    }

    /**
     * Run a guarded regex, returning the matched text or null.
     *
     * @param array<string,mixed> $rule
     */
    private static function regexMatch(string $subject, array $rule): ?string
    {
        $compiled = self::compileRegex(is_string($rule['value_text'] ?? null) ? $rule['value_text'] : null);
        if ($compiled === null) {
            return null;
        }

        // Two bounds, because either alone is escapable: a short subject keeps
        // an exponential pattern cheap, and a low backtrack limit makes the
        // engine give up rather than burn the request budget.
        $bounded  = mb_substr($subject, 0, self::MAX_REGEX_SUBJECT);
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', (string) self::REGEX_BACKTRACK_LIMIT);
        $result = @preg_match($compiled, $bounded, $matches);
        if ($previous !== false) {
            ini_set('pcre.backtrack_limit', $previous);
        }

        // A pattern that exhausted the backtrack limit returns false. It is
        // reported as "did not match" rather than throwing: one runaway rule
        // must not cost the company its whole assessment.
        if ($result !== 1) {
            return null;
        }

        return (string) ($matches[0] ?? '');
    }

    // -----------------------------------------------------------------------
    // Subject-specific matching
    // -----------------------------------------------------------------------

    /**
     * `clause_missing equals confidentiality` reads as "the confidentiality
     * clause is missing" — the rule's value names the category being asked
     * about, not something to compare a subject to. The seeded rules and the
     * settings screen are both phrased that way.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $subject
     * @return array<string,mixed>|null
     */
    private static function matchClauseMissing(array $rule, array $subject): ?array
    {
        $present = is_array($subject['clause_categories'] ?? null)
            ? array_map(static fn ($c): string => strtolower((string) $c), $subject['clause_categories'])
            : [];

        $wanted = self::listValues($rule);
        if ($wanted === [] && self::needle($rule) !== '') {
            $wanted = [self::needle($rule)];
        }

        // With no category named, the question is whether the contract has any
        // extracted clause at all.
        $absent = $wanted === []
            ? ($present === [] ? ['any clause'] : [])
            : array_values(array_filter($wanted, static fn (string $code): bool => ! in_array(strtolower($code), $present, true)));

        $operator = (string) $rule['operator'];
        $missing  = $absent !== [];

        $fires = match ($operator) {
            'equals', 'in_list', 'is_true'          => $missing,
            'not_equals', 'not_in_list', 'is_false' => ! $missing,
            'is_null'                               => $present === [],
            'is_not_null'                           => $present !== [],
            default                                 => false,
        };

        return $fires
            ? ['actual' => $missing ? implode(', ', $absent) : null, 'clause_id' => null, 'excerpt' => null, 'page' => null]
            : null;
    }

    /**
     * Match against clause bodies, keeping the clause that matched.
     *
     * A positive test is answered clause by clause so the finding can point at
     * the paragraph it is about. A negative test ("no clause says X") is a
     * statement about the whole document and is answered against the joined
     * text — asking it of one clause at a time would fire on the first clause
     * that happens not to mention it, which is nearly all of them.
     *
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $subject
     * @return array<string,mixed>|null
     */
    private static function matchClauseText(array $rule, array $subject): ?array
    {
        $operator = (string) $rule['operator'];

        if (in_array($operator, ['not_contains', 'not_equals', 'not_in_list', 'is_null', 'is_false'], true)) {
            return self::applyOperator($operator, $subject['clause_text'] ?? null, $rule)
                ? ['actual' => null, 'clause_id' => null, 'excerpt' => null, 'page' => null]
                : null;
        }

        $clauses = is_array($subject['clauses'] ?? null) ? $subject['clauses'] : [];

        foreach ($clauses as $clause) {
            $body = (string) ($clause['body_text'] ?? '');
            if ($body === '' || ! self::applyOperator($operator, $body, $rule)) {
                continue;
            }

            $anchor = $operator === 'regex'
                ? self::regexMatch($body, $rule)
                : self::needle($rule);

            return [
                'actual'    => self::nullIfBlank($clause['heading'] ?? null),
                'clause_id' => isset($clause['id']) ? (int) $clause['id'] : null,
                'excerpt'   => self::excerpt($body, $anchor),
                'page'      => isset($clause['source_page']) && $clause['source_page'] !== null
                    ? (int) $clause['source_page']
                    : null,
            ];
        }

        return null;
    }

    /**
     * @param array<string,mixed> $rule
     * @param array<string,mixed> $match
     * @return array<string,mixed>
     */
    private static function finding(array $rule, array $match): array
    {
        $severity = Enums::isValid($rule['severity'] ?? null, Enums::RISK_SEVERITIES)
            ? (string) $rule['severity']
            : 'medium';
        $category = Enums::isValid($rule['risk_category'] ?? null, Enums::RISK_CATEGORIES)
            ? (string) $rule['risk_category']
            : 'legal';

        $detail = self::nullIfBlank($rule['description'] ?? null);
        $actual = $match['actual'] ?? null;

        // The recorded value is appended to the explanation rather than left
        // implicit: "notice period longer than 60 days" is an accusation until
        // it says 90.
        if (is_scalar($actual) && ! is_bool($actual)) {
            $shown = trim((string) $actual);
            if ($shown !== '' && mb_strlen($shown) <= 120) {
                $detail = trim(($detail ?? '') . ' Recorded value: ' . $shown . '.');
            }
        }

        return [
            'rule_id'        => isset($rule['id']) ? (int) $rule['id'] : null,
            'rule_key'       => self::nullIfBlank($rule['rule_key'] ?? null),
            'clause_id'      => $match['clause_id'] ?? null,
            'risk_category'  => $category,
            'severity'       => $severity,
            'title'          => mb_substr((string) ($rule['name'] ?? $rule['rule_key'] ?? 'Risk finding'), 0, 255),
            'detail'         => $detail,
            'source_excerpt' => $match['excerpt'] ?? null,
            'source_page'    => $match['page'] ?? null,
            'recommendation' => self::nullIfBlank($rule['recommendation'] ?? null),
            'detected_by'    => 'rules',
            'score_impact'   => self::impact((int) ($rule['score_weight'] ?? 10), $severity),
            'review_status'  => 'open',
            'score_weight'   => max(0, min(100, (int) ($rule['score_weight'] ?? 10))),
        ];
    }

    public static function impact(int $weight, string $severity): int
    {
        $weight     = max(0, min(100, $weight));
        $multiplier = self::SEVERITY_MULTIPLIER[$severity] ?? 1.0;

        return (int) max(0, min(100, (int) round($weight * $multiplier)));
    }

    // -----------------------------------------------------------------------
    // Subject assembly
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function loadClauses(TenantContext $ctx, int $contractId, ?int $versionId): array
    {
        $sql = 'SELECT cl.id, cl.clause_number, cl.heading, cl.body_text, cl.source_page,
                       cat.code AS category_code
                FROM contract_clauses cl
                LEFT JOIN clause_categories cat ON cat.id = cl.category_id
                WHERE cl.contract_id = ? AND cl.environment = ? AND cl.cmp_id = ?';

        if ($versionId !== null) {
            $st = $this->pdo->prepare($sql . ' AND cl.version_id = ? ORDER BY cl.id');
            $st->execute([$contractId, $ctx->environment, $ctx->cmpId, $versionId]);
            $rows = $st->fetchAll() ?: [];

            // A version whose clauses have not been extracted yet is assessed
            // against the clauses the contract does have, rather than as though
            // it had none — an empty extraction is a pipeline state, not a
            // contract with no terms.
            if ($rows !== []) {
                return array_values($rows);
            }
        }

        $st = $this->pdo->prepare($sql . ' ORDER BY cl.id');
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_values($st->fetchAll() ?: []);
    }

    /** @return array<string,mixed> */
    private function loadCommercialTerms(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_commercial_terms
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $row : [];
    }

    /**
     * The four existence questions the rules ask, in one round trip.
     *
     * @return array<string,int>
     */
    private function loadPresenceCounts(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM contract_parties p
                  WHERE p.contract_id = :cid AND p.environment = :env AND p.cmp_id = :cmp
                    AND p.party_role <> 'company')                                   AS counterparties,
                (SELECT COUNT(*) FROM contract_documents d
                  WHERE d.contract_id = :cid AND d.environment = :env AND d.cmp_id = :cmp) AS documents,
                (SELECT COUNT(*) FROM contract_document_versions v
                   JOIN contract_documents dv ON dv.id = v.document_id
                  WHERE dv.contract_id = :cid AND v.environment = :env AND v.cmp_id = :cmp
                    AND v.is_executed)                                               AS executed_versions,
                (SELECT COUNT(*) FROM signature_requests s
                  WHERE s.contract_id = :cid AND s.environment = :env AND s.cmp_id = :cmp
                    AND s.status IN ('signed','completed'))                          AS completed_signatures"
        );
        $st->execute(['cid' => $contractId, 'env' => $ctx->environment, 'cmp' => $ctx->cmpId]);
        $row = $st->fetch();

        return [
            'counterparties'       => (int) ($row['counterparties'] ?? 0),
            'documents'            => (int) ($row['documents'] ?? 0),
            'executed_versions'    => (int) ($row['executed_versions'] ?? 0),
            'completed_signatures' => (int) ($row['completed_signatures'] ?? 0),
        ];
    }

    /**
     * The liability cap, or null when there is none.
     *
     * Null means "no cap": either no limitation of liability clause at all, or
     * one that says liability is unlimited. Both are the same risk, and the
     * seeded `unlimited_liability` rule is written as `liability_cap is_null`
     * because of it. When a cap does exist this returns the figure if one can
     * be read, and the wording otherwise — a cap of "fees paid in the preceding
     * twelve months" is a real cap with no number in it.
     *
     * @param list<array<string,mixed>> $clauses
     */
    private static function readLiabilityCap(array $clauses): ?string
    {
        $body = null;
        foreach ($clauses as $clause) {
            $code = strtolower((string) ($clause['category_code'] ?? ''));
            $text = (string) ($clause['body_text'] ?? '');
            if ($code === 'limitation_liability'
                || preg_match('/limitation of liability|liability (?:shall be |is )?limited|liability cap/i', mb_substr($text, 0, 4000)) === 1) {
                $body = $text;
                break;
            }
        }

        if ($body === null) {
            return null;
        }

        $head = mb_substr($body, 0, 4000);
        if (preg_match('/\b(?:unlimited|without limit|no limitation|not limited)\b/i', $head) === 1) {
            return null;
        }

        if (preg_match('/(?:INR|USD|EUR|GBP|Rs\.?|\x{20B9}|\$)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $head, $m) === 1) {
            return str_replace(',', '', $m[1]);
        }

        return mb_substr(trim($head), 0, 500);
    }

    /**
     * @param list<array<string,mixed>> $clauses
     * @param list<string>              $categoryCodes
     * @param list<string>              $phrases
     */
    private static function hasClause(array $clauses, array $categoryCodes, array $phrases): bool
    {
        foreach ($clauses as $clause) {
            $code = strtolower((string) ($clause['category_code'] ?? ''));
            if ($code !== '' && in_array($code, $categoryCodes, true)) {
                return true;
            }

            // An unclassified clause still counts: extraction routinely returns
            // a paragraph it could not file, and refusing to read those would
            // report a contract as having no indemnity because the classifier
            // shrugged.
            $text = mb_substr((string) ($clause['body_text'] ?? ''), 0, 8000);
            foreach ($phrases as $phrase) {
                if ($text !== '' && mb_stripos($text, $phrase) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function durationMonths(?string $from, ?string $to): ?int
    {
        $start = Dates::parse($from);
        $end   = Dates::parse($to);
        if ($start === null || $end === null || $end < $start) {
            return null;
        }

        $diff = $start->diff($end);

        // Rounded to the nearer month: a term stated as "twelve months" often
        // lands a day either side of the anniversary, and reporting 11 for it
        // would be wrong in every sense a reader cares about.
        return ($diff->y * 12) + $diff->m + ((int) $diff->d >= 15 ? 1 : 0);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function activeRules(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_risk_rules
             WHERE environment = ? AND cmp_id = ? AND is_active
             ORDER BY id'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        return array_values($st->fetchAll() ?: []);
    }

    /**
     * A rule with an empty applies_to_types applies to everything; one that
     * names types applies only to those.
     *
     * @param array<string,mixed> $rule
     */
    private static function appliesToType(array $rule, ?string $typeCode): bool
    {
        $types = self::decodeList($rule['applies_to_types'] ?? null);
        if ($types === []) {
            return true;
        }
        if ($typeCode === null || $typeCode === '') {
            return false;
        }

        foreach ($types as $type) {
            if (strtolower($type) === strtolower($typeCode)) {
                return true;
            }
        }

        return false;
    }

    private function assertVersionBelongsToContract(TenantContext $ctx, int $contractId, int $versionId): void
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_document_versions v
             JOIN contract_documents d ON d.id = v.document_id
             WHERE v.id = ? AND d.contract_id = ? AND v.environment = ? AND v.cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$versionId, $contractId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw DomainException::notFound('Document version not found.');
        }
    }

    /**
     * Recompute one assessment from the findings as they now stand.
     *
     * @throws DomainException
     */
    private function rescoreAssessment(TenantContext $ctx, int $assessmentId): void
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_risk_assessments
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$assessmentId, $ctx->environment, $ctx->cmpId]);
        $assessment = $st->fetch();
        if (! is_array($assessment)) {
            return;
        }

        $findingsSt = $this->pdo->prepare(
            'SELECT severity, risk_category, score_impact, review_status
             FROM contract_risk_findings
             WHERE assessment_id = ? AND environment = ? AND cmp_id = ?'
        );
        $findingsSt->execute([$assessmentId, $ctx->environment, $ctx->cmpId]);
        $findings = $findingsSt->fetchAll() ?: [];

        $score      = self::scoreFromFindings($findings);
        $counts     = self::severityCounts($findings);
        $contractId = (int) $assessment['contract_id'];

        $this->pdo->prepare(
            'UPDATE contract_risk_assessments
             SET overall_score = ?, risk_level = ?, category_scores = ?::jsonb,
                 critical_count = ?, high_count = ?, summary = ?
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([
            $score['score'],
            $score['level'],
            json_encode($score['categories'], JSON_UNESCAPED_SLASHES),
            $counts['critical'],
            $counts['high'],
            self::summarise($score, $counts, count($findings)),
            $assessmentId,
            $ctx->environment,
            $ctx->cmpId,
        ]);

        // Only the assessment in force may move the contract's own headline
        // numbers; rescoring an archived one must not overwrite them.
        if (ContractService::toBool($assessment['is_current'])) {
            $this->pdo->prepare(
                'UPDATE contracts SET risk_level = ?, ai_risk_score = ?
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$score['level'], $score['score'], $contractId, $ctx->environment, $ctx->cmpId]);
        }
    }

    /** @return array<string,mixed>|null */
    private function assessmentById(TenantContext $ctx, int $assessmentId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_risk_assessments
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$assessmentId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();
        if (! is_array($row)) {
            return null;
        }

        $assessment = $this->hydrateAssessment($row);

        $findings = $this->pdo->prepare(
            'SELECT f.*, c.heading AS clause_heading, c.clause_number
             FROM contract_risk_findings f
             LEFT JOIN contract_clauses c ON c.id = f.clause_id
             WHERE f.assessment_id = ? AND f.environment = ? AND f.cmp_id = ?
             ORDER BY ' . self::SEVERITY_ORDER . ', f.id'
        );
        $findings->execute([$assessmentId, $ctx->environment, $ctx->cmpId]);
        $assessment['findings'] = array_map(fn (array $r): array => $this->hydrateFinding($r), $findings->fetchAll() ?: []);

        return $assessment;
    }

    /** @return array<string,mixed>|null */
    private function findingById(TenantContext $ctx, int $findingId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT f.*, c.heading AS clause_heading, c.clause_number
             FROM contract_risk_findings f
             LEFT JOIN contract_clauses c ON c.id = f.clause_id
             WHERE f.id = ? AND f.environment = ? AND f.cmp_id = ? LIMIT 1'
        );
        $st->execute([$findingId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrateFinding($row) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateAssessment(array $row): array
    {
        foreach (['category_scores', 'health_breakdown'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $decoded   = json_decode($row[$key], true);
                $row[$key] = is_array($decoded) ? $decoded : [];
            }
        }
        foreach (['id', 'cmp_id', 'contract_id', 'overall_score', 'findings_count', 'critical_count', 'high_count'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['version_id', 'health_score'] as $key) {
            $row[$key] = isset($row[$key]) && $row[$key] !== null ? (int) $row[$key] : null;
        }
        foreach (['ai_used', 'is_current'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = ContractService::toBool($row[$key]);
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateFinding(array $row): array
    {
        foreach (['id', 'assessment_id', 'contract_id', 'cmp_id', 'score_impact'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        foreach (['rule_id', 'clause_id', 'source_page'] as $key) {
            $row[$key] = isset($row[$key]) && $row[$key] !== null ? (int) $row[$key] : null;
        }

        return $row;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private static function severityCounts(array $findings): array
    {
        $counts = ['informational' => 0, 'low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
        foreach ($findings as $finding) {
            if ((string) ($finding['review_status'] ?? 'open') === 'false_positive') {
                continue;
            }
            $severity = (string) ($finding['severity'] ?? 'medium');
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    /**
     * The one-line explanation stored on the assessment.
     *
     * Written here, from the numbers, rather than asked of a model: an
     * assessment that claims to be deterministic cannot carry a summary that
     * changes wording between runs.
     *
     * @param array{score: int, level: string, categories: array<string,int>} $score
     * @param array<string,int> $counts
     */
    private static function summarise(array $score, array $counts, int $total): string
    {
        if ($total === 0) {
            return 'No rule findings. Risk assessed as low (0).';
        }

        $parts = [];
        foreach (['critical', 'high'] as $severity) {
            if (($counts[$severity] ?? 0) > 0) {
                $parts[] = $counts[$severity] . ' ' . $severity;
            }
        }

        return sprintf(
            '%d finding%s%s. Risk assessed as %s (%d).',
            $total,
            $total === 1 ? '' : 's',
            $parts === [] ? '' : ' (' . implode(', ', $parts) . ')',
            $score['level'],
            $score['score']
        );
    }

    // -----------------------------------------------------------------------
    // Value helpers
    // -----------------------------------------------------------------------

    /** @param array<string,mixed> $rule */
    private static function needle(array $rule): string
    {
        $text = $rule['value_text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return trim($text);
        }

        $numeric = $rule['value_numeric'] ?? null;

        return is_numeric($numeric) ? (string) $numeric : '';
    }

    /** @param array<string,mixed> $rule */
    private static function threshold(array $rule): ?float
    {
        if (is_numeric($rule['value_numeric'] ?? null)) {
            return (float) $rule['value_numeric'];
        }
        if (is_numeric($rule['value_text'] ?? null)) {
            return (float) $rule['value_text'];
        }

        return null;
    }

    /** @param array<string,mixed> $rule @return list<string> */
    private static function listValues(array $rule): array
    {
        return self::decodeList($rule['value_list'] ?? null);
    }

    /** @param array<string,mixed> $rule */
    private static function inList(string $value, array $rule): bool
    {
        foreach (self::listValues($rule) as $candidate) {
            if (self::sameText($value, $candidate)) {
                return true;
            }
            if (is_numeric($value) && is_numeric($candidate) && (float) $value === (float) $candidate) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function decodeList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (is_scalar($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $out[] = $text;
                }
            }
        }

        return $out;
    }

    private static function text(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function sameText(mixed $a, mixed $b): bool
    {
        return mb_strtolower(self::text($a)) === mb_strtolower(self::text($b));
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /** A window of the clause around what matched, for the finding card. */
    private static function excerpt(string $body, ?string $anchor): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        if ($anchor !== null && $anchor !== '') {
            $at = mb_stripos($body, $anchor);
            if ($at !== false) {
                $start = max(0, $at - 60);

                return ($start > 0 ? '…' : '') . mb_substr($body, $start, self::EXCERPT_LENGTH)
                    . (mb_strlen($body) > $start + self::EXCERPT_LENGTH ? '…' : '');
            }
        }

        return mb_substr($body, 0, self::EXCERPT_LENGTH) . (mb_strlen($body) > self::EXCERPT_LENGTH ? '…' : '');
    }
}
