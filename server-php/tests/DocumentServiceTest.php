<?php

declare(strict_types=1);

/**
 * Contract documents end to end: the upload session, the version lineage that
 * an audit depends on, the file-safety rules, tenant isolation, and the two
 * text extractors.
 *
 * Drive is faked at the transport, so nothing here touches the network. The
 * local storage half runs against a real temporary directory, because the
 * questions worth asking of it — is the stored name really opaque, is the byte
 * count really enforced on what arrived — are questions about a filesystem.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Core\Env;
use App\Core\Http;
use App\Modules\Drive\LocalStorageAdapter;
use App\Services\DocumentService;
use App\Services\TextExtractionService;
use App\Support\DomainException;
use App\Support\Permissions;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

Env::configureForTests([
    'DRIVE_API_BASE'                => 'https://drive.example.com',
    'CONTRACTS_MAX_UPLOAD_MB'       => '25',
    'CONTRACTS_ALLOW_LOCAL_STORAGE' => 'false',
    'CONTRACTS_LOCAL_STORAGE_PATH'  => '',
]);

$ctx      = t_context();
$otherCmp = t_context(cmpId: 2, uuid: 'USER-B');
$otherEnv = t_context(environment: 'production');

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$makeContract = static function (int $cmpId = 1, string $environment = 'sandbox') use ($pdo): int {
    static $seq = 0;
    $seq++;

    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, owner_uuid, created_by)
         VALUES (?, ?, ?, ?, \'draft\', \'draft\', \'USER-A\', \'USER-A\') RETURNING id'
    );
    $st->execute([$environment, $cmpId, sprintf('CON-2026-%06d', $seq), 'Master services agreement']);

    return (int) $st->fetchColumn();
};

/** A .docx is a zip; this builds the smallest one our reader accepts. */
$makeDocx = static function (array $paragraphs): string {
    $path = tempnam(sys_get_temp_dir(), 'ctr-docx-fixture');
    $zip  = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);

    $body = '';
    foreach ($paragraphs as $paragraph) {
        $body .= '<w:p><w:r><w:t>' . htmlspecialchars($paragraph, ENT_XML1) . '</w:t></w:r></w:p>';
    }

    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
    );
    $zip->addFromString(
        'word/document.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        . $body
        . '<w:p><w:r><w:instrText>MERGEFIELD CounterpartyName</w:instrText></w:r></w:p>'
        . '</w:body></w:document>'
    );
    $zip->close();

    $bytes = (string) file_get_contents($path);
    @unlink($path);

    return $bytes;
};

/** A one-page PDF with a real, uncompressed text object. */
$makePdf = static function (string $sentence): string {
    $content = "BT /F1 12 Tf 72 720 Td (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $sentence) . ") Tj ET";

    return "%PDF-1.4\n"
        . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        . "3 0 obj<</Type/Page/Parent 2 0 R/Contents 4 0 R>>endobj\n"
        . "4 0 obj<</Length " . strlen($content) . ">>stream\n"
        . $content . "\nendstream endobj\n"
        . "trailer<</Root 1 0 R>>\n%%EOF";
};

// ---------------------------------------------------------------------------
// A fake Drive
// ---------------------------------------------------------------------------

/** @var list<array{method: string, path: string, body: array<string,mixed>}> $drive */
$drive = [];
/** @var array<string,string> $objects */
$objects       = [];
$sessionSeq    = 9000;
$documentSeq   = 5000;

