<?php

declare(strict_types=1);

/**
 * The controller-level upload logic in DocumentController that
 * DocumentServiceTest cannot see — it drives DocumentService directly and
 * never exercises what the controller does with a request before the service
 * is called at all. Three bugs lived exactly there:
 *
 *   1. completeUpload() never read the request body, so a local-storage
 *      upload could not complete no matter what the browser sent.
 *   2. directUpload() moved the temp file itself, using the session's
 *      *relative* local_path with no root prefix — which resolves against
 *      this process's working directory, not the configured storage root.
 *   3. linkDriveFile() built a payload missing the two fields
 *      DocumentService::linkExistingDriveFile() requires, so the endpoint
 *      failed validation on every call.
 *
 * `is_uploaded_file()` and `file_get_contents('php://input')` are real SAPI
 * behaviour a CLI test cannot produce honestly, so this file overrides both
 * inside App\Controllers\Api — PHP resolves an unqualified call by checking
 * the current namespace first — to stand in for a genuine multipart request
 * and a genuine POST body without touching DocumentController.php itself.
 */

namespace App\Controllers\Api {
    $GLOBALS['__dc_test_raw_body']    = null;
    $GLOBALS['__dc_test_is_uploaded'] = false;

    function file_get_contents(string $filename, mixed ...$rest): string|false
    {
        if ($filename === 'php://input' && $GLOBALS['__dc_test_raw_body'] !== null) {
            return $GLOBALS['__dc_test_raw_body'];
        }

        return \file_get_contents($filename, ...$rest);
    }

    function is_uploaded_file(string $filename): bool
    {
        return $GLOBALS['__dc_test_is_uploaded'] === true ? \is_file($filename) : \is_uploaded_file($filename);
    }
}

