<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ActivityService;
use App\Services\AuditService;
use App\Services\ContractService;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use Throwable;

/**
 * The contract resource.
 *
 * Each action names the permission it needs before it does anything, so a new
 * endpoint that forgets one fails review rather than shipping open.
 */
final class ContractController extends BaseController
{
    public function index(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $result = $this->run(fn () => $this->service()->search(
            $ctx,
            $this->filters(),
            $page['per_page'],
            $page['offset']
        ));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function store(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_CREATE);

        $this->respond(fn () => $this->service()->create($ctx, $this->body()), 201);
    }

    public function show(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);

        $contract = $this->run(fn () => $this->service()->findOrFail($ctx, $contractId));

        // Recording the view is a convenience for the "recently viewed" strip;
        // it must never be able to fail the read it decorates.
        try {
            $this->service()->touchRecentView($ctx, $contractId);
        } catch (Throwable $e) {
            error_log('[contracts] recent-view write failed: ' . $e->getMessage());
        }

        $contract['tabs'] = $this->tabCounts($ctx, $contractId);

        if (! $ctx->has(Permissions::COMMERCIALS_VIEW)) {
            // Commercial figures are a separate grant. Leaving them in the
            // payload and hiding them client-side would put the numbers in the
            // browser's network tab for anyone who looks.
            foreach (['total_value', 'recurring_value', 'commercial_summary'] as $field) {
                $contract[$field] = null;
            }
            $contract['commercials_hidden'] = true;
        }

