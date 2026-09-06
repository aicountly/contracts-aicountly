<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\TenantContext;
use PDO;
use RuntimeException;

/**
 * Human-readable contract and request numbers — `CON-2026-000142`.
 *
 * A dedicated counter table rather than a PostgreSQL sequence, because the
 * numbering is per company and optionally resets each year, and one sequence
 * can do neither. Allocation takes a row lock inside the caller's transaction,
 * so two simultaneous creates cannot both take 142.
 */
final class NumberingService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function nextContractNumber(TenantContext $ctx): string
    {
        return $this->allocate($ctx->environment, $ctx->cmpId, 'contract');
    }

    public function nextRequestNumber(TenantContext $ctx): string
    {
        return $this->allocate($ctx->environment, $ctx->cmpId, 'request');
    }

    /**
     * Take the next number in a series.
     *
     * Must run inside a transaction that also writes the row the number is for.
     * If it does not, a rolled-back create leaves a gap — which is tolerable —
     * but a crash between allocate and insert leaves a number issued to nothing,
     * which is why the callers wrap both.
     */
    public function allocate(string $environment, int $cmpId, string $series): string
    {
        $settings = $this->settings($environment, $cmpId);

        $prefix = $series === 'request'
            ? (string) ($settings['request_prefix'] ?? 'REQ')
            : (string) ($settings['number_prefix'] ?? 'CON');

        $pad         = max(3, min(12, (int) ($settings['number_pad'] ?? 6)));
        $includeYear = (bool) ($settings['number_include_year'] ?? true);
        $resetYearly = (bool) ($settings['number_reset_yearly'] ?? true);

        $year      = date('Y');
        $seriesKey = $resetYearly ? $series . ':' . $year : $series;

        $st = $this->pdo->prepare(
            'INSERT INTO contract_number_counters (environment, cmp_id, series_key, last_value, updated_at)
             VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP)
             ON CONFLICT (environment, cmp_id, series_key) DO UPDATE
             SET last_value = contract_number_counters.last_value + 1,
                 updated_at = CURRENT_TIMESTAMP
             RETURNING last_value'
        );
        $st->execute([$environment, $cmpId, $seriesKey]);
        $value = $st->fetchColumn();

        if ($value === false) {
            throw new RuntimeException('Could not allocate a contract number.');
        }

        $parts = [$prefix];
        if ($includeYear) {
            $parts[] = $year;
        }
        $parts[] = str_pad((string) (int) $value, $pad, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    /**
     * What the next number would look like, without taking it.
     *
     * The settings screen shows this so an admin changing the prefix can see
     * the result before saving.
     */
    public function preview(string $environment, int $cmpId, string $series = 'contract'): string
    {
        $settings    = $this->settings($environment, $cmpId);
        $prefix      = $series === 'request'
            ? (string) ($settings['request_prefix'] ?? 'REQ')
            : (string) ($settings['number_prefix'] ?? 'CON');
        $pad         = max(3, min(12, (int) ($settings['number_pad'] ?? 6)));
        $includeYear = (bool) ($settings['number_include_year'] ?? true);
        $resetYearly = (bool) ($settings['number_reset_yearly'] ?? true);

        $year      = date('Y');
        $seriesKey = $resetYearly ? $series . ':' . $year : $series;

        $st = $this->pdo->prepare(
            'SELECT last_value FROM contract_number_counters
             WHERE environment = ? AND cmp_id = ? AND series_key = ?'
        );
        $st->execute([$environment, $cmpId, $seriesKey]);
        $next = ((int) $st->fetchColumn()) + 1;

        $parts = [$prefix];
        if ($includeYear) {
            $parts[] = $year;
        }
        $parts[] = str_pad((string) $next, $pad, '0', STR_PAD_LEFT);

        return implode('-', $parts);
    }

    /** @return array<string,mixed> */
    private function settings(string $environment, int $cmpId): array
    {
        $st = $this->pdo->prepare(
            'SELECT number_prefix, number_pad, number_include_year, number_reset_yearly, settings_json
             FROM contract_settings WHERE environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$environment, $cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            return [];
        }

        $extra = [];
        if (isset($row['settings_json'])) {
            $decoded = json_decode((string) $row['settings_json'], true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }

        return array_merge($row, $extra);
    }
}
