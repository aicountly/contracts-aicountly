<?php

declare(strict_types=1);

/**
 * Notifications: that the same warning is written once however often the sweep
 * runs, that an inbox belongs to exactly one person, and that the email channel
 * tells the truth about whether anything was sent.
 *
 * The dedupe assertions matter more than they look. Every deadline warning in
 * this product comes from cron, cPanel cron is not exactly-once, and a company
 * whose renewal alerts arrive three times is a company that turns them off.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Env;
use App\Services\NotificationService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$service = new NotificationService($pdo);
$env     = 'sandbox';

$alice = t_context(1, 'USER-ALICE');
$bob   = t_context(1, 'USER-BOB');
$other = t_context(2, 'USER-ALICE');

$countAll = static fn (): int => (int) $pdo->query('SELECT COUNT(*) FROM contract_notifications')->fetchColumn();

// A contract to hang notifications off, so the contract_id foreign key and the
// join in listFor() are both exercised.
$pdo->exec(
    "INSERT INTO contracts (environment, cmp_id, contract_number, title, status, owner_uuid, created_by)
     VALUES ('sandbox', 1, 'CON-2026-000001', 'Acme master services agreement', 'active', 'USER-ALICE', 'USER-ALICE')"
);
$contractId = (int) $pdo->query("SELECT id FROM contracts LIMIT 1")->fetchColumn();

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

$id = $service->notify($env, 1, 'USER-ALICE', 'contract.expiring', 'Expires in 30 days — CON-2026-000001',
    'Acme master services agreement expires on 2026-12-31.', [
        'contract_id' => $contractId,
        'link_path'   => '/contracts/' . $contractId,
        'severity'    => 'warning',
        'dedupe_key'  => 'expiry:' . $contractId . ':30',
        'metadata'    => ['threshold' => 30],
    ]);

assert_not_null($id, 'notify() writes a notification and returns its id');

$row = $pdo->query("SELECT * FROM contract_notifications WHERE id = {$id}")->fetch();
assert_same('warning', $row['severity'], 'the severity is stored');
assert_same('/contracts/' . $contractId, $row['link_path'], 'the deep link is stored');
assert_same($contractId, (int) $row['contract_id'], 'the notification points at its contract');
assert_null($row['read_at'], 'a new notification is unread');
assert_contains('"threshold"', (string) $row['metadata'], 'the metadata is stored as JSON');

// ---------------------------------------------------------------------------
// Dedupe — the point of the whole table
// ---------------------------------------------------------------------------

$repeat = $service->notify($env, 1, 'USER-ALICE', 'contract.expiring', 'Expires in 30 days — CON-2026-000001',
    'A second sweep in the same night.', ['dedupe_key' => 'expiry:' . $contractId . ':30']);

assert_null($repeat, 'the same dedupe key for the same recipient is refused');
assert_same(1, $countAll(), 'and writes no second row');

// The same warning genuinely does have to reach each recipient.
$forBob = $service->notify($env, 1, 'USER-BOB', 'contract.expiring', 'Expires in 30 days — CON-2026-000001',
    null, ['dedupe_key' => 'expiry:' . $contractId . ':30']);
assert_not_null($forBob, 'the same dedupe key for a different recipient is a different notification');
assert_same(2, $countAll(), 'each recipient gets their own row');

// A different threshold in the ladder is a different thing to say.
assert_not_null(
    $service->notify($env, 1, 'USER-ALICE', 'contract.expiring', 'Expires in 7 days — CON-2026-000001', null,
        ['dedupe_key' => 'expiry:' . $contractId . ':7']),
    'the next rung of the alert ladder is a new notification'
);

// Without a key, nothing is deduplicated: a comment mention is not a sweep.
$service->notify($env, 1, 'USER-ALICE', 'comment.mention', 'Priya mentioned you', null);
$service->notify($env, 1, 'USER-ALICE', 'comment.mention', 'Priya mentioned you', null);
assert_same(5, $countAll(), 'notifications with no dedupe key are never merged');

// The key is scoped to the company as well as the recipient.
assert_not_null(
    $service->notify($env, 2, 'USER-ALICE', 'contract.expiring', 'A different company', null,
        ['dedupe_key' => 'expiry:' . $contractId . ':30']),
    'the same key under another company is a separate notification'
);

// An unknown severity falls back rather than tripping the CHECK constraint.
$loose = $service->notify($env, 1, 'USER-ALICE', 'system.notice', 'Something happened', null, ['severity' => 'catastrophic']);
assert_not_null($loose, 'an unrecognised severity does not fail the write');
assert_same('info', (string) $pdo->query("SELECT severity FROM contract_notifications WHERE id = {$loose}")->fetchColumn(),
    'an unrecognised severity is stored as info');

// An empty recipient is a caller bug, not a row.
assert_null($service->notify($env, 1, '  ', 'system.notice', 'Nobody', null), 'a blank recipient writes nothing');

// ---------------------------------------------------------------------------
// notifyMany
// ---------------------------------------------------------------------------

$written = $service->notifyMany($env, 1, ['USER-ALICE', 'USER-BOB', 'USER-CARA', 'USER-BOB'],
    'renewal.review_due', 'Renewal decision needed', null, ['dedupe_key' => 'renewal_review:1']);

assert_same(3, $written, 'notifyMany writes one per distinct recipient');
assert_same(0, $service->notifyMany($env, 1, ['USER-ALICE', 'USER-BOB'], 'renewal.review_due',
    'Renewal decision needed', null, ['dedupe_key' => 'renewal_review:1']),
    'running the same sweep again writes nothing');

// ---------------------------------------------------------------------------
// Reading — an inbox belongs to one person
// ---------------------------------------------------------------------------

$aliceInbox = $service->listFor($alice, [], 50, 0);
$bobInbox   = $service->listFor($bob, [], 50, 0);

assert_same(6, $aliceInbox['total'], 'Alice sees only her own notifications');
assert_same(2, $bobInbox['total'], 'Bob sees only his');
// The one row written under company 2 — and nothing of company 1's six, even
// though the recipient uuid is the same person.
$otherInbox = $service->listFor($other, [], 50, 0);
assert_same(1, $otherInbox['total'], 'the same user in another company sees only that company\'s notifications');
assert_same('A different company', $otherInbox['items'][0]['title'], 'and it is the row written for that company');

$firstItem = $aliceInbox['items'][0];
assert_true(array_key_exists('is_read', $firstItem), 'each item reports whether it has been read');
assert_true(is_array($firstItem['metadata']), 'metadata comes back decoded');

$withContract = null;
foreach ($aliceInbox['items'] as $item) {
    if ($item['contract_id'] === $contractId) {
        $withContract = $item;
    }
}
assert_not_null($withContract, 'a notification about a contract is returned with it');
assert_same('CON-2026-000001', $withContract['contract_number'], 'the contract number is joined in for the list');

assert_same(1, $service->listFor($alice, ['contract_id' => $contractId], 50, 0)['total'],
    'the contract filter narrows the inbox to the one notification carrying that contract');
assert_same(2, $service->listFor($alice, ['event_type' => 'comment.mention'], 50, 0)['total'],
    'the event-type filter narrows to one kind of notification');
assert_same(6, $service->listFor($alice, ['unread_only' => true], 50, 0)['total'],
    'everything is unread to begin with');

$page = $service->listFor($alice, [], 2, 0);
assert_count(2, $page['items'], 'the page size is honoured');
assert_same(6, $page['total'], 'the total counts past the page');

// ---------------------------------------------------------------------------
// Read state
// ---------------------------------------------------------------------------

assert_same(6, $service->unreadCount($alice), 'the badge counts the unread');
assert_true($service->markRead($alice, (int) $id), 'a recipient may mark their own notification read');
assert_same(5, $service->unreadCount($alice), 'the badge drops by one');

// The same call from someone else must not work, and must not say why.
assert_false($service->markRead($bob, (int) $id), 'another user cannot mark it read');
assert_false($service->markRead($other, (int) $id), 'nor can the same user in another company');
assert_false($service->markRead($alice, 999999), 'a missing id answers the same way as a forbidden one');

assert_same(5, $service->markAllRead($alice), 'markAllRead reports how many it changed');
assert_same(0, $service->unreadCount($alice), 'the badge is clear');
assert_same(0, $service->markAllRead($alice), 'a second sweep of the inbox changes nothing');
assert_same(2, $service->unreadCount($bob), 'reading Alice\'s inbox left Bob\'s alone');

assert_true($service->delete($alice, (int) $id), 'a recipient may delete their own notification');
assert_false($service->delete($alice, (int) $id), 'deleting it twice is not a second success');
assert_true($service->delete($bob, (int) $forBob), 'Bob may delete his own copy');

// ---------------------------------------------------------------------------
// Channels — reported honestly, never pretended
// ---------------------------------------------------------------------------

NotificationService::setEmailChannel(null);
Env::configureForTests(['CONTRACTS_EMAIL_ENABLED' => 'false']);

$status = $service->channelStatus();
assert_true($status['in_app']['enabled'], 'in-app notifications always work');
assert_false($status['email']['enabled'], 'email is off by default');
assert_false($status['email']['configured'], 'and reports that no transport is configured');
assert_same('none', $status['email']['transport'], 'the transport is named as none rather than invented');
assert_contains('CONTRACTS_EMAIL_ENABLED', $status['email']['reason'], 'the reason names the setting to change');

$noEmail = $service->notify($env, 1, 'USER-DAVE', 'contract.expiring', 'No transport configured', null,
    ['email' => 'dave@example.com']);
assert_null($pdo->query("SELECT email_sent_at FROM contract_notifications WHERE id = {$noEmail}")->fetchColumn() ?: null,
    'with no transport, nothing claims an email was sent');

// Turning the flag on without a transport must not change that.
Env::configureForTests(['CONTRACTS_EMAIL_ENABLED' => 'true']);
$flagOnly = $service->notify($env, 1, 'USER-DAVE', 'contract.expiring', 'Flag on, still no transport', null,
    ['email' => 'dave@example.com']);
assert_false($service->channelStatus()['email']['enabled'], 'the flag alone does not make email work');
assert_null($pdo->query("SELECT email_sent_at FROM contract_notifications WHERE id = {$flagOnly}")->fetchColumn() ?: null,
    'and still nothing is recorded as sent');

// An anonymous class rather than a named one: the interface lives beside its
// consumer in NotificationService.php, so it is only loadable once that class
// has been reached, and a runtime declaration is what guarantees that ordering.
$transport = new class implements \App\Services\EmailChannel {
    /** @var list<array{to: string, subject: string}> */
    public array $sent = [];

    public bool $accept = true;

    public function send(string $to, string $subject, string $body, array $context = []): bool
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject];

        return $this->accept;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'test-transport';
    }
};

