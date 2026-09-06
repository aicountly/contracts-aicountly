<?php

declare(strict_types=1);

/**
 * Parties, and the evidence of who they were.
 *
 * The claim this suite exists to hold still is the one the product is sold on:
 * renaming a company in Contacts must not restate who signed an agreement. So
 * a contact is snapshotted, the master is then changed underneath, and the
 * existing snapshot must read exactly as it did — while a fresh capture reads
 * the new name, as a *second* row rather than an edit to the first.
 *
 * Contacts is faked through App\Core\Http::setTransportForTests, which also
 * lets the outbound request be inspected: the caller's own ses_key and company
 * headers reaching Contacts is the part nothing else would notice going
 * missing.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Env;
use App\Core\Http;
use App\Modules\Contacts\ContactsClient;
use App\Services\PartyService;
use App\Support\DomainException;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

Env::configureForTests(['CONTACTS_API_BASE' => 'https://contacts.example.com']);

$ctx1 = t_context(1, 'USER-A');
$ctx2 = t_context(2, 'USER-B');

$parties = new PartyService($pdo);

// ---------------------------------------------------------------------------
// The Contacts master, standing in for the live record
// ---------------------------------------------------------------------------

/**
 * The contact Contacts currently holds, keyed by id.
 *
 * Mutable on purpose: the whole suite turns on editing this between captures.
 *
 * @var array<string,array<string,mixed>> $master
 */
$master = [
    'contact-acme' => [
        'id'               => 'contact-acme',
        'displayName'      => 'Priya Nair',
        'contactKind'      => 'organization',
        'organizationName' => 'Acme Industries Private Limited',
        'companyName'      => 'Acme',
        'emails'           => [['value' => 'legal@acme.example']],
        'phones'           => [['value' => '+91 22 5555 0100']],
        'addresses'        => [['value' => '14 Marine Drive, Mumbai 400020']],
        'integrationMeta'  => ['gstin' => '27AAACA1111A1Z5', 'pan' => 'AAACA1111A', 'designation' => 'General Counsel'],
    ],
];

/** @var list<array<string,mixed>> $sent */
$sent = [];

/** Contacts answers from $master; anything else is a 404. */
$serveContacts = static function () use (&$master, &$sent): void {
    Http::setTransportForTests(static function (
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connectTimeout
    ) use (&$master, &$sent): array {
        $sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers];

        $path  = (string) parse_url($url, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if (preg_match('#^/api/contacts/([^/]+)$#', $path, $m)) {
            $id = rawurldecode($m[1]);

            return isset($master[$id])
                ? ['status' => 200, 'body' => json_encode(['status' => 1, 'data' => $master[$id]]), 'content_type' => 'application/json', 'error' => '']
                : ['status' => 404, 'body' => json_encode(['message' => 'Not found']), 'content_type' => 'application/json', 'error' => ''];
        }

        if ($path === '/api/contacts') {
            $needle = mb_strtolower((string) ($query['q'] ?? ''));
            $hits   = array_values(array_filter(
                $master,
                static fn (array $row): bool => str_contains(mb_strtolower($row['displayName'] . ' ' . $row['organizationName']), $needle)
            ));

            return [
                'status'       => 200,
                'body'         => json_encode(['status' => 1, 'data' => $hits, 'meta' => ['total' => count($hits)]]),
                'content_type' => 'application/json',
                'error'        => '',
            ];
        }

        return ['status' => 404, 'body' => '', 'content_type' => '', 'error' => ''];
    });
};

$serveContacts();

/** A contract inserted directly, so this suite does not depend on services other agents own. */
function p_contract(PDO $pdo, int $cmpId, string $number, string $title = 'Master services agreement'): int
{
    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, currency, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $number, $title, 'draft', 'draft', 'INR', 'USER-A']);

    return (int) $st->fetchColumn();
}

function p_counterparty_name(PDO $pdo, int $contractId): ?string
{
    $st = $pdo->prepare('SELECT counterparty_name FROM contracts WHERE id = ?');
    $st->execute([$contractId]);
    $value = $st->fetchColumn();

    return $value === false || $value === null ? null : (string) $value;
}

// ---------------------------------------------------------------------------
// The Contacts client itself
// ---------------------------------------------------------------------------

