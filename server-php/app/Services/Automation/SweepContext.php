<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Services\ActivityService;
use App\Services\NotificationService;
use App\Support\Dates;
use PDO;

/**
 * What a nightly sweep needs, assembled once.
 *
 * Sweeps run without a signed-in user, so they cannot use TenantContext — each
 * works across companies and attributes its actions to the system rather than
 * to whoever last touched the record.
 */
final class SweepContext
{
    public int $processed = 0;

    public int $notified = 0;

    public int $errors = 0;

    /** @var array<string,mixed> */
    public array $detail = [];

    public function __construct(
        public readonly PDO $pdo,
        public readonly string $environment,
        public readonly ?int $cmpId = null,
        public readonly bool $dryRun = false,
    ) {
    }

    public function notifications(): NotificationService
    {
        return new NotificationService($this->pdo);
    }

    public function activity(): ActivityService
    {
        return new ActivityService($this->pdo);
    }

    /**
     * Companies with Contracts configured.
     *
     * A company appears here only once it has opened Contracts, because that is
     * when CompanyBootstrapService writes its settings row. Sweeping every
     * cmp_id that ever appeared in any table would mean doing work for
     * companies that have never used the product.
     *
     * @return list<int>
     */
    public function companies(): array
    {
        if ($this->cmpId !== null) {
            return [$this->cmpId];
        }

        $st = $this->pdo->prepare(
            'SELECT cmp_id FROM contract_settings WHERE environment = ? ORDER BY cmp_id'
        );
        $st->execute([$this->environment]);

        return array_map(static fn (array $r): int => (int) $r['cmp_id'], $st->fetchAll() ?: []);
    }

    /**
     * A company's configured alert ladder, e.g. [90, 60, 30, 15, 7].
     *
     * @param 'expiry_alert_days'|'obligation_alert_days' $column
     * @param list<int> $fallback
     * @return list<int>
     */
    public function reminderLadder(int $cmpId, string $column, array $fallback): array
    {
        // The column is chosen from a fixed pair at the call site, never from
        // input, so interpolating it is safe — and the union type above is what
        // makes that checkable rather than a promise in prose.
        if (! in_array($column, ['expiry_alert_days', 'obligation_alert_days'], true)) {
            return $fallback;
        }

        $st = $this->pdo->prepare(
            "SELECT {$column} FROM contract_settings WHERE environment = ? AND cmp_id = ? LIMIT 1"
        );
        $st->execute([$this->environment, $cmpId]);
        $raw = $st->fetchColumn();

        return Dates::reminderLadder(is_string($raw) ? $raw : null, $fallback);
    }

    public function noteError(string $task, string $message): void
    {
        $this->errors++;
        $this->detail['errors'][] = $task . ': ' . $message;
        error_log("[contracts][cron][{$task}] " . $message);
    }
}
