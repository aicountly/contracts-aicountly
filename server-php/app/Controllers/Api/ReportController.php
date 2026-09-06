<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ReportService;
use App\Support\Enums;
use App\Support\Permissions;
use Closure;

/**
 * The report catalogue, one report's rows, and the CSV of them.
 *
 * A report key is a lookup into the service's own catalogue, never a fragment
 * of SQL. It is confirmed against that catalogue here so an unknown key answers
 * 404 — there is nothing to conceal, since the catalogue is readable by anyone
 * holding REPORT_VIEW — rather than becoming whatever a lookup miss turns into
 * further down.
 *
 * Export is separately granted and separately rate-limited: reading a report on
 * screen and carrying the whole of it out of the product are different
 * decisions, and only the second one leaves the data somewhere this product can
 * no longer control.
 */
final class ReportController extends BaseController
{
    /**
     * Rows one export may carry.
     *
     * Capped rather than streamed, as the contract export is: without a ceiling
     * a single request is a way to pull an entire tenant, and nobody reads
     * 10,000 rows in a spreadsheet anyway.
     */
    private const EXPORT_LIMIT = 10000;

    public function definitions(): void
    {
        $this->requirePermission(Permissions::REPORT_VIEW);

        $this->respond(fn (): array => $this->service()->definitions());
    }

    /**
     * One page of a report's rows.
     *
     * The route names this action `run`, and BaseController::run(callable) —
     * which respond() reaches through `$this` — already holds that name. PHP
     * allows the override only while it stays signature-compatible with the
     * base, so the parameter is widened and a closure arriving here is the base
     * helper being used and goes straight back to it. A string is the report
     * key out of the URL. Renaming the route instead would mean editing a file
     * this controller does not own.
     */
    public function run(mixed $key = null): mixed
    {
        if ($key instanceof Closure) {
            return parent::run($key);
        }

        $ctx       = $this->requirePermission(Permissions::REPORT_VIEW);
        $reportKey = $this->reportKey($key);
        $page      = Request::pagination(50, 200);

        $result = parent::run(fn (): array => $this->service()->run(
            $ctx,
            $reportKey,
            $this->filters(),
            $page['per_page'],
            $page['offset']
        ));

        $rows = self::rows($result);

        // `items` is what every paged endpoint in this API answers with, so a
        // shared pager can read it; `rows` is the name the report contract
        // gives the same list, and the report screen reads it beside `columns`.
        // One array under two names is cheaper than two shapes of pager.
        Response::paginated($rows, (int) ($result['total'] ?? count($rows)), $page['page'], $page['per_page'], [
            'key'     => $reportKey,
            'columns' => self::columns($result),
            'rows'    => $rows,
            'summary' => $result['summary'] ?? null,
        ]);
    }