$normalised = ContactsClient::normalise($master['contact-acme']);
assert_same('Acme Industries Private Limited', $normalised['legal_name'], 'the organisation, not the person, is the legal name');
assert_same('Priya Nair', $normalised['display_name'], 'the display name is who Contacts shows');
assert_same('Acme', $normalised['trading_name'], 'the company tag becomes a trading name when it differs');
assert_same('legal@acme.example', $normalised['email'], 'the first email is the contact email');
assert_same('27AAACA1111A1Z5', $normalised['gstin'], 'a GSTIN carried in integrationMeta is read');
assert_count(1, $normalised['contact_persons'], 'a named person at an organisation becomes a contact person');
assert_same('General Counsel', $normalised['contact_persons'][0]['designation'], 'the designation comes from integrationMeta');

$sent    = [];
$results = ContactsClient::search($ctx1, 'acme', 10);
assert_count(1, $results, 'search finds the contact through Contacts');
assert_same('contact-acme', $results[0]['id'], 'search returns the normalised shape');
assert_contains('https://contacts.example.com/api/contacts?', $sent[0]['url'], 'search calls Contacts under its /api prefix');
assert_contains('Authorization: Bearer test-ses-key', implode("\n", $sent[0]['headers']), "the caller's own ses_key is relayed");
assert_contains('X-AIC-CMP-ID: 1', implode("\n", $sent[0]['headers']), 'the company context header is relayed');

assert_null(ContactsClient::find($ctx1, 'contact-nobody'), 'a contact Contacts does not have reads as null');

// Contacts down: the lookup that decorates a form must not fail the form.
Http::setTransportForTests(static fn (): array => ['status' => 0, 'body' => '', 'content_type' => '', 'error' => 'connection refused']);
assert_same([], ContactsClient::search($ctx1, 'acme', 10), 'search degrades to an empty list when Contacts is unreachable');
assert_throws(
    static fn () => ContactsClient::find($ctx1, 'contact-acme'),
    'find refuses rather than pretending the contact has no details',
    'Contacts'
);
$serveContacts();

// ---------------------------------------------------------------------------
// Adding a party
// ---------------------------------------------------------------------------

$contract = p_contract($pdo, 1, 'CON-2026-000001');

$company = $parties->add($ctx1, $contract, [
    'party_role'     => 'company',
    'display_name'   => 'Test Company 1',
    'signatory_name' => 'R. Mehta',
]);
assert_same('company', $company['party_role'], 'the company is a party in its own right');
assert_false($company['is_primary'], 'a party is not primary unless it is asked to be');

$acme = $parties->add($ctx1, $contract, [
    'party_role'      => 'counterparty',
    'display_name'    => 'Acme Industries Private Limited',
    'contact_ref_id'  => 'contact-acme',
    'signatory_name'  => 'Priya Nair',
    'signatory_email' => 'priya@acme.example',
]);
assert_same('contact-acme', $acme['contact_ref_id'], 'the party keeps only a reference into Contacts');
assert_same('contact', $acme['contact_ref_type'], 'the reference type defaults rather than being repeated by every caller');

assert_count(2, $parties->listForContract($ctx1, $contract), 'both parties are listed');
assert_same('company', $parties->listForContract($ctx1, $contract)[0]['party_role'], 'the company reads first, as in a signature block');

assert_throws(
    static fn () => $parties->add($ctx1, $contract, ['party_role' => 'counterparty']),
    'a party without a name is refused',
    'This field is required.'
);
assert_throws(
    static fn () => $parties->add($ctx1, $contract, ['party_role' => 'financier', 'display_name' => 'Someone']),
    'a role outside the vocabulary is refused',
    'Choose one of'
);

// ---------------------------------------------------------------------------
// Primary counterparty, denormalised onto the contract
// ---------------------------------------------------------------------------

assert_null(p_counterparty_name($pdo, $contract), 'a contract names no counterparty until one is chosen');

$primary = $parties->setPrimaryCounterparty($ctx1, $contract, (int) $acme['id']);
assert_true($primary['is_primary'], 'the chosen party is primary');
assert_same(
    'Acme Industries Private Limited',
    p_counterparty_name($pdo, $contract),
    'the name is denormalised so the repository list needs no join'
);