$installDrive = static function () use (&$drive, &$objects, &$sessionSeq, &$documentSeq): void {
    Http::setTransportForTests(static function (
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        int $connectTimeout
    ) use (&$drive, &$objects, &$sessionSeq, &$documentSeq): array {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
        $json = $body === null ? [] : (json_decode($body, true) ?: []);

        $drive[] = ['method' => $method, 'path' => $path, 'body' => is_array($json) ? $json : []];

        $ok = static fn (array $data): array => [
            'status'       => 200,
            'body'         => (string) json_encode(['success' => true, 'data' => $data]),
            'content_type' => 'application/json',
            'error'        => '',
        ];

        // The object store behind the presigned URLs.
        if ($host === 'objects.example.com') {
            if ($method === 'PUT') {
                $objects[$url] = (string) $body;

                return ['status' => 200, 'body' => '', 'content_type' => '', 'error' => ''];
            }

            return ['status' => 200, 'body' => $objects[$url] ?? '', 'content_type' => '', 'error' => ''];
        }

        if ($path === '/api/upload-sessions' && $method === 'POST') {
            $id = ++$sessionSeq;

            return $ok([
                'session_id'    => $id,
                'session_token' => 'tok-' . $id,
                'upload_ref'    => 'UPL' . $id,
                'upload_url'    => 'https://objects.example.com/put/' . $id,
                'method'        => 'PUT',
                'headers'       => ['Content-Type' => $json['content_type'] ?? 'application/octet-stream'],
                'expires_at'    => gmdate('Y-m-d H:i:s', time() + 3600),
                'bucket'        => 'quarantine',
                'object_key'    => 'incoming/product/contracts/upload/UPL' . $id . '/original/' . ($json['filename'] ?? 'f'),
            ]);
        }

        if (preg_match('#^/api/upload-sessions/(\d+)/complete$#', $path, $m) === 1) {
            return $ok(['session_id' => (int) $m[1], 'status' => 'uploaded']);
        }

        if (preg_match('#^/api/upload-sessions/(\d+)/finalize$#', $path, $m) === 1) {
            $docId = ++$documentSeq;

            return $ok([
                'id'              => $docId,
                'doc_ref'         => sprintf('DOC%05d', $docId),
                'size_bytes'      => (int) ($json['size_bytes'] ?? 0),
                'checksum_sha256' => hash('sha256', 'drive-object-' . $docId),
            ]);
        }

        if (preg_match('#^/api/upload-sessions/(\d+)/abort$#', $path) === 1) {
            return $ok(['status' => 'aborted']);
        }

        if ($path === '/api/document-links' && $method === 'POST') {
            return $ok(['link_id' => 700 + count($drive), 'document_id' => $json['doc_id'] ?? 0]);
        }

        if (preg_match('#^/api/documents/(\d+)/(view|download)$#', $path, $m) === 1) {
            return $ok([
                'url'        => 'https://objects.example.com/get/' . $m[1],
                'expires_in' => 900,
                'filename'   => 'drive-name.pdf',
                'mime_type'  => 'application/pdf',
            ]);
        }

        if (preg_match('#^/api/documents/(\d+)$#', $path) === 1 && $method === 'DELETE') {
            return $ok(['deleted' => true]);
        }

        return ['status' => 404, 'body' => '{"success":false,"message":"no route"}', 'content_type' => 'application/json', 'error' => ''];
    });
};

$installDrive();

$service    = new DocumentService($pdo);
$contractId = $makeContract();

/** Run one whole upload against the fake Drive. */
$upload = static function (array $session, array $finalize = []) use ($service, $ctx): array {
    $created = $service->createUploadSession($ctx, $session);
    $service->completeUpload($ctx, (int) $created['session_id']);

    return $service->finalizeUpload($ctx, (int) $created['session_id'], $finalize);
};

// ---------------------------------------------------------------------------
// Filename handling
// ---------------------------------------------------------------------------

assert_same('passwd.pdf', DocumentService::sanitiseFilename('../../etc/passwd.pdf'), 'traversal is stripped to a bare name');
assert_same('x.pdf', DocumentService::sanitiseFilename('..\\..\\windows\\x.pdf'), 'backslash traversal is stripped too');
assert_same('report.pdf', DocumentService::sanitiseFilename('report..pdf'), 'a dot run cannot survive to become traversal');
assert_same('document', DocumentService::sanitiseFilename('../'), 'a name that sanitises to nothing gets a safe default');
assert_same('a_b.pdf', DocumentService::sanitiseFilename('a;b.pdf'), 'shell metacharacters are replaced, not dropped');

// ---------------------------------------------------------------------------
// The full session -> complete -> finalize flow
// ---------------------------------------------------------------------------

