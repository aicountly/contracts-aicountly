<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use PDO;

/**
 * The landing screen's aggregates.
 *
 * Every number here is computed by the database over the whole tenant, not by
 * summing a page of rows the browser happens to hold — a total derived from one
 * page is quietly wrong the moment the set is larger than the page, and nobody
 * notices because it still looks like a number.
 *
 * Each figure is one query. The temptation on a dashboard is to fetch the
 * contracts and count them in PHP, which turns a screen into a few hundred
 * round trips on a company with real data; the aggregate FILTER clauses below
 * are what keep the KPI block to a handful of statements no matter how many
 * contracts a company has.
 *
 * The tenant filter and the owner narrowing are the repository's, applied the
 * same way: a user without CONTRACT_VIEW_ALL must not be able to learn the
 * company's total contract value from a tile they could not reach by opening
 * the contracts themselves.
 */
final class DashboardService
{
    /** Fallback expiry window, in days, when a company has configured no ladder. */
    private const DEFAULT_EXPIRY_WINDOW = 90;

    /** How far ahead an obligation counts as "coming due" rather than merely scheduled. */
    private const DEFAULT_OBLIGATION_WINDOW = 30;

    private const TIMELINE_MONTHS = 12;

    public function __construct(private PDO $pdo)
    {
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // KPIs
    // -----------------------------------------------------------------------

    /**
     * The headline figures.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function kpis(TenantContext $ctx, array $filters = []): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $expiryWindow     = $this->expiryWindow($ctx, $filters);
        $obligationWindow = isset($filters['obligation_window_days'])
            ? max(1, min(365, (int) $filters['obligation_window_days']))
            : self::DEFAULT_OBLIGATION_WINDOW;

        $counts = $this->pdo->prepare(
            "SELECT
                 COUNT(*)                                                          AS total,
                 COUNT(*) FILTER (WHERE c.status IN ('active', 'renewal_review'))   AS active,
                 COUNT(*) FILTER (WHERE c.status = 'draft')                         AS draft,
                 COUNT(*) FILTER (WHERE c.status = 'awaiting_approval')             AS awaiting_approval,
                 COUNT(*) FILTER (WHERE c.status = 'awaiting_signature')            AS awaiting_signature,
                 COUNT(*) FILTER (WHERE c.risk_level IN ('high', 'critical'))       AS high_risk,
                 COUNT(*) FILTER (
                     WHERE c.status IN ('active', 'renewal_review')
                       AND c.expiry_date IS NOT NULL
                       AND c.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :window)
                 )                                                                  AS expiring_soon
             FROM contracts c
             {$where}"
        );
        $counts->execute(array_merge($params, ['window' => $expiryWindow]));
        $row = $counts->fetch() ?: [];

        $obligations = $this->obligationCounts($ctx, $filters, $obligationWindow);
        $commitments = $this->commitments($ctx, $filters);

        return [
            'total'                   => (int) ($row['total'] ?? 0),
            'active'                  => (int) ($row['active'] ?? 0),
            'draft'                   => (int) ($row['draft'] ?? 0),
            'awaiting_approval'       => (int) ($row['awaiting_approval'] ?? 0),
            'awaiting_signature'      => (int) ($row['awaiting_signature'] ?? 0),
            'expiring_soon'           => (int) ($row['expiring_soon'] ?? 0),
            'expiring_window_days'    => $expiryWindow,
            'renewals_due'            => $this->renewalsDue($ctx, $filters),
            'obligations_due'         => $obligations['due'],
            'obligations_overdue'     => $obligations['overdue'],
            'obligation_window_days'  => $obligationWindow,
            'high_risk'               => (int) ($row['high_risk'] ?? 0),
            'total_value'             => $this->totalValueByCurrency($ctx, $filters),
            'receivable_commitments'  => $commitments['receivable'],
            'payable_commitments'     => $commitments['payable'],
        ];
    }

    /**
     * Renewal cycles waiting on a decision.
     *
     * Counted on the cycle's own state *or* its decision date having passed,
     * not on state alone: the sweep is what moves a cycle to `review_due`, and
     * a dashboard that only believes the sweep shows nothing on the morning
     * after cron failed — which is exactly the morning somebody needed to see
     * it.
     *
     * @param array<string,mixed> $filters
     */
    private function renewalsDue(TenantContext $ctx, array $filters): int
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             {$where}
               AND (
                     r.status IN ('review_due', 'under_review')
                  OR (r.status = 'not_yet_due' AND r.decision_due_date IS NOT NULL AND r.decision_due_date <= CURRENT_DATE)
                   )"
        );
        $st->execute($params);

