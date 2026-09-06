<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use PDO;
use PDOStatement;

/**
 * The four aggregates behind the landing screen.
 *
 * Every figure here is computed by PostgreSQL over the whole matching set. A
 * browser that summed the page of contracts it happens to hold would produce a
 * total that is quietly wrong the moment that page is not the whole set, so the
 * narrowing is part of the question asked of the database and nothing is
 * sampled, estimated or carried over between requests.
 *
 * Two narrowings apply to all of it. The tenant filter — `environment` and
 * `cmp_id` from the TenantContext, never from request input — is the one this
 * product cannot afford to get wrong. The second is the row-level visibility
 * rule the repository applies: without CONTRACT_VIEW_ALL a user sees only the
 * contracts they own, created, or were asked to approve. A count that includes
 * rows the caller cannot open is a leak wearing a number, and a tile that links
 * through to a list which then shows fewer rows than the tile claimed is a bug
 * report in both screens, so the same predicate is repeated here.
 *
 * Queries are grouped by figure rather than written one per number: ten of the
 * thirteen KPIs come out of a single scan and six of the ten charts out of one
 * set of GROUPING SETS, because a dashboard that fires twenty-five round trips
 * is a dashboard that times out.
 */
final class DashboardService
{
    /**
     * Rows one personal queue may return.
     *
     * The panel renders six of each group and links to the full queue for the
     * rest, so this is a ceiling on a preview rather than a page size — it
     * stops a user with a thousand open obligations from downloading all of
     * them to look at half a dozen. The exact badge numbers come from `/me`.
     */
    private const MAX_PER_GROUP = 25;

    /** Months a timeline chart spans, including the current one. */
    private const TIMELINE_MONTHS = 12;

    /**
     * Counterparties drawn individually before the rest are summed.
     *
     * Eight fits the card without a legend that needs scrolling, and a ninth
     * bar has never changed anyone's reading of a concentration chart.
     */
    private const TOP_COUNTERPARTIES = 8;

    /** Mirrors the column default of contract_settings.expiry_alert_days. */
    private const EXPIRY_LADDER = [90, 60, 30, 15, 7];