$drive = [];
$first = $upload([
    'filename'     => '../../etc/passwd.pdf',
    'content_type' => 'application/pdf',
    'size_bytes'   => 2048,
    'contract_id'  => $contractId,
], ['title' => 'Master services agreement', 'notes' => 'First draft to legal']);

$documentId = (int) $first['document']['id'];
$v1Id       = (int) $first['version']['id'];

assert_same(1, (int) $first['version']['version_no'], 'the first version of a document is version 1');
assert_same('passwd.pdf', $first['version']['filename'], 'the stored filename is the sanitised one, with no path left');
assert_not_contains('/', (string) $first['version']['filename'], 'no path separator survives into the database');
assert_same('drive', $first['version']['storage_provider'], 'Drive is used when it is configured');
assert_same(
    hash('sha256', 'drive-object-5001'),
    $first['version']['checksum_sha256'],
    'the checksum stored is the one Drive computed over the stored object'
);
assert_same(1, (int) $first['document']['version_count'], 'the document counts its first version');
assert_same($v1Id, (int) $first['document']['current_version_id'], 'the document points at its newest version');
assert_null($first['version']['local_path'] ?? null, 'the server-side path is never published in an API response');

$paths = array_column($drive, 'path');
assert_true(in_array('/api/document-links', $paths, true), 'the Drive document is linked back to the contract');

$link = null;
foreach ($drive as $call) {
    if ($call['path'] === '/api/document-links') {
        $link = $call['body'];
    }
}
assert_same('contract', $link['external_record_type'] ?? null, 'the link records that this belongs to a contract');
assert_same((string) $contractId, $link['external_record_id'] ?? null, 'the link points at the contract id');

// The upload session is closed and remembers what it produced.
$session = $pdo->query('SELECT * FROM contract_upload_sessions ORDER BY id DESC LIMIT 1')->fetch();
assert_same('finalized', $session['status'], 'the session ends finalized');
assert_same($documentId, (int) $session['document_id'], 'the session records the document it created');

// Text extraction is queued rather than run inline.
$job = $pdo->query("SELECT * FROM contract_jobs WHERE job_type = 'document.extract_text' ORDER BY id DESC LIMIT 1")->fetch();
assert_true(is_array($job), 'finalising queues a text-extraction job');
assert_same('document.extract_text:' . $v1Id, $job['idempotency_key'], 'the job is keyed so a retry cannot queue it twice');

// --- version 2 --------------------------------------------------------------
$second = $upload([
    'filename'     => 'msa-redline.docx',
    'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'size_bytes'   => 4096,
    'document_id'  => $documentId,
    'version_status' => 'counterparty_redline',
]);
$v2Id = (int) $second['version']['id'];

assert_same(2, (int) $second['version']['version_no'], 'the next upload onto the same document is version 2');
assert_same($documentId, (int) $second['version']['document_id'], 'version 2 stays on the same document');
assert_same(2, (int) $second['document']['version_count'], 'the document now holds two versions');
assert_same($v2Id, (int) $second['document']['current_version_id'], 'the newest version becomes current');

$v1 = $service->findVersion($ctx, $v1Id);
assert_same('superseded', $v1['version_status'], 'the previous draft is marked superseded rather than overwritten');
assert_same('passwd.pdf', $v1['filename'], 'version 1 still holds its own file — history is not rewritten');

assert_count(2, $service->versions($ctx, $documentId), 'both versions are in the history');

// --- a number is never reused ------------------------------------------------
$service->deleteDraftVersion($ctx, $v2Id);
assert_null($service->findVersion($ctx, $v2Id), 'the deleted draft is gone');
assert_same($v1Id, (int) $service->findDocumentOrFail($ctx, $documentId)['current_version_id'], 'current falls back to the surviving version');

$third = $upload([
    'filename'     => 'msa-final.pdf',
    'content_type' => 'application/pdf',
    'size_bytes'   => 5000,
    'document_id'  => $documentId,
]);
assert_same(
    3,
    (int) $third['version']['version_no'],
    'the number of a deleted version is never handed to a later upload'
);

