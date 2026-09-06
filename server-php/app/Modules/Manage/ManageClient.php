<?php

declare(strict_types=1);

namespace App\Modules\Manage;

use App\Core\Env;
use App\Core\Http;

/**
 * Company, branch and financial-year context from Aicountly Manage.
 *
 * Manage is the system of record for the company master. Contracts stores a
 * `cmp_id` on every row and nothing else about the company — the legal name and
 * address are read from here at render time, and captured into an immutable
 * party snapshot only at execution (see ContractPartyService), because a
 * contract must keep saying what the company was called on the day it was
 * signed even after Manage is edited.
 */
final class ManageClient
{
    /** Per-process memo: one request may validate context on several code paths. */
    private static array $memo = [];

    public static function base(): string
    {
        $fromEnv = trim(Env::get('MANAGE_API_BASE'));
        if ($fromEnv !== '') {
            return self::normalise($fromEnv);
        }

        return self::isSandboxHost() ? 'https://manage.gh.aicountly.com' : 'https://manage.aicountly.com';
    }

    /**
     * The company payload for `cmp_id`, as the acting user is allowed to see it.
     *
     * This doubles as the authorisation check: Manage refuses a company the
     * session may not read, so a null here means "no access", not just "no
     * data". Contracts never decides company access on its own.
     *
     * @return array<string,mixed>|null
     */
    public static function companyInfo(string $sesKey, string $cmpId): ?array
    {
        $memoKey = hash('sha256', $sesKey) . '|' . $cmpId;
        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }

        $url = self::base() . '/api/companyinfo?comp_id=' . rawurlencode($cmpId);

        $payload = Http::json('GET', $url, ['Authorization: Bearer ' . $sesKey]);

        if ($payload === null) {
            // Fall back to the portal origin, which also serves companyinfo —
            // Drive does the same, and it is what keeps context working when a
            // MANAGE_API_BASE in .env has gone stale.
            $payload = Http::json(
                'GET',
                'https://my.aicountly.com/api/companyinfo?comp_id=' . rawurlencode($cmpId),
                ['Authorization: Bearer ' . $sesKey]
            );
        }

        if ($payload === null) {
            return self::$memo[$memoKey] = null;
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        return self::$memo[$memoKey] = $payload;
    }

    /**
     * Company details reduced to the fields a contract actually needs.
     *
     * @param array<string,mixed> $company
     * @return array<string,string>
     */
    public static function summarise(array $company): array
    {
        return [
            'cmp_id'          => (string) self::pick($company, ['cmp_id', 'comp_id', 'id']),
            'legal_name'      => (string) self::pick($company, ['legal_name', 'company_name', 'cmp_name', 'name']),
            'trading_name'    => (string) self::pick($company, ['trade_name', 'trading_name', 'display_name']),
            'gstin'           => (string) self::pick($company, ['gstin', 'gst_no', 'gst_number']),
            'pan'             => (string) self::pick($company, ['pan', 'pan_no', 'pan_number']),
            'cin'             => (string) self::pick($company, ['cin', 'cin_no']),
            'address'         => (string) self::pick($company, ['address', 'registered_address', 'addr1']),
            'city'            => (string) self::pick($company, ['city', 'cty_name']),
            'state'           => (string) self::pick($company, ['state', 'state_name']),
            'country'         => (string) self::pick($company, ['country', 'country_name']),
            'pincode'         => (string) self::pick($company, ['pincode', 'pin_code', 'zip']),
            'email'           => (string) self::pick($company, ['email', 'company_email']),
            'phone'           => (string) self::pick($company, ['phone', 'mobile', 'contact_no']),
            'base_currency'   => (string) (self::pick($company, ['currency', 'base_currency', 'currency_code']) ?: 'INR'),
        ];
    }

    /**
     * Whether `fyId` is one of the company's financial years.
     *
     * Manage returns the list under several key names depending on the
     * endpoint version, so every known spelling is tried rather than assuming
     * one — guessing wrong here would reject every request rather than fail
     * visibly.
     *
     * @param array<string,mixed> $company
     */
    public static function financialYearIsValid(array $company, string $fyId): bool
    {
        foreach (['cmpfymastr_id', 'fy_id', 'comp_fy_id'] as $key) {
            if (isset($company[$key]) && (string) $company[$key] === $fyId) {
                return true;
            }
        }

        return self::listContains(
            self::firstList($company, ['fy_list', 'financial_years', 'financialYears', 'fyList', 'fys']),
            $fyId,
            ['cmpfymastr_id', 'comp_fy_id', 'fy_id', 'id']
        );
    }

    /** @param array<string,mixed> $company */
    public static function branchIsValid(array $company, string $boId): bool
    {
        foreach (['bo_id', 'hobo_id'] as $key) {
            if (isset($company[$key]) && (string) $company[$key] === $boId) {
                return true;
            }
        }

        return self::listContains(
            self::firstList($company, ['branch_list', 'bo_list', 'branches', 'hobo_list']),
            $boId,
            ['id', 'bo_id', 'hobo_id', 'branch_id']
        );
    }

    /**
     * Branches for the company, normalised to `{id, name}`.
     *
     * @param array<string,mixed> $company
     * @return list<array{id: string, name: string}>
     */
    public static function branches(array $company): array
    {
        $rows = self::firstList($company, ['branch_list', 'bo_list', 'branches', 'hobo_list']) ?? [];
        $out  = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = (string) self::pick($row, ['id', 'bo_id', 'hobo_id', 'branch_id']);
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id'   => $id,
                'name' => (string) (self::pick($row, ['name', 'bo_name', 'branch_name', 'title']) ?: ('Branch ' . $id)),
            ];
        }

        return $out;
    }

    /** @internal tests only */
    public static function resetMemo(): void
    {
        self::$memo = [];
    }

    private static function isSandboxHost(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = preg_replace('/^www\./i', '', $host) ?? '';

        return str_ends_with($host, '.gh.aicountly.com')
            || str_starts_with($host, 'gh-')
            || $host === 'localhost'
            || $host === '127.0.0.1';
    }

    /** The gh-* sandbox hosts were retired; remap so a stale .env still resolves. */
    private static function normalise(string $base): string
    {
        $base   = rtrim(trim($base), '/');
        $legacy = [
            'https://gh-manage.aicountly.com' => 'https://manage.gh.aicountly.com',
            'http://gh-manage.aicountly.com'  => 'https://manage.gh.aicountly.com',
        ];

        return $legacy[$base] ?? $base;
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

    /** @param array<string,mixed> $row @param list<string> $keys @return list<mixed>|null */
    private static function firstList(array $row, array $keys): ?array
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_array($row[$key])) {
                return array_values($row[$key]);
            }
        }

        return null;
    }

    /** @param list<mixed>|null $rows @param list<string> $keys */
    private static function listContains(?array $rows, string $needle, array $keys): bool
    {
        if ($rows === null) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($keys as $key) {
                if (isset($row[$key]) && (string) $row[$key] === $needle) {
                    return true;
                }
            }
        }

        return false;
    }
}
