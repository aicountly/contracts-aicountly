<?php

declare(strict_types=1);

/**
 * The Drive integration seen from our side: that the caller's own session and
 * company context reach Drive on every call, that Drive's envelope is unwrapped
 * rather than handed on, and that each way Drive can say no becomes the right
 * kind of failure here.
 *
 * No network. App\Core\Http::setTransportForTests() stands in for cURL, which
 * also lets the request itself be inspected — the headers are the part that
 * matters and the part nothing else would notice going missing.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Env;
use App\Core\Http;
use App\Modules\Drive\DriveClient;
use App\Support\DomainException;

Env::configureForTests([
    'DRIVE_API_BASE'               => 'https://drive.example.com',
    'CONTRACTS_ALLOW_LOCAL_STORAGE' => 'false',
    'CONTRACTS_MAX_UPLOAD_MB'      => '25',
]);

$ctx = t_context();

/** @var list<array<string,mixed>> $sent */
$sent = [];

/**
 * A transport whose reply is chosen per test.
 *
 * @param array{status?: int, body?: mixed, raw?: string} $reply
 */
$respondWith = static function (array $reply) use (&$sent): void {
    Http::setTransportForTests(static function (
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connectTimeout
    ) use (&$sent, $reply): array {
        $sent[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body === null ? null : json_decode($body, true),
            'raw'     => $body,
        ];

        return [
            'status'       => $reply['status'] ?? 200,
            'body'         => $reply['raw'] ?? json_encode($reply['body'] ?? ['success' => true, 'data' => []]),
            'content_type' => 'application/json',
            'error'        => '',
        ];
    });
};

// --- configuration ----------------------------------------------------------
assert_true(DriveClient::isConfigured(), 'Drive is configured when DRIVE_API_BASE is set');
assert_same('https://drive.example.com', DriveClient::baseUrl(), 'the base URL loses its trailing slash');

$client = DriveClient::make();
assert_not_null($client, 'make() builds a client when Drive is configured');

Env::configureForTests(['DRIVE_API_BASE' => '']);
assert_false(DriveClient::isConfigured(), 'Drive is not configured when the base URL is blank');
assert_null(DriveClient::make(), 'make() returns null rather than a client that cannot call anything');
Env::configureForTests(['DRIVE_API_BASE' => 'https://drive.example.com']);

$client = DriveClient::make();

// --- module codes -----------------------------------------------------------
assert_same('contract-document', DriveClient::moduleCodeFor('contract'), 'a contract files under contract-document');
assert_same('executed-copy', DriveClient::moduleCodeFor('executed_copy'), 'an executed copy keeps its own module');
assert_same('obligation-evidence', DriveClient::moduleCodeFor('evidence'), 'evidence files under obligation-evidence');
assert_same('request-attachment', DriveClient::moduleCodeFor('request_attachment'), 'a request attachment keeps its own module');
assert_same(
    'contract-document',
    DriveClient::moduleCodeFor('something-nobody-defined'),
    'an unmapped kind falls back rather than sending a blank module code'
);

// --- creating a session -----------------------------------------------------
$sent = [];
$respondWith(['body' => ['success' => true, 'data' => [
    'session_id'    => 4242,
    'session_token' => 'tok',
    'upload_url'    => 'https://objects.example.com/put/4242',
    'method'        => 'PUT',
    'headers'       => ['Content-Type' => 'application/pdf'],
    'expires_at'    => '2026-09-06 12:00:00',
    'object_key'    => 'incoming/product/contracts/...',
]]]);

$session = $client->createUploadSession($ctx, [
    'filename'     => 'msa.pdf',
    'content_type' => 'application/pdf',
    'size_bytes'   => 1024,
    'module_code'  => 'contract-document',
    'entity_type'  => 'contract',
    'entity_id'    => '17',
]);

assert_same(4242, $session['session_id'], 'the envelope is unwrapped to its data');
assert_same('POST', $sent[0]['method'], 'a session is created with POST');
assert_same('https://drive.example.com/api/upload-sessions', $sent[0]['url'], 'the session endpoint is Drive\'s own');

$headers = implode("\n", $sent[0]['headers']);
assert_contains('Authorization: Bearer test-ses-key', $headers, 'the caller\'s own ses_key is forwarded');
assert_contains('X-AIC-CMP-ID: 1', $headers, 'the company context header is sent');
assert_contains('X-AIC-FY-ID: 1', $headers, 'the financial-year context header is sent');
assert_contains('X-AIC-BO-ID: 1', $headers, 'the branch context header is sent');

assert_same('contracts', $sent[0]['body']['product_code'], 'uploads are filed under the contracts product code');
assert_same('company', $sent[0]['body']['scope'], 'a contract document is company scope, never personal');
assert_same('contract', $sent[0]['body']['entity_type'], 'the entity type reaches Drive');
assert_same('17', $sent[0]['body']['entity_id'], 'the contract id is the entity id');
assert_same('1', $sent[0]['body']['branch_key'], 'the branch key comes from the session context');

// --- linking ----------------------------------------------------------------
$sent = [];
$respondWith(['body' => ['success' => true, 'data' => ['link_id' => 9, 'document_id' => 5000]]]);

