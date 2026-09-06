<?php

declare(strict_types=1);

/**
 * Adversarial input, from the outside.
 *
 * Every case here is something a caller can actually send. The point is not
 * that the code "should" be safe — it is that these specific attempts produce
 * a refusal or a harmless result, and keep doing so.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\Api\ContractController;
use App\Core\Http;
use App\Services\ContractService;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Validator;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured');
}
t_reset_database($pdo);

$service = new ContractService($pdo);
$ctx     = t_context(cmpId: 1, uuid: 'ALICE');

// --- SQL injection through every caller-influenced filter --------------------
// These reach a WHERE clause. If any of them were interpolated rather than
// bound, one of these would drop the table or return another company's rows.
$service->create($ctx, ['title' => 'Baseline contract', 'counterparty_name' => 'Acme']);

$payloads = [
    "'; DROP TABLE contracts; --",
    "' OR '1'='1",
    "1' UNION SELECT NULL,NULL,NULL--",
    "%'; DELETE FROM contracts WHERE '1'='1",
    "\\'; TRUNCATE contracts; --",
    "') OR 1=1--",
];

foreach ($payloads as $index => $payload) {
    $byQuery = $service->search($ctx, ['q' => $payload], 20, 0);
    assert_same(0, $byQuery['total'], "injection payload #{$index} in q matches nothing");

    $byCounterparty = $service->search($ctx, ['counterparty' => $payload], 20, 0);
    assert_same(0, $byCounterparty['total'], "injection payload #{$index} in counterparty matches nothing");
}

$stillThere = (int) $pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn();
assert_same(1, $stillThere, 'the contracts table survived every injection attempt');

// --- The sort column is the one piece of caller-influenced SQL text ----------
// It is looked up in a fixed map rather than interpolated, so a hostile value
// falls back to the default instead of reaching the query.
$hostileSort = $service->search($ctx, ['sort' => 'title; DROP TABLE contracts', 'dir' => 'asc'], 20, 0);
assert_same(1, $hostileSort['total'], 'an unknown sort key falls back to the default ordering');

$hostileDir = $service->search($ctx, ['sort' => 'title', 'dir' => 'asc; DELETE FROM contracts'], 20, 0);
assert_same(1, $hostileDir['total'], 'an unknown sort direction falls back to DESC');
assert_same(
    1,
    (int) $pdo->query('SELECT COUNT(*) FROM contracts')->fetchColumn(),
    'the table survived the sort-key attempts'
);

// --- Pagination cannot be used to pull a whole tenant ------------------------
$_GET = ['per_page' => '100000', 'page' => '1'];
$page = \App\Core\Request::pagination(25, 100);
assert_same(100, $page['per_page'], 'per_page is clamped to the maximum');

$_GET = ['per_page' => '-5', 'page' => '-3'];
$page = \App\Core\Request::pagination(25, 100);
assert_same(25, $page['per_page'], 'a negative per_page falls back to the default');
assert_same(1, $page['page'], 'a negative page is clamped to the first page');

$_GET = ['page' => '999999999999'];
$page = \App\Core\Request::pagination(25, 100);
assert_true($page['page'] <= 100000, 'an absurd page number is clamped');
$_GET = [];

// --- Enum coercion never lets a free-text status through ---------------------
assert_null(Enums::coerce('active; DROP TABLE contracts', Enums::CONTRACT_STATUSES), 'a hostile status is rejected');
assert_null(Enums::coerce('', Enums::CONTRACT_STATUSES), 'an empty status is rejected');
assert_null(Enums::coerce(['active'], Enums::CONTRACT_STATUSES), 'an array status is rejected');
assert_same('active', Enums::coerce('ACTIVE', Enums::CONTRACT_STATUSES), 'a valid status is accepted case-insensitively');
assert_same('under_review', Enums::coerce('under-review', Enums::CONTRACT_STATUSES), 'hyphens are normalised to underscores');

// --- Validation refuses malformed input rather than storing it --------------
$v = new Validator([
    'title'          => str_repeat('x', 5000),
    'effective_date' => '2026-02-30',
    'expiry_date'    => 'not-a-date',
    'total_value'    => '12,34,567.89xyz',
    'currency'       => 'rupee',
    'notice_period_days' => '999999',
]);
$v->requiredString('title', 255);
$v->optionalDate('effective_date');
$v->optionalDate('expiry_date');
$v->optionalDecimal('total_value');
$v->optionalCurrency('currency');
$v->optionalInt('notice_period_days', 0, 3650);

$errors = $v->errors();
assert_true(isset($errors['title']), 'an over-long title is rejected');
assert_true(isset($errors['effective_date']), '30 February is rejected as a date');
assert_true(isset($errors['expiry_date']), 'a non-date is rejected');
assert_true(isset($errors['total_value']), 'a malformed amount is rejected');
assert_true(isset($errors['currency']), 'a currency that is not a 3-letter code is rejected');

// Lowercase is normalised rather than rejected: people type "inr", and the
// database CHECK wants "INR". Silently correcting the case is kinder than a
// validation error, and the stored value is still the canonical form.
$currency = new Validator(['currency' => 'inr']);
assert_same('INR', $currency->optionalCurrency('currency'), 'a lowercase currency code is normalised to uppercase');
assert_false($currency->failed(), 'normalising the case is not an error');

$badCurrency = new Validator(['currency' => '1$X']);
$badCurrency->optionalCurrency('currency');
assert_true($badCurrency->failed(), 'a non-alphabetic currency code is rejected');
assert_true(isset($errors['notice_period_days']), 'an out-of-range notice period is rejected');

// A well-formed amount keeps its precision, as a string.
$ok = new Validator(['total_value' => '1234567.891']);
assert_same('1234567.89', $ok->optionalDecimal('total_value'), 'an amount is returned as a fixed-scale string');
assert_false($ok->failed(), 'a valid amount produces no error');

// --- Business rules cannot be bypassed via the service ----------------------
$contract = $service->create($ctx, ['title' => 'Rule check', 'status' => 'draft']);
$id       = (int) $contract['id'];

assert_throws(
    static fn () => $service->changeStatus($ctx, $id, 'terminated'),
    'a draft cannot jump straight to terminated',
    'cannot move from'
);

assert_throws(
    static fn () => $service->create($ctx, [
        'title'          => 'Backwards dates',
        'effective_date' => '2027-01-01',
        'expiry_date'    => '2026-01-01',
    ]),
    'a contract cannot expire before it starts',
    'cannot be before'
);

// --- CSV export cannot smuggle a formula ------------------------------------
// A cell starting =, +, - or @ executes when the file is opened in a
// spreadsheet. This is how an export becomes remote code execution on a
// finance user's machine.
$csv = ContractController::toCsv(
    ['Title', 'Counterparty'],
    [
        ['=cmd|\' /C calc\'!A0', '+1234'],
        ['-2+3', '@SUM(1:9)'],
        ['Normal "quoted" value', 'Acme, Inc.'],
    ]
);

assert_contains('"\'=cmd', $csv, 'a leading = is neutralised with an apostrophe');
assert_contains('"\'+1234"', $csv, 'a leading + is neutralised');
assert_contains('"\'-2+3"', $csv, 'a leading - is neutralised');
assert_contains('"\'@SUM(1:9)"', $csv, 'a leading @ is neutralised');
assert_contains('"Normal ""quoted"" value"', $csv, 'embedded quotes are doubled');
assert_contains('"Acme, Inc."', $csv, 'an embedded comma stays inside the quoted field');

// --- Outbound requests cannot be pointed at the internal network -------------
// The only caller-influenced outbound URLs are provider base URLs from Console,
// but an SSRF through a config value is as good as one through a form field.
// The flag is set explicitly rather than inherited from whatever .env this
// machine happens to have — a security assertion that passes because of local
// configuration is not an assertion.
$withLoopback = static function (bool $allowed, callable $fn): void {
    $previous = getenv('ALLOW_LOOPBACK_INTEGRATIONS');
    $value    = $allowed ? 'true' : 'false';
    putenv('ALLOW_LOOPBACK_INTEGRATIONS=' . $value);
    $_ENV['ALLOW_LOOPBACK_INTEGRATIONS'] = $value;
    try {
        $fn();
    } finally {
        if ($previous === false) {
            putenv('ALLOW_LOOPBACK_INTEGRATIONS');
            unset($_ENV['ALLOW_LOOPBACK_INTEGRATIONS']);
        } else {
            putenv('ALLOW_LOOPBACK_INTEGRATIONS=' . $previous);
            $_ENV['ALLOW_LOOPBACK_INTEGRATIONS'] = $previous;
        }
    }
};

$withLoopback(false, static function (): void {
    assert_false(Http::isSafeUrl('http://127.0.0.1:5432/'), 'loopback is refused when the flag is off');
    assert_false(Http::isSafeUrl('http://localhost:8000/'), 'the localhost name is refused when the flag is off');
});

$withLoopback(true, static function (): void {
    assert_true(Http::isSafeUrl('http://127.0.0.1:8000/'), 'loopback is allowed for local development when the flag is on');

    // The escape hatch covers loopback and nothing else. The metadata endpoint
    // must stay refused even in development, because a developer's laptop is
    // not where this flag causes damage — a production host with a stale .env is.
    assert_false(
        Http::isSafeUrl('http://169.254.169.254/latest/meta-data/'),
        'the cloud metadata endpoint stays refused even with loopback allowed'
    );
    assert_false(Http::isSafeUrl('http://10.0.0.5/internal'), 'RFC1918 space stays refused with loopback allowed');
});

assert_false(Http::isSafeUrl('http://169.254.169.254/latest/meta-data/'), 'the cloud metadata endpoint is refused');
assert_false(Http::isSafeUrl('http://[fd00::1]/'), 'a unique-local IPv6 address is refused');
assert_false(Http::isSafeUrl('http://192.168.1.1/'), 'a private LAN address is refused');
assert_false(Http::isSafeUrl('file:///etc/passwd'), 'a non-HTTP scheme is refused');
assert_false(Http::isSafeUrl('gopher://example.com/'), 'gopher is refused');
assert_false(Http::isSafeUrl('not a url'), 'a malformed URL is refused');
assert_false(Http::isSafeUrl('https://this-host-does-not-resolve.invalid/x'), 'a host that resolves to nothing is refused');
assert_true(Http::isSafeUrl('https://drive.aicountly.com/api/documents'), 'a real integration host is allowed');

// --- Date arithmetic is not a place to be clever ----------------------------
// A notice deadline computed wrongly is a missed cancellation window, so the
// month-end cases are pinned.
assert_same('2026-02-28', Dates::addMonths('2026-01-31', 1), 'a month-end date does not spill into March');
assert_same('2028-02-29', Dates::addMonths('2028-01-31', 1), 'a leap year is handled');
assert_same('2026-12-30', Dates::addMonths('2026-11-30', 1), 'the day of month is preserved when the target month is long enough');
assert_same('2026-04-30', Dates::addMonths('2026-03-31', 1), '31 March plus a month lands on 30 April, not 1 May');
assert_same('2027-02-28', Dates::addMonths('2026-08-31', 6), 'a six-month step from a 31st lands on the last day of February');
assert_same('2027-01-01', Dates::addDays('2026-12-31', 1), 'adding a day crosses the year boundary');
assert_same('2026-10-02', Dates::noticeDeadline('2026-12-31', 90), 'a 90-day notice deadline is 90 days before expiry');
assert_null(Dates::noticeDeadline('2026-12-31', 0), 'a zero notice period has no deadline');
assert_null(Dates::noticeDeadline(null, 90), 'no expiry means no notice deadline');

t_done('SecurityTest');
