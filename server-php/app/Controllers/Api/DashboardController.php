<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Services\DashboardService;
use App\Support\Enums;
use App\Support\Permissions;

/**
 * The landing screen's four reads.
 *
 * Every figure here is an aggregate the server computes. A browser that summed
 * the page of contracts it happens to hold would produce a total that is
 * quietly wrong the moment that page is not the whole set, so the narrowing is
 * part of the question asked of the database and every filter is validated
 * before it gets there.
 *
 * All four actions ask only for CONTRACT_VIEW: the dashboard shows counts and
 * shapes over rows the caller can already open, and the service applies the
 * same tenant scope and the same owner narrowing the repository does.
 */
final class DashboardController extends BaseController
{
    /**
     * Longest activity feed a caller may ask for.
     *
     * The feed is a glance at what just happened, not a history — the timeline
     * on a contract is where history lives — so there is no pager here and the
     * cap is what stands in for one.
     */
    private const MAX_ACTIVITY = 100;

    public function kpis(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn (): array => $this->service()->kpis($ctx, $this->filters()));
    }

    public function charts(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn (): array => $this->service()->charts($ctx, $this->filters()));
    }

    public function myActions(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn (): array => $this->service()->myActions($ctx));
    }

    public function activity(): void
    {
        $ctx   = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $limit = self::clampedInt(Request::query('limit'), 20, 1, self::MAX_ACTIVITY);

        $this->respond(fn (): array => $this->service()->recentActivity($ctx, $limit));
    }

    /**
     * What the dashboard is narrowed to, cleaned.
     *
     * Only the keys below survive. Anything else appended to the query string
     * is dropped here rather than passed along, so a parameter the service does
     * not understand can never become one it half-applies. A filter the caller
     * did not send is omitted rather than sent as null, which keeps "not
     * filtered" distinguishable from "filtered to nothing".
     *
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        // The SPA sends the branch and the date window under the repository's
        // names, because following a KPI tile through to the contract list
        // reuses the same query string and the repository filters on
        // `effective_from`/`effective_to`. Accepting both spellings is what
        // makes the drilled-through list show the rows the tile counted.
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
        // dashboard reads as broken rather than as a mistyped date.
        if ($filters['date_from'] !== null && $filters['date_to'] !== null
            && $filters['date_to'] < $filters['date_from']) {
            [$filters['date_from'], $filters['date_to']] = [$filters['date_to'], $filters['date_from']];
        }

        return array_filter($filters, static fn (mixed $value): bool => $value !== null);
    }

    private static function id(?string $value): ?int
    {
        if ($value === null || preg_match('/^\d{1,19}$/', trim($value)) !== 1) {
            return null;
        }

        $id = (int) trim($value);

        return $id > 0 ? $id : null;
    }

    /** Trimmed and truncated to what the column can hold, or null if nothing is left. */
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

    /** A caller-supplied count, pulled into range rather than refused. */
    private static function clampedInt(?string $value, int $default, int $min, int $max): int
    {
        if ($value === null || preg_match('/^\d{1,9}$/', trim($value)) !== 1) {
            return $default;
        }

        return max($min, min($max, (int) trim($value)));
    }

    private function service(): DashboardService
    {
        return new DashboardService($this->db());
    }
}