namespace {

require_once __DIR__ . '/bootstrap.php';

use App\Controllers\Api\BaseController;
use App\Controllers\Api\DocumentController;
use App\Core\Env;
use App\Core\Http;
use App\Core\Response;
use App\Core\ResponseSent;
use App\Modules\Drive\LocalStorageAdapter;
use App\Services\DocumentService;
use App\Support\TenantContext;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$storageRoot = sys_get_temp_dir() . '/ctr-doc-controller-test-' . bin2hex(random_bytes(4));
mkdir($storageRoot, 0700, true);

Env::configureForTests([
    'DRIVE_API_BASE'                => '',
    'CONTRACTS_MAX_UPLOAD_MB'       => '25',
    'CONTRACTS_ALLOW_LOCAL_STORAGE' => 'true',
    'CONTRACTS_LOCAL_STORAGE_PATH'  => $storageRoot,
]);

$ctx = t_context();

$st = $pdo->prepare(
    "INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, owner_uuid, created_by)
     VALUES ('sandbox', 1, 'CON-2026-000900', 'Controller test contract', 'draft', 'draft', 'USER-A', 'USER-A') RETURNING id"
);
$st->execute();
$contractId = (int) $st->fetchColumn();

/** Drives a real controller action without a live portal session behind it. */
function dc_call(DocumentController $controller, TenantContext $ctx, callable $action): int
{
    $ref  = new ReflectionClass(BaseController::class);
    $prop = $ref->getProperty('context');
    $prop->setAccessible(true);
    $prop->setValue($controller, $ctx);

    try {
        $action($controller);
    } catch (ResponseSent $e) {
        return $e->status;
    }

    throw new RuntimeException('the action did not send a response');
}

Response::enableTestMode();

// ---------------------------------------------------------------------------
// Finding 2: completeUpload() must forward the request body
// ---------------------------------------------------------------------------

$local   = LocalStorageAdapter::make();
$localSvc = new DocumentService($pdo, $local);
$session  = $localSvc->createUploadSession($ctx, [
    'filename' => 'agreement.pdf', 'content_type' => 'application/pdf',
    'size_bytes' => 5, 'contract_id' => $contractId,
]);
$sessionId = (int) $session['session_id'];

$bytes = "%PDF-1.4\ncontroller test body\n%%EOF";
$GLOBALS['__dc_test_raw_body'] = $bytes;

$status = dc_call(new DocumentController(), $ctx, fn (DocumentController $c) => $c->completeUpload((string) $sessionId));
$body   = Response::lastForTests();
$GLOBALS['__dc_test_raw_body'] = null;

assert_same(200, $status, 'a request body carrying the file completes a local-storage upload instead of 400 UPLOAD_BODY_REQUIRED');
assert_same('uploaded', $body['body']['data']['status'] ?? null, 'the session is marked uploaded');

$storedPath = (string) $pdo->query('SELECT local_path FROM contract_upload_sessions WHERE id = ' . $sessionId)->fetchColumn();
assert_true($storedPath !== '' && is_file($storageRoot . '/' . $storedPath), 'the bytes the controller read from the request landed under the configured storage root');
assert_same($bytes, (string) file_get_contents($storageRoot . '/' . $storedPath), 'and are exactly the bytes the request body carried, not a placeholder');

// A session the caller never sends a body for (the normal Drive case, where
// the browser PUTs straight to Drive) must still complete -- forwarding an
// empty body as null, not as an empty string, is what keeps that working.
$drive = [];
Http::setTransportForTests(static function (string $method, string $url, array $headers, ?string $reqBody, int $t, int $ct) use (&$drive): array {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
    $json = $reqBody === null ? [] : (json_decode($reqBody, true) ?: []);
    $drive[] = ['method' => $method, 'path' => $path];

    $ok = static fn (array $data): array => ['status' => 200, 'body' => (string) json_encode(['success' => true, 'data' => $data]), 'content_type' => 'application/json', 'error' => ''];

    if ($host === 'objects.example.com' && $method === 'PUT') {
        return ['status' => 200, 'body' => '', 'content_type' => '', 'error' => ''];
    }
    if ($path === '/api/upload-sessions' && $method === 'POST') {
        return $ok([
            'session_id' => 4200, 'session_token' => 'tok-4200', 'upload_ref' => 'UPL4200',
            'upload_url' => 'https://objects.example.com/put/4200', 'method' => 'PUT',
            'headers' => ['Content-Type' => $json['content_type'] ?? 'application/octet-stream'],
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600), 'bucket' => 'quarantine',
            'object_key' => 'incoming/UPL4200/original/' . ($json['filename'] ?? 'f'),
        ]);
    }
    if (preg_match('#^/api/upload-sessions/(\d+)/complete$#', $path) === 1) {
        return $ok(['session_id' => 4200, 'status' => 'uploaded']);
    }

    return ['status' => 404, 'body' => '{"success":false,"message":"no route"}', 'content_type' => 'application/json', 'error' => ''];
});

Env::configureForTests(['DRIVE_API_BASE' => 'https://drive.example.com']);
$driveSvc     = new DocumentService($pdo);
$driveSession = $driveSvc->createUploadSession($ctx, [
    'filename' => 'redline.pdf', 'content_type' => 'application/pdf',
    'size_bytes' => 5, 'contract_id' => $contractId,
]);
$driveSessionId = (int) $driveSession['session_id'];

$status = dc_call(new DocumentController(), $ctx, fn (DocumentController $c) => $c->completeUpload((string) $driveSessionId));
assert_same(200, $status, 'an empty body still completes a Drive-backed session -- no regression from the local-storage fix');

Env::configureForTests(['DRIVE_API_BASE' => '']);

// ---------------------------------------------------------------------------
// Finding 1: directUpload() must not resolve the storage path against CWD
// ---------------------------------------------------------------------------

$tmpUpload = tempnam(sys_get_temp_dir(), 'ctr-direct-upload-fixture');
file_put_contents($tmpUpload, "%PDF-1.4\ndirect upload fixture\n%%EOF");

$_FILES['file'] = [
    'name'     => 'direct-agreement.pdf',
    'type'     => 'application/pdf',
    'size'     => filesize($tmpUpload),
    'tmp_name' => $tmpUpload,
    'error'    => UPLOAD_ERR_OK,
];
$_POST['contract_id'] = (string) $contractId;
$GLOBALS['__dc_test_is_uploaded'] = true;

// The process's working directory at the time a real request is served is a
// deployment detail the controller must not depend on -- moving to some other
// directory here is what would have let the pre-fix bug write into it.
$originalCwd = getcwd();
$decoyCwd    = sys_get_temp_dir() . '/ctr-decoy-cwd-' . bin2hex(random_bytes(4));
mkdir($decoyCwd, 0755, true);
chdir($decoyCwd);