        Response::success($contract);
    }

    public function update(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_EDIT);

        $this->respond(fn () => $this->service()->update($ctx, $this->intId($id), $this->body()));
    }

    public function destroy(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_DELETE);
        $contractId = $this->intId($id);

        $this->run(function () use ($ctx, $contractId): bool {
            $this->service()->deleteDraft($ctx, $contractId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    public function changeStatus(?string $id = null): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $body = $this->body();

        $status = Enums::coerce($body['status'] ?? null, Enums::CONTRACT_STATUSES);
        if ($status === null) {
            Response::validationError(['status' => 'Choose a valid contract status.']);
        }

        // Terminating is a separate grant from editing: ending an agreement has
        // consequences an editor should not be able to trigger by changing a
        // dropdown.
        if ($status === 'terminated' && ! $ctx->has(Permissions::CONTRACT_TERMINATE)) {
            Response::forbidden('Your Contracts role does not allow terminating a contract.');
        }
        if ($status === 'archived' && ! $ctx->has(Permissions::CONTRACT_ARCHIVE)) {
            Response::forbidden('Your Contracts role does not allow archiving a contract.');
        }

        $note = isset($body['note']) && is_string($body['note']) ? mb_substr(trim($body['note']), 0, 1000) : null;

        $this->respond(fn () => $this->service()->changeStatus($ctx, $this->intId($id), $status, $note));
    }

    public function archive(?string $id = null): void
    {
        $ctx      = $this->requirePermission(Permissions::CONTRACT_ARCHIVE);
        $archived = (bool) ($this->body()['archived'] ?? true);

        $this->respond(fn () => $this->service()->archive($ctx, $this->intId($id), $archived));
    }

    public function favourite(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $favourite = (bool) ($this->body()['favourite'] ?? true);
        $contractId = $this->intId($id);

        $this->run(function () use ($ctx, $contractId, $favourite): bool {
            $this->service()->setFavourite($ctx, $contractId, $favourite);

            return true;
        });

        Response::success(['favourite' => $favourite]);
    }

    public function activity(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);
        $page       = Request::pagination(30, 100);

        // Confirms the contract is this tenant's before reading its history.
        $this->run(fn () => $this->service()->findOrFail($ctx, $contractId));

        $activity = new ActivityService($this->db());
        $items    = $activity->listForContract($ctx, $contractId, $page['per_page'], $page['offset']);

        Response::paginated($items, count($items) < $page['per_page']
            ? $page['offset'] + count($items)
            : $page['offset'] + $page['per_page'] + 1, $page['page'], $page['per_page']);
    }

    public function audit(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::AUDIT_VIEW);
        $contractId = $this->intId($id);
        $page       = Request::pagination(50, 200);

        $this->run(fn () => $this->service()->findOrFail($ctx, $contractId));

        $audit = new AuditService($this->db());

        Response::paginated(
            $audit->listForContract($ctx, $contractId, $page['per_page'], $page['offset']),
            $audit->countForContract($ctx, $contractId),
            $page['page'],
            $page['per_page']
        );
    }

    /**
     * CSV of the current filter selection.
     *
     * Capped rather than streamed: an unbounded export is a way to pull an
     * entire tenant through one request, and 10k rows is already far more than
     * anyone reads in a spreadsheet.
     */
    public function export(): void
    {
        $ctx = $this->requireAnyPermission([Permissions::EXPORT, Permissions::CONTRACT_VIEW_ALL]);
        $this->rateLimit('export', 10, 300);

        $result = $this->run(fn () => $this->service()->search($ctx, $this->filters(), 10000, 0));

        $columns = [
            'contract_number' => 'Contract number',
            'title'           => 'Title',
            'status'          => 'Status',
            'counterparty_name' => 'Counterparty',
            'contract_type_name' => 'Type',
            'department_name' => 'Department',
            'owner_uuid'      => 'Owner',
            'effective_date'  => 'Effective date',
            'expiry_date'     => 'Expiry date',
            'notice_deadline' => 'Notice deadline',
            'renewal_type'    => 'Renewal type',
            'auto_renewal'    => 'Auto renewal',
            'currency'        => 'Currency',
            'total_value'     => 'Total value',
            'risk_level'      => 'Risk',
            'ai_risk_score'   => 'Risk score',
            'approval_status' => 'Approval status',
            'signing_status'  => 'Signing status',
        ];

        if (! $ctx->has(Permissions::COMMERCIALS_VIEW)) {
            unset($columns['total_value']);
        }

        $rows = [];
        foreach ($result['items'] as $item) {
            $row = [];
            foreach (array_keys($columns) as $key) {
                $value = $item[$key] ?? '';
                $row[] = is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
            }
            $rows[] = $row;
        }

        $csv = self::toCsv(array_values($columns), $rows);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="contracts-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }

    /**
     * Serialise to CSV, neutralising formula injection.
     *
     * A cell beginning `=`, `+`, `-` or `@` is executed as a formula when the
     * file is opened in a spreadsheet — that is how an innocuous-looking export
     * becomes a way to run something on a finance user's machine. Prefixing an
     * apostrophe makes the cell literal text.
     *
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $escape = static function (string $value): string {
            if ($value !== '' && str_contains('=+-@', $value[0])) {
                $value = "'" . $value;
            }

            return '"' . str_replace('"', '""', $value) . '"';
        };

        $lines = [implode(',', array_map($escape, $headers))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map($escape, $row));
        }

        // CRLF and a BOM: Excel on Windows misreads a UTF-8 file without them,
        // and a counterparty name with an accent is not an edge case.
        return "\u{FEFF}" . implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Counts behind each workspace tab, so the UI can label them without
     * fetching every tab's payload up front.
     *
     * @return array<string,int>
     */
    private function tabCounts(TenantContext $ctx, int $contractId): array
    {
        $pdo = $this->db();

        // The contract was already confirmed as this tenant's before we got
        // here, so filtering the children by contract_id alone would be
        // correct today. They carry cmp_id anyway: it costs nothing, it uses
        // the same composite indexes, and it means a future refactor that
        // reaches this method with an unvalidated id returns zero rather than
        // leaking a count.
        $tables = [
            'parties'     => 'SELECT COUNT(*) FROM contract_parties WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
            'documents'   => 'SELECT COUNT(*) FROM contract_documents WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
            'clauses'     => 'SELECT COUNT(*) FROM contract_clauses WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
            'obligations' => 'SELECT COUNT(*) FROM contract_obligations WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND is_active',
            'milestones'  => 'SELECT COUNT(*) FROM contract_milestones WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
            'payments'    => 'SELECT COUNT(*) FROM contract_payment_schedules WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
            'approvals'   => 'SELECT COUNT(*) FROM contract_approval_instances WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
            'versions'    => 'SELECT COUNT(*) FROM contract_document_versions v
                              JOIN contract_documents d ON d.id = v.document_id
                              WHERE d.contract_id = ? AND v.environment = ? AND v.cmp_id = ?',
            'amendments'  => 'SELECT COUNT(*) FROM contract_amendments WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
            'risks'       => 'SELECT COUNT(*) FROM contract_risk_findings WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND review_status = \'open\'',
            'comments'    => 'SELECT COUNT(*) FROM contract_comments WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND deleted_at IS NULL',
            'links'       => 'SELECT COUNT(*) FROM contract_linked_records WHERE contract_id = ? AND environment = ? AND cmp_id = ?',
        ];

        $counts = [];
        foreach ($tables as $key => $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
                $counts[$key] = (int) $st->fetchColumn();
            } catch (Throwable $e) {
                // A badge is not worth a 500. A table missing during a partial
                // deploy shows zero rather than breaking the whole workspace.
                error_log('[contracts] tab count for ' . $key . ' failed: ' . $e->getMessage());
                $counts[$key] = 0;
            }
        }

        return $counts;
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $statuses = array_values(array_filter(
            Request::queryList('status'),
            static fn (string $s): bool => Enums::isValid($s, Enums::CONTRACT_STATUSES)
        ));

        $autoRenewal = Request::query('auto_renewal');

        return [
            'q'                    => Request::query('q'),
            'status'               => $statuses,
            'contract_type_id'     => Request::query('contract_type_id'),
            'department_id'        => Request::query('department_id'),
            'owner_uuid'           => Request::query('owner_uuid'),
            'counterparty'         => Request::query('counterparty'),
            'risk_level'           => Enums::coerce(Request::query('risk_level'), Enums::RISK_LEVELS),
            'currency'             => Request::query('currency'),
            'auto_renewal'         => $autoRenewal === null || $autoRenewal === ''
                ? null
                : in_array(strtolower($autoRenewal), ['1', 'true', 'yes'], true),
            'approval_status'      => Request::query('approval_status'),
            'signing_status'       => Request::query('signing_status'),
            'effective_from'       => self::date(Request::query('effective_from')),
            'effective_to'         => self::date(Request::query('effective_to')),
            'expiry_from'          => self::date(Request::query('expiry_from')),
            'expiry_to'            => self::date(Request::query('expiry_to')),
            'value_min'            => self::number(Request::query('value_min')),
            'value_max'            => self::number(Request::query('value_max')),
            'tag_id'               => Request::query('tag_id'),
            'favourites_only'      => Request::query('favourites_only') === '1',
            'expiring_within_days' => self::smallInt(Request::query('expiring_within_days'), 3650),
            'obligation_status'    => Enums::coerce(Request::query('obligation_status'), Enums::OBLIGATION_STATUSES),
            'archived'             => Request::query('archived'),
            'sort'                 => Request::query('sort'),
            'dir'                  => Request::query('dir'),
        ];
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private static function number(?string $value): ?string
    {
        return $value !== null && preg_match('/^-?\d{1,16}(\.\d{1,4})?$/', $value) ? $value : null;
    }

    private static function smallInt(?string $value, int $max): ?int
    {
        if ($value === null || ! preg_match('/^\d{1,5}$/', $value)) {
            return null;
        }

        return min((int) $value, $max);
    }

    private function service(): ContractService
    {
        return new ContractService($this->db());
    }
}