assert_throws(
    static fn () => $parties->setPrimaryCounterparty($ctx1, $contract, (int) $company['id']),
    'the company cannot be its own counterparty',
    'not its counterparty'
);
assert_throws(
    static fn () => $parties->update($ctx1, (int) $acme['id'], ['party_role' => 'company']),
    'nor can the primary counterparty be turned into the company behind the list\'s back',
    'primary counterparty'
);

// Only one party may claim it, and the contract follows whoever holds it.
$second = $parties->add($ctx1, $contract, [
    'party_role'   => 'guarantor',
    'display_name' => 'Northwind Holdings',
    'is_primary'   => true,
]);
assert_true($second['is_primary'], 'a party added as primary is primary');
assert_false($parties->find($ctx1, (int) $acme['id'])['is_primary'], 'the previous primary stands down');
assert_same('Northwind Holdings', p_counterparty_name($pdo, $contract), 'the denormalised name follows the primary party');

$parties->update($ctx1, (int) $second['id'], ['display_name' => 'Northwind Holdings LLP']);
assert_same('Northwind Holdings LLP', p_counterparty_name($pdo, $contract), 'renaming the primary party updates the contract');

$parties->remove($ctx1, (int) $second['id']);
assert_null(p_counterparty_name($pdo, $contract), 'removing the primary party leaves no counterparty named');

$parties->setPrimaryCounterparty($ctx1, $contract, (int) $acme['id']);

// ---------------------------------------------------------------------------
// A snapshot captures the contact as it was
// ---------------------------------------------------------------------------

$snapshot = $parties->captureSnapshot($ctx1, (int) $acme['id'], 'execution');
assert_same('Acme Industries Private Limited', $snapshot['legal_name'], 'the snapshot records the legal name Contacts held');
assert_same('14 Marine Drive, Mumbai 400020', $snapshot['registered_address'], 'the registered address is captured');
assert_same('27AAACA1111A1Z5', $snapshot['gstin'], 'the statutory identifier is captured');
assert_same('Priya Nair', $snapshot['authorised_representative'], 'who signed comes from the party row, not the master');
assert_same('execution', $snapshot['captured_reason'], 'the reason is recorded');
assert_same('contacts', $snapshot['raw_payload']['source'], 'the snapshot records that the master confirmed it');

// --- and a later change in Contacts does not reach back into it -------------
$master['contact-acme']['organizationName'] = 'Zenith Industries Limited';
$master['contact-acme']['addresses']        = [['value' => '1 New Road, Pune 411001']];

$unchanged = $parties->latestSnapshot($ctx1, (int) $acme['id']);
assert_same(
    'Acme Industries Private Limited',
    $unchanged['legal_name'],
    'renaming the company in Contacts does not restate who signed'
);
assert_same('14 Marine Drive, Mumbai 400020', $unchanged['registered_address'], 'nor where they were when they signed');

// The live party still points at the master, so the screen shows the new name.
assert_same(
    'Zenith Industries Limited',
    ContactsClient::find($ctx1, 'contact-acme')['legal_name'],
    'the master itself has moved on'
);

// ---------------------------------------------------------------------------
// Snapshots are append-only: a correction is a new row
// ---------------------------------------------------------------------------

$correction = $parties->captureSnapshot($ctx1, (int) $acme['id'], 'correction');
assert_same('Zenith Industries Limited', $correction['legal_name'], 'a fresh capture reads the master as it is now');

$history = $parties->snapshots($ctx1, (int) $acme['id']);
assert_count(2, $history, 'the correction is a second row, not an edit of the first');
assert_same('correction', $history[0]['captured_reason'], 'the newest snapshot reads first');
assert_same('Acme Industries Private Limited', $history[1]['legal_name'], 'the original reading is still there to be read');
assert_true($history[0]['id'] > $history[1]['id'], 'the later capture is the later row');

// Nothing in this service can restate one — no update path, no delete path.
$snapshotWriters = array_values(array_filter(
    array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(PartyService::class))->getMethods(ReflectionMethod::IS_PUBLIC)
    ),
    static fn (string $name): bool => preg_match('/^(update|delete|remove|edit|revise|correct).*snapshot/i', $name) === 1
));
assert_same([], $snapshotWriters, 'the service offers no way to change a snapshot');

// A party carrying evidence cannot be deleted out from under it either — the
// foreign key cascades, so a delete would take the snapshots with it.
assert_throws(
    static fn () => $parties->remove($ctx1, (int) $acme['id']),
    'a snapshotted party cannot be removed',
    'cannot be removed'
);