try {
    $status = dc_call(new DocumentController(), $ctx, fn (DocumentController $c) => $c->directUpload());
} finally {
    chdir($originalCwd);
    $GLOBALS['__dc_test_is_uploaded'] = false;
    unset($_FILES['file'], $_POST['contract_id']);
    @unlink($tmpUpload);
}

$body = Response::lastForTests();
assert_same(201, $status, 'a direct multipart upload is stored and finalised in one call');

$versionId = (int) ($body['body']['data']['version']['id'] ?? 0);
assert_true($versionId > 0, 'a document version was created');
assert_same('local', $body['body']['data']['version']['storage_provider'] ?? null, 'the fallback adapter stored it');

$directPath = (string) $pdo->query('SELECT local_path FROM contract_document_versions WHERE id = ' . $versionId)->fetchColumn();
assert_true($directPath !== '', 'the version records where its bytes live');
assert_true(
    is_file($storageRoot . '/' . $directPath),
    'the file was written under the configured storage root, not moved by the controller to some other location'
);
assert_same(
    "%PDF-1.4\ndirect upload fixture\n%%EOF",
    (string) file_get_contents($storageRoot . '/' . $directPath),
    'and holds exactly the bytes that were uploaded'
);
assert_false(
    is_file($decoyCwd . '/' . $directPath),
    'nothing was written relative to the process working directory -- the exact leak the pre-fix code produced'
);

// ---------------------------------------------------------------------------
// Finding 3: linkDriveFile() must send a payload the service accepts
// ---------------------------------------------------------------------------

Http::setTransportForTests(static function (string $method, string $url, array $headers, ?string $reqBody, int $t, int $ct): array {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $json = $reqBody === null ? [] : (json_decode($reqBody, true) ?: []);

    if ($path === '/api/document-links' && $method === 'POST') {
        return [
            'status' => 200,
            'body'   => (string) json_encode(['success' => true, 'data' => ['link_id' => 701, 'document_id' => $json['doc_id'] ?? 0]]),
            'content_type' => 'application/json',
            'error'  => '',
        ];
    }

    return ['status' => 404, 'body' => '{"success":false,"message":"no route"}', 'content_type' => 'application/json', 'error' => ''];
});
Env::configureForTests(['DRIVE_API_BASE' => 'https://drive.example.com']);

\App\Core\Request::configureForTests([
    'contract_id'       => $contractId,
    'drive_document_id' => '4321',
    'title'             => 'Executed MSA',
    'doc_kind'          => 'executed_copy',
    'filename'          => 'executed-msa.pdf',
    'content_type'      => 'application/pdf',
    'description'       => 'Countersigned copy from the counterparty',
]);

$status = dc_call(new DocumentController(), $ctx, fn (DocumentController $c) => $c->linkDriveFile());
$body   = Response::lastForTests();

assert_same(
    201,
    $status,
    'the documented request shape for POST /api/documents/link succeeds instead of 422 on filename/content_type'
);
assert_same('executed-msa.pdf', $body['body']['data']['version']['filename'] ?? null, 'the filename the caller sent is recorded');
assert_same('application/pdf', $body['body']['data']['version']['content_type'] ?? null, 'and its content type');
assert_same(
    'Countersigned copy from the counterparty',
    $body['body']['data']['version']['notes'] ?? null,
    "'description' in the request becomes the service's 'notes' field, not a dropped key"
);

// The two fields the endpoint requires are still enforced -- fixing the typo
// must not turn linkDriveFile() into an endpoint that accepts anything.
\App\Core\Request::configureForTests([
    'contract_id'       => $contractId,
    'drive_document_id' => '9999',
    'title'             => 'Untitled',
]);
$status = dc_call(new DocumentController(), $ctx, fn (DocumentController $c) => $c->linkDriveFile());
$body   = Response::lastForTests();
assert_same(422, $status, 'omitting filename/content_type is still a validation error, not silently accepted');
assert_true(isset($body['body']['errors']['filename']), 'the missing field is named');

t_done('DocumentControllerTest');

}