// ---------------------------------------------------------------------------
// File safety
// ---------------------------------------------------------------------------

assert_throws(
    static fn () => $service->createUploadSession($ctx, [
        'filename' => 'payload.exe', 'content_type' => 'application/pdf',
        'size_bytes' => 100, 'contract_id' => $contractId,
    ]),
    'an extension outside the allow-list is refused',
    'Upload one of'
);

assert_throws(
    static fn () => $service->createUploadSession($ctx, [
        'filename' => 'shell.php', 'content_type' => 'text/plain',
        'size_bytes' => 100, 'contract_id' => $contractId,
    ]),
    'a .php upload is refused however it is declared',
    'Upload one of'
);

assert_throws(
    static fn () => $service->createUploadSession($ctx, [
        'filename' => 'logo.png', 'content_type' => 'application/pdf',
        'size_bytes' => 100, 'contract_id' => $contractId,
    ]),
    'a content type that does not match the extension is refused',
    'does not match'
);

assert_throws(
    static fn () => $service->createUploadSession($ctx, [
        'filename' => 'huge.pdf', 'content_type' => 'application/pdf',
        'size_bytes' => 26 * 1024 * 1024, 'contract_id' => $contractId,
    ]),
    'a file over the declared cap is refused before it is uploaded',
    'under 25 MB'
);

assert_throws(
    static fn () => $service->createUploadSession($ctx, [
        'filename' => 'msa.pdf', 'content_type' => 'application/pdf', 'size_bytes' => 10,
    ]),
    'a document must belong to exactly one record',
    'exactly one'
);

assert_throws(
    static fn () => $service->createUploadSession($ctx, [
        'filename' => 'msa.pdf', 'content_type' => 'application/pdf', 'size_bytes' => 10,
        'contract_id' => $contractId, 'request_id' => 1,
    ]),
    'a document cannot belong to two records at once',
    'exactly one'
);

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

assert_not_null($service->findVersion($ctx, $v1Id), 'the owning tenant can read its own version');
assert_null($service->findVersion($otherCmp, $v1Id), 'another company sees nothing, not a refusal');
assert_null($service->findVersion($otherEnv, $v1Id), 'the same company in another environment sees nothing either');
assert_null($service->findDocument($otherCmp, $documentId), 'the document is invisible across the tenant boundary too');

assert_throws(
    static fn () => $service->listForContract($otherCmp, $contractId),
    'listing another company\'s contract documents is a not-found',
    'not found'
);

$foreignContract = $makeContract(2);
assert_throws(
    static fn () => $service->createUploadSession($ctx, [
        'filename' => 'msa.pdf', 'content_type' => 'application/pdf',
        'size_bytes' => 10, 'contract_id' => $foreignContract,
    ]),
    'a file cannot be filed against another company\'s contract',
    'not found'
);

// ---------------------------------------------------------------------------
// Executed copies
// ---------------------------------------------------------------------------

$v3Id     = (int) $third['version']['id'];
$executed = $service->setExecutedCopy($ctx, $v3Id, 'Countersigned original received');

assert_true($executed['is_executed'], 'the version is marked executed');
assert_same('executed', $executed['version_status'], 'and its status says so');
assert_true($service->findDocumentOrFail($ctx, $documentId)['is_executed_copy'], 'the document is flagged as holding the executed copy');

assert_throws(
    static fn () => $service->deleteDraftVersion($ctx, $v3Id),
    'an executed copy cannot be deleted',
    'cannot be deleted'
);

// A later draft must not quietly demote the signed copy to "superseded".
$fourth = $upload([
    'filename'     => 'msa-annex.pdf',
    'content_type' => 'application/pdf',
    'size_bytes'   => 900,
    'document_id'  => $documentId,
]);
assert_same(4, (int) $fourth['version']['version_no'], 'the next version continues the sequence');
assert_same(
    'executed',
    $service->findVersion($ctx, $v3Id)['version_status'],
    'a new draft does not overwrite the status of the executed copy'
);

// ---------------------------------------------------------------------------
// Reading and listing
// ---------------------------------------------------------------------------