// Contacts being unreachable refuses the capture rather than writing a blank
// counterparty into the evidence.
Http::setTransportForTests(static fn (): array => ['status' => 503, 'body' => '', 'content_type' => '', 'error' => '']);
assert_throws(
    static fn () => $parties->captureSnapshot($ctx1, (int) $acme['id'], 'verification'),
    'a snapshot is refused rather than fabricated when Contacts is down',
    'Contacts'
);
assert_count(2, $parties->snapshots($ctx1, (int) $acme['id']), 'the refused capture wrote nothing');
$serveContacts();

// ---------------------------------------------------------------------------
// Execution captures every party, the company's own side from Manage
// ---------------------------------------------------------------------------

$executed = p_contract($pdo, 1, 'CON-2026-000002', 'Supply agreement');

$vendor = $parties->add($ctx1, $executed, [
    'party_role'     => 'vendor',
    'display_name'   => 'Riverbend Logistics',
    'signatory_name' => 'S. Iyer',
]);

$captured = $parties->captureAllForExecution($ctx1, $executed);
assert_count(2, $captured, 'execution captures the counterparty and the company that is missing one');

$all = $parties->listForContract($ctx1, $executed);
assert_count(2, $all, 'the company was added as a party in its own right');
assert_same('company', $all[0]['party_role'], 'the company party leads the list');

$companySnapshot = $parties->latestSnapshot($ctx1, (int) $all[0]['id']);
assert_same('Test Company 1', $companySnapshot['legal_name'], "the company's own side is captured from Manage");
assert_same('manage', $companySnapshot['raw_payload']['source'], 'and is recorded as having come from Manage');

// A party nobody linked to Contacts is still evidenced, from what we do hold.
$vendorSnapshot = $parties->latestSnapshot($ctx1, (int) $vendor['id']);
assert_same('Riverbend Logistics', $vendorSnapshot['legal_name'], 'a hand-entered party is captured from its own row');
assert_same('party_row', $vendorSnapshot['raw_payload']['source'], 'and is recorded as never having had a master to read');
assert_same('S. Iyer', $vendorSnapshot['authorised_representative'], 'the signatory is carried into the snapshot');
assert_same('execution', $vendorSnapshot['captured_reason'], 'execution is the reason recorded');

// ---------------------------------------------------------------------------
// Tenant isolation — company 2 must not reach any of this, whatever id it tries
// ---------------------------------------------------------------------------

assert_throws(
    static fn () => $parties->listForContract($ctx2, $contract),
    "another company's contract has no parties to list",
    'not found'
);
assert_null($parties->find($ctx2, (int) $acme['id']), "another company's party does not exist");
assert_throws(
    static fn () => $parties->add($ctx2, $contract, ['party_role' => 'counterparty', 'display_name' => 'Intruder Ltd']),
    "a party cannot be added to another company's contract",
    'not found'
);
assert_throws(
    static fn () => $parties->update($ctx2, (int) $acme['id'], ['display_name' => 'Renamed By Someone Else']),
    "another company's party cannot be renamed",
    'not found'
);
assert_throws(
    static fn () => $parties->remove($ctx2, (int) $acme['id']),
    "another company's party cannot be removed",
    'not found'
);
assert_throws(
    static fn () => $parties->captureSnapshot($ctx2, (int) $acme['id'], 'manual'),
    "another company's party cannot be snapshotted",
    'not found'
);
assert_throws(
    static fn () => $parties->snapshots($ctx2, (int) $acme['id']),
    "another company's snapshots cannot be read",
    'not found'
);
assert_null($parties->latestSnapshot($ctx2, (int) $acme['id']), "another company's latest snapshot is not visible");
assert_throws(
    static fn () => $parties->setPrimaryCounterparty($ctx2, $contract, (int) $acme['id']),
    "another company's counterparty cannot be chosen",
    'not found'
);

// The party rows themselves are untouched by any of that.
assert_same('Acme Industries Private Limited', $parties->find($ctx1, (int) $acme['id'])['display_name'], 'the party survived every attempt');
assert_count(2, $parties->snapshots($ctx1, (int) $acme['id']), 'and so did its evidence');

Http::setTransportForTests(null);

t_done('PartyServiceTest');
