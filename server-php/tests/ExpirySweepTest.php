<?php

declare(strict_types=1);

/**
 * ExpirySweep's dedupe key must survive a renewal.
 *
 * A renewal moves notice_deadline/expiry_date forward on the SAME contract
 * row (RenewalService::renew() is an UPDATE, never an INSERT), so a dedupe key
 * built from contract id and threshold alone is identical across terms. That
 * collides with `uq_notification_dedupe`, which is keyed on the dedupe string
 * itself — the second term's entire notice ladder is then silently swallowed
 * by ON CONFLICT DO NOTHING and nobody is ever warned that the cancellation
 * window is closing again.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\Automation\ExpirySweep;
use App\Services\Automation\SweepContext;
use App\Support\Dates;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$env   = 'sandbox';
$cmpId = 1;

$pdo->exec(
    "INSERT INTO contracts (environment, cmp_id, contract_number, title, status, owner_uuid,
        auto_renewal, notice_period_days, created_by)
     VALUES ('sandbox', 1, 'CON-2026-000001', 'Managed services', 'active', 'USER-ALICE',
        TRUE, 90, 'USER-ALICE')"
);
$contractId = (int) $pdo->query('SELECT id FROM contracts LIMIT 1')->fetchColumn();

$countRows = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM contract_notifications')->fetchColumn();

$setNoticeDeadline = static function (string $date) use ($pdo, $contractId): void {
    $pdo->prepare('UPDATE contracts SET notice_deadline = ? WHERE id = ?')->execute([$date, $contractId]);
};

$sweep = static function () use ($pdo, $env, $cmpId): SweepContext {
    $ctx = new SweepContext($pdo, $env, $cmpId, false);
    ExpirySweep::run($ctx);

    return $ctx;
};

// -----------------------------------------------------------------------
// Term 1 — walk the default notice ladder (90/60/30/15/7 days out).
// -----------------------------------------------------------------------

foreach ([90, 60, 30, 15, 7] as $daysOut) {
    $setNoticeDeadline(Dates::addDays(Dates::today(), $daysOut));
    $ctx = $sweep();
    assert_same(0, $ctx->errors, "term 1, {$daysOut} days out: sweep runs without error");
    assert_same(1, $ctx->notified, "term 1, {$daysOut} days out: exactly one recipient is notified");
}

assert_same(5, $countRows(), 'term 1 sends one notification per ladder step');

// -----------------------------------------------------------------------
// Renewal — same contract row, RenewalService::renew() UPDATEs
// notice_deadline in place rather than inserting a new contract. A test
// cannot fast-forward the real clock a year to land on the same band the
// slow way, so it stands in a fresh notice_deadline that falls a few days
// inside each band ExpirySweep already fired on (90/60/30/15/7 -> 85/55/
// 25/12/5). thresholdFor() maps every one of those to the SAME threshold as
// term 1 — which is exactly the pre-fix collision: same contract id, same
// threshold, but a genuinely different deadline the recipient has never
// been warned about.
// -----------------------------------------------------------------------

foreach ([90 => 85, 60 => 55, 30 => 25, 15 => 12, 7 => 5] as $band => $daysOut) {
    $setNoticeDeadline(Dates::addDays(Dates::today(), $daysOut));
    $ctx = $sweep();
    assert_same(0, $ctx->errors, "term 2, band {$band}: sweep runs without error");
    assert_same(
        1,
        $ctx->notified,
        "term 2, band {$band}: the renewed term's ladder is not swallowed by term 1's dedupe keys"
    );
}

assert_same(10, $countRows(), 'the second term notifies on every ladder step, same as the first');

// The two terms' rows for the same threshold must carry different dedupe
// keys — that is the actual mechanism the fix relies on, not just the count.
$keys = $pdo->query(
    "SELECT dedupe_key FROM contract_notifications
     WHERE event_type = 'contract.notice_deadline' AND dedupe_key LIKE 'notice:{$contractId}:%:7'
     ORDER BY id"
)->fetchAll(PDO::FETCH_COLUMN);

assert_count(2, $keys, 'both terms leave a distinct row for the 7-day band');
assert_true($keys[0] !== $keys[1], 'the two terms\' dedupe keys for the same band are not identical');

t_done('ExpirySweepTest');
