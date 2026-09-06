<?php

declare(strict_types=1);

/**
 * Signature requests, and the delivery path that has no session behind it.
 *
 * The assertion this file exists for is the last one: a provider retry must be
 * a no-op. Every e-signature vendor redelivers, none of them guarantees
 * exactly-once, and a second application of "signed" is how one signature
 * becomes two state transitions. Proving idempotence by replaying an event and
 * seeing the same state is not proof — the apply is idempotent by accident. So
 * the state is *moved back* between the two deliveries: if the duplicate were
 * applied, it would move it forward again, and it does not.
 *
 * The rest holds the two boundaries in place. Execution goes through
 * ContractService::changeStatus, which is checked by looking for the things
 * only that path produces — a renewal cycle and a status activity row — rather
 * than by looking at the status column, which a raw UPDATE would also set. And
 * the shipped ManualProvider refuses every webhook, because a provider that
 * sends nothing has no callbacks and accepting one would put an anonymous POST
 * in charge of executing contracts.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Env;
use App\Services\SignatureService;
use App\Signature\ManualProvider;
use App\Signature\SignatureProvider;
use App\Signature\SignatureProviderFactory;
use App\Support\Dates;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

Env::configureForTests(['SIGNATURE_WEBHOOK_SECRET' => 'test-webhook-secret']);

/**
 * A vendor that signs its deliveries, so the webhook path can be exercised.
 *
 * The shipped provider cannot stand in here: ManualProvider::verifyWebhook
 * returns false by design, which is itself asserted below.
 */
final class HmacTestProvider implements SignatureProvider
{
    public const REFERENCE = 'VENDOR-ENV-77';

    public int $cancelCalls = 0;

    public function name(): string
    {
        return 'testvendor';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(array $request, array $signers): array
    {
        return [
            'provider_reference' => self::REFERENCE,
            'status'             => 'sent',
            'delivered'          => true,
            'detail'             => 'Envelope delivered to ' . count($signers) . ' signatory(ies).',
        ];
    }

    public function cancel(string $providerRef): bool
    {
        $this->cancelCalls++;

        return true;
    }

    public function verifyWebhook(string $rawBody, array $headers): bool
    {
        $expected = hash_hmac('sha256', $rawBody, Env::get('SIGNATURE_WEBHOOK_SECRET'));

        return hash_equals($expected, (string) ($headers['x-signature'] ?? ''));
    }

    public function parseWebhook(array $payload): array
    {
        return [
            'event_id'           => (string) ($payload['event_id'] ?? ''),
            'event_type'         => (string) ($payload['event_type'] ?? 'unknown'),
            'provider_reference' => isset($payload['reference']) ? (string) $payload['reference'] : null,
            'signer_reference'   => null,
            'signer_email'       => isset($payload['signer_email']) ? (string) $payload['signer_email'] : null,
            'occurred_at'        => isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : null,
            'decline_reason'     => isset($payload['reason']) ? (string) $payload['reason'] : null,
        ];
    }
}

/** Sign a payload the way the fake vendor does, and hand back body plus headers. */
$deliver = static function (array $payload): array {
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);

    return [$body, ['x-signature' => hash_hmac('sha256', $body, 'test-webhook-secret')]];
};