$link = $client->linkDocument($ctx, '5000', 'contract', '17');
assert_same(9, $link['link_id'], 'the link id comes back');
assert_same('https://drive.example.com/api/document-links', $sent[0]['url'], 'links are created at the links endpoint');
assert_same(5000, $sent[0]['body']['doc_id'], 'the Drive document id is sent as doc_id');
assert_same('contracts', $sent[0]['body']['product_code'], 'the link records which product owns the record');
assert_same('17', $sent[0]['body']['external_record_id'], 'the Contracts record id is the external record id');

// --- signed URLs ------------------------------------------------------------
$sent = [];
$respondWith(['body' => ['success' => true, 'data' => [
    'url' => 'https://objects.example.com/get/5000', 'expires_in' => 600,
    'filename' => 'msa.pdf', 'mime_type' => 'application/pdf',
]]]);

$view = $client->signedUrl($ctx, '5000', true);
assert_same('https://objects.example.com/get/5000', $view['url'], 'the signed URL is returned');
assert_same(600, $view['expires_in'], 'the expiry is carried through');
assert_contains('/api/documents/5000/view', $sent[0]['url'], 'an inline link asks for /view');

$sent = [];
$client->signedUrl($ctx, '5000', false);
assert_contains('/api/documents/5000/download', $sent[0]['url'], 'a download link asks for /download');

// --- failures ---------------------------------------------------------------
$respondWith(['status' => 0, 'raw' => '']);
assert_throws(
    static fn () => $client->signedUrl($ctx, '5000', true),
    'a dead socket is an integration outage, not a bad request',
    'not reachable'
);

$outage = null;
try {
    $client->signedUrl($ctx, '5000', true);
} catch (DomainException $e) {
    $outage = $e;
}
assert_same(503, $outage?->status, 'an unreachable Drive answers 503 so the caller can retry');

$respondWith(['status' => 500, 'body' => ['success' => false, 'message' => 'boom']]);
$serverError = null;
try {
    $client->signedUrl($ctx, '5000', true);
} catch (DomainException $e) {
    $serverError = $e;
}
assert_same(503, $serverError?->status, 'a 5xx from Drive is reported as an integration outage');

$respondWith(['status' => 403, 'body' => ['success' => false, 'message' => 'nope']]);
$denied = null;
try {
    $client->signedUrl($ctx, '5000', true);
} catch (DomainException $e) {
    $denied = $e;
}
assert_same(403, $denied?->status, 'Drive refusing access stays a 403');
assert_same('PERMISSION_DENIED', $denied?->errorCode, 'and keeps the permission error code');

$respondWith(['status' => 404, 'body' => ['success' => false, 'message' => 'gone']]);
$missing = null;
try {
    $client->signedUrl($ctx, '5000', true);
} catch (DomainException $e) {
    $missing = $e;
}
assert_same(404, $missing?->status, 'a document Drive does not have is a 404');

// A 200 carrying success:false is still a refusal — the status alone is not the
// whole answer, and treating it as one would return an empty document as a win.
$respondWith(['status' => 200, 'body' => ['success' => false, 'message' => 'Declared file size exceeds server limit.']]);
$refused = null;
try {
    $client->createUploadSession($ctx, [
        'filename' => 'msa.pdf', 'content_type' => 'application/pdf', 'size_bytes' => 1,
        'module_code' => 'contract-document', 'entity_type' => 'contract', 'entity_id' => '17',
    ]);
} catch (DomainException $e) {
    $refused = $e;
}
assert_same(400, $refused?->status, 'success:false with a 200 is still a refusal');
assert_contains('exceeds server limit', $refused?->getMessage() ?? '', 'and Drive\'s own message reaches the caller');

// --- putting bytes ----------------------------------------------------------
$sent = [];
$respondWith(['status' => 200, 'raw' => '']);
$client->putBytes('https://objects.example.com/put/4242', ['Content-Type' => 'application/pdf'], 'hello');
assert_same('PUT', $sent[0]['method'], 'bytes go up with PUT');
assert_same('hello', $sent[0]['raw'], 'the body is the file itself, not JSON');
assert_not_contains('Authorization', implode("\n", $sent[0]['headers']), 'no session key is attached to the presigned URL');

$respondWith(['status' => 403, 'raw' => 'expired']);
assert_throws(
    static fn () => $client->putBytes('https://objects.example.com/put/4242', [], 'hello'),
    'a storage refusal on the presigned PUT is surfaced',
    'refused the upload'
);

// --- reading bytes back -----------------------------------------------------
$respondWith(['status' => 200, 'raw' => 'file-contents']);
assert_same('file-contents', $client->fetchBytes('https://objects.example.com/get/5000', 1024), 'bytes come back whole');
assert_null(
    $client->fetchBytes('https://objects.example.com/get/5000', 4),
    'an object larger than the cap is refused rather than pulled into memory'
);

$respondWith(['status' => 404, 'raw' => '']);
assert_null($client->fetchBytes('https://objects.example.com/get/5000', 1024), 'a missing object reads as null');

Http::setTransportForTests(null);

t_done('DriveClientTest');
