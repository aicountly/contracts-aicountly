<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Http;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Manage\ManageClient;
use App\Modules\Portal\PortalClient;

/**
 * The company and branch lists the SPA needs before a company is selected.
 *
 * These are relayed rather than called from the browser because
 * manage.aicountly.com's CORS allow-list does not include this host — and
 * widening it for every product would mean maintaining an eleven-entry list on
 * every product. Relaying also means the ses_key is only ever presented to one
 * origin from the browser's point of view.
 *
 * This is a narrow relay of two reads, not a general proxy. Anything that
 * writes to Manage belongs in Manage's own UI.
 */
final class ManageProxyController extends BaseController
{
    public function companies(): void
    {
        $sesKey = $this->sessionKey();

        $payload = Http::json(
            'GET',
            ManageClient::base() . '/api/companies',
            ['Authorization: Bearer ' . $sesKey]
        );

        if ($payload === null) {
            Response::error('MANAGE_UNAVAILABLE', 'Could not reach Manage Account to list your companies.', 503);
        }

        $rows = $payload['data'] ?? $payload;
        if (! is_array($rows)) {
            $rows = [];
        }

        $companies = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = self::pick($row, ['cmp_id', 'comp_id', 'id']);
            if ($id === '') {
                continue;
            }
            $companies[] = [
                'cmp_id'     => $id,
                'name'       => self::pick($row, ['company_name', 'cmp_name', 'name', 'legal_name']) ?: ('Company ' . $id),
                'legal_name' => self::pick($row, ['legal_name', 'company_name']),
                'gstin'      => self::pick($row, ['gstin', 'gst_no']),
                'currency'   => self::pick($row, ['currency', 'base_currency']) ?: 'INR',
                'is_owner'   => (bool) ($row['is_owner'] ?? $row['owner'] ?? false),
            ];
        }

        Response::success($companies);
    }

    public function company(): void
    {
        $sesKey = $this->sessionKey();
        $cmpId  = trim((string) (Request::query('cmp_id') ?? ''));

        if ($cmpId === '' || ! ctype_digit($cmpId)) {
            Response::error('MISSING_COMPANY_CONTEXT', 'cmp_id is required.', 400);
        }

        $company = ManageClient::companyInfo($sesKey, $cmpId);
        if ($company === null) {
            Response::error('COMPANY_ACCESS_DENIED', 'You do not have access to this company.', 403);
        }

        Response::success([
            'company'         => array_merge(
                ManageClient::summarise($company),
                ['name' => ManageClient::summarise($company)['legal_name'] ?: ('Company ' . $cmpId)]
            ),
            'branches'        => ManageClient::branches($company),
            'financial_years' => self::financialYears($company),
        ]);
    }

    /**
     * Authenticate without requiring a company.
     *
     * These two endpoints are what the SPA calls in order to *choose* a
     * company, so requiring one would be circular.
     */
    private function sessionKey(): string
    {
        $sesKey = Request::bearerToken();
        if ($sesKey === '') {
            Response::unauthorized('Missing bearer session key.');
        }

        if (PortalClient::validateSesKey($sesKey) === null) {
            Response::unauthorized();
        }

        return $sesKey;
    }

    /** @param array<string,mixed> $company @return list<array<string,mixed>> */
    private static function financialYears(array $company): array
    {
        foreach (['fy_list', 'financial_years', 'financialYears', 'fyList', 'fys'] as $key) {
            if (! isset($company[$key]) || ! is_array($company[$key])) {
                continue;
            }

            $out = [];
            foreach ($company[$key] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = self::pick($row, ['cmpfymastr_id', 'comp_fy_id', 'fy_id', 'id']);
                if ($id === '') {
                    continue;
                }
                $start = self::pick($row, ['fy_start', 'start_date', 'from_date']);
                $end   = self::pick($row, ['fy_end', 'end_date', 'to_date']);

                $out[] = [
                    'id'         => $id,
                    'label'      => self::pick($row, ['fy_name', 'label', 'name', 'financial_year'])
                        ?: trim(substr($start, 0, 4) . '–' . substr($end, 0, 4), '–') ?: ('FY ' . $id),
                    'start_date' => $start ?: null,
                    'end_date'   => $end ?: null,
                    'is_current' => (bool) ($row['is_current'] ?? $row['current'] ?? false),
                ];
            }

            if ($out !== []) {
                return $out;
            }
        }

        // Some companyinfo shapes carry only the active year, not a list.
        $single = self::pick($company, ['cmpfymastr_id', 'fy_id', 'comp_fy_id']);

        return $single === ''
            ? []
            : [['id' => $single, 'label' => 'Current financial year', 'is_current' => true]];
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private static function pick(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && (is_string($row[$key]) || is_numeric($row[$key]))) {
                $value = trim((string) $row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}