$signed = $service->signedUrl($ctx, $v1Id, true);
assert_contains('https://objects.example.com/get/', $signed['url'], 'a version resolves to a Drive signed URL');
assert_same('passwd.pdf', $signed['filename'], 'the download is named after our record, not the Drive object');

$viewer = t_context(permissions: [Permissions::CONTRACT_VIEW, Permissions::CONTRACT_VIEW_ALL]);
assert_throws(
    static fn () => $service->signedUrl($viewer, $v1Id, true),
    'downloading needs the download permission',
    'permission'
);
assert_throws(
    static fn () => $service->createUploadSession($viewer, [
        'filename' => 'msa.pdf', 'content_type' => 'application/pdf',
        'size_bytes' => 10, 'contract_id' => $contractId,
    ]),
    'uploading needs the upload permission',
    'permission'
);

$listed = $service->listForContract($ctx, $contractId);
assert_count(1, $listed, 'the contract has one document');
assert_same(4, (int) $listed[0]['current_version_no'], 'the listing carries the current version number');

// ---------------------------------------------------------------------------
// Aborting
// ---------------------------------------------------------------------------

$abandoned = $service->createUploadSession($ctx, [
    'filename' => 'never-sent.pdf', 'content_type' => 'application/pdf',
    'size_bytes' => 10, 'contract_id' => $contractId,
]);
$service->abort($ctx, (int) $abandoned['session_id']);

$st = $pdo->prepare('SELECT status FROM contract_upload_sessions WHERE id = ?');
$st->execute([(int) $abandoned['session_id']]);
assert_same('aborted', $st->fetchColumn(), 'an abandoned session is marked aborted rather than left pending');

assert_throws(
    static fn () => $service->finalizeUpload($ctx, (int) $abandoned['session_id'], []),
    'an aborted session cannot be finalised afterwards',
    'already been aborted'
);

// A session belonging to someone else is not visible even with its id.
$stranger = t_context(uuid: 'USER-C');
assert_throws(
    static fn () => $service->completeUpload($stranger, (int) $abandoned['session_id']),
    'another user cannot drive somebody else\'s upload session',
    'not found'
);

// ---------------------------------------------------------------------------
// Local storage fallback
// ---------------------------------------------------------------------------

$root = sys_get_temp_dir() . '/contracts-local-' . bin2hex(random_bytes(6));

Env::configureForTests([
    'DRIVE_API_BASE'                => '',
    'CONTRACTS_ALLOW_LOCAL_STORAGE' => 'false',
    'CONTRACTS_LOCAL_STORAGE_PATH'  => $root,
]);

assert_null(LocalStorageAdapter::make(), 'local storage is refused entirely unless it is explicitly enabled');
assert_throws(
    static fn () => (new DocumentService($pdo))->createUploadSession($ctx, [
        'filename' => 'msa.pdf', 'content_type' => 'application/pdf',
        'size_bytes' => 10, 'contract_id' => $contractId,
    ]),
    'with neither Drive nor the fallback enabled, an upload fails loudly',
    'not configured'
);

Env::configureForTests(['CONTRACTS_ALLOW_LOCAL_STORAGE' => 'true']);

$local      = LocalStorageAdapter::make();
$localSvc   = new DocumentService($pdo, $local);
$localCtr   = $makeContract();
$agreement  = "MASTER SERVICES AGREEMENT\n\nThis Agreement is made between Acme Industries and Globex Corporation.\n";

assert_not_null($local, 'the fallback is available once it is enabled');
assert_true($local->isConfigured(), 'and reports itself configured');
assert_same('local', $local->name(), 'it stores under the local provider');

$created = $localSvc->createUploadSession($ctx, [
    'filename'     => 'terms & conditions.txt',
    'content_type' => 'text/plain',
    'size_bytes'   => strlen($agreement),
    'contract_id'  => $localCtr,
]);

assert_same('local', $created['provider'], 'the fallback is the adapter in use');
assert_contains(
    '/api/uploads/sessions/' . $created['session_id'] . '/complete',
    (string) $created['upload_url'],
    'with no presigned destination, the client uploads through this API instead'
);
assert_same('POST', $created['method'], 'and does it with a POST');