    /**
     * The same report as a CSV file.
     *
     * Two grants are named because the two are separate decisions, and the
     * budget is the contract export's: ten in five minutes is generous for
     * someone working and hostile to a script walking the catalogue.
     */
    public function export(?string $key = null): void
    {
        $this->requirePermission(Permissions::REPORT_VIEW);
        $ctx = $this->requirePermission(Permissions::EXPORT);
        $this->rateLimit('report.export', 10, 300);

        $reportKey = $this->reportKey($key);

        $result = parent::run(fn (): array => $this->service()->run(
            $ctx,
            $reportKey,
            $this->filters(),
            self::EXPORT_LIMIT,
            0
        ));

        $columns = self::columns($result);
        $rows    = self::rows($result);

        // The service defines the shape of its own columns and rows, so it
        // serialises them when it can. The fallback exists so a report is still
        // exportable while that method is being written, and it neutralises
        // formula injection the same way because a CSV with a live `=cmd|…`
        // cell in it is remote code execution on a finance user's machine.
        $csv = method_exists(ReportService::class, 'toCsv')
            ? (string) $this->service()->toCsv($columns, $rows)
            : ContractController::toCsv(self::headers($columns), self::matrix($columns, $rows));

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="report-' . $reportKey . '-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    /**
     * The report named in the URL, as the catalogue spells it.
     *
     * The shape check runs first so a malformed key never reaches the
     * catalogue read, and it is also what makes the key safe to place in a
     * Content-Disposition filename.
     */
    private function reportKey(mixed $raw): string
    {
        $key = is_string($raw) ? strtolower(trim($raw)) : '';
        if ($key === '' || preg_match('/^[a-z0-9_]{1,64}$/', $key) !== 1) {
            Response::notFound('No such report.');
        }

        $definitions = parent::run(fn (): array => $this->service()->definitions());

        foreach ($definitions as $definition) {
            $candidate = is_array($definition) ? ($definition['key'] ?? null) : $definition;
            if (is_string($candidate) && strtolower(trim($candidate)) === $key) {
                return trim($candidate);
            }
        }

        Response::notFound('No such report.');
    }

    /**
     * What the report is narrowed to, cleaned.
     *
     * Only these keys survive; anything else in the query string is dropped
     * rather than handed on, so a parameter the service does not understand can
     * never become one it half-applies. A filter the caller did not send is
     * omitted rather than sent as null.
     *
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        $filters = [
            'bo_id'            => self::id(Request::query('bo_id') ?? Request::query('branch_id')),
            'contract_type_id' => self::id(Request::query('contract_type_id')),
            'department_id'    => self::id(Request::query('department_id')),
            'owner_uuid'       => self::text(Request::query('owner_uuid'), 64),
            'counterparty'     => self::text(Request::query('counterparty'), 255),
            'status'           => Enums::coerce(Request::query('status'), Enums::CONTRACT_STATUSES),
            'risk_level'       => Enums::coerce(Request::query('risk_level'), Enums::RISK_LEVELS),
            'date_from'        => self::date(Request::query('date_from') ?? Request::query('effective_from')),
            'date_to'          => self::date(Request::query('date_to') ?? Request::query('effective_to')),
        ];

        // A window whose end precedes its start matches nothing, and an empty
        // report reads as broken rather than as a mistyped date.
        if ($filters['date_from'] !== null && $filters['date_to'] !== null
            && $filters['date_to'] < $filters['date_from']) {
            [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
        }

        return array_filter($filters, static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string,mixed> $result @return array<mixed> */
    private static function columns(array $result): array
    {
        return is_array($result['columns'] ?? null) ? $result['columns'] : [];
    }

    /** @param array<string,mixed> $result @return list<mixed> */
    private static function rows(array $result): array
    {
        return is_array($result['rows'] ?? null) ? array_values($result['rows']) : [];
    }

    /**
     * Column labels for the fallback serialiser.
     *
     * Reads the forms a column list plausibly takes — objects carrying a label,
     * a map of key to label, or bare names — so the fallback still produces a
     * headed file rather than an unlabelled grid.
     *
     * @param array<mixed> $columns
     * @return list<string>
     */
    private static function headers(array $columns): array
    {
        $headers = [];
        foreach ($columns as $key => $column) {
            $headers[] = is_array($column)
                ? (string) ($column['label'] ?? $column['title'] ?? $column['key'] ?? $key)
                : (string) $column;
        }

        return $headers;
    }

    /**
     * Rows flattened to strings, in column order, for the fallback serialiser.
     *
     * A row may be an object keyed by column or a plain tuple; both are read
     * here so the file's columns line up with its header either way.
     *
     * @param array<mixed> $columns
     * @param list<mixed> $rows
     * @return list<list<string>>
     */
    private static function matrix(array $columns, array $rows): array
    {
        $positional = array_is_list($columns);
        $keys       = [];
        foreach ($columns as $key => $column) {
            if (is_array($column)) {
                $keys[] = $column['key'] ?? $column['field'] ?? $key;
            } else {
                // A bare list of column names: the name is also how a row keys
                // its cell when the row is an object rather than a tuple.
                $keys[] = $positional ? $column : $key;
            }
        }

        $matrix = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $tuple = array_is_list($row);
            $cells = [];
            foreach ($keys as $position => $columnKey) {
                $cells[] = self::cell($tuple ? ($row[$position] ?? null) : ($row[$columnKey] ?? null));
            }
            $matrix[] = $cells;
        }

        return $matrix;
    }

    private static function cell(mixed $value): string
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

    private static function id(?string $value): ?int
    {
        if ($value === null || preg_match('/^\d{1,19}$/', trim($value)) !== 1) {
            return null;
        }

        $id = (int) trim($value);

        return $id > 0 ? $id : null;
    }

    private static function text(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = mb_substr(trim($value), 0, $max);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m) !== 1) {
            return null;
        }

        // "2026-02-30" satisfies the pattern and then raises a datetime field
        // overflow in PostgreSQL — a 500 for what is a typo in a date picker.
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $m[0] : null;
    }

    private function service(): ReportService
    {
        return new ReportService($this->db());
    }
}
