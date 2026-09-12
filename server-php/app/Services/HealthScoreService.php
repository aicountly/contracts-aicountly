<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\TenantContext;
use PDO;

/**
 * Contract health: how sound this agreement is, in five numbers and a list of
 * reasons.
 *
 * Health is not the inverse of risk. Risk asks what the contract says; health
 * also asks what the record is missing, because a contract nobody attached a
 * document to is unmanageable whatever its terms are. Both halves are derived
 * from rows — findings that actually fired, and fields that are actually
 * empty — so every point deducted can be pointed at, and the panel never shows
 * a number that came from nowhere.
 *
 * The explanations are the product. A score of 68 tells a user nothing; "no
 * executed copy on file for an active contract" tells them what to do next.
 */
final class HealthScoreService
{
    /** The five panels, in display order. */
    public const CATEGORIES = ['legal', 'commercial', 'compliance', 'operational', 'financial'];

    /**
     * How much each category counts toward the headline number. Legal and
     * compliance carry the most because they are the two that make a contract
     * unenforceable rather than merely inconvenient.
     */
    private const WEIGHTS = [
        'legal'       => 25,
        'commercial'  => 20,
        'compliance'  => 25,
        'operational' => 15,
        'financial'   => 15,
    ];

    /** Which health panel a risk category lands in. */
    private const RISK_TO_HEALTH = [
        'legal'           => 'legal',
        'commercial'      => 'commercial',
        'financial'       => 'financial',
        'compliance'      => 'compliance',
        'operational'     => 'operational',
        'data_protection' => 'compliance',
        'renewal'         => 'operational',
        'counterparty'    => 'commercial',
        'sla'             => 'operational',
    ];

    /** Findings named individually in the explanations; the rest are rolled up. */
    private const NAMED_FINDINGS = ['critical', 'high'];

    private AuditService $audit;