$localSvc->completeUpload($ctx, (int) $created['session_id'], $agreement);
$localDoc = $localSvc->finalizeUpload($ctx, (int) $created['session_id'], ['title' => 'Terms and conditions']);

$localVersionId = (int) $localDoc['version']['id'];
assert_same('local', $localDoc['version']['storage_provider'], 'the version records that it is stored locally');
assert_same(
    hash('sha256', $agreement),
    $localDoc['version']['checksum_sha256'],
    'the checksum is computed over the bytes that were actually written'
);
assert_same(strlen($agreement), (int) $localDoc['version']['size_bytes'], 'the size is the real one, not the declared one');

$storedPath = (string) $pdo->query('SELECT local_path FROM contract_document_versions WHERE id = ' . $localVersionId)->fetchColumn();
assert_not_contains('terms', strtolower($storedPath), 'the user\'s filename never becomes the name on disk');
assert_not_contains('..', $storedPath, 'and the stored path holds no traversal');
assert_true(is_file($root . '/' . $storedPath), 'the file really is on disk under the configured root');
assert_same($agreement, (string) file_get_contents($root . '/' . $storedPath), 'and holds exactly what was uploaded');
assert_same('terms & conditions.txt', $localDoc['version']['filename'], 'the readable name is kept as metadata');

$localSigned = $localSvc->signedUrl($ctx, $localVersionId, true);
assert_contains('token=', $localSigned['url'], 'a locally stored file is served through a signed link, not a bare path');
assert_not_contains($storedPath, $localSigned['url'], 'the link does not leak where the file lives');

parse_str((string) parse_url($localSigned['url'], PHP_URL_QUERY), $query);
assert_true(
    LocalStorageAdapter::verifyViewToken($localVersionId, true, (int) $query['expires'], (string) $query['token']),
    'the link this adapter mints is the one it will accept back'
);
assert_false(
    LocalStorageAdapter::verifyViewToken($localVersionId + 1, true, (int) $query['expires'], (string) $query['token']),
    'a token for one version does not open another'
);
assert_false(
    LocalStorageAdapter::verifyViewToken($localVersionId, true, time() - 60, (string) $query['token']),
    'an expired link is refused even with a token that once matched'
);

// The cap and the sniff are enforced on the bytes that arrived, not on a claim.
$sniffSession = $localSvc->createUploadSession($ctx, [
    'filename' => 'not-really.pdf', 'content_type' => 'application/pdf',
    'size_bytes' => 20, 'contract_id' => $localCtr,
]);
assert_throws(
    static fn () => $localSvc->completeUpload($ctx, (int) $sniffSession['session_id'], 'MZ this is an executable'),
    'a file whose contents do not match its extension is refused at write time',
    'not really a PDF'
);

Env::configureForTests(['CONTRACTS_MAX_UPLOAD_MB' => '1']);
$capSession = $localSvc->createUploadSession($ctx, [
    'filename' => 'small-claim.txt', 'content_type' => 'text/plain',
    'size_bytes' => 20, 'contract_id' => $localCtr,
]);
assert_throws(
    static fn () => $localSvc->completeUpload($ctx, (int) $capSession['session_id'], str_repeat('a', 2 * 1024 * 1024)),
    'a client that declares 20 bytes and sends two megabytes is still capped',
    'the limit is'
);
Env::configureForTests(['CONTRACTS_MAX_UPLOAD_MB' => '25']);

// ---------------------------------------------------------------------------
// Text extraction
// ---------------------------------------------------------------------------

$extractor = new TextExtractionService($pdo, $local);

$txtResult = $extractor->extract($ctx, $localVersionId);
assert_contains('MASTER SERVICES AGREEMENT', (string) $txtResult['text'], 'plain text is extracted verbatim');
assert_contains('Globex Corporation', (string) $txtResult['text'], 'the whole body is kept, not just the first line');
assert_false($txtResult['scanned'], 'a text file is never marked as needing OCR');