NotificationService::setEmailChannel($transport);

$status = $service->channelStatus();
assert_true($status['email']['enabled'], 'with the flag set and a transport installed, email is on');
assert_same('test-transport', $status['email']['transport'], 'the transport names itself in the health report');
assert_same('', $status['email']['reason'], 'a working channel has nothing to explain');

$mailed = $service->notify($env, 1, 'USER-DAVE', 'contract.expiring', 'Expires in 7 days', 'Body text',
    ['email' => 'dave@example.com', 'severity' => 'critical']);
assert_count(1, $transport->sent, 'the transport is handed the message');
assert_same('dave@example.com', $transport->sent[0]['to'], 'to the address the caller resolved');
assert_not_null($pdo->query("SELECT email_sent_at FROM contract_notifications WHERE id = {$mailed}")->fetchColumn() ?: null,
    'a message the transport accepted is recorded as sent');

// Contracts holds uuids, not addresses. Without one there is nobody to write
// to, and guessing would send contract detail to the wrong person.
$noAddress = $service->notify($env, 1, 'USER-ERIN', 'contract.expiring', 'No address known', null);
assert_count(1, $transport->sent, 'no address means no send attempt');
assert_null($pdo->query("SELECT email_sent_at FROM contract_notifications WHERE id = {$noAddress}")->fetchColumn() ?: null,
    'and nothing is recorded as sent');