    public function __construct(private PDO $pdo)
    {
        $this->audit = new AuditService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    /**
     * Score one contract and store the result.
     *
     * @return array{overall: int, categories: array<string,int>, explanations: list<string>}
     * @throws DomainException
     */
    public function evaluate(TenantContext $ctx, int $contractId): array
    {
        $contract = (new ContractService($this->pdo))->findOrFail($ctx, $contractId);
        $health   = $this->scoreForContract($ctx, $contract, $this->currentFindings($ctx, $contractId));

        Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contract, $contractId, $health): void {
            $pdo->prepare(
                'UPDATE contracts SET health_score = ?
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$health['overall'], $contractId, $ctx->environment, $ctx->cmpId]);

            // The breakdown rides on the assessment in force rather than on the
            // contract, so the reasons behind last quarter's score survive the
            // next run instead of being overwritten by it.
            $pdo->prepare(
                'UPDATE contract_risk_assessments
                 SET health_score = ?, health_breakdown = ?::jsonb
                 WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND is_current'
            )->execute([
                $health['overall'],
                json_encode($health, JSON_UNESCAPED_SLASHES),
                $contractId,
                $ctx->environment,
                $ctx->cmpId,
            ]);

            // Audited only on a change: a nightly recompute that lands on the
            // same number is not an event, and writing it would bury the times
            // health actually moved.
            $before = $contract['health_score'] ?? null;
            if ($before === null || (int) $before !== $health['overall']) {
                $this->audit->log($ctx, 'contract', $contractId, 'health.scored', $contractId, [
                    'health_score' => ['from' => $before, 'to' => $health['overall']],
                ]);
            }
        });

        return $health;
    }

    /**
     * Score without storing, for a caller that is already inside a write.
     *
     * RiskEngine::assess() uses this so the assessment row it is about to
     * insert carries its health numbers from the start; a second pass to fill
     * them in afterwards would leave a window where the panel shows a fresh
     * risk score beside a stale health score.
     *
     * @param array<string,mixed>       $contract
     * @param list<array<string,mixed>> $findings
     * @return array{overall: int, categories: array<string,int>, explanations: list<string>}
     */
    public function scoreForContract(TenantContext $ctx, array $contract, array $findings): array
    {
        return self::compute($contract, $this->facts($ctx, (int) $contract['id']), $findings);
    }

    /**
     * The completeness half of the score, in one round trip.
     *
     * @return array<string,mixed>
     */
    public function facts(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM contract_clauses cl
                  WHERE cl.contract_id = :cid AND cl.environment = :env AND cl.cmp_id = :cmp)      AS clauses,
                (SELECT COUNT(*) FROM contract_documents d
                  WHERE d.contract_id = :cid AND d.environment = :env AND d.cmp_id = :cmp)         AS documents,
                (SELECT COUNT(*) FROM contract_document_versions v
                   JOIN contract_documents dv ON dv.id = v.document_id
                  WHERE dv.contract_id = :cid AND v.environment = :env AND v.cmp_id = :cmp
                    AND v.is_executed)                                                             AS executed_versions,
                (SELECT COUNT(*) FROM contract_parties p
                  WHERE p.contract_id = :cid AND p.environment = :env AND p.cmp_id = :cmp)         AS parties,
                (SELECT COUNT(*) FROM contract_parties p2
                  WHERE p2.contract_id = :cid AND p2.environment = :env AND p2.cmp_id = :cmp
                    AND p2.party_role <> 'company')                                                AS counterparties,
                (SELECT COUNT(*) FROM contract_payment_schedules s
                  WHERE s.contract_id = :cid AND s.environment = :env AND s.cmp_id = :cmp)         AS payment_schedules,
                (SELECT payment_terms_days FROM contract_commercial_terms t
                  WHERE t.contract_id = :cid AND t.environment = :env AND t.cmp_id = :cmp)         AS payment_terms_days,
                (SELECT COUNT(*) FROM contract_commercial_terms t2
                  WHERE t2.contract_id = :cid AND t2.environment = :env AND t2.cmp_id = :cmp)      AS commercial_terms"
        );
        $st->execute(['cid' => $contractId, 'env' => $ctx->environment, 'cmp' => $ctx->cmpId]);
        $row = $st->fetch();
        $row = is_array($row) ? $row : [];

        return [
            'clauses'            => (int) ($row['clauses'] ?? 0),
            'documents'          => (int) ($row['documents'] ?? 0),
            'executed_versions'  => (int) ($row['executed_versions'] ?? 0),
            'parties'            => (int) ($row['parties'] ?? 0),
            'counterparties'     => (int) ($row['counterparties'] ?? 0),
            'payment_schedules'  => (int) ($row['payment_schedules'] ?? 0),
            'payment_terms_days' => isset($row['payment_terms_days']) && $row['payment_terms_days'] !== null
                ? (int) $row['payment_terms_days']
                : null,
            'commercial_terms'   => ((int) ($row['commercial_terms'] ?? 0)) > 0,
        ];
    }

    /**
     * The scoring itself: pure, so the panel can be reasoned about without a
     * database and a rule change can be argued over on paper.
     *
     * Each category starts whole and loses points for two kinds of thing — risk
     * that fired, and data that is absent. Nothing here is a constant: a
     * contract with every field filled and no findings scores 100 because there
     * is nothing to deduct, not because 100 is the default.
     *
     * @param array<string,mixed>       $contract
     * @param array<string,mixed>       $facts
     * @param list<array<string,mixed>> $findings
     * @return array{overall: int, categories: array<string,int>, explanations: list<string>}
     */
    public static function compute(array $contract, array $facts, array $findings): array
    {
        $scores       = array_fill_keys(self::CATEGORIES, 100);
        $explanations = [];

        $deduct = static function (string $category, int $points, string $reason) use (&$scores, &$explanations): void {
            if ($points <= 0) {
                return;
            }
            $scores[$category] = max(0, $scores[$category] - $points);
            $explanations[]    = sprintf('%s (%s -%d)', $reason, $category, $points);
        };

        // ---- what the contract says -----------------------------------------
        $rolled = 0;
        $rolledPoints = 0;

        foreach ($findings as $finding) {
            if ((string) ($finding['review_status'] ?? 'open') === 'false_positive') {
                continue;
            }

            $severity = Enums::isValid($finding['severity'] ?? null, Enums::RISK_SEVERITIES)
                ? (string) $finding['severity']
                : 'medium';
            $riskCategory = Enums::isValid($finding['risk_category'] ?? null, Enums::RISK_CATEGORIES)
                ? (string) $finding['risk_category']
                : 'legal';
            $category = self::RISK_TO_HEALTH[$riskCategory] ?? 'legal';
            $impact   = (int) ($finding['score_impact'] ?? 0);

            if ($impact <= 0) {
                continue;
            }

            if (in_array($severity, self::NAMED_FINDINGS, true)) {
                $deduct($category, $impact, (string) ($finding['title'] ?? 'Risk finding'));
                continue;
            }

            $scores[$category] = max(0, $scores[$category] - $impact);
            $rolled++;
            $rolledPoints += $impact;
        }

        if ($rolled > 0) {
            $explanations[] = sprintf(
                '%d further risk finding%s of lower severity (-%d in total)',
                $rolled,
                $rolled === 1 ? '' : 's',
                $rolledPoints
            );
        }

        // ---- what the record is missing --------------------------------------
        $blank = static fn (mixed $value): bool => $value === null || trim((string) $value) === '';

        if ((int) $facts['clauses'] === 0) {
            $deduct('legal', 20, 'No clauses have been extracted, so the terms cannot be reviewed');
        }
        if ($blank($contract['governing_law'] ?? null)) {
            $deduct('legal', 12, 'Governing law is not recorded');
        }
        if ($blank($contract['jurisdiction'] ?? null)) {
            $deduct('legal', 8, 'Jurisdiction is not recorded');
        }

        $counterpartyMissing = (int) $facts['counterparties'] === 0
            && $blank($contract['counterparty_name'] ?? null);
        if ($counterpartyMissing) {
            $deduct('commercial', 20, 'No counterparty is recorded');
        }
        if ($blank($contract['total_value'] ?? null) && $blank($contract['recurring_value'] ?? null)) {
            $deduct('commercial', 18, 'No contract value is recorded');
        }
        if (($contract['contract_type_id'] ?? null) === null) {
            $deduct('commercial', 8, 'No contract type is set, so type-specific rules cannot apply');
        }

        $status   = (string) ($contract['status'] ?? 'draft');
        $isLive   = in_array($status, Enums::ACTIVE_STATUSES, true);
        $executed = (int) $facts['executed_versions'] > 0 || ! $blank($contract['execution_date'] ?? null);

        if ($isLive && ! $executed) {
            $deduct('compliance', 25, 'This contract is live with no executed copy on file');
        }
        if ((int) $facts['parties'] === 0) {
            $deduct('compliance', 12, 'No parties are recorded');
        }
        if ((string) ($contract['verification_state'] ?? '') === 'ai_extracted') {
            $deduct('compliance', 10, 'Extracted data has not been verified by a person');
        }

        if ((int) $facts['documents'] === 0) {
            $deduct('operational', 25, 'No document is attached to this contract');
        }
        // An evergreen or perpetual contract has no expiry by design, so its
        // absence is only a gap where a term was meant to be.
        if ($blank($contract['expiry_date'] ?? null)
            && ! in_array((string) ($contract['renewal_type'] ?? ''), ['perpetual', 'evergreen'], true)) {
            $deduct('operational', 15, 'No expiry date is recorded, so this contract cannot reach any renewal report');
        }
        if ($blank($contract['owner_uuid'] ?? null)) {
            $deduct('operational', 12, 'No owner is recorded');
        }

        if ($facts['payment_terms_days'] === null) {
            $deduct('financial', 18, 'Payment terms are not recorded');
        }
        if ($facts['commercial_terms'] !== true) {
            $deduct('financial', 12, 'No commercial terms have been captured');
        }
        if ($isLive && ! $blank($contract['recurring_value'] ?? null) && (int) $facts['payment_schedules'] === 0) {
            $deduct('financial', 10, 'A recurring contract has no payment schedule');
        }

        $overall = 0;
        foreach (self::CATEGORIES as $category) {
            $overall += $scores[$category] * self::WEIGHTS[$category];
        }

        return [
            'overall'      => (int) max(0, min(100, (int) round($overall / array_sum(self::WEIGHTS)))),
            'categories'   => $scores,
            'explanations' => $explanations,
        ];
    }

    /**
     * The findings of the assessment in force, which are the only ones health
     * should answer for — an earlier assessment's findings describe a contract
     * that has since been amended.
     *
     * @return list<array<string,mixed>>
     */
    private function currentFindings(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT f.title, f.severity, f.risk_category, f.score_impact, f.review_status
             FROM contract_risk_findings f
             JOIN contract_risk_assessments a ON a.id = f.assessment_id
             WHERE f.contract_id = ? AND f.environment = ? AND f.cmp_id = ? AND a.is_current
             ORDER BY f.id'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        return array_values($st->fetchAll() ?: []);
    }
}
