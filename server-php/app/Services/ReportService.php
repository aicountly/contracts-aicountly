<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use PDO;

/**
 * The report catalogue: twenty-four named questions, each answered by a real
 * query against real tables.
 *
 * A report key is a lookup into the catalogue below, never a fragment of SQL.
 * That is the whole design: the caller chooses which prepared plan runs and
 * supplies bound values for its filters, and there is no path by which a key,
 * a column name or a sort direction reaches the statement as text.
 *
 * Aggregate reports group in the database rather than in PHP. "Contracts by
 * counterparty" over a company with ten thousand agreements is one GROUP BY or
 * it is ten thousand rows crossing the wire to be counted in a loop, and the
 * second one stops working long before anybody calls it a bug.
 *
 * Values are never summed across currencies. This product has no exchange-rate
 * source, and a total that silently adds dollars to rupees is wrong in a way a
 * reader cannot see.
 */
final class ReportService
{
    private const CLOSED_OBLIGATION_STATUSES = "('completed', 'waived', 'not_applicable')";

    /** Values a spreadsheet would treat as the start of a formula. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(private PDO $pdo)
    {
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // The catalogue
    // -----------------------------------------------------------------------

    /**
     * Every report, with the columns it returns and the filters it honours.
     *
     * The report screen renders itself from this — column headings, types and
     * the filter bar — so a report added here needs no front-end change to
     * become usable.
     *
     * @return list<array{key: string, name: string, description: string,
     *                    columns: list<array{key: string, label: string, type: string}>,
     *                    filters: list<string>}>
     */
    public function definitions(): array
    {
        $common     = ['bo_id', 'contract_type_id', 'department_id', 'owner_uuid', 'counterparty', 'status', 'risk_level', 'date_from', 'date_to'];
        $contractish = [
            self::col('contract_number', 'Contract No.'),
            self::col('title', 'Title'),
            self::col('contract_type_name', 'Type'),
            self::col('department_name', 'Department'),
            self::col('counterparty_name', 'Counterparty'),
            self::col('owner_uuid', 'Owner'),
        ];

        return [
            [
                'key'         => 'active_contracts',
                'name'        => 'Active contracts',
                'description' => 'Every contract currently in force, with its term and value.',
                'columns'     => array_merge($contractish, [
                    self::col('status', 'Status'),
                    self::col('effective_date', 'Effective', 'date'),
                    self::col('expiry_date', 'Expiry', 'date'),
                    self::col('days_to_expiry', 'Days to expiry', 'number'),
                    self::col('currency', 'Currency'),
                    self::col('total_value', 'Value', 'money'),
                ]),
                'filters' => $common,
            ],
            [
                'key'         => 'expired_contracts',
                'name'        => 'Expired contracts',
                'description' => 'Contracts past their expiry date, including any the nightly sweep has not yet closed.',
                'columns'     => array_merge($contractish, [
                    self::col('status', 'Status'),
                    self::col('expiry_date', 'Expiry', 'date'),
                    self::col('days_since_expiry', 'Days since expiry', 'number'),
                    self::col('currency', 'Currency'),
                    self::col('total_value', 'Value', 'money'),
                ]),
                'filters' => $common,
            ],
            [
                'key'         => 'expiring_contracts',
                'name'        => 'Expiring contracts',
                'description' => 'Contracts expiring inside the alert window, with the cancellation deadline that precedes each one.',
                'columns'     => array_merge($contractish, [
                    self::col('expiry_date', 'Expiry', 'date'),
                    self::col('days_to_expiry', 'Days to expiry', 'number'),
                    self::col('notice_deadline', 'Notice deadline', 'date'),
                    self::col('days_to_notice', 'Days to notice', 'number'),
                    self::col('auto_renewal', 'Auto renews', 'boolean'),
                    self::col('currency', 'Currency'),
                    self::col('total_value', 'Value', 'money'),
                ]),
                'filters' => array_merge($common, ['days']),
            ],
            [
                'key'         => 'renewal_pipeline',
                'name'        => 'Renewal pipeline',
                'description' => 'Open renewal cycles and where each one has got to.',
                'columns'     => [
                    self::col('contract_number', 'Contract No.'),
                    self::col('title', 'Title'),
                    self::col('counterparty_name', 'Counterparty'),
                    self::col('cycle_no', 'Cycle', 'number'),
                    self::col('renewal_status', 'Renewal status'),
                    self::col('current_expiry', 'Current expiry', 'date'),
                    self::col('notice_deadline', 'Notice deadline', 'date'),
                    self::col('decision_due_date', 'Decision due', 'date'),
                    self::col('days_to_decision', 'Days to decision', 'number'),
                    self::col('recommendation', 'Recommendation'),
                    self::col('owner_uuid', 'Owner'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'auto_renewal_contracts',
                'name'        => 'Auto-renewing contracts',
                'description' => 'Contracts that renew unless notice is given, and the date by which that notice must be served.',
                'columns'     => array_merge($contractish, [
                    self::col('renewal_type', 'Renewal type'),
                    self::col('renewal_frequency', 'Frequency'),
                    self::col('notice_period_days', 'Notice days', 'number'),
                    self::col('notice_deadline', 'Notice deadline', 'date'),
                    self::col('expiry_date', 'Expiry', 'date'),
                    self::col('currency', 'Currency'),
                    self::col('total_value', 'Value', 'money'),
                ]),
                'filters' => $common,
            ],
            [
                'key'         => 'contracts_by_type',
                'name'        => 'Contracts by type',
                'description' => 'Counts and value per contract type, per currency.',
                'columns'     => [
                    self::col('contract_type_name', 'Type'),
                    self::col('category', 'Category'),
                    self::col('currency', 'Currency'),
                    self::col('contracts', 'Contracts', 'number'),
                    self::col('active_contracts', 'Active', 'number'),
                    self::col('total_value', 'Value', 'money'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'contracts_by_counterparty',
                'name'        => 'Contracts by counterparty',
                'description' => 'Exposure per counterparty: how many agreements and how much they are worth.',
                'columns'     => [
                    self::col('counterparty_name', 'Counterparty'),
                    self::col('currency', 'Currency'),
                    self::col('contracts', 'Contracts', 'number'),
                    self::col('active_contracts', 'Active', 'number'),
                    self::col('total_value', 'Value', 'money'),
                    self::col('earliest_expiry', 'Earliest expiry', 'date'),
                    self::col('latest_expiry', 'Latest expiry', 'date'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'contracts_by_department',
                'name'        => 'Contracts by department',
                'description' => 'Which part of the business owns what.',
                'columns'     => [
                    self::col('department_name', 'Department'),
                    self::col('currency', 'Currency'),
                    self::col('contracts', 'Contracts', 'number'),
                    self::col('active_contracts', 'Active', 'number'),
                    self::col('total_value', 'Value', 'money'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'contracts_by_owner',
                'name'        => 'Contracts by owner',
                'description' => 'Workload per contract owner, and how much of it is overdue for renewal.',
                'columns'     => [
                    self::col('owner_uuid', 'Owner'),
                    self::col('currency', 'Currency'),
                    self::col('contracts', 'Contracts', 'number'),
                    self::col('active_contracts', 'Active', 'number'),
                    self::col('expiring_90_days', 'Expiring in 90 days', 'number'),
                    self::col('total_value', 'Value', 'money'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'contract_value_analysis',
                'name'        => 'Contract value analysis',
                'description' => 'Spread of contract value by currency and status — count, total, average and range.',
                'columns'     => [
                    self::col('currency', 'Currency'),
                    self::col('status', 'Status'),
                    self::col('contracts', 'Contracts', 'number'),
                    self::col('total_value', 'Total', 'money'),
                    self::col('average_value', 'Average', 'money'),
                    self::col('smallest_value', 'Smallest', 'money'),
                    self::col('largest_value', 'Largest', 'money'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'receivable_commitments',
                'name'        => 'Receivable commitments',
                'description' => 'What counterparties have committed to pay the company.',
                'columns'     => self::commitmentColumns(),
                'filters'     => $common,
            ],
            [
                'key'         => 'payable_commitments',
                'name'        => 'Payable commitments',
                'description' => 'What the company has committed to pay.',
                'columns'     => self::commitmentColumns(),
                'filters'     => $common,
            ],
            [
                'key'         => 'obligations_due',
                'name'        => 'Obligations due',
                'description' => 'Obligation occurrences falling due inside the window, and who owns each.',
                'columns'     => self::obligationColumns('days_to_due', 'Days to due'),
                'filters'     => array_merge($common, ['days']),
            ],
            [
                'key'         => 'overdue_obligations',
                'name'        => 'Overdue obligations',
                'description' => 'Obligation occurrences whose due date has passed and which are still open.',
                'columns'     => self::obligationColumns('days_overdue', 'Days overdue'),
                'filters'     => $common,
            ],
            [
                'key'         => 'high_risk_contracts',
                'name'        => 'High-risk contracts',
                'description' => 'Contracts assessed high or critical, with the findings behind the rating.',
                'columns'     => array_merge($contractish, [
                    self::col('risk_level', 'Risk'),
                    self::col('ai_risk_score', 'Score', 'number'),
                    self::col('open_findings', 'Open findings', 'number'),
                    self::col('critical_findings', 'Critical', 'number'),
                    self::col('high_findings', 'High', 'number'),
                    self::col('expiry_date', 'Expiry', 'date'),
                    self::col('total_value', 'Value', 'money'),
                ]),
                'filters' => $common,
            ],
            [
                'key'         => 'clause_deviations',
                'name'        => 'Clause deviations',
                'description' => 'Where a contract\'s wording departs from the playbook, and whether anyone has accepted it.',
                'columns'     => [
                    self::col('contract_number', 'Contract No.'),
                    self::col('title', 'Title'),
                    self::col('counterparty_name', 'Counterparty'),
                    self::col('category_name', 'Clause category'),
                    self::col('rule_label', 'Playbook rule'),
                    self::col('severity', 'Severity'),
                    self::col('deviation_summary', 'Deviation'),
                    self::col('review_status', 'Review status'),
                    self::col('detected_by', 'Detected by'),
                    self::col('created_at', 'Detected', 'datetime'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'approval_turnaround',
                'name'        => 'Approval turnaround',
                'description' => 'How long completed approvals took, from submission to outcome.',
                'columns'     => [
                    self::col('contract_number', 'Contract No.'),
                    self::col('title', 'Title'),
                    self::col('workflow_name', 'Workflow'),
                    self::col('approval_status', 'Outcome'),
                    self::col('submitted_by', 'Submitted by'),
                    self::col('submitted_at', 'Submitted', 'datetime'),
                    self::col('completed_at', 'Completed', 'datetime'),
                    self::col('hours_to_decide', 'Hours', 'number'),
                    self::col('steps', 'Steps', 'number'),
                    self::col('approvers', 'Approvers', 'number'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'contract_execution_timeline',
                'name'        => 'Contract execution timeline',
                'description' => 'How long each executed contract took from creation to signature.',
                'columns'     => [
                    self::col('contract_number', 'Contract No.'),
                    self::col('title', 'Title'),
                    self::col('counterparty_name', 'Counterparty'),
                    self::col('created_on', 'Created', 'date'),
                    self::col('effective_date', 'Effective', 'date'),
                    self::col('execution_date', 'Executed', 'date'),
                    self::col('days_to_execute', 'Days to execute', 'number'),
                    self::col('expiry_date', 'Expiry', 'date'),
                    self::col('status', 'Status'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'amendment_register',
                'name'        => 'Amendment register',
                'description' => 'Every amendment raised against a contract and whether it has been applied.',
                'columns'     => [
                    self::col('contract_number', 'Contract No.'),
                    self::col('title', 'Contract'),
                    self::col('amendment_no', 'Amendment', 'number'),
                    self::col('amendment_title', 'Amendment title'),
                    self::col('amendment_status', 'Status'),
                    self::col('effective_date', 'Effective', 'date'),
                    self::col('execution_date', 'Executed', 'date'),
                    self::col('applied_at', 'Applied', 'datetime'),
                    self::col('created_by', 'Raised by'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'terminated_contracts',
                'name'        => 'Terminated contracts',
                'description' => 'Contracts brought to an end early, with the ground relied on and any settlement.',
                'columns'     => [
                    self::col('contract_number', 'Contract No.'),
                    self::col('title', 'Title'),
                    self::col('counterparty_name', 'Counterparty'),
                    self::col('termination_type', 'Type'),
                    self::col('initiating_party', 'Initiated by'),
                    self::col('termination_status', 'Status'),
                    self::col('notice_issued_date', 'Notice issued', 'date'),
                    self::col('termination_effective', 'Effective', 'date'),
                    self::col('settlement_amount', 'Settlement', 'money'),
                    self::col('settlement_currency', 'Currency'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'audit_activity',
                'name'        => 'Audit activity',
                'description' => 'The compliance trail: what changed, on what, by whom.',
                'columns'     => [
                    self::col('created_at', 'When', 'datetime'),
                    self::col('actor_uuid', 'Actor'),
                    self::col('action', 'Action'),
                    self::col('entity_type', 'Entity'),
                    self::col('contract_number', 'Contract No.'),
                    self::col('field_name', 'Field'),
                    self::col('old_value', 'From'),
                    self::col('new_value', 'To'),
                ],
                'filters' => array_merge($common, ['action', 'actor_uuid']),
            ],
            [
                'key'         => 'missing_documents',
                'name'        => 'Missing documents',
                'description' => 'Contracts past draft with no document on file, or no executed copy.',
                'columns'     => array_merge($contractish, [
                    self::col('status', 'Status'),
                    self::col('execution_date', 'Executed', 'date'),
                    self::col('documents', 'Documents', 'number'),
                    self::col('executed_copies', 'Executed copies', 'number'),
                    self::col('gap', 'Gap'),
                ]),
                'filters' => $common,
            ],
            [
                'key'         => 'missing_mandatory_clauses',
                'name'        => 'Missing mandatory clauses',
                'description' => 'Clauses a contract type requires that the contract does not contain.',
                'columns'     => [
                    self::col('contract_number', 'Contract No.'),
                    self::col('title', 'Title'),
                    self::col('contract_type_name', 'Type'),
                    self::col('counterparty_name', 'Counterparty'),
                    self::col('missing_clause', 'Missing clause'),
                    self::col('status', 'Status'),
                    self::col('risk_level', 'Risk'),
                ],
                'filters' => $common,
            ],
            [
                'key'         => 'contract_health',
                'name'        => 'Contract health',
                'description' => 'One row per contract with its health score and what is dragging it down.',
                'columns'     => array_merge($contractish, [
                    self::col('status', 'Status'),
                    self::col('health_score', 'Health', 'number'),
                    self::col('risk_level', 'Risk'),
                    self::col('overdue_obligations', 'Overdue obligations', 'number'),
                    self::col('open_findings', 'Open findings', 'number'),
                    self::col('open_deviations', 'Open deviations', 'number'),
                    self::col('documents', 'Documents', 'number'),
                    self::col('days_to_expiry', 'Days to expiry', 'number'),
                ]),
                'filters' => $common,
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Running one
    // -----------------------------------------------------------------------

    /**
     * A page of one report.
     *
     * @param array<string,mixed> $filters
     * @return array{columns: list<array<string,string>>, rows: list<array<string,mixed>>,
     *               total: int, summary: array<string,mixed>}
     */
    public function run(TenantContext $ctx, string $reportKey, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $definition = null;
        foreach ($this->definitions() as $candidate) {
            if ($candidate['key'] === $reportKey) {
                $definition = $candidate;
                break;
            }
        }

        if ($definition === null) {
            throw DomainException::notFound('No such report.');
        }

        $plan = $this->plan($ctx, $reportKey, $filters);

        $where = $plan['where'] === [] ? '' : 'WHERE ' . implode("\n  AND ", $plan['where']);
        $group = $plan['group'] === '' ? '' : 'GROUP BY ' . $plan['group'];

        $countSql = "SELECT COUNT(*) FROM (SELECT 1 FROM {$plan['from']} {$where} {$group}) AS counted";
        $countSt  = $this->pdo->prepare($countSql);
        $countSt->execute($plan['params']);
        $total = (int) $countSt->fetchColumn();

        $rows = [];
        if ($total > 0) {
            $sql = "SELECT {$plan['select']}
                    FROM {$plan['from']}
                    {$where}
                    {$group}
                    ORDER BY {$plan['order']}
                    LIMIT :report_limit OFFSET :report_offset";

            $st = $this->pdo->prepare($sql);
            foreach ($plan['params'] as $key => $value) {
                $st->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $st->bindValue(':report_limit', max(1, min(10000, $limit)), PDO::PARAM_INT);
            $st->bindValue(':report_offset', max(0, $offset), PDO::PARAM_INT);
            $st->execute();

            $rows = array_map(
                static fn (array $row): array => self::normaliseRow($row, $definition['columns']),
                $st->fetchAll() ?: []
            );
        }

        return [
            'columns' => $definition['columns'],
            'rows'    => $rows,
            'total'   => $total,
            'summary' => array_merge(
                ['report' => $reportKey, 'name' => $definition['name'], 'rows' => $total],
                $this->summary($plan, $total)
            ),
        ];
    }

    /**
     * Totals that belong under the table rather than in it.
     *
     * Only where a report has a money column worth totalling, and always split
     * by currency — see the class docblock.
     *
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private function summary(array $plan, int $total): array
    {
        if ($plan['money'] === null || $total === 0) {
            return [];
        }

        [$valueExpr, $currencyExpr] = $plan['money'];

        $where = $plan['where'] === [] ? '' : 'WHERE ' . implode("\n  AND ", $plan['where']);

        $st = $this->pdo->prepare(
            "SELECT {$currencyExpr} AS currency, SUM({$valueExpr}) AS total, COUNT({$valueExpr}) AS counted
             FROM {$plan['from']}
             {$where}
             GROUP BY {$currencyExpr}
             ORDER BY SUM({$valueExpr}) DESC NULLS LAST"
        );
        $st->execute($plan['params']);

        return [
            'by_currency' => array_map(static fn (array $r): array => [
                'currency' => (string) ($r['currency'] ?? ''),
                'total'    => (string) ($r['total'] ?? '0'),
                // Source rows carrying a value, which on a grouped report is
                // more than the number of rows the table shows.
                'values'   => (int) $r['counted'],
            ], $st->fetchAll() ?: []),
        ];
    }

    // -----------------------------------------------------------------------
    // Plans
    // -----------------------------------------------------------------------

    /**
     * The SELECT/FROM/WHERE for one report.
     *
     * Every fragment here is a literal in this file. The only caller-supplied
     * material is bound values in `params`; nothing a request carries is ever
     * concatenated into the statement.
     *
     * @param array<string,mixed> $f
     * @return array{select: string, from: string, where: list<string>, group: string,
     *               order: string, params: array<string,mixed>, money: array{0:string,1:string}|null}
     */
    private function plan(TenantContext $ctx, string $key, array $f): array
    {
        $joins = 'contracts c
                  LEFT JOIN contract_types ct ON ct.id = c.contract_type_id
                  LEFT JOIN contract_departments dep ON dep.id = c.department_id';

        $contractCols = "c.id AS contract_id, c.contract_number, c.title,
                         ct.name AS contract_type_name, dep.name AS department_name,
                         c.counterparty_name, c.owner_uuid";

        [$scope, $params] = $this->scope($ctx, $f);

        $windowDays = isset($f['days']) ? max(1, min(3650, (int) $f['days'])) : null;

        return match ($key) {
            'active_contracts' => [
                'select' => "{$contractCols}, c.status, c.effective_date, c.expiry_date,
                             (c.expiry_date - CURRENT_DATE) AS days_to_expiry,
                             c.currency, c.total_value",
                'from'   => $joins,
                'where'  => array_merge($scope, ["c.status IN ('active', 'renewal_review')"]),
                'group'  => '',
                'order'  => 'c.expiry_date ASC NULLS LAST, c.id',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'expired_contracts' => [
                'select' => "{$contractCols}, c.status, c.expiry_date,
                             (CURRENT_DATE - c.expiry_date) AS days_since_expiry,
                             c.currency, c.total_value",
                'from'   => $joins,
                // Status *or* date: the sweep is what moves a contract to
                // `expired`, and a report that trusts only the status hides
                // every contract that ran out on a night cron did not run.
                'where'  => array_merge($scope, [
                    "(c.status = 'expired'
                      OR (c.expiry_date IS NOT NULL AND c.expiry_date < CURRENT_DATE
                          AND c.status IN ('active', 'renewal_review')))",
                ]),
                'group'  => '',
                'order'  => 'c.expiry_date DESC NULLS LAST, c.id',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'expiring_contracts' => [
                'select' => "{$contractCols}, c.expiry_date,
                             (c.expiry_date - CURRENT_DATE) AS days_to_expiry,
                             c.notice_deadline,
                             (c.notice_deadline - CURRENT_DATE) AS days_to_notice,
                             c.auto_renewal, c.currency, c.total_value",
                'from'   => $joins,
                'where'  => array_merge($scope, [
                    "c.status IN ('active', 'renewal_review')",
                    'c.expiry_date IS NOT NULL',
                    'c.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :window)',
                ]),
                'group'  => '',
                'order'  => 'c.expiry_date ASC, c.id',
                'params' => array_merge($params, ['window' => $windowDays ?? 90]),
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'renewal_pipeline' => [
                'select' => "c.id AS contract_id, c.contract_number, c.title, c.counterparty_name,
                             r.cycle_no, r.status AS renewal_status, r.current_expiry,
                             r.notice_deadline, r.decision_due_date,
                             (r.decision_due_date - CURRENT_DATE) AS days_to_decision,
                             r.recommendation, r.owner_uuid",
                'from'   => 'contract_renewals r JOIN contracts c ON c.id = r.contract_id',
                'where'  => array_merge($scope, ["r.status NOT IN ('closed', 'renewed')"]),
                'group'  => '',
                'order'  => 'r.decision_due_date ASC NULLS LAST, r.id',
                'params' => $params,
                'money'  => null,
            ],

            'auto_renewal_contracts' => [
                'select' => "{$contractCols}, c.renewal_type, c.renewal_frequency,
                             c.notice_period_days, c.notice_deadline, c.expiry_date,
                             c.currency, c.total_value",
                'from'   => $joins,
                'where'  => array_merge($scope, [
                    "(c.auto_renewal = TRUE OR c.renewal_type IN ('auto_renew', 'evergreen'))",
                    "c.status NOT IN ('terminated', 'cancelled')",
                ]),
                'group'  => '',
                'order'  => 'c.notice_deadline ASC NULLS LAST, c.expiry_date ASC NULLS LAST, c.id',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'contracts_by_type' => [
                'select' => "COALESCE(ct.name, 'Unclassified') AS contract_type_name,
                             COALESCE(ct.category, 'general') AS category,
                             c.currency,
                             COUNT(*) AS contracts,
                             COUNT(*) FILTER (WHERE c.status IN ('active', 'renewal_review')) AS active_contracts,
                             COALESCE(SUM(c.total_value), 0) AS total_value",
                'from'   => $joins,
                'where'  => $scope,
                'group'  => "COALESCE(ct.name, 'Unclassified'), COALESCE(ct.category, 'general'), c.currency",
                'order'  => 'COUNT(*) DESC, contract_type_name',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'contracts_by_counterparty' => [
                'select' => "COALESCE(NULLIF(TRIM(c.counterparty_name), ''), 'Unspecified') AS counterparty_name,
                             c.currency,
                             COUNT(*) AS contracts,
                             COUNT(*) FILTER (WHERE c.status IN ('active', 'renewal_review')) AS active_contracts,
                             COALESCE(SUM(c.total_value), 0) AS total_value,
                             MIN(c.expiry_date) AS earliest_expiry,
                             MAX(c.expiry_date) AS latest_expiry",
                'from'   => $joins,
                'where'  => $scope,
                'group'  => "COALESCE(NULLIF(TRIM(c.counterparty_name), ''), 'Unspecified'), c.currency",
                'order'  => 'COALESCE(SUM(c.total_value), 0) DESC, COUNT(*) DESC, counterparty_name',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'contracts_by_department' => [
                'select' => "COALESCE(dep.name, 'Unassigned') AS department_name,
                             c.currency,
                             COUNT(*) AS contracts,
                             COUNT(*) FILTER (WHERE c.status IN ('active', 'renewal_review')) AS active_contracts,
                             COALESCE(SUM(c.total_value), 0) AS total_value",
                'from'   => $joins,
                'where'  => $scope,
                'group'  => "COALESCE(dep.name, 'Unassigned'), c.currency",
                'order'  => 'COUNT(*) DESC, department_name',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'contracts_by_owner' => [
                'select' => "COALESCE(NULLIF(TRIM(c.owner_uuid), ''), 'Unassigned') AS owner_uuid,
                             c.currency,
                             COUNT(*) AS contracts,
                             COUNT(*) FILTER (WHERE c.status IN ('active', 'renewal_review')) AS active_contracts,
                             COUNT(*) FILTER (
                                 WHERE c.expiry_date BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '90 days'
                             ) AS expiring_90_days,
                             COALESCE(SUM(c.total_value), 0) AS total_value",
                'from'   => $joins,
                'where'  => $scope,
                'group'  => "COALESCE(NULLIF(TRIM(c.owner_uuid), ''), 'Unassigned'), c.currency",
                'order'  => 'COUNT(*) DESC, owner_uuid',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'contract_value_analysis' => [
                'select' => 'c.currency, c.status,
                             COUNT(*) AS contracts,
                             COALESCE(SUM(c.total_value), 0) AS total_value,
                             ROUND(COALESCE(AVG(c.total_value), 0), 2) AS average_value,
                             COALESCE(MIN(c.total_value), 0) AS smallest_value,
                             COALESCE(MAX(c.total_value), 0) AS largest_value',
                'from'   => $joins,
                'where'  => array_merge($scope, ['c.total_value IS NOT NULL']),
                'group'  => 'c.currency, c.status',
                'order'  => 'COALESCE(SUM(c.total_value), 0) DESC, c.currency, c.status',
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'receivable_commitments', 'payable_commitments' => [
                'select' => "c.id AS contract_id, c.contract_number, c.title, c.counterparty_name,
                             c.status, c.expiry_date,
                             t.currency, t.total_value, t.recurring_amount, t.billing_frequency,
                             t.payment_terms_days, t.minimum_revenue_commitment, t.value_direction",
                'from'   => 'contract_commercial_terms t JOIN contracts c ON c.id = t.contract_id',
                'where'  => array_merge($scope, [
                    $key === 'receivable_commitments'
                        ? "t.value_direction IN ('receivable', 'both')"
                        : "t.value_direction IN ('payable', 'both')",
                    "c.status NOT IN ('cancelled', 'archived')",
                ]),
                'group'  => '',
                'order'  => 't.total_value DESC NULLS LAST, c.id',
                'params' => $params,
                'money'  => ['t.total_value', 't.currency'],
            ],

            'obligations_due' => [
                'select' => self::obligationSelect() . ', (o.due_date - CURRENT_DATE) AS days_to_due',
                'from'   => self::obligationFrom(),
                'where'  => array_merge($scope, [
                    'o.status NOT IN ' . self::CLOSED_OBLIGATION_STATUSES,
                    'o.due_date BETWEEN CURRENT_DATE AND CURRENT_DATE + make_interval(days => :window)',
                ]),
                'group'  => '',
                'order'  => 'o.due_date ASC, o.id',
                'params' => array_merge($params, ['window' => $windowDays ?? 30]),
                'money'  => ['o.amount', "COALESCE(ob.currency, c.currency)"],
            ],

            'overdue_obligations' => [
                'select' => self::obligationSelect() . ', (CURRENT_DATE - o.due_date) AS days_overdue',
                'from'   => self::obligationFrom(),
                'where'  => array_merge($scope, [
                    'o.status NOT IN ' . self::CLOSED_OBLIGATION_STATUSES,
                    'o.due_date < CURRENT_DATE',
                ]),
                'group'  => '',
                'order'  => 'o.due_date ASC, o.id',
                'params' => $params,
                'money'  => ['o.amount', "COALESCE(ob.currency, c.currency)"],
            ],

            'high_risk_contracts' => [
                'select' => "{$contractCols}, c.risk_level, c.ai_risk_score, c.expiry_date,
                             c.currency, c.total_value,
                             (SELECT COUNT(*) FROM contract_risk_findings fx
                               WHERE fx.contract_id = c.id AND fx.review_status = 'open') AS open_findings,
                             (SELECT COUNT(*) FROM contract_risk_findings fx
                               WHERE fx.contract_id = c.id AND fx.severity = 'critical'
                                 AND fx.review_status = 'open') AS critical_findings,
                             (SELECT COUNT(*) FROM contract_risk_findings fx
                               WHERE fx.contract_id = c.id AND fx.severity = 'high'
                                 AND fx.review_status = 'open') AS high_findings",
                'from'   => $joins,
                'where'  => array_merge($scope, ["c.risk_level IN ('high', 'critical')"]),
                'group'  => '',
                'order'  => "CASE c.risk_level WHEN 'critical' THEN 0 ELSE 1 END, c.ai_risk_score DESC NULLS LAST, c.id",
                'params' => $params,
                'money'  => ['c.total_value', 'c.currency'],
            ],

            'clause_deviations' => [
                'select' => 'c.id AS contract_id, c.contract_number, c.title, c.counterparty_name,
                             cat.name AS category_name, pr.label AS rule_label,
                             dv.severity, dv.deviation_summary, dv.review_status,
                             dv.detected_by, dv.created_at',
                'from'   => 'clause_deviations dv
                             JOIN contracts c ON c.id = dv.contract_id
                             LEFT JOIN clause_categories cat ON cat.id = dv.category_id
                             LEFT JOIN playbook_rules pr ON pr.id = dv.playbook_rule_id',
                'where'  => $scope,
                'group'  => '',
                'order'  => "CASE dv.severity
                                 WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2
                                 WHEN 'low' THEN 3 ELSE 4 END, dv.created_at DESC, dv.id",
                'params' => $params,
                'money'  => null,
            ],

            'approval_turnaround' => [
                'select' => "c.id AS contract_id, c.contract_number, c.title,
                             i.workflow_name, i.status AS approval_status, i.submitted_by,
                             i.submitted_at, i.completed_at,
                             ROUND(EXTRACT(EPOCH FROM (i.completed_at - i.submitted_at)) / 3600.0, 1) AS hours_to_decide,
                             (SELECT COUNT(DISTINCT a.step_no) FROM contract_approval_assignments a
                               WHERE a.instance_id = i.id) AS steps,
                             (SELECT COUNT(*) FROM contract_approval_assignments a
                               WHERE a.instance_id = i.id) AS approvers",
                'from'   => 'contract_approval_instances i JOIN contracts c ON c.id = i.contract_id',
                'where'  => array_merge($scope, ['i.completed_at IS NOT NULL']),
                'group'  => '',
                'order'  => 'i.completed_at DESC, i.id',
                'params' => $params,
                'money'  => null,
            ],

            'contract_execution_timeline' => [
                'select' => 'c.id AS contract_id, c.contract_number, c.title, c.counterparty_name,
                             c.created_at::date AS created_on, c.effective_date, c.execution_date,
                             (c.execution_date - c.created_at::date) AS days_to_execute,
                             c.expiry_date, c.status',
                'from'   => $joins,
                'where'  => array_merge($scope, ['c.execution_date IS NOT NULL']),
                'group'  => '',
                'order'  => 'c.execution_date DESC, c.id',
                'params' => $params,
                'money'  => null,
            ],

            'amendment_register' => [
                'select' => 'c.id AS contract_id, c.contract_number, c.title,
                             am.amendment_no, am.title AS amendment_title, am.status AS amendment_status,
                             am.effective_date, am.execution_date, am.applied_at, am.created_by',
                'from'   => 'contract_amendments am JOIN contracts c ON c.id = am.contract_id',
                'where'  => $scope,
                'group'  => '',
                'order'  => 'c.contract_number, am.amendment_no',
                'params' => $params,
                'money'  => null,
            ],

            'terminated_contracts' => [
                'select' => 'c.id AS contract_id, c.contract_number, c.title, c.counterparty_name,
                             tm.termination_type, tm.initiating_party, tm.status AS termination_status,
                             tm.notice_issued_date, tm.effective_date AS termination_effective,
                             tm.settlement_amount, tm.settlement_currency',
                // LEFT JOIN, so a contract closed without a termination record
                // still appears. Rooting on the termination table would report
                // the process rather than the outcome.
                'from'   => 'contracts c LEFT JOIN contract_terminations tm ON tm.contract_id = c.id',
                'where'  => array_merge($scope, ["(c.status = 'terminated' OR tm.id IS NOT NULL)"]),
                'group'  => '',
                'order'  => 'COALESCE(tm.effective_date, c.expiry_date) DESC NULLS LAST, c.id',
                'params' => $params,
                'money'  => ['tm.settlement_amount', "COALESCE(tm.settlement_currency, c.currency)"],
            ],

            'audit_activity' => $this->auditPlan($ctx, $f),

            'missing_documents' => [
                'select' => "{$contractCols}, c.status, c.execution_date,
                             (SELECT COUNT(*) FROM contract_documents d WHERE d.contract_id = c.id) AS documents,
                             (SELECT COUNT(*) FROM contract_documents d
                               WHERE d.contract_id = c.id AND d.is_executed_copy) AS executed_copies,
                             CASE
                                 WHEN NOT EXISTS (SELECT 1 FROM contract_documents d WHERE d.contract_id = c.id)
                                     THEN 'No document on file'
                                 ELSE 'No executed copy'
                             END AS gap",
                'from'   => $joins,
                'where'  => array_merge($scope, [
                    "c.status NOT IN ('draft', 'cancelled')",
                    "NOT EXISTS (SELECT 1 FROM contract_documents d
                                  WHERE d.contract_id = c.id AND d.is_executed_copy)",
                ]),
                'group'  => '',
                'order'  => 'c.status, c.contract_number',
                'params' => $params,
                'money'  => null,
            ],

            'missing_mandatory_clauses' => [
                'select' => 'c.id AS contract_id, c.contract_number, c.title,
                             ct.name AS contract_type_name, c.counterparty_name,
                             required.clause_key AS missing_clause, c.status, c.risk_level',
                // A contract type stores its required clauses as a JSONB array
                // of keys; the lateral expands that to one row per requirement
                // so a contract missing three of them is three findings, not
                // one row somebody has to read a list out of.
                'from'   => "contracts c
                             JOIN contract_types ct ON ct.id = c.contract_type_id
                             CROSS JOIN LATERAL jsonb_array_elements_text(
                                 CASE WHEN jsonb_typeof(ct.mandatory_clauses) = 'array'
                                      THEN ct.mandatory_clauses ELSE '[]'::jsonb END
                             ) AS required(clause_key)",
                'where'  => array_merge($scope, [
                    "c.status NOT IN ('cancelled', 'archived')",
                    "NOT EXISTS (
                         SELECT 1 FROM contract_clauses cc
                         LEFT JOIN clause_library cl ON cl.id = cc.library_clause_id
                         LEFT JOIN clause_categories cat ON cat.id = cc.category_id
                         WHERE cc.contract_id = c.id
                           AND (
                                 lower(COALESCE(cc.heading, '')) LIKE '%' || lower(required.clause_key) || '%'
                              OR lower(COALESCE(cl.name, ''))    = lower(required.clause_key)
                              OR lower(COALESCE(cat.code, ''))   = lower(required.clause_key)
                              OR lower(COALESCE(cat.name, ''))   = lower(required.clause_key)
                               )
                     )",
                ]),
                'group'  => '',
                'order'  => 'c.contract_number, required.clause_key',
                'params' => $params,
                'money'  => null,
            ],

            'contract_health' => [
                'select' => "{$contractCols}, c.status, c.health_score, c.risk_level, c.expiry_date,
                             (c.expiry_date - CURRENT_DATE) AS days_to_expiry,
                             (SELECT COUNT(*) FROM obligation_occurrences oo
                               WHERE oo.contract_id = c.id
                                 AND oo.due_date < CURRENT_DATE
                                 AND oo.status NOT IN " . self::CLOSED_OBLIGATION_STATUSES . ") AS overdue_obligations,
                             (SELECT COUNT(*) FROM contract_risk_findings fx
                               WHERE fx.contract_id = c.id AND fx.review_status = 'open') AS open_findings,
                             (SELECT COUNT(*) FROM clause_deviations dv
                               WHERE dv.contract_id = c.id AND dv.review_status = 'open') AS open_deviations,
                             (SELECT COUNT(*) FROM contract_documents d WHERE d.contract_id = c.id) AS documents",
                'from'   => $joins,
                'where'  => array_merge($scope, ["c.status NOT IN ('cancelled', 'archived')"]),
                'group'  => '',
                // Worst first: a health report opened at the top should show the
                // contracts somebody has to do something about.
                'order'  => 'c.health_score ASC NULLS LAST, c.id',
                'params' => $params,
                'money'  => null,
            ],

            default => throw DomainException::notFound('No such report.'),
        };
    }

    /**
     * The audit trail, which is the one report not rooted on a contract.
     *
     * An audit row can belong to no contract at all — a settings change, a role
     * grant — so the tenant filter is on the audit row itself, and the row-level
     * narrowing has to allow those through for the person who made them rather
     * than dropping them entirely.
     *
     * @param array<string,mixed> $f
     * @return array{select: string, from: string, where: list<string>, group: string,
     *               order: string, params: array<string,mixed>, money: null}
     */
    private function auditPlan(TenantContext $ctx, array $f): array
    {
        $where  = ['al.environment = :env', 'al.cmp_id = :cmp'];
        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        if (! $ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            $where[] = '(al.actor_uuid = :me1
                         OR (c.id IS NOT NULL AND (c.owner_uuid = :me2 OR c.created_by = :me3)))';
            $params['me1'] = $ctx->uuid;
            $params['me2'] = $ctx->uuid;
            $params['me3'] = $ctx->uuid;
        }

        if (! empty($f['action']) && is_string($f['action'])) {
            $where[]           = 'al.action = :action';
            $params['action']  = mb_substr($f['action'], 0, 64);
        }
        if (! empty($f['actor_uuid']) && is_string($f['actor_uuid'])) {
            $where[]          = 'al.actor_uuid = :actor';
            $params['actor']  = mb_substr($f['actor_uuid'], 0, 64);
        }
        if (! empty($f['date_from'])) {
            $where[]         = 'al.created_at >= :from';
            $params['from']  = (string) $f['date_from'] . ' 00:00:00';
        }
        if (! empty($f['date_to'])) {
            $where[]       = 'al.created_at <= :to';
            $params['to']  = (string) $f['date_to'] . ' 23:59:59';
        }

        return [
            'select' => 'al.created_at, al.actor_uuid, al.action, al.entity_type,
                         c.contract_number, al.field_name, al.old_value, al.new_value',
            'from'   => 'contract_audit_logs al LEFT JOIN contracts c ON c.id = al.contract_id',
            'where'  => $where,
            'group'  => '',
            'order'  => 'al.created_at DESC, al.id DESC',
            'params' => $params,
            'money'  => null,
        ];
    }

    /**
     * The tenant filter and the caller's filters, on the `c` alias.
     *
     * Every report except the audit trail joins `contracts c`, so this is the
     * one place the tenant scope and the row-level narrowing are written. A
     * report that built its own would eventually be the report that forgot one.
     *
     * @param array<string,mixed> $f
     * @return array{0: list<string>, 1: array<string,mixed>}
     */
    private function scope(TenantContext $ctx, array $f): array
    {
        $where  = ['c.environment = :env', 'c.cmp_id = :cmp'];
        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        if (! $ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            $where[] = '(c.owner_uuid = :me1 OR c.created_by = :me2
                         OR EXISTS (
                             SELECT 1 FROM contract_approval_assignments aa
                             JOIN contract_approval_instances ai ON ai.id = aa.instance_id
                             WHERE ai.contract_id = c.id AND aa.approver_uuid = :me3
                         ))';
            $params['me1'] = $ctx->uuid;
            $params['me2'] = $ctx->uuid;
            $params['me3'] = $ctx->uuid;
        }

        if (! empty($f['bo_id'])) {
            $where[]      = 'c.bo_id = :bo';
            $params['bo'] = (int) $f['bo_id'];
        }
        if (! empty($f['contract_type_id'])) {
            $where[]        = 'c.contract_type_id = :type';
            $params['type'] = (int) $f['contract_type_id'];
        }
        if (! empty($f['department_id'])) {
            $where[]        = 'c.department_id = :dept';
            $params['dept'] = (int) $f['department_id'];
        }
        if (! empty($f['owner_uuid'])) {
            $where[]         = 'c.owner_uuid = :owner';
            $params['owner'] = (string) $f['owner_uuid'];
        }
        if (! empty($f['counterparty'])) {
            $where[]      = 'c.counterparty_name ILIKE :cp';
            $params['cp'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $f['counterparty']) . '%';
        }
        if (! empty($f['status']) && Enums::isValid($f['status'], Enums::CONTRACT_STATUSES)) {
            $where[]          = 'c.status = :status';
            $params['status'] = (string) $f['status'];
        }
        if (! empty($f['risk_level']) && Enums::isValid($f['risk_level'], Enums::RISK_LEVELS)) {
            $where[]        = 'c.risk_level = :risk';
            $params['risk'] = (string) $f['risk_level'];
        }
        if (! empty($f['date_from'])) {
            $where[]        = 'c.effective_date >= :from';
            $params['from'] = (string) $f['date_from'];
        }
        if (! empty($f['date_to'])) {
            $where[]      = 'c.effective_date <= :to';
            $params['to'] = (string) $f['date_to'];
        }

        return [$where, $params];
    }

    // -----------------------------------------------------------------------
    // CSV
    // -----------------------------------------------------------------------

    /**
     * Serialise a report to CSV.
     *
     * Two things matter here and both are security, not formatting.
     *
     * RFC 4180 quoting, so a counterparty called `Smith, Jones & Co "Holdings"`
     * does not shift every column after it.
     *
     * And a leading apostrophe on any cell that starts with `=`, `+`, `-`, `@`,
     * a tab or a carriage return. Excel and Sheets treat those as the start of
     * a formula, and `=cmd|'/C calc'!A0` in a contract title is remote code
     * execution on the machine of whoever opens the export. Purely numeric
     * values are left alone — otherwise every negative amount in a financial
     * report arrives as text and the spreadsheet cannot sum a column.
     *
     * @param list<array{key: string, label: string, type?: string}> $columns
     * @param list<array<string,mixed>> $rows
     */
    public function toCsv(array $columns, array $rows): string
    {
        $keys    = [];
        $headers = [];
        foreach ($columns as $index => $column) {
            if (is_array($column)) {
                $keys[]    = (string) ($column['key'] ?? $column['field'] ?? $index);
                $headers[] = (string) ($column['label'] ?? $column['title'] ?? $column['key'] ?? $index);
            } else {
                $keys[]    = (string) $column;
                $headers[] = (string) $column;
            }
        }

        $lines = [implode(',', array_map(static fn (string $h): string => self::csvCell($h), $headers))];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $tuple = array_is_list($row);
            $cells = [];
            foreach ($keys as $position => $key) {
                $cells[] = self::csvCell(self::stringify($tuple ? ($row[$position] ?? null) : ($row[$key] ?? null)));
            }
            $lines[] = implode(',', $cells);
        }

        // CRLF: the format's own line ending, and the one Excel expects when it
        // opens a .csv without being asked anything.
        return implode("\r\n", $lines) . "\r\n";
    }

    private static function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** One CSV cell: formula-guarded, then quoted if it needs to be. */
    private static function csvCell(string $value): string
    {
        $guarded = self::neutraliseFormula($value);

        if (preg_match('/[",\r\n]/', $guarded) === 1 || $guarded !== trim($guarded)) {
            return '"' . str_replace('"', '""', $guarded) . '"';
        }

        return $guarded;
    }

    /** @see toCsv() for why. */
    public static function neutraliseFormula(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // A plain number is not a formula, and quoting it would make a column of
        // amounts unsummable in the spreadsheet it was exported for.
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return in_array($value[0], self::FORMULA_PREFIXES, true) ? "'" . $value : $value;
    }

    // -----------------------------------------------------------------------
    // Shapes
    // -----------------------------------------------------------------------

    /** @return array{key: string, label: string, type: string} */
    private static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /** @return list<array{key: string, label: string, type: string}> */
    private static function commitmentColumns(): array
    {
        return [
            self::col('contract_number', 'Contract No.'),
            self::col('title', 'Title'),
            self::col('counterparty_name', 'Counterparty'),
            self::col('status', 'Status'),
            self::col('currency', 'Currency'),
            self::col('total_value', 'Committed value', 'money'),
            self::col('recurring_amount', 'Recurring', 'money'),
            self::col('billing_frequency', 'Billing'),
            self::col('payment_terms_days', 'Payment terms (days)', 'number'),
            self::col('minimum_revenue_commitment', 'Minimum commitment', 'money'),
            self::col('expiry_date', 'Expiry', 'date'),
        ];
    }

    /** @return list<array{key: string, label: string, type: string}> */
    private static function obligationColumns(string $dayKey, string $dayLabel): array
    {
        return [
            self::col('contract_number', 'Contract No.'),
            self::col('contract_title', 'Contract'),
            self::col('obligation_title', 'Obligation'),
            self::col('obligation_type', 'Type'),
            self::col('responsible_party', 'Responsible'),
            self::col('owner_uuid', 'Owner'),
            self::col('due_date', 'Due', 'date'),
            self::col($dayKey, $dayLabel, 'number'),
            self::col('occurrence_status', 'Status'),
            self::col('currency', 'Currency'),
            self::col('amount', 'Amount', 'money'),
        ];
    }

    private static function obligationSelect(): string
    {
        return 'c.id AS contract_id, c.contract_number, c.title AS contract_title,
                ob.title AS obligation_title, ob.obligation_type, ob.responsible_party,
                ob.owner_uuid, o.due_date, o.status AS occurrence_status,
                COALESCE(ob.currency, c.currency) AS currency, o.amount';
    }

    private static function obligationFrom(): string
    {
        return 'obligation_occurrences o
                JOIN contract_obligations ob ON ob.id = o.obligation_id
                JOIN contracts c ON c.id = o.contract_id';
    }

    /**
     * Coerce a row to the types its column list declares.
     *
     * PDO hands everything back as a string, so without this a "number" column
     * arrives as `"3"` and the front end has to guess. Money stays a string
     * deliberately — see the class docblock.
     *
     * @param array<string,mixed> $row
     * @param list<array{key: string, label: string, type: string}> $columns
     * @return array<string,mixed>
     */
    private static function normaliseRow(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            $key = $column['key'];
            if (! array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $row[$key] = match ($column['type']) {
                'number'  => (int) $row[$key],
                'boolean' => ContractService::toBool($row[$key]),
                default   => $row[$key],
            };
        }

        if (isset($row['contract_id'])) {
            $row['contract_id'] = (int) $row['contract_id'];
        }

        return $row;
    }
}