// A transport that refuses must not leave a row claiming success.
$transport->accept = false;
$refused = $service->notify($env, 1, 'USER-DAVE', 'contract.expiring', 'Transport refused', null,
    ['email' => 'dave@example.com']);
assert_count(2, $transport->sent, 'the transport was asked');
assert_null($pdo->query("SELECT email_sent_at FROM contract_notifications WHERE id = {$refused}")->fetchColumn() ?: null,
    'a refused send is not recorded as sent');

// A transport that throws must not cost the caller their in-app notification.
NotificationService::setEmailChannel(new class implements \App\Services\EmailChannel {
    public function send(string $to, string $subject, string $body, array $context = []): bool
    {
        throw new RuntimeException('SMTP connection refused');
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'broken-transport';
    }
});

$survived = $service->notify($env, 1, 'USER-DAVE', 'contract.expiring', 'Transport exploded', null,
    ['email' => 'dave@example.com']);
assert_not_null($survived, 'a broken email transport does not lose the in-app notification');
assert_null($pdo->query("SELECT email_sent_at FROM contract_notifications WHERE id = {$survived}")->fetchColumn() ?: null,
    'and does not record a send that did not happen');

NotificationService::setEmailChannel(null);
Env::configureForTests(['CONTRACTS_EMAIL_ENABLED' => 'false']);

t_done('NotificationServiceTest');