/** Insert a contract directly: this suite is not testing contract creation. */
$makeContract = static function (int $cmpId = 1, string $status = 'draft') use ($pdo): int {
    static $seq = 0;
    $seq++;

    $expiry = sprintf('%d-12-31', (int) date('Y') + 3);

    $st = $pdo->prepare(
        'INSERT INTO contracts
         (environment, cmp_id, contract_number, title, status, lifecycle_stage,
          effective_date, expiry_date, notice_period_days, notice_deadline,
          renewal_frequency, owner_uuid, created_by, counterparty_name)
         VALUES (:env, :cmp, :num, :title, :status, :stage, :eff, :exp, 60, :deadline,
                 \'annual\', :owner, :owner2, \'Northwind Supplies\')
         RETURNING id'
    );
    $st->execute([
        'env'      => 'sandbox',
        'cmp'      => $cmpId,
        'num'      => sprintf('SIG-%04d-%03d', $cmpId, $seq),
        'title'    => 'Signature fixture ' . $seq,
        'status'   => $status,
        'stage'    => $status === 'draft' ? 'draft' : 'signature',
        'eff'      => date('Y-m-d'),
        'exp'      => $expiry,
        'deadline' => Dates::noticeDeadline($expiry, 60),
        'owner'    => 'USER-A',
        'owner2'   => 'USER-A',
    ]);

    return (int) $st->fetchColumn();
};

$ctx  = t_context(1, 'USER-A');
$ctx2 = t_context(2, 'USER-B');

// ---------------------------------------------------------------------------
// The provider layer
// ---------------------------------------------------------------------------

assert_same('manual', SignatureProviderFactory::default()->name(), 'with no SIGNATURE_PROVIDER set the default is manual');
assert_true(SignatureProviderFactory::default()->isConfigured(), 'the manual provider needs no configuration');
assert_false(
    (new ManualProvider())->verifyWebhook('{}', ['x-signature' => 'anything']),
    'the manual provider refuses every webhook: it has no vendor and therefore no callbacks'
);
assert_false(
    (bool) SignatureProviderFactory::status()['delivers'],
    'the manual provider reports honestly that it delivers nothing'
);

Env::configureForTests(['SIGNATURE_PROVIDER' => 'docusign']);
assert_throws(
    static fn () => SignatureProviderFactory::default(),
    'a SIGNATURE_PROVIDER this build has no client for is refused rather than downgraded to manual',
    'docusign'
);
Env::configureForTests(['SIGNATURE_PROVIDER' => '']);

// ---------------------------------------------------------------------------
// The manual path, end to end
// ---------------------------------------------------------------------------

$manual     = new SignatureService($pdo);
$contractId = $makeContract();

$request = $manual->create($ctx, $contractId, [
    'subject' => 'Please sign the supply agreement',
    'signers' => [
        ['name' => 'Priya Raman', 'email' => 'priya@example.com', 'side' => 'company', 'signer_order' => 1],
        ['name' => 'Sam Okafor', 'email' => 'sam@northwind.example', 'side' => 'counterparty', 'signer_order' => 2],
    ],
]);

assert_same('draft', $request['status'], 'a new signature request starts as a draft');
assert_count(2, $request['signers'], 'both signatories are stored');
assert_same(2, $request['outstanding'], 'both signatories are outstanding before anything is sent');
assert_same('Priya Raman', $request['signers'][0]['name'], 'signatories come back in signing order');

assert_throws(
    static fn () => $manual->create($ctx, $contractId, [
        'signers' => [['name' => 'Someone Else']],
    ]),
    'a second envelope cannot be opened while one is still in progress',
    'already has a signature request in progress'
);

assert_throws(
    static fn () => $manual->create($ctx, $contractId, ['signers' => []]),
    'a signature request with no signatories is refused'
);

$sent = $manual->send($ctx, (int) $request['id']);
assert_same('sent', $sent['status'], 'sending moves the request to sent');
assert_false($sent['delivery']['delivered'], 'the manual provider reports that nothing was actually delivered');
assert_contains('nothing was emailed', $sent['delivery']['detail'], 'and says so in words the screen can show');
assert_same('sent', $sent['signers'][0]['status'], 'a sequential envelope notifies the first signatory');
assert_same('pending', $sent['signers'][1]['status'], 'and leaves the second waiting their turn');

$contract = $pdo->query("SELECT status, signing_status, lifecycle_stage FROM contracts WHERE id = {$contractId}")->fetch();
assert_same('sent', $contract['signing_status'], 'the contract records that it is out for signature');
assert_same('awaiting_signature', $contract['status'], 'and moves to awaiting_signature');
assert_same('signature', $contract['lifecycle_stage'], 'through changeStatus, which is what sets the lifecycle stage');

assert_throws(
    static fn () => $manual->send($ctx, (int) $request['id']),
    'a request that has already been sent cannot be sent again',
    'Only a draft'
);

// --- execution ---------------------------------------------------------------
$executedOn = Dates::addDays(Dates::today(), -1) ?? Dates::today();

$signed = $manual->markSigned($ctx, (int) $request['id'], [
    'execution_date'     => $executedOn,
    'evidence_reference' => 'Wet ink, scanned 3 pages',
    'signers'            => [
        ['id' => $request['signers'][0]['id']],
        ['id' => $request['signers'][1]['id']],
        ['name' => 'Ana Silva', 'designation' => 'Witness', 'side' => 'witness'],
    ],
]);

assert_same('completed', $signed['status'], 'recording the signed copy completes the request');
assert_same(0, $signed['outstanding'], 'nobody is left outstanding');
assert_count(3, $signed['signers'], 'a signatory discovered only on the executed copy is added to the record');
assert_true($signed['execution']['contract_activated'], 'the contract was moved to active');

$contract = $pdo->query("SELECT status, signing_status, execution_date, lifecycle_stage FROM contracts WHERE id = {$contractId}")->fetch();
assert_same('active', $contract['status'], 'the executed contract is active');
assert_same('completed', $contract['signing_status'], 'and its signing status is complete');
assert_same($executedOn, $contract['execution_date'], 'the execution date is the date on the signed copy');

// Going active through ContractService::changeStatus is what opens the renewal
// cycle. A raw UPDATE on contracts.status would have left this empty, which is
// why it is asserted here rather than the status column alone.
$cycles = (int) $pdo->query("SELECT COUNT(*) FROM contract_renewals WHERE contract_id = {$contractId}")->fetchColumn();
assert_same(1, $cycles, 'activation ran through changeStatus, so a renewal cycle was opened');

$activity = (int) $pdo->query(
    "SELECT COUNT(*) FROM contract_activity_logs WHERE contract_id = {$contractId} AND event_type = 'contract.status.active'"
)->fetchColumn();
assert_same(1, $activity, 'and the standard status activity row was written');

assert_throws(
    static fn () => $manual->cancel($ctx, (int) $request['id'], 'changed our minds'),
    'a completed signature request cannot be cancelled',
    'cannot be cancelled'
);
assert_throws(
    static fn () => $manual->markSigned($ctx, (int) $request['id'], ['execution_date' => $executedOn]),
    'a completed signature request cannot be executed twice',
    'already completed'
);

// --- input the record must refuse ------------------------------------------
$secondId  = $makeContract();
$secondReq = $manual->create($ctx, $secondId, ['signers' => [['name' => 'Lee Chan']]]);

assert_throws(
    static fn () => $manual->markSigned($ctx, (int) $secondReq['id'], [
        'execution_date' => Dates::addDays(Dates::today(), 30),
    ]),
    'a contract cannot be executed on a future date',
    'future date'
);

$cancelled = $manual->cancel($ctx, (int) $secondReq['id'], 'Counterparty withdrew');
assert_same('cancelled', $cancelled['status'], 'an unsent request can be cancelled');
assert_same('expired', $cancelled['signers'][0]['status'], 'and its outstanding signatories stop waiting');
assert_same(
    'cancelled',
    $pdo->query("SELECT signing_status FROM contracts WHERE id = {$secondId}")->fetchColumn(),
    'the contract no longer shows as out for signature'
);

// --- another company's request is not found, not forbidden -------------------
assert_throws(
    static fn () => $manual->findOrFail($ctx2, (int) $request['id']),
    'company 2 cannot reach company 1 signature request',
    'not found'
);
assert_count(0, $manual->listForContract($ctx2, $contractId), 'nor list it against the contract id');

// ---------------------------------------------------------------------------
// Provider callbacks
// ---------------------------------------------------------------------------

$vendor  = new HmacTestProvider();
$service = new SignatureService($pdo, $vendor);

$webhookContractId = $makeContract();
$webhookRequest    = $service->create($ctx, $webhookContractId, [
    'signers' => [
        ['name' => 'Priya Raman', 'email' => 'priya@example.com', 'side' => 'company', 'signer_order' => 1],
        ['name' => 'Sam Okafor', 'email' => 'sam@northwind.example', 'side' => 'counterparty', 'signer_order' => 2],
    ],
]);
$service->send($ctx, (int) $webhookRequest['id']);

// --- an unsigned delivery is refused and stores nothing ----------------------
[$body, $headers] = $deliver(['event_id' => 'evt-forged', 'event_type' => 'signed', 'reference' => HmacTestProvider::REFERENCE]);

assert_throws(
    static fn () => $service->recordWebhook('testvendor', $body, ['x-signature' => 'not-the-hmac']),
    'a delivery whose signature does not verify is refused',
    'did not verify'
);
assert_same(
    0,
    (int) $pdo->query("SELECT COUNT(*) FROM signature_webhook_events WHERE event_id = 'evt-forged'")->fetchColumn(),
    'and is not stored: an unverified body is not evidence of anything'
);

// --- a reference we do not recognise is stored, not applied ------------------
[$body, $headers] = $deliver(['event_id' => 'evt-stray', 'event_type' => 'signed', 'reference' => 'NOT-OURS']);
$stray = $service->recordWebhook('testvendor', $body, $headers);

assert_true($stray['stored'], 'a verified delivery for an unknown envelope is still stored');
assert_false($stray['applied'], 'but nothing is applied');
assert_not_null(
    $pdo->query("SELECT error_message FROM signature_webhook_events WHERE event_id = 'evt-stray'")->fetchColumn(),
    'and the reason it could not be applied is on the row'
);

// --- a real event is applied -------------------------------------------------
[$body, $headers] = $deliver([
    'event_id'     => 'evt-100',
    'event_type'   => 'signed',
    'reference'    => HmacTestProvider::REFERENCE,
    'signer_email' => 'priya@example.com',
]);

$first = $service->recordWebhook('testvendor', $body, $headers);
assert_false($first['duplicate'], 'the first delivery of an event is not a duplicate');
assert_true($first['applied'], 'and it is applied');
assert_same('partially_signed', $first['status'], 'one of two signatories signing leaves the envelope partially signed');

$signers = $pdo->query(
    "SELECT status FROM signature_signers WHERE request_id = " . (int) $webhookRequest['id'] . ' ORDER BY signer_order'
)->fetchAll();
assert_same('signed', $signers[0]['status'], 'the signatory named in the event is recorded as signed');
assert_same('sent', $signers[1]['status'], 'and a sequential envelope moves on to the next one');

// --- the same event again must change nothing --------------------------------
//
// The state is deliberately rolled back first. Replaying against the state the
// event already produced would pass even if the duplicate were fully applied,
// which is exactly the bug this is here to catch.
$pdo->exec("UPDATE signature_requests SET status = 'sent' WHERE id = " . (int) $webhookRequest['id']);
$pdo->exec("UPDATE signature_signers SET status = 'pending', signed_at = NULL
            WHERE request_id = " . (int) $webhookRequest['id'] . ' AND signer_order = 1');

$replay = $service->recordWebhook('testvendor', $body, $headers);

assert_true($replay['duplicate'], 'a redelivered event is recognised as one we already hold');
assert_false($replay['applied'], 'and it is not applied a second time');
assert_same(
    'pending',
    $pdo->query("SELECT status FROM signature_signers WHERE request_id = " . (int) $webhookRequest['id'] . ' AND signer_order = 1')->fetchColumn(),
    'the duplicate did not re-sign the signatory'
);
assert_same(
    'sent',
    $pdo->query("SELECT status FROM signature_requests WHERE id = " . (int) $webhookRequest['id'])->fetchColumn(),
    'nor move the envelope forward again'
);
assert_same(
    1,
    (int) $pdo->query("SELECT COUNT(*) FROM signature_webhook_events WHERE provider = 'testvendor' AND event_id = 'evt-100'")->fetchColumn(),
    'and the delivery is held exactly once, keyed by (provider, event_id)'
);

// --- completion is recorded, but does not execute the contract ---------------
[$body, $headers] = $deliver([
    'event_id'   => 'evt-101',
    'event_type' => 'completed',
    'reference'  => HmacTestProvider::REFERENCE,
]);
$completed = $service->recordWebhook('testvendor', $body, $headers);

assert_true($completed['applied'], 'a completion event is applied');
assert_same('completed', $completed['status'], 'and completes the envelope');

$webhookContract = $pdo->query(
    "SELECT status, signing_status, execution_date FROM contracts WHERE id = {$webhookContractId}"
)->fetch();
assert_same('signed', $webhookContract['signing_status'], 'the contract records that the envelope came back signed');
assert_same('awaiting_signature', $webhookContract['status'], 'but an unauthenticated callback does not make a contract active');
assert_null($webhookContract['execution_date'], 'nor date the execution: a person confirms that against the signed copy');

// --- an unknown provider in the URL is a 404, not a 500 ----------------------
assert_throws(
    static fn () => (new SignatureService($pdo))->recordWebhook('nosuchvendor', '{}', []),
    'a webhook addressed to a provider we do not implement is not found'
);

t_done('SignatureServiceTest');