$stored = $pdo->query('SELECT extracted_text, text_extracted_at, is_scanned FROM contract_document_versions WHERE id = ' . $localVersionId)->fetch();
assert_contains('Acme Industries', (string) $stored['extracted_text'], 'the text is written back onto the version');
assert_not_null($stored['text_extracted_at'], 'and the version records when it was extracted');

// --- .docx ------------------------------------------------------------------
$docxBytes   = $makeDocx([
    'CONFIDENTIALITY AND NON-DISCLOSURE',
    'Each party shall keep the Confidential Information of the other party secret.',
    'This clause survives termination for a period of three years.',
]);
$docxSession = $localSvc->createUploadSession($ctx, [
    'filename'     => 'nda.docx',
    'content_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'size_bytes'   => strlen($docxBytes),
    'contract_id'  => $localCtr,
]);
$localSvc->completeUpload($ctx, (int) $docxSession['session_id'], $docxBytes);
$docx = $localSvc->finalizeUpload($ctx, (int) $docxSession['session_id'], []);

$docxResult = $extractor->extract($ctx, (int) $docx['version']['id']);
assert_contains('CONFIDENTIALITY AND NON-DISCLOSURE', (string) $docxResult['text'], 'a Word heading is extracted');
assert_contains('survives termination', (string) $docxResult['text'], 'and so is a later paragraph');
assert_contains(
    "NON-DISCLOSURE\nEach party",
    (string) $docxResult['text'],
    'paragraphs stay on their own lines instead of running together'
);
assert_not_contains('MERGEFIELD', (string) $docxResult['text'], 'field codes are not mistaken for contract text');
assert_false($docxResult['scanned'], 'a Word document is not a scan');

// --- .pdf -------------------------------------------------------------------
$sentence  = 'This Agreement is made on 1 April 2026 between Acme Industries Private Limited and Globex Corporation.';
$pdfResult = TextExtractionService::fromBytes($makePdf($sentence), 'agreement.pdf');

assert_contains('Acme Industries Private Limited', (string) $pdfResult['text'], 'text is read out of an uncompressed PDF stream');
assert_same(1, $pdfResult['pages'], 'the page count is read from the page tree');
assert_false($pdfResult['scanned'], 'a PDF with a real text layer is not flagged for OCR');

// A scan: one page, one image, no text objects at all.
$scan = "%PDF-1.4\n"
    . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
    . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    . "3 0 obj<</Type/Page/Parent 2 0 R/Contents 4 0 R>>endobj\n"
    . "4 0 obj<</Type/XObject/Subtype/Image/Length 12>>stream\n"
    . "\xFF\xD8\xFF\xE0scanned!\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

$scanResult = TextExtractionService::fromBytes($scan, 'scanned.pdf');
assert_null($scanResult['text'], 'a scanned page yields no text rather than invented text');
assert_true($scanResult['scanned'], 'and is recorded as needing OCR');
assert_contains('OCR', (string) $scanResult['reason'], 'with a reason a person can act on');

$encrypted = "%PDF-1.4\ntrailer<</Root 1 0 R/Encrypt 9 0 R>>\n%%EOF";
$encResult = TextExtractionService::fromBytes($encrypted, 'locked.pdf');
assert_null($encResult['text'], 'an encrypted PDF yields nothing');
assert_false($encResult['scanned'], 'and is not misreported as needing OCR, which would not help');

$unknown = TextExtractionService::fromBytes('anything', 'photo.png');
assert_null($unknown['text'], 'a format with no extractor stores no text');
assert_false(TextExtractionService::supports('scan.tiff'), 'and says plainly that it is unsupported');
assert_true(TextExtractionService::supports('agreement.PDF'), 'extension matching is case-insensitive');

// ---------------------------------------------------------------------------
// Teardown
// ---------------------------------------------------------------------------

Http::setTransportForTests(null);

$removeTree = static function (string $dir) use (&$removeTree): void {
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? $removeTree($path) : @unlink($path);
    }
    @rmdir($dir);
};
if (is_dir($root)) {
    $removeTree($root);
}

t_done('DocumentServiceTest');