    /** Which side of the table a contract type puts us on. Mirrors ck_contract_types_side. */
    private const SIDE_LABELS = [
        'customer' => 'Customer',
        'vendor'   => 'Vendor',
        'internal' => 'Internal',
        'either'   => 'Either side',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Key figures
    // -----------------------------------------------------------------------

    /**
     * The thirteen figures the KPI row prints, plus the two descriptors that
     * let the tiles label themselves honestly.
     *
     * The money figures and the risk count are withheld — null, never zero —
     * from a caller whose role does not include them. A zero is a real answer
     * about the portfolio and must not be confused with "not available to you".
     *
     * @param array<string,mixed> $filters the keys DashboardController::filters() emits
     * @return array<string,mixed>
     */
    public function kpis(TenantContext $ctx, array $filters): array
    {
        $settings         = $this->settings($ctx);
        [$clauses, $base] = $this->portfolio($ctx, $filters);
        $where            = 'WHERE ' . implode("\n               AND ", $clauses);

        $currency = $settings['currency'];
        $within   = $settings['expiring_within_days'];

        // One scan for ten of the thirteen. FILTER is what makes that honest:
        // each figure is its own predicate over the same set of rows, so the
        // tiles cannot disagree with each other about what the portfolio was.
        //
        // The money sums are restricted to contracts denominated in the
        // company's own currency. There is no rate table in this schema, and
        // adding dollars to rupees produces a total that is wrong in a way
        // nobody reading the tile can see; the currency travels with the
        // figures so the tile can say which one it counted.
        $portfolio = $this->query(
            "SELECT COUNT(*)                                                              AS total_contracts,
                    COUNT(*) FILTER (WHERE c.status IN ('active', 'renewal_review'))      AS active,
                    COUNT(*) FILTER (WHERE c.status = 'draft')                            AS draft,
                    COUNT(*) FILTER (WHERE c.status = 'awaiting_approval')                AS awaiting_approval,
                    COUNT(*) FILTER (WHERE c.status = 'awaiting_signature')               AS awaiting_signature,
                    COUNT(*) FILTER (WHERE c.expiry_date IS NOT NULL
                                       AND c.expiry_date BETWEEN CURRENT_DATE
                                                             AND CURRENT_DATE + make_interval(days => :within))
                                                                                          AS expiring_soon,
                    COUNT(*) FILTER (WHERE c.risk_level IN ('high', 'critical'))          AS high_risk,
                    COALESCE(SUM(c.total_value) FILTER (WHERE c.currency = :ccy_all), 0)::numeric(18,2)
                                                                                          AS total_value,
                    COALESCE(SUM(c.total_value) FILTER (WHERE c.currency = :ccy_in
                                                          AND ct.counterparty_side = 'customer'), 0)::numeric(18,2)
                                                                                          AS receivable_commitments,
                    COALESCE(SUM(c.total_value) FILTER (WHERE c.currency = :ccy_out
                                                          AND ct.counterparty_side = 'vendor'), 0)::numeric(18,2)
                                                                                          AS payable_commitments
             FROM contracts c
             LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
             {$where}",
            array_merge($base, [
                'within'  => $within,
                'ccy_all' => $currency,
                'ccy_in'  => $currency,
                'ccy_out' => $currency,
            ])
        )->fetch();

        // 'due' and 'overdue' rather than a date comparison, because both tiles
        // link to the obligations queue filtered on exactly those statuses and
        // the nightly sweep is what moves an occurrence between them.
        $obligations = $this->query(
            "SELECT COUNT(*) FILTER (WHERE occ.status = 'due')     AS obligations_due,
                    COUNT(*) FILTER (WHERE occ.status = 'overdue') AS overdue_obligations
             FROM obligation_occurrences occ
             JOIN contracts c ON c.id = occ.contract_id
             WHERE occ.environment = :occ_env
               AND occ.cmp_id = :occ_cmp
               AND " . implode("\n               AND ", $clauses),
            array_merge($base, ['occ_env' => $ctx->environment, 'occ_cmp' => $ctx->cmpId])
        )->fetch();

        // An undecided cycle whose review window has opened. A cycle carrying a
        // decision is settled work, however recently it was settled.
        $renewals = $this->query(
            "SELECT COUNT(*) AS renewals_due
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             WHERE r.environment = :ren_env
               AND r.cmp_id = :ren_cmp
               AND r.status IN ('review_due', 'under_review')
               AND r.decision IS NULL
               AND " . implode("\n               AND ", $clauses),
            array_merge($base, ['ren_env' => $ctx->environment, 'ren_cmp' => $ctx->cmpId])
        )->fetch();

        $portfolio   = is_array($portfolio) ? $portfolio : [];
        $obligations = is_array($obligations) ? $obligations : [];
        $renewals    = is_array($renewals) ? $renewals : [];

        $money = $ctx->has(Permissions::COMMERCIALS_VIEW);
        $risk  = $ctx->has(Permissions::AI_RISK_VIEW);

        return [
            'total_contracts'        => (int) ($portfolio['total_contracts'] ?? 0),
            'active'                 => (int) ($portfolio['active'] ?? 0),
            'draft'                  => (int) ($portfolio['draft'] ?? 0),
            'awaiting_approval'      => (int) ($portfolio['awaiting_approval'] ?? 0),
            'awaiting_signature'     => (int) ($portfolio['awaiting_signature'] ?? 0),
            'expiring_soon'          => (int) ($portfolio['expiring_soon'] ?? 0),
            'renewals_due'           => (int) ($renewals['renewals_due'] ?? 0),
            'obligations_due'        => (int) ($obligations['obligations_due'] ?? 0),
            'overdue_obligations'    => (int) ($obligations['overdue_obligations'] ?? 0),
            'high_risk'              => $risk ? (int) ($portfolio['high_risk'] ?? 0) : null,
            'total_value'            => $money ? (string) ($portfolio['total_value'] ?? '0.00') : null,
            'receivable_commitments' => $money ? (string) ($portfolio['receivable_commitments'] ?? '0.00') : null,
            'payable_commitments'    => $money ? (string) ($portfolio['payable_commitments'] ?? '0.00') : null,
            'currency'               => $currency,
            'expiring_within_days'   => $within,
        ];
    }

    // -----------------------------------------------------------------------
    // Charts
    // -----------------------------------------------------------------------

    /**
     * The ten series the portfolio section draws.
     *
     * Each bucket carries a stable key and a printable label, so the page can
     * render any of them through the same three chart components without
     * knowing what the series is about. A series the caller's role does not
     * cover comes back empty rather than absent: the shape the SPA destructures
     * stays the same whoever asks.
     *
     * @param array<string,mixed> $filters
     * @return array<string, list<array<string,mixed>>>
     */
    public function charts(TenantContext $ctx, array $filters): array
    {
        [$clauses, $params] = $this->portfolio($ctx, $filters);
        $currency           = $this->settings($ctx)['currency'];

        $mix = $this->mix($clauses, $params, $currency);

        return [
            'by_status'            => $mix['by_status'],
            'by_type'              => $mix['by_type'],
            'by_department'        => $mix['by_department'],
            'value_by_category'    => $ctx->has(Permissions::COMMERCIALS_VIEW) ? $mix['value_by_category'] : [],
            'expiry_timeline'      => $this->expiryTimeline($clauses, $params),
            'renewal_pipeline'     => $this->renewalPipeline($ctx, $clauses, $params),
            'risk_distribution'    => $ctx->has(Permissions::AI_RISK_VIEW) ? $mix['risk_distribution'] : [],
            'obligations_timeline' => $this->obligationTimeline($ctx, $clauses, $params),
            'customer_vs_vendor'   => $mix['customer_vs_vendor'],
            'monthly_executed'     => $this->executedTimeline($clauses, $params),
            'counterparty_mix'     => $this->counterpartyConcentration($clauses, $params),
            'approval_throughput'  => $this->approvalThroughput($ctx, $clauses, $params),
        ];
    }

    /**
     * Who the portfolio is actually with.
     *
     * The head of the list is the answer anyone wants — concentration is a risk
     * question, not a directory — so the tail is summed into one "everyone
     * else" point rather than dropped. A chart that silently truncates makes a
     * spread portfolio and a concentrated one look identical.
     *
     * Grouped on the counterparty name held on the contract rather than on the
     * linked contact id: a contract signed before the counterparty was linked
     * still belongs in the mix, and dropping it would understate exactly the
     * concentration this chart exists to show.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function counterpartyConcentration(array $clauses, array $params): array
    {
        $where = 'WHERE ' . implode("\n               AND ", $clauses);

        $rows = $this->query(
            "SELECT COALESCE(NULLIF(TRIM(c.counterparty_name), ''), 'Unnamed') AS label,
                    COUNT(*)                                                   AS n
             FROM contracts c
             {$where}
             GROUP BY 1",
            $params
        )->fetchAll() ?: [];

        $points = [];
        foreach ($rows as $row) {
            $label    = (string) $row['label'];
            $points[] = self::countPoint(mb_strtolower($label), $label, $row['n']);
        }

        $points = self::ranked($points, 'count');
        if (count($points) <= self::TOP_COUNTERPARTIES) {
            return $points;
        }

        $head = array_slice($points, 0, self::TOP_COUNTERPARTIES);
        $tail = array_slice($points, self::TOP_COUNTERPARTIES);
        $rest = 0;
        foreach ($tail as $point) {
            $rest += (int) $point['count'];
        }

        $head[] = self::countPoint('__rest', sprintf('%d others', count($tail)), $rest);

        return $head;
    }

    /**
     * How long approvals are taking, by the month they finished.
     *
     * Measured on completed runs only. An approval still sitting on someone's
     * desk has no duration yet, and counting it as zero — or as its age so far
     * — would make a stuck queue look like a fast one.
     *
     * The average is in whole days because that is the unit anyone acts on: a
     * cycle time reported to two decimal places invites a conversation about
     * the decimals rather than about the four days.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function approvalThroughput(TenantContext $ctx, array $clauses, array $params): array
    {
        $rows = $this->query(
            "SELECT to_char(i.completed_at, 'YYYY-MM') AS bucket,
                    COUNT(*) AS n,
                    AVG(EXTRACT(EPOCH FROM (i.completed_at - i.submitted_at)) / 86400.0) AS avg_days
             FROM contract_approval_instances i
             JOIN contracts c ON c.id = i.contract_id
             WHERE i.environment = :apt_env
               AND i.cmp_id = :apt_cmp
               AND i.completed_at IS NOT NULL
               AND i.submitted_at IS NOT NULL
               AND i.completed_at >= (CURRENT_DATE - INTERVAL '12 months')
               AND " . implode("\n               AND ", $clauses) . '
             GROUP BY 1
             ORDER BY 1',
            array_merge($params, ['apt_env' => $ctx->environment, 'apt_cmp' => $ctx->cmpId])
        )->fetchAll() ?: [];

        $points = [];
        foreach ($rows as $row) {
            $bucket   = (string) $row['bucket'];
            $point    = self::monthPoint($bucket, $row['n']);
            // Null rather than 0 where there is nothing to average: an empty
            // month is not a zero-day month.
            $point['avg_days'] = $row['avg_days'] === null ? null : (int) round((float) $row['avg_days']);
            $points[] = $point;
        }

        return $points;
    }

    /**
     * Six categorical series from one pass over the contracts table.
     *
     * GROUPING SETS is the whole point: status mix, type mix, department mix,
     * value by category, risk mix and which side of the table we are on are six
     * different cuts of the same rows, and asking for them separately would be
     * six scans that a concurrent write could make disagree with each other.
     * GROUPING() tells the sets apart afterwards — it distinguishes "this
     * column is not part of this grouping" from "this column is null here",
     * which a plain IS NULL test cannot.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $params
     * @return array<string, list<array<string,mixed>>>
     */
    private function mix(array $clauses, array $params, string $currency): array
    {
        $where = 'WHERE ' . implode("\n                   AND ", $clauses);

        $rows = $this->query(
            "SELECT CASE
                        WHEN GROUPING(c.status) = 0             THEN 'status'
                        WHEN GROUPING(c.risk_level) = 0         THEN 'risk'
                        WHEN GROUPING(ct.counterparty_side) = 0 THEN 'side'
                        WHEN GROUPING(ct.category) = 0          THEN 'category'
                        WHEN GROUPING(c.contract_type_id) = 0   THEN 'type'
                        ELSE                                         'department'
                    END                      AS series,
                    c.status                 AS status,
                    c.risk_level             AS risk_level,
                    ct.counterparty_side     AS counterparty_side,
                    ct.category              AS category,
                    c.contract_type_id       AS contract_type_id,
                    ct.name                  AS type_name,
                    c.department_id          AS department_id,
                    dep.name                 AS department_name,
                    COUNT(*)                 AS n,
                    COALESCE(SUM(c.total_value) FILTER (WHERE c.currency = :mix_ccy), 0)::numeric(18,2) AS amount
             FROM contracts c
             LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
             LEFT JOIN contract_departments dep ON dep.id = c.department_id
             {$where}
             GROUP BY GROUPING SETS (
                 (c.status),
                 (c.risk_level),
                 (ct.counterparty_side),
                 (ct.category),
                 (c.contract_type_id, ct.name),
                 (c.department_id, dep.name)
             )",
            array_merge($params, ['mix_ccy' => $currency])
        )->fetchAll() ?: [];

        $status     = [];
        $risk       = [];
        $side       = [];
        $category   = [];
        $type       = [];
        $department = [];

        foreach ($rows as $row) {
            $count = (int) $row['n'];

            switch ((string) $row['series']) {
                case 'status':
                    $key          = (string) $row['status'];
                    $status[$key] = self::countPoint($key, Enums::label($key), $count);
                    break;

                case 'risk':
                    // A contract nobody has assessed is a real and interesting
                    // bucket — it is the portfolio nobody has looked at — so it
                    // is named rather than dropped.
                    $key        = $row['risk_level'] === null ? 'unassessed' : (string) $row['risk_level'];
                    $risk[$key] = self::countPoint(
                        $key,
                        $key === 'unassessed' ? 'Not assessed' : Enums::label($key),
                        $count
                    );
                    break;

                case 'side':
                    $key        = $row['counterparty_side'] === null ? 'unclassified' : (string) $row['counterparty_side'];
                    $side[$key] = self::countPoint($key, self::SIDE_LABELS[$key] ?? 'Unclassified', $count);
                    break;

                case 'category':
                    $key        = $row['category'] === null ? 'uncategorised' : (string) $row['category'];
                    $category[] = self::moneyPoint(
                        $key,
                        $key === 'uncategorised' ? 'Uncategorised' : Enums::label($key),
                        (string) $row['amount'],
                        $currency
                    );
                    break;

                case 'type':
                    $type[] = self::countPoint(
                        $row['contract_type_id'] === null ? 'untyped' : (string) $row['contract_type_id'],
                        $row['type_name'] === null ? 'No type' : (string) $row['type_name'],
                        $count
                    );
                    break;

                default:
                    $department[] = self::countPoint(
                        $row['department_id'] === null ? 'unassigned' : (string) $row['department_id'],
                        $row['department_name'] === null ? 'No department' : (string) $row['department_name'],
                        $count
                    );
                    break;
            }
        }

        return [
            // The three vocabularies come back in their own order rather than
            // by size: a status mix reads as a lifecycle, and risk reads as a
            // ladder. The open-ended ones are ranked, because the chart draws
            // the head of the list and a long tail of ones is the normal shape.
            'by_status'          => self::inOrder($status, Enums::CONTRACT_STATUSES),
            'risk_distribution'  => self::inOrder($risk, Enums::RISK_LEVELS),
            'customer_vs_vendor' => self::inOrder($side, array_keys(self::SIDE_LABELS)),
            'by_type'            => self::ranked($type, 'count'),
            'by_department'      => self::ranked($department, 'count'),
            'value_by_category'  => self::ranked($category, 'amount'),
        ];
    }

    /**
     * Contracts reaching their expiry date, by month, over the next year.
     *
     * The month grid is generated in the database and left-joined to the
     * counts, so a month in which nothing expires is a zero column rather than
     * a gap that makes the next month look adjacent to this one.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function expiryTimeline(array $clauses, array $params): array
    {
        $where = 'WHERE ' . implode("\n                       AND ", $clauses);

        $rows = $this->query(
            "WITH months AS (
                 SELECT to_char(m, 'YYYY-MM') AS bucket
                 FROM generate_series(
                          date_trunc('month', CURRENT_DATE),
                          date_trunc('month', CURRENT_DATE) + make_interval(months => :span),
                          INTERVAL '1 month') AS m
             ),
             hits AS (
                 SELECT to_char(c.expiry_date, 'YYYY-MM') AS bucket, COUNT(*) AS n
                 FROM contracts c
                 {$where}
                       AND c.expiry_date >= date_trunc('month', CURRENT_DATE)
                       AND c.expiry_date <  date_trunc('month', CURRENT_DATE) + make_interval(months => :span_end)
                 GROUP BY 1
             )
             SELECT m.bucket, COALESCE(h.n, 0) AS n
             FROM months m
             LEFT JOIN hits h ON h.bucket = m.bucket
             ORDER BY m.bucket",
            array_merge($params, [
                'span'     => self::TIMELINE_MONTHS - 1,
                'span_end' => self::TIMELINE_MONTHS,
            ])
        )->fetchAll() ?: [];

        return array_map(
            static fn (array $row): array => self::monthPoint((string) $row['bucket'], $row['n']),
            $rows
        );
    }

    /**
     * Contracts executed per month over the year to date.
     *
     * Keyed on execution_date — when the agreement was signed — not on
     * effective_date, which is when its terms begin and is routinely backdated.
     * A contract with no execution date has not been recorded as signed and is
     * absent by design rather than by accident.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function executedTimeline(array $clauses, array $params): array
    {
        $where = 'WHERE ' . implode("\n                       AND ", $clauses);

        $rows = $this->query(
            "WITH months AS (
                 SELECT to_char(m, 'YYYY-MM') AS bucket
                 FROM generate_series(
                          date_trunc('month', CURRENT_DATE) - make_interval(months => :span),
                          date_trunc('month', CURRENT_DATE),
                          INTERVAL '1 month') AS m
             ),
             hits AS (
                 SELECT to_char(c.execution_date, 'YYYY-MM') AS bucket, COUNT(*) AS n
                 FROM contracts c
                 {$where}
                       AND c.execution_date >= date_trunc('month', CURRENT_DATE) - make_interval(months => :span_start)
                       AND c.execution_date <  date_trunc('month', CURRENT_DATE) + INTERVAL '1 month'
                 GROUP BY 1
             )
             SELECT m.bucket, COALESCE(h.n, 0) AS n
             FROM months m
             LEFT JOIN hits h ON h.bucket = m.bucket
             ORDER BY m.bucket",
            array_merge($params, [
                'span'       => self::TIMELINE_MONTHS - 1,
                'span_start' => self::TIMELINE_MONTHS - 1,
            ])
        )->fetchAll() ?: [];

        return array_map(
            static fn (array $row): array => self::monthPoint((string) $row['bucket'], $row['n']),
            $rows
        );
    }

    /**
     * Open obligations by the month they fall due, with everything already past
     * due collected into a single leading bucket.
     *
     * Overdue work has no useful month — it is late now, whichever month it was
     * meant to land in — and spreading it back across the year would hide the
     * one column anybody needs to act on. The bucket keeps its own name so the
     * page can draw it as a hazard.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function obligationTimeline(TenantContext $ctx, array $clauses, array $params): array
    {
        $rows = $this->query(
            "WITH months AS (
                 SELECT to_char(m, 'YYYY-MM') AS bucket
                 FROM generate_series(
                          date_trunc('month', CURRENT_DATE),
                          date_trunc('month', CURRENT_DATE) + make_interval(months => :span),
                          INTERVAL '1 month') AS m
             ),
             buckets AS (
                 SELECT 'overdue' AS bucket, 0 AS ord
                 UNION ALL
                 SELECT bucket, 1 FROM months
             ),
             hits AS (
                 SELECT CASE WHEN occ.status = 'overdue'
                             THEN 'overdue'
                             ELSE to_char(occ.due_date, 'YYYY-MM')
                        END       AS bucket,
                        COUNT(*)  AS n
                 FROM obligation_occurrences occ
                 JOIN contracts c ON c.id = occ.contract_id
                 WHERE occ.environment = :occ_env
                   AND occ.cmp_id = :occ_cmp
                   AND occ.status IN ('upcoming', 'due', 'overdue')
                   AND (occ.status = 'overdue'
                        OR (occ.due_date >= date_trunc('month', CURRENT_DATE)
                            AND occ.due_date < date_trunc('month', CURRENT_DATE) + make_interval(months => :span_end)))
                   AND " . implode("\n                   AND ", $clauses) . "
                 GROUP BY 1
             )
             SELECT b.bucket, COALESCE(h.n, 0) AS n
             FROM buckets b
             LEFT JOIN hits h ON h.bucket = b.bucket
             ORDER BY b.ord, b.bucket",
            array_merge($params, [
                'occ_env'  => $ctx->environment,
                'occ_cmp'  => $ctx->cmpId,
                'span'     => self::TIMELINE_MONTHS - 1,
                'span_end' => self::TIMELINE_MONTHS,
            ])
        )->fetchAll() ?: [];

        return array_map(
            static function (array $row): array {
                $bucket = (string) $row['bucket'];

                return $bucket === 'overdue'
                    ? self::countPoint('overdue', 'Overdue', $row['n'])
                    : self::monthPoint($bucket, $row['n']);
            },
            $rows
        );
    }

    /**
     * Where each live renewal cycle has got to.
     *
     * Closed cycles are left out: a company three years in has hundreds of them
     * and they would bury the handful somebody still has to decide.
     *
     * @param list<string>        $clauses
     * @param array<string,mixed> $params
     * @return list<array<string,mixed>>
     */
    private function renewalPipeline(TenantContext $ctx, array $clauses, array $params): array
    {
        $rows = $this->query(
            "SELECT r.status AS bucket, COUNT(*) AS n
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             WHERE r.environment = :ren_env
               AND r.cmp_id = :ren_cmp
               AND r.status <> 'closed'
               AND " . implode("\n               AND ", $clauses) . "
             GROUP BY r.status",
            array_merge($params, ['ren_env' => $ctx->environment, 'ren_cmp' => $ctx->cmpId])
        )->fetchAll() ?: [];

        $points = [];
        foreach ($rows as $row) {
            $key          = (string) $row['bucket'];
            $points[$key] = self::countPoint($key, Enums::label($key), $row['n']);
        }

        return self::inOrder($points, Enums::RENEWAL_STATUSES);
    }

    // -----------------------------------------------------------------------
    // Waiting on people
    // -----------------------------------------------------------------------

    /**
     * The work assigned to this caller, in four queues.
     *
     * Deliberately unfiltered: "what is waiting on me" does not change because
     * somebody narrowed the portfolio to one department, and the page does not
     * send the filter bar's values here.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public function myActions(TenantContext $ctx): array
    {
        return [
            'approvals'   => $this->myApprovals($ctx),
            'obligations' => $this->myObligations($ctx),
            'renewals'    => $this->myRenewals($ctx),
            // The AI review queue is a tab this role may not even have; an
            // empty list is the honest answer for someone who cannot open it.
            'ai_reviews'  => $ctx->has(Permissions::AI_USE) ? $this->myAiReviews($ctx) : [],
        ];
    }

    /**
     * Steps assigned to the caller on a run that is sitting at that step.
     *
     * No visibility predicate here, and that is not an oversight: being an
     * approver on a contract is one of the three things that grants sight of
     * it, so every row this returns is one the repository would also show.
     *
     * @return list<array<string,mixed>>
     */
    private function myApprovals(TenantContext $ctx): array
    {
        $rows = $this->query(
            "SELECT a.id                                    AS id,
                    i.contract_id                           AS contract_id,
                    c.contract_number                       AS contract_number,
                    c.title                                 AS contract_title,
                    COALESCE(c.title, i.workflow_name)      AS title,
                    a.step_name                             AS description,
                    a.due_at::date                          AS due_date,
                    (a.due_at::date - CURRENT_DATE)         AS days_remaining,
                    i.status                                AS status,
                    c.risk_level                            AS risk_level,
                    c.total_value                           AS amount,
                    c.currency                              AS currency
             FROM contract_approval_assignments a
             JOIN contract_approval_instances i ON i.id = a.instance_id
             LEFT JOIN contracts c ON c.id = i.contract_id
                                  AND c.environment = a.environment
                                  AND c.cmp_id = a.cmp_id
             WHERE a.environment = :env
               AND a.cmp_id = :cmp
               AND a.approver_uuid = :me
               AND a.status = 'pending'
               AND i.status IN ('pending', 'in_progress')
               AND a.step_no = i.current_step
             ORDER BY a.due_at ASC NULLS LAST, a.assigned_at ASC, a.id ASC
             LIMIT :lim",
            ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'me' => $ctx->uuid, 'lim' => self::MAX_PER_GROUP]
        )->fetchAll() ?: [];

        return array_map(static fn (array $row): array => self::actionRow($row), $rows);
    }