        return (int) $st->fetchColumn();
    }

    /**
     * Obligation occurrences coming due and already late.
     *
     * Derived from the due date rather than from the stored status, for the
     * same reason as renewals: the status is maintained by a nightly sweep and
     * the dashboard has to be right on a night the sweep did not run.
     *
     * @param array<string,mixed> $filters
     * @return array{due: int, overdue: int}
     */
    private function obligationCounts(TenantContext $ctx, array $filters, int $windowDays): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT
                 COUNT(*) FILTER (WHERE o.due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :window)) AS due,
                 COUNT(*) FILTER (WHERE o.due_date < CURRENT_DATE)                                                        AS overdue
             FROM obligation_occurrences o
             JOIN contracts c ON c.id = o.contract_id
             {$where}
               AND o.status NOT IN ('completed', 'waived', 'not_applicable')"
        );
        $st->execute(array_merge($params, ['window' => $windowDays]));
        $row = $st->fetch() ?: [];

        return ['due' => (int) ($row['due'] ?? 0), 'overdue' => (int) ($row['overdue'] ?? 0)];
    }

    /**
     * Contract value, split by currency.
     *
     * Never summed across currencies. Adding INR to USD produces a number that
     * is wrong in a way nobody can see, and this product has no rate source it
     * could honestly convert with.
     *
     * @param array<string,mixed> $filters
     * @return list<array{currency: string, total: string, contracts: int}>
     */
    private function totalValueByCurrency(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT c.currency, SUM(c.total_value) AS total, COUNT(*) AS contracts
             FROM contracts c
             {$where}
               AND c.total_value IS NOT NULL
             GROUP BY c.currency
             ORDER BY SUM(c.total_value) DESC, c.currency"
        );
        $st->execute($params);

        return array_map(static fn (array $r): array => [
            'currency'  => (string) $r['currency'],
            // A string all the way out: a contract value through a float comes
            // back off by a paisa, and a dashboard is where somebody notices.
            'total'     => (string) $r['total'],
            'contracts' => (int) $r['contracts'],
        ], $st->fetchAll() ?: []);
    }

    /**
     * Money the company expects to receive and expects to pay, per currency.
     *
     * Read from the commercial terms rather than from the contract's own
     * `total_value`, because only the terms record a direction — without it a
     * customer contract and a vendor contract are the same number with the
     * same sign. `both` counts on each side: a reseller agreement genuinely is
     * a receivable and a payable.
     *
     * Live contracts only. A commitment under a contract that has expired is
     * not a commitment.
     *
     * @param array<string,mixed> $filters
     * @return array{receivable: list<array<string,mixed>>, payable: list<array<string,mixed>>}
     */
    private function commitments(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT t.currency, t.value_direction, SUM(t.total_value) AS total, COUNT(*) AS contracts
             FROM contract_commercial_terms t
             JOIN contracts c ON c.id = t.contract_id
             {$where}
               AND c.status IN ('active', 'renewal_review')
               AND t.total_value IS NOT NULL
             GROUP BY t.currency, t.value_direction"
        );
        $st->execute($params);

        $sides = ['receivable' => [], 'payable' => []];

        foreach ($st->fetchAll() ?: [] as $r) {
            $direction = (string) $r['value_direction'];
            $currency  = (string) $r['currency'];

            foreach (['receivable', 'payable'] as $side) {
                if ($direction !== $side && $direction !== 'both') {
                    continue;
                }
                $bucket = $sides[$side][$currency] ?? ['currency' => $currency, 'total' => '0', 'contracts' => 0];
                // Added as strings: these are NUMERIC(18,2) values and summing
                // enough of them through a float drifts by a paisa a time.
                $bucket['total']       = self::addMoney($bucket['total'], (string) $r['total']);
                $bucket['contracts']  += (int) $r['contracts'];
                $sides[$side][$currency] = $bucket;
            }
        }

        return [
            'receivable' => array_values($sides['receivable']),
            'payable'    => array_values($sides['payable']),
        ];
    }

    // -----------------------------------------------------------------------
    // Charts
    // -----------------------------------------------------------------------

    /**
     * The shapes behind the numbers, one query each.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function charts(TenantContext $ctx, array $filters = []): array
    {
        return [
            'by_status'           => $this->byStatus($ctx, $filters),
            'by_type'             => $this->byType($ctx, $filters),
            'by_department'       => $this->byDepartment($ctx, $filters),
            'value_by_category'   => $this->valueByCategory($ctx, $filters),
            'expiry_timeline'     => $this->expiryTimeline($ctx, $filters),
            'renewal_pipeline'    => $this->renewalPipeline($ctx, $filters),
            'risk_distribution'   => $this->riskDistribution($ctx, $filters),
            'obligations_timeline' => $this->obligationsTimeline($ctx, $filters),
            'customer_vs_vendor'  => $this->customerVsVendor($ctx, $filters),
            'monthly_executed'    => $this->monthlyExecuted($ctx, $filters),
        ];
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function byStatus(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT c.status, COUNT(*) AS contracts FROM contracts c {$where} GROUP BY c.status"
        );
        $st->execute($params);

        $counts = [];
        foreach ($st->fetchAll() ?: [] as $r) {
            $counts[(string) $r['status']] = (int) $r['contracts'];
        }

        // Every status, in lifecycle order, including the empty ones: a bar
        // chart whose categories change shape between two companies is unusable
        // as a comparison, and a zero is information.
        $out = [];
        foreach (Enums::CONTRACT_STATUSES as $status) {
            $out[] = [
                'status' => $status,
                'label'  => Enums::label($status),
                'count'  => $counts[$status] ?? 0,
            ];
        }

        return $out;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function byType(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT c.contract_type_id,
                    COALESCE(ct.name, 'Unclassified') AS name,
                    COALESCE(ct.category, 'general')  AS category,
                    COUNT(*) AS contracts
             FROM contracts c
             LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
             {$where}
             GROUP BY c.contract_type_id, ct.name, ct.category
             ORDER BY COUNT(*) DESC, name"
        );
        $st->execute($params);

        return array_map(static fn (array $r): array => [
            'contract_type_id' => $r['contract_type_id'] === null ? null : (int) $r['contract_type_id'],
            'name'             => (string) $r['name'],
            'category'         => (string) $r['category'],
            'count'            => (int) $r['contracts'],
        ], $st->fetchAll() ?: []);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function byDepartment(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT c.department_id, COALESCE(d.name, 'Unassigned') AS name, COUNT(*) AS contracts
             FROM contracts c
             LEFT JOIN contract_departments d ON d.id = c.department_id
             {$where}
             GROUP BY c.department_id, d.name
             ORDER BY COUNT(*) DESC, name"
        );
        $st->execute($params);

        return array_map(static fn (array $r): array => [
            'department_id' => $r['department_id'] === null ? null : (int) $r['department_id'],
            'name'          => (string) $r['name'],
            'count'         => (int) $r['contracts'],
        ], $st->fetchAll() ?: []);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function valueByCategory(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT COALESCE(ct.category, 'general') AS category, c.currency,
                    SUM(c.total_value) AS total, COUNT(*) AS contracts
             FROM contracts c
             LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
             {$where}
               AND c.total_value IS NOT NULL
             GROUP BY COALESCE(ct.category, 'general'), c.currency
             ORDER BY SUM(c.total_value) DESC"
        );
        $st->execute($params);

        return array_map(static fn (array $r): array => [
            'category'  => (string) $r['category'],
            'currency'  => (string) $r['currency'],
            'total'     => (string) $r['total'],
            'contracts' => (int) $r['contracts'],
        ], $st->fetchAll() ?: []);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function expiryTimeline(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT to_char(date_trunc('month', c.expiry_date), 'YYYY-MM') AS month,
                    COUNT(*) AS contracts,
                    COALESCE(SUM(c.total_value), 0) AS value
             FROM contracts c
             {$where}
               AND c.expiry_date IS NOT NULL
               AND c.status IN ('active', 'renewal_review')
               AND c.expiry_date >= date_trunc('month', CURRENT_DATE)
               AND c.expiry_date < date_trunc('month', CURRENT_DATE) + make_interval(months => :months)
             GROUP BY 1"
        );
        $st->execute(array_merge($params, ['months' => self::TIMELINE_MONTHS]));

        return self::fillMonths($st->fetchAll() ?: [], 0, self::TIMELINE_MONTHS);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function obligationsTimeline(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT to_char(date_trunc('month', o.due_date), 'YYYY-MM') AS month,
                    COUNT(*) AS contracts,
                    COALESCE(SUM(o.amount), 0) AS value
             FROM obligation_occurrences o
             JOIN contracts c ON c.id = o.contract_id
             {$where}
               AND o.status NOT IN ('completed', 'waived', 'not_applicable')
               AND o.due_date >= date_trunc('month', CURRENT_DATE)
               AND o.due_date < date_trunc('month', CURRENT_DATE) + make_interval(months => :months)
             GROUP BY 1"
        );
        $st->execute(array_merge($params, ['months' => self::TIMELINE_MONTHS]));

        return self::fillMonths($st->fetchAll() ?: [], 0, self::TIMELINE_MONTHS);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function monthlyExecuted(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT to_char(date_trunc('month', c.execution_date), 'YYYY-MM') AS month,
                    COUNT(*) AS contracts,
                    COALESCE(SUM(c.total_value), 0) AS value
             FROM contracts c
             {$where}
               AND c.execution_date IS NOT NULL
               AND c.execution_date >= date_trunc('month', CURRENT_DATE) - make_interval(months => :back)
               AND c.execution_date < date_trunc('month', CURRENT_DATE) + INTERVAL '1 month'
             GROUP BY 1"
        );
        $st->execute(array_merge($params, ['back' => self::TIMELINE_MONTHS - 1]));

        return self::fillMonths($st->fetchAll() ?: [], -(self::TIMELINE_MONTHS - 1), self::TIMELINE_MONTHS);
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function renewalPipeline(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT r.status, COUNT(*) AS cycles
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             {$where}
             GROUP BY r.status"
        );
        $st->execute($params);

        $counts = [];
        foreach ($st->fetchAll() ?: [] as $r) {
            $counts[(string) $r['status']] = (int) $r['cycles'];
        }

        $out = [];
        foreach (Enums::RENEWAL_STATUSES as $status) {
            $out[] = ['status' => $status, 'label' => Enums::label($status), 'count' => $counts[$status] ?? 0];
        }

        return $out;
    }

    /** @param array<string,mixed> $filters @return list<array<string,mixed>> */
    private function riskDistribution(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT COALESCE(c.risk_level, 'unrated') AS risk_level, COUNT(*) AS contracts
             FROM contracts c
             {$where}
             GROUP BY COALESCE(c.risk_level, 'unrated')"
        );
        $st->execute($params);

        $counts = [];
        foreach ($st->fetchAll() ?: [] as $r) {
            $counts[(string) $r['risk_level']] = (int) $r['contracts'];
        }

        $out = [];
        foreach (array_merge(Enums::RISK_LEVELS, ['unrated']) as $level) {
            $out[] = ['risk_level' => $level, 'label' => Enums::label($level), 'count' => $counts[$level] ?? 0];
        }

        return $out;
    }

    /**
     * Which side of the business a contract sits on.
     *
     * Taken from the contract type's `counterparty_side` rather than guessed
     * from the counterparty's name — the type is the field a company actually
     * configures, and guessing would put a supplier in the sales chart.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function customerVsVendor(TenantContext $ctx, array $filters): array
    {
        [$where, $params] = $this->scope($ctx, $filters);

        $st = $this->pdo->prepare(
            "SELECT COALESCE(ct.counterparty_side, 'either') AS side, c.currency,
                    COUNT(*) AS contracts, COALESCE(SUM(c.total_value), 0) AS value
             FROM contracts c
             LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
             {$where}
             GROUP BY COALESCE(ct.counterparty_side, 'either'), c.currency
             ORDER BY side, c.currency"
        );
        $st->execute($params);

        return array_map(static fn (array $r): array => [
            'side'      => (string) $r['side'],
            'label'     => Enums::label((string) $r['side']),
            'currency'  => (string) $r['currency'],
            'count'     => (int) $r['contracts'],
            'value'     => (string) $r['value'],
        ], $st->fetchAll() ?: []);
    }

    // -----------------------------------------------------------------------
    // My actions
    // -----------------------------------------------------------------------

    /**
     * What is waiting on this user specifically.
     *
     * Four separate questions rather than one union: they come from unrelated
     * tables with unrelated shapes, and a union would need every column padded
     * to the widest of them for no gain.
     *
     * @return array<string,mixed>
     */
    public function myActions(TenantContext $ctx): array
    {
        $me = $ctx->uuid;

        $approvals = $this->pdo->prepare(
            "SELECT a.id AS assignment_id, a.step_no, a.step_name, a.assigned_at, a.due_at,
                    i.id AS instance_id, i.subject_type, i.workflow_name,
                    c.id AS contract_id, c.contract_number, c.title, c.counterparty_name
             FROM contract_approval_assignments a
             JOIN contract_approval_instances i ON i.id = a.instance_id
             LEFT JOIN contracts c ON c.id = i.contract_id
             WHERE a.environment = ? AND a.cmp_id = ? AND a.approver_uuid = ? AND a.status = 'pending'
               AND i.status IN ('pending', 'in_progress')
             ORDER BY a.due_at NULLS LAST, a.assigned_at
             LIMIT 50"
        );
        $approvals->execute([$ctx->environment, $ctx->cmpId, $me]);

        $obligations = $this->pdo->prepare(
            "SELECT o.id AS occurrence_id, o.due_date, o.status, o.amount,
                    ob.id AS obligation_id, ob.title, ob.responsible_party,
                    c.id AS contract_id, c.contract_number, c.title AS contract_title,
                    (o.due_date < CURRENT_DATE) AS is_overdue
             FROM obligation_occurrences o
             JOIN contract_obligations ob ON ob.id = o.obligation_id
             JOIN contracts c ON c.id = o.contract_id
             WHERE o.environment = ? AND o.cmp_id = ? AND ob.owner_uuid = ?
               AND o.status NOT IN ('completed', 'waived', 'not_applicable')
               AND o.due_date <= CURRENT_DATE + make_interval(days => ?)
             ORDER BY o.due_date
             LIMIT 50"
        );
        $obligations->execute([$ctx->environment, $ctx->cmpId, $me, self::DEFAULT_OBLIGATION_WINDOW]);

        $renewals = $this->pdo->prepare(
            "SELECT r.id AS renewal_id, r.cycle_no, r.status, r.decision_due_date, r.notice_deadline,
                    r.current_expiry, r.recommendation,
                    c.id AS contract_id, c.contract_number, c.title, c.auto_renewal
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             WHERE r.environment = ? AND r.cmp_id = ? AND r.owner_uuid = ?
               AND r.status IN ('not_yet_due', 'review_due', 'under_review')
               AND (r.decision_due_date IS NULL OR r.decision_due_date <= CURRENT_DATE + make_interval(days => ?))
             ORDER BY r.decision_due_date NULLS LAST
             LIMIT 50"
        );
        $renewals->execute([$ctx->environment, $ctx->cmpId, $me, self::DEFAULT_EXPIRY_WINDOW]);

        // Extractions are grouped by contract because the review screen is per
        // contract: a fifty-field extraction is one piece of work, not fifty
        // items in somebody's to-do list.
        $extractions = $this->pdo->prepare(
            "SELECT c.id AS contract_id, c.contract_number, c.title,
                    COUNT(*) AS pending_fields,
                    MIN(e.confidence) AS lowest_confidence,
                    MAX(e.created_at) AS extracted_at
             FROM ai_extractions e
             JOIN contracts c ON c.id = e.contract_id
             WHERE e.environment = ? AND e.cmp_id = ? AND e.review_state = 'pending'
               AND (c.owner_uuid = ? OR c.created_by = ?)
             GROUP BY c.id, c.contract_number, c.title
             ORDER BY MAX(e.created_at) DESC
             LIMIT 50"
        );
        $extractions->execute([$ctx->environment, $ctx->cmpId, $me, $me]);

        $approvalRows   = $approvals->fetchAll() ?: [];
        $obligationRows = array_map(static function (array $r): array {
            $r['is_overdue'] = ContractService::toBool($r['is_overdue']);

            return $r;
        }, $obligations->fetchAll() ?: []);
        $renewalRows    = array_map(static function (array $r): array {
            $r['auto_renewal'] = ContractService::toBool($r['auto_renewal']);

            return $r;
        }, $renewals->fetchAll() ?: []);
        $extractionRows = array_map(static function (array $r): array {
            $r['pending_fields'] = (int) $r['pending_fields'];

            return $r;
        }, $extractions->fetchAll() ?: []);

        return [
            'approvals'      => $approvalRows,
            'obligations'    => $obligationRows,
            'renewals'       => $renewalRows,
            'ai_reviews'     => $extractionRows,
            'total'          => count($approvalRows) + count($obligationRows) + count($renewalRows) + count($extractionRows),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function recentActivity(TenantContext $ctx, int $limit = 20): array
    {
        return (new ActivityService($this->pdo))->recent($ctx, max(1, min(100, $limit)));
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The WHERE clause every aggregate here shares.
     *
     * Contracts are always aliased `c`, so a query that joins obligations or
     * renewals to contracts gets the tenant filter and the owner narrowing for
     * free rather than reimplementing them — and reimplementing them is how one
     * of ten dashboard queries ends up missing the archive filter.
     *
     * @param array<string,mixed> $f
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function scope(TenantContext $ctx, array $f): array
    {
        $clauses = ['c.environment = :env', 'c.cmp_id = :cmp', 'c.archived_at IS NULL'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        // The same row-level rule the repository applies. Without it a tile
        // would report a company-wide total to somebody who cannot open any of
        // the contracts behind it.
        if (! $ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            $clauses[] = '(c.owner_uuid = :me1 OR c.created_by = :me2
                           OR EXISTS (
                               SELECT 1 FROM contract_approval_assignments a
                               JOIN contract_approval_instances i ON i.id = a.instance_id
                               WHERE i.contract_id = c.id AND a.approver_uuid = :me3
                           ))';
            $params['me1'] = $ctx->uuid;
            $params['me2'] = $ctx->uuid;
            $params['me3'] = $ctx->uuid;
        }

        if (! empty($f['bo_id'])) {
            $clauses[]      = 'c.bo_id = :bo';
            $params['bo']   = (int) $f['bo_id'];
        }
        if (! empty($f['contract_type_id'])) {
            $clauses[]      = 'c.contract_type_id = :type';
            $params['type'] = (int) $f['contract_type_id'];
        }
        if (! empty($f['department_id'])) {
            $clauses[]      = 'c.department_id = :dept';
            $params['dept'] = (int) $f['department_id'];
        }
        if (! empty($f['owner_uuid'])) {
            $clauses[]       = 'c.owner_uuid = :owner';
            $params['owner'] = (string) $f['owner_uuid'];
        }
        if (! empty($f['counterparty'])) {
            $clauses[]    = 'c.counterparty_name ILIKE :cp';
            $params['cp'] = '%' . $f['counterparty'] . '%';
        }
        if (! empty($f['status']) && Enums::isValid($f['status'], Enums::CONTRACT_STATUSES)) {
            $clauses[]        = 'c.status = :status';
            $params['status'] = (string) $f['status'];
        }
        if (! empty($f['risk_level']) && Enums::isValid($f['risk_level'], Enums::RISK_LEVELS)) {
            $clauses[]      = 'c.risk_level = :risk';
            $params['risk'] = (string) $f['risk_level'];
        }

        // The date window narrows on the effective date, which is the one the
        // repository's own from/to filters use — a KPI tile has to count the
        // same rows the list shows when the user clicks through it.
        if (! empty($f['date_from'])) {
            $clauses[]      = 'c.effective_date >= :from';
            $params['from'] = (string) $f['date_from'];
        }
        if (! empty($f['date_to'])) {
            $clauses[]    = 'c.effective_date <= :to';
            $params['to'] = (string) $f['date_to'];
        }

        return ['WHERE ' . implode("\n  AND ", $clauses), $params];
    }

    /**
     * The company's configured expiry alert window, widest step first.
     *
     * The dashboard's "expiring soon" and the nightly sweep's first warning
     * should mean the same thing: a tile that lights up a month after people
     * started receiving emails about the same contracts is a tile nobody
     * trusts.
     *
     * @param array<string,mixed> $filters
     */
    private function expiryWindow(TenantContext $ctx, array $filters): int
    {
        if (isset($filters['expiry_window_days'])) {
            return max(1, min(3650, (int) $filters['expiry_window_days']));
        }

        $st = $this->pdo->prepare(
            'SELECT expiry_alert_days FROM contract_settings WHERE environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);
        $raw = $st->fetchColumn();

        $ladder = \App\Support\Dates::reminderLadder(
            is_string($raw) ? $raw : null,
            [self::DEFAULT_EXPIRY_WINDOW]
        );

        return $ladder === [] ? self::DEFAULT_EXPIRY_WINDOW : max($ladder);
    }

    /**
     * Pad a month series so the chart has a bucket for every month.
     *
     * A timeline that omits the quiet months compresses them out of existence,
     * and a reader sees a steady stream where there was a gap.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{month: string, count: int, value: string}>
     */
    private static function fillMonths(array $rows, int $startOffset, int $months): array
    {
        $found = [];
        foreach ($rows as $r) {
            $found[(string) $r['month']] = [
                'count' => (int) $r['contracts'],
                'value' => (string) $r['value'],
            ];
        }

        $out    = [];
        $cursor = new \DateTimeImmutable(date('Y-m-01'));
        $cursor = $cursor->modify(($startOffset >= 0 ? '+' : '') . $startOffset . ' months');

        for ($i = 0; $i < $months; $i++) {
            $key   = $cursor->format('Y-m');
            $out[] = [
                'month' => $key,
                'count' => $found[$key]['count'] ?? 0,
                'value' => $found[$key]['value'] ?? '0',
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $out;
    }

    /**
     * Add two NUMERIC(18,2) strings without going through a float.
     *
     * bcmath is not guaranteed on these hosts, so the arithmetic is done in
     * integer paise and formatted back.
     */
    private static function addMoney(string $a, string $b): string
    {
        $toMinor = static function (string $value): int {
            $clean = trim($value) === '' ? '0' : trim($value);
            $sign  = str_starts_with($clean, '-') ? -1 : 1;
            $clean = ltrim($clean, '+-');
            [$whole, $frac] = array_pad(explode('.', $clean, 2), 2, '0');

            return $sign * ((int) $whole * 100 + (int) str_pad(substr($frac, 0, 2), 2, '0'));
        };

        $sum = $toMinor($a) + $toMinor($b);

        return sprintf('%s%d.%02d', $sum < 0 ? '-' : '', intdiv(abs($sum), 100), abs($sum) % 100);
    }
}