    /**
     * Occurrences that are due or late and land on this caller.
     *
     * An obligation with no owner falls to whoever owns the contract, which is
     * where the responsibility actually sits once nobody has been named.
     *
     * @return list<array<string,mixed>>
     */
    private function myObligations(TenantContext $ctx): array
    {
        [$visibility, $visibilityParams] = $this->visibility($ctx);

        $rows = $this->query(
            "SELECT occ.id                              AS id,
                    occ.contract_id                     AS contract_id,
                    c.contract_number                   AS contract_number,
                    c.title                             AS contract_title,
                    o.title                             AS title,
                    o.obligation_type                   AS obligation_type,
                    occ.due_date                        AS due_date,
                    (occ.due_date - CURRENT_DATE)       AS days_remaining,
                    occ.status                          AS status,
                    c.risk_level                        AS risk_level,
                    COALESCE(occ.amount, o.amount)      AS amount,
                    COALESCE(o.currency, c.currency)    AS currency
             FROM obligation_occurrences occ
             JOIN contract_obligations o ON o.id = occ.obligation_id
             JOIN contracts c ON c.id = occ.contract_id
             WHERE occ.environment = :env
               AND occ.cmp_id = :cmp
               AND c.environment = :env2
               AND c.cmp_id = :cmp2
               AND c.archived_at IS NULL
               AND occ.status IN ('due', 'overdue')
               AND (o.owner_uuid = :me OR (o.owner_uuid IS NULL AND c.owner_uuid = :me2))"
                . ($visibility === '' ? '' : "\n               AND " . $visibility) . "
             ORDER BY occ.due_date ASC, occ.id ASC
             LIMIT :lim",
            array_merge($visibilityParams, [
                'env'  => $ctx->environment,
                'cmp'  => $ctx->cmpId,
                'env2' => $ctx->environment,
                'cmp2' => $ctx->cmpId,
                'me'   => $ctx->uuid,
                'me2'  => $ctx->uuid,
                'lim'  => self::MAX_PER_GROUP,
            ])
        )->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            $type = $row['obligation_type'] ?? null;
            unset($row['obligation_type']);
            $row['description'] = is_string($type) ? Enums::label($type) : null;

            return self::actionRow($row);
        }, $rows);
    }

    /**
     * Renewal cycles whose decision is the caller's to make.
     *
     * The due date is the first of the dates that actually bind: the decision
     * date if one was set, otherwise the notice deadline — the last day a
     * cancellation can still be served — and only then the expiry itself.
     *
     * @return list<array<string,mixed>>
     */
    private function myRenewals(TenantContext $ctx): array
    {
        [$visibility, $visibilityParams] = $this->visibility($ctx);

        $rows = $this->query(
            "SELECT r.id                                                                  AS id,
                    r.contract_id                                                         AS contract_id,
                    c.contract_number                                                     AS contract_number,
                    c.title                                                               AS contract_title,
                    c.title                                                               AS title,
                    r.cycle_no                                                            AS cycle_no,
                    r.status                                                              AS status,
                    COALESCE(r.decision_due_date, r.notice_deadline, r.current_expiry)    AS due_date,
                    (COALESCE(r.decision_due_date, r.notice_deadline, r.current_expiry)
                     - CURRENT_DATE)                                                      AS days_remaining,
                    c.risk_level                                                          AS risk_level,
                    c.total_value                                                         AS amount,
                    c.currency                                                            AS currency
             FROM contract_renewals r
             JOIN contracts c ON c.id = r.contract_id
             WHERE r.environment = :env
               AND r.cmp_id = :cmp
               AND c.environment = :env2
               AND c.cmp_id = :cmp2
               AND c.archived_at IS NULL
               AND r.status IN ('review_due', 'under_review')
               AND r.decision IS NULL
               AND (r.owner_uuid = :me OR c.owner_uuid = :me2)"
                . ($visibility === '' ? '' : "\n               AND " . $visibility) . "
             ORDER BY due_date ASC NULLS LAST, r.id ASC
             LIMIT :lim",
            array_merge($visibilityParams, [
                'env'  => $ctx->environment,
                'cmp'  => $ctx->cmpId,
                'env2' => $ctx->environment,
                'cmp2' => $ctx->cmpId,
                'me'   => $ctx->uuid,
                'me2'  => $ctx->uuid,
                'lim'  => self::MAX_PER_GROUP,
            ])
        )->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            $cycle = (int) ($row['cycle_no'] ?? 1);
            unset($row['cycle_no']);
            $row['description'] = 'Cycle ' . $cycle;

            return self::actionRow($row);
        }, $rows);
    }

    /**
     * Contracts carrying AI-extracted fields nobody has verified.
     *
     * One row per contract rather than per field: the reviewer opens a
     * contract and works through its fields, and a queue of forty rows that
     * turn out to be four contracts is a queue people stop reading. Lowest
     * confidence first, because that is where the machine is most likely wrong.
     *
     * @return list<array<string,mixed>>
     */
    private function myAiReviews(TenantContext $ctx): array
    {
        [$visibility, $visibilityParams] = $this->visibility($ctx);

        $rows = $this->query(
            "SELECT e.contract_id      AS id,
                    e.contract_id      AS contract_id,
                    c.contract_number  AS contract_number,
                    c.title            AS contract_title,
                    c.title            AS title,
                    c.risk_level       AS risk_level,
                    COUNT(*)           AS pending_fields
             FROM ai_extractions e
             JOIN contracts c ON c.id = e.contract_id
             WHERE e.environment = :env
               AND e.cmp_id = :cmp
               AND c.environment = :env2
               AND c.cmp_id = :cmp2
               AND c.archived_at IS NULL
               AND e.review_state = 'pending'"
                . ($visibility === '' ? '' : "\n               AND " . $visibility) . "
             GROUP BY e.contract_id, c.contract_number, c.title, c.risk_level
             ORDER BY MIN(e.confidence) ASC NULLS FIRST, e.contract_id ASC
             LIMIT :lim",
            array_merge($visibilityParams, [
                'env'  => $ctx->environment,
                'cmp'  => $ctx->cmpId,
                'env2' => $ctx->environment,
                'cmp2' => $ctx->cmpId,
                'lim'  => self::MAX_PER_GROUP,
            ])
        )->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            $pending = (int) ($row['pending_fields'] ?? 0);
            unset($row['pending_fields']);
            $row['description'] = $pending === 1 ? '1 field to verify' : $pending . ' fields to verify';
            $row['status']      = 'pending';

            return self::actionRow($row);
        }, $rows);
    }

    // -----------------------------------------------------------------------
    // Activity
    // -----------------------------------------------------------------------

    /**
     * The company's recent activity, newest first.
     *
     * A caller without CONTRACT_VIEW_ALL sees their own actions plus whatever
     * happened on the contracts they can open. Without that clause the feed
     * would be a side channel around the repository — the summary line names
     * the contract, so an entry about a contract somebody cannot open still
     * tells them it exists and what was done to it.
     *
     * Archived contracts are not excluded: archiving one is itself an event
     * worth seeing, and hiding the trail the moment the contract leaves the
     * default list is how a timeline stops being trustworthy.
     *
     * @return list<array<string,mixed>>
     */
    public function recentActivity(TenantContext $ctx, int $limit): array
    {
        [$visibility, $visibilityParams] = $this->visibility($ctx);

        $params = array_merge($visibilityParams, [
            'env'  => $ctx->environment,
            'cmp'  => $ctx->cmpId,
            'env2' => $ctx->environment,
            'cmp2' => $ctx->cmpId,
            'lim'  => max(1, $limit),
        ]);

        $mine = '';
        if ($visibility !== '') {
            $mine                = "\n               AND (a.actor_uuid = :feed_me OR (c.id IS NOT NULL AND " . $visibility . '))';
            $params['feed_me']   = $ctx->uuid;
        }

        $rows = $this->query(
            "SELECT a.id                                       AS id,
                    a.event_type                               AS action,
                    a.summary                                  AS description,
                    a.actor_label                              AS actor_name,
                    a.actor_uuid                               AS actor_uuid,
                    CASE WHEN a.contract_id IS NOT NULL THEN 'contract'
                         WHEN a.request_id IS NOT NULL  THEN 'request'
                    END                                        AS subject_type,
                    COALESCE(a.contract_id, a.request_id)      AS subject_id,
                    a.contract_id                              AS contract_id,
                    c.contract_number                          AS contract_number,
                    c.title                                    AS contract_title,
                    a.created_at                               AS created_at
             FROM contract_activity_logs a
             LEFT JOIN contracts c ON c.id = a.contract_id
                                  AND c.environment = :env2
                                  AND c.cmp_id = :cmp2
             WHERE a.environment = :env
               AND a.cmp_id = :cmp{$mine}
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT :lim",
            $params
        )->fetchAll() ?: [];

        return array_map(static function (array $row): array {
            $row['id']          = (int) $row['id'];
            $row['contract_id'] = $row['contract_id'] === null ? null : (int) $row['contract_id'];
            $row['subject_id']  = $row['subject_id'] === null ? null : (int) $row['subject_id'];

            return $row;
        }, $rows);
    }

    // -----------------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------------

    /**
     * The scope every portfolio figure shares: this tenant, the rows this
     * caller may see, and whatever the filter bar narrowed to.
     *
     * Returned as a clause list rather than a finished WHERE because most of
     * the queries above hang it off a joined `contracts c` alongside conditions
     * of their own, and a pre-built string cannot be extended without surgery.
     *
     * @param array<string,mixed> $f
     * @return array{0: list<string>, 1: array<string,mixed>}
     */
    private function portfolio(TenantContext $ctx, array $f): array
    {
        // Archived contracts are out, as they are in the repository by default.
        // Every KPI tile links through to that list, and a figure the list
        // cannot reproduce reads as a bug in both screens.
        $clauses = ['c.environment = :env', 'c.cmp_id = :cmp', 'c.archived_at IS NULL'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        [$visibility, $visibilityParams] = $this->visibility($ctx);
        if ($visibility !== '') {
            $clauses[] = $visibility;
            $params    = array_merge($params, $visibilityParams);
        }

        if (! empty($f['bo_id'])) {
            $clauses[]    = 'c.bo_id = :bo';
            $params['bo'] = (int) $f['bo_id'];
        }
        if (! empty($f['contract_type_id'])) {
            $clauses[]         = 'c.contract_type_id = :type_id';
            $params['type_id'] = (int) $f['contract_type_id'];
        }
        if (! empty($f['department_id'])) {
            $clauses[]         = 'c.department_id = :dept_id';
            $params['dept_id'] = (int) $f['department_id'];
        }
        if (! empty($f['owner_uuid'])) {
            $clauses[]       = 'c.owner_uuid = :owner';
            $params['owner'] = (string) $f['owner_uuid'];
        }
        if (! empty($f['counterparty'])) {
            $clauses[]    = 'c.counterparty_name ILIKE :cp';
            $params['cp'] = '%' . $f['counterparty'] . '%';
        }
        if (! empty($f['status'])) {
            $clauses[]        = 'c.status = :status';
            $params['status'] = (string) $f['status'];
        }
        if (! empty($f['risk_level'])) {
            $clauses[]      = 'c.risk_level = :risk';
            $params['risk'] = (string) $f['risk_level'];
        }

        // The period narrows on effective_date, because the repository filters
        // on that under `effective_from`/`effective_to` and a tile that drills
        // through to a different window than it counted is indefensible.
        if (! empty($f['date_from'])) {
            $clauses[]           = 'c.effective_date >= :date_from';
            $params['date_from'] = (string) $f['date_from'];
        }
        if (! empty($f['date_to'])) {
            $clauses[]         = 'c.effective_date <= :date_to';
            $params['date_to'] = (string) $f['date_to'];
        }

        return [$clauses, $params];
    }

    /**
     * Which contracts this caller may be shown, as a fragment over alias `c`.
     *
     * This is ContractService's rule restated. It is a copy because that class
     * keeps its predicate private, and the two must agree: a dashboard counting
     * anything wider would report, and link to, rows the repository then
     * refuses to open. Widening it here rather than there is exactly the
     * mistake worth guarding against, so DashboardServiceTest asserts that a
     * user without CONTRACT_VIEW_ALL sees the same set from both.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function visibility(TenantContext $ctx): array
    {
        if ($ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            return ['', []];
        }

        // Three names for one value: with prepare emulation off PDO gives every
        // named placeholder its own position, so a name used twice in one
        // statement is a bound-parameter mismatch rather than a convenience.
        return [
            '(c.owner_uuid = :vis_self
                    OR c.created_by = :vis_self2
                    OR EXISTS (
                           SELECT 1
                           FROM contract_approval_assignments a
                           JOIN contract_approval_instances i ON i.id = a.instance_id
                           WHERE i.contract_id = c.id AND a.approver_uuid = :vis_self3
                       ))',
            [
                'vis_self'  => $ctx->uuid,
                'vis_self2' => $ctx->uuid,
                'vis_self3' => $ctx->uuid,
            ],
        ];
    }

    /**
     * The two per-company numbers the dashboard reads.
     *
     * The expiry window is the widest rung of the company's own alert ladder
     * rather than a hard-coded ninety days: a company that warns at 180 should
     * see the same horizon on the tile that it sees in its reminders, and the
     * tile prints the number back so it can say which window it counted.
     *
     * @return array{expiring_within_days: int, currency: string}
     */
    private function settings(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT expiry_alert_days, default_currency
             FROM contract_settings
             WHERE environment = ? AND cmp_id = ?
             LIMIT 1'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();
        $row = is_array($row) ? $row : [];

        $ladder = Dates::reminderLadder(
            is_string($row['expiry_alert_days'] ?? null) ? (string) $row['expiry_alert_days'] : null,
            self::EXPIRY_LADDER
        );

        $currency = isset($row['default_currency']) && is_string($row['default_currency'])
                    && preg_match('/^[A-Z]{3}$/', $row['default_currency']) === 1
            ? $row['default_currency']
            : $ctx->currency();

        return [
            'expiring_within_days' => $ladder[0] ?? self::EXPIRY_LADDER[0],
            'currency'             => $currency,
        ];
    }

    /**
     * Prepare, bind and run.
     *
     * Binding rather than passing the array to execute() so integers go over as
     * integers: LIMIT and make_interval() take a number, and a quoted one is a
     * type error PostgreSQL raises rather than a value it coerces.
     *
     * @param array<string,mixed> $params
     */
    private function query(string $sql, array $params): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->execute();

        return $st;
    }

    // -----------------------------------------------------------------------
    // Shaping
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function countPoint(string $key, string $label, mixed $count): array
    {
        return ['key' => $key, 'label' => $label, 'count' => (int) $count];
    }

    /** Money stays a string all the way to the browser; a float would round it. */
    private static function moneyPoint(string $key, string $label, string $amount, string $currency): array
    {
        return ['key' => $key, 'label' => $label, 'amount' => $amount, 'currency' => $currency];
    }

    /** A `YYYY-MM` bucket. The page shortens the key into an axis label. */
    private static function monthPoint(string $bucket, mixed $count): array
    {
        return ['key' => $bucket, 'month' => $bucket, 'count' => (int) $count];
    }

    /**
     * Points in a vocabulary's own order, with anything unrecognised kept at
     * the end rather than dropped — a status the enum has not caught up with is
     * still a contract somebody owns.
     *
     * @param array<string, array<string,mixed>> $points keyed by bucket
     * @param list<string>                       $order
     * @return list<array<string,mixed>>
     */
    private static function inOrder(array $points, array $order): array
    {
        $ordered = [];
        foreach ($order as $key) {
            if (isset($points[$key])) {
                $ordered[] = $points[$key];
                unset($points[$key]);
            }
        }

        return array_merge($ordered, array_values($points));
    }

    /**
     * Biggest first. The charts draw the head of an open-ended list and a long
     * tail of ones is the normal shape of a department or counterparty mix.
     *
     * @param list<array<string,mixed>> $points
     * @return list<array<string,mixed>>
     */
    private static function ranked(array $points, string $field): array
    {
        usort($points, static function (array $a, array $b) use ($field): int {
            $left  = (float) ($a[$field] ?? 0);
            $right = (float) ($b[$field] ?? 0);

            return $right <=> $left ?: strcmp((string) $a['label'], (string) $b['label']);
        });

        return $points;
    }

    /**
     * One row of a personal queue, in the shape the panel reads.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function actionRow(array $row): array
    {
        foreach (['id', 'contract_id', 'days_remaining'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }

        $row['description'] = $row['description'] ?? null;
        $row['due_date']    = $row['due_date'] ?? null;
        $row['amount']      = isset($row['amount']) ? (string) $row['amount'] : null;

        return $row;
    }
}
