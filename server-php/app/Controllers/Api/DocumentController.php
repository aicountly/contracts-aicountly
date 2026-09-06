<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Services\ContractService;
use App\Services\DiffService;
use App\Services\DocumentService;
use App\Services\TextExtractionService;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\Validator;
use Throwable;

/**
 * Contract documents, their version history and the upload flow.
 *
 * Where Drive is configured the bytes never pass through this process: the
 * browser is handed a short-lived upload URL and Contracts only learns that the
 * transfer finished. `directUpload` is the single exception and exists for a
 * deployment that has not provisioned Drive yet.
 *
 * A version id is caller-supplied and resolves to a stored file, so every
 * action that takes one proves the version belongs to this tenant before acting
 * on it. That proof is the whole access-control surface of this file.
 */
final class DocumentController extends BaseController
{
    /**
     * Extensions the upload path accepts, each with the content types allowed
     * to accompany it. Mirrors the allow-list in docs/SECURITY.md.
     *
     * Content sniffing is deliberately not the gate: a .docx is a zip and a
     * .rtf is plain text, so finfo would refuse the two formats a negotiated
     * contract most often arrives in while still waving through a renamed file
     * that happens to carry a plausible header.
     */
    private const ALLOWED_UPLOADS = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'txt'  => ['text/plain'],
        'rtf'  => ['application/rtf', 'text/rtf', 'text/richtext'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'tiff' => ['image/tiff'],
    ];

    /** Mirrors ck_contract_documents_kind; the two change together or a write fails. */
    private const DOC_KINDS = [
        'contract', 'annexure', 'schedule', 'amendment', 'evidence', 'executed_copy',
        'correspondence', 'request_attachment', 'template', 'other',
    ];

    public function index(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);

        $this->respond(function () use ($ctx, $contractId): array {
            $documents = $this->documents()->listForContract($ctx, $contractId);

            // The documents tab draws a file and its history as one row group,
            // so the versions ride along here rather than costing the browser a
            // request per document.
            foreach ($documents as $index => $document) {
                if (! is_array($document) || array_key_exists('versions', $document)) {
                    continue;
                }
                $documents[$index]['versions'] = $this->documents()->versions($ctx, (int) ($document['id'] ?? 0));
            }

            return $documents;
        });
    }

    /**
     * A redline between two versions of this contract's paper.
     *
     * Both ids arrive in the query string, so both are proved to be this
     * tenant's — and this contract's — before the diff runs.
     */
    public function compare(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);

        $base   = self::versionIdFromQuery('base');
        $target = self::versionIdFromQuery('target');

        $errors = [];
        if ($base === null) {
            $errors['base'] = 'Choose a version to compare from.';
        }
        if ($target === null) {
            $errors['target'] = 'Choose a version to compare against.';
        }
        if ($errors !== []) {
            Response::validationError($errors);
        }

        // The comparison cache refuses a self-comparison
        // (ck_version_comparison_distinct), and it is a caller mistake rather
        // than an empty diff worth computing and storing.
        if ($base === $target) {
            Response::validationError(['target' => 'Choose two different versions to compare.']);
        }

        // A diff over two long agreements is expensive on the first request and
        // free afterwards; the limit is what stops a loop over version pairs
        // from paying that cost repeatedly.
        $this->rateLimit('documents.compare', 60, 300);

        $this->run(fn () => (new ContractService($this->db()))->findOrFail($ctx, $contractId));
        $this->requireVersion($ctx, $base, $contractId);
        $this->requireVersion($ctx, $target, $contractId);

        $this->respond(fn () => (new DiffService($this->db()))->compareVersions($ctx, $base, $target));
    }

    public function createUploadSession(): void
    {
        $ctx = $this->requirePermission(Permissions::DOCUMENT_UPLOAD);

        // A session reserves a row and, on Drive, an object. Both are reaped
        // when abandoned, but not cheaply enough to let a loop create them.
        $this->rateLimit('uploads.session', 60, 300);

        $this->respond(
            fn () => $this->documents()->createUploadSession($ctx, $this->uploadIntent($this->body())),
            201
        );
    }

    public function completeUpload(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::DOCUMENT_UPLOAD);
        $sessionId = $this->intId($id);

        $this->respond(fn () => $this->documents()->completeUpload($ctx, $sessionId));
    }

    public function finalizeUpload(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::DOCUMENT_UPLOAD);
        $sessionId = $this->intId($id);

        $this->respond(
            fn () => $this->documents()->finalizeUpload($ctx, $sessionId, $this->finalizeIntent($this->body())),
            201
        );
    }

    public function abortUpload(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::DOCUMENT_UPLOAD);
        $sessionId = $this->intId($id);

        $this->run(function () use ($ctx, $sessionId): bool {
            $this->documents()->abortUpload($ctx, $sessionId);

            return true;
        });

        Response::success(['status' => 'aborted']);
    }

    /**
     * Multipart upload for the local-storage fallback.
     *
     * Only reachable on a deployment that has not provisioned Drive: everywhere
     * else the bytes go straight to storage and this process never holds a
     * customer's contract in a temp file. Everything the browser claims about
     * the file — its name, its type, its size — is checked or replaced before
     * the file is moved anywhere.
     */
    public function directUpload(): void
    {
        $ctx = $this->requirePermission(Permissions::DOCUMENT_UPLOAD);

        if (! Env::bool('CONTRACTS_ALLOW_LOCAL_STORAGE')) {
            Response::error(
                'INTEGRATION_UNAVAILABLE',
                'Document storage is not configured on this deployment.',
                503
            );
        }

        $this->rateLimit('uploads.direct', 30, 300);

        $file = $this->uploadedFile();

        // A multipart request carries its fields in $_POST; Request parses only
        // JSON, and a browser cannot send both in one request.
        $form = self::formFields();

        $this->respond(function () use ($ctx, $file, $form): array {
            // The real byte count and the sanitised name win over whatever the
            // form claimed: a declared size is a suggestion, and a filename is
            // attacker-controlled text.
            $intent = $this->uploadIntent(array_merge($form, [
                'filename'     => $file['name'],
                'content_type' => $file['type'],
                'size_bytes'   => $file['size'],
            ]));

            $documents = $this->documents();
            $session   = $documents->createUploadSession($ctx, $intent);
            $sessionId = (int) ($session['session_id'] ?? $session['id'] ?? 0);

            if ($sessionId < 1) {
                throw DomainException::unavailable('The upload could not be started.');
            }

            try {
                $this->storeLocally($ctx, $sessionId, $session, $file['tmp_name']);
                $documents->completeUpload($ctx, $sessionId);

                return $documents->finalizeUpload($ctx, $sessionId, $this->finalizeIntent($form));
            } catch (Throwable $e) {
                // A half-finished direct upload otherwise leaves a pending
                // session and a file nothing points at. Aborting hands both to
                // the reaper instead of to a person reading the table by hand.
                try {
                    $documents->abortUpload($ctx, $sessionId);
                } catch (Throwable $ignored) {
                    error_log('[contracts] abort after failed direct upload: ' . $ignored->getMessage());
                }

                throw $e;
            }
        }, 201);
    }

    /** Registers a file already living in Drive as a document on this contract. */
    public function linkDriveFile(): void
    {
        $ctx = $this->requirePermission(Permissions::DOCUMENT_UPLOAD);

        $this->respond(function () use ($ctx): array {
            $v    = new Validator($this->body());
            $link = [
                'contract_id'       => $v->optionalId('contract_id'),
                'drive_document_id' => $v->requiredString('drive_document_id', 64),
                'title'             => $v->requiredString('title', 255),
                'doc_kind'          => $v->optionalEnum('doc_kind', self::DOC_KINDS, 'contract') ?? 'contract',
                'description'       => $v->optionalText('description', 4000),
            ];

            if ($link['contract_id'] === null) {
                $v->fail('contract_id', 'Name the contract this document belongs to.');
            }
            $v->assert();

            return $this->documents()->linkExistingDriveFile($ctx, $link);
        }, 201);
    }

    public function versionUrl(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::DOCUMENT_DOWNLOAD);
        $versionId = $this->intId($id);

        // A signed URL is a bearer capability with a life of its own once
        // issued, so minting them is budgeted apart from reading metadata.
        $this->rateLimit('versions.url', 120, 300);

        $this->requireVersion($ctx, $versionId);

        $inline = in_array(strtolower((string) (Request::query('inline') ?? '')), ['1', 'true', 'yes'], true);

        $this->respond(fn () => $this->documents()->signedUrl($ctx, $versionId, $inline));
    }

    public function versionText(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::DOCUMENT_DOWNLOAD);
        $versionId = $this->intId($id);

        // The first call parses the whole document, which is far more work than
        // the size of the response suggests.
        $this->rateLimit('versions.text', 30, 300);

        $this->requireVersion($ctx, $versionId);

        $this->respond(fn () => (new TextExtractionService($this->db()))->extract($ctx, $versionId));
    }

    public function markExecuted(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::DOCUMENT_UPLOAD);
        $versionId = $this->intId($id);

        $this->requireVersion($ctx, $versionId);

        $this->respond(fn () => ['version' => $this->documents()->setExecutedCopy($ctx, $versionId)]);
    }

    public function destroyVersion(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::CONTRACT_EDIT);
        $versionId = $this->intId($id);

        $this->run(function () use ($ctx, $versionId): bool {
            $this->documents()->deleteDraftVersion($ctx, $versionId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    /**
     * The version, or a 404 that says nothing about why.
     *
     * Belonging to this tenant is not on its own enough when the id arrived
     * through a contract-scoped route: two unrelated agreements put side by
     * side is a leak between deals inside one company.
     *
     * @return array<string,mixed>
     */
    private function requireVersion(TenantContext $ctx, int $versionId, ?int $contractId = null): array
    {
        $version = $this->run(fn () => $this->documents()->findVersion($ctx, $versionId));

        if (! is_array($version)) {
            Response::notFound('Document version not found.');
        }

        if ($contractId !== null && isset($version['contract_id']) && (int) $version['contract_id'] !== $contractId) {
            Response::notFound('Document version not found.');
        }

        return $version;
    }

    /**
     * The parts of an upload request this server is willing to believe.
     *
     * Size and type are settled here rather than at storage time because on the
     * Drive path the bytes never reach this process — this is the last moment
     * at which the cap and the allow-list can still be applied at all.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function uploadIntent(array $body): array
    {
        $v        = new Validator($body);
        $filename = $v->requiredString('filename', 255);
        $type     = strtolower($v->requiredString('content_type', 128));
        $size     = $v->optionalInt('size_bytes', 0, null, 0) ?? 0;

        $extension = self::extensionOf($filename);
        if ($extension === null || ! isset(self::ALLOWED_UPLOADS[$extension])) {
            $v->fail('filename', 'Upload one of: ' . implode(', ', array_keys(self::ALLOWED_UPLOADS)) . '.');
        } elseif (! in_array($type, self::ALLOWED_UPLOADS[$extension], true)) {
            // A mismatch is how a script arrives named `agreement.pdf`. Refusing
            // the pair costs an honest caller a corrected header and costs a
            // dishonest one the trick entirely.
            $v->fail('content_type', 'The file type does not match the file extension.');
        }

        if ($size > self::maxUploadBytes()) {
            $v->fail('size_bytes', 'Files must be under ' . self::maxUploadMb() . ' MB.');
        }

        $contractId = $v->optionalId('contract_id');
        $requestId  = $v->optionalId('request_id');
        if ($contractId === null && $requestId === null) {
            $v->fail('contract_id', 'Name the contract or request this document belongs to.');
        }

        $intent = [
            'filename'       => self::safeFilename($filename),
            'content_type'   => $type,
            'size_bytes'     => $size,
            'doc_kind'       => $v->optionalEnum('doc_kind', self::DOC_KINDS, 'contract') ?? 'contract',
            'version_status' => $v->optionalEnum('version_status', Enums::DOCUMENT_VERSION_STATUSES, 'internal_draft')
                ?? 'internal_draft',
            'contract_id'    => $contractId,
            'request_id'     => $requestId,
            'document_id'    => $v->optionalId('document_id'),
        ];

        $v->assert();

        return $intent;
    }

    /**
     * What finalizing may change about the version being sealed.
     *
     * A key the caller did not send is left out rather than passed as null: the
     * columns behind these are NOT NULL with defaults the service picks, and an
     * explicit null would overwrite a choice made when the session opened.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function finalizeIntent(array $body): array
    {
        $v      = new Validator($body);
        $intent = [];

        if ($v->has('version_status')) {
            $intent['version_status'] = $v->requiredEnum('version_status', Enums::DOCUMENT_VERSION_STATUSES);
        }

        $notes = $v->optionalText('notes', 4000);
        if ($notes !== null) {
            $intent['notes'] = $notes;
        }

        $v->assert();

        return $intent;
    }

    /**
     * Move the uploaded temp file to the location the session allocated.
     *
     * The destination comes from the session row and never from the request:
     * `$_FILES['name']` is text the caller chose, and letting it reach a path is
     * how an upload becomes a write into the document root.
     *
     * @param array<string,mixed> $session
     */
    private function storeLocally(TenantContext $ctx, int $sessionId, array $session, string $tmpPath): void
    {
        $provider = is_string($session['storage_provider'] ?? null) ? $session['storage_provider'] : '';
        $path     = is_string($session['local_path'] ?? null) ? $session['local_path'] : '';

        if ($provider === '' || $path === '') {
            $st = $this->db()->prepare(
                'SELECT storage_provider, local_path FROM contract_upload_sessions
                 WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
            );
            $st->execute([$sessionId, $ctx->environment, $ctx->cmpId]);
            $row = $st->fetch();

            if (! is_array($row)) {
                throw DomainException::notFound('Upload session not found.');
            }

            $provider = (string) ($row['storage_provider'] ?? '');
            $path     = (string) ($row['local_path'] ?? '');
        }

        if ($provider !== 'local') {
            // Drive is the active adapter, so the bytes have a better home and
            // a caller posting them here is using the wrong flow.
            throw DomainException::conflict('This deployment uploads documents to storage directly. Use an upload session.');
        }
        if ($path === '') {
            throw DomainException::unavailable('Local document storage is enabled but no location was allocated.');
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw DomainException::unavailable('The local document store is not writable.');
        }

        // is_uploaded_file is what separates a real multipart temp file from a
        // path someone talked another part of the request into producing.
        if (! is_uploaded_file($tmpPath) || ! move_uploaded_file($tmpPath, $path)) {
            throw DomainException::unavailable('The upload could not be stored.');
        }
    }

    /**
     * The single uploaded part, with PHP's own failure modes translated.
     *
     * @return array{name: string, type: string, size: int, tmp_name: string}
     */
    private function uploadedFile(): array
    {
        $file = $_FILES['file'] ?? null;
        if (! is_array($file) || ! isset($file['tmp_name']) || ! is_string($file['tmp_name'])) {
            Response::validationError(['file' => 'Attach a file.']);
        }

        $error = isset($file['error']) && is_int($file['error']) ? $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            // PHP dropped the body before any of this ran, so the cap below
            // never saw the file; the answer still has to name the limit.
            Response::validationError(['file' => 'Files must be under ' . self::maxUploadMb() . ' MB.']);
        }
        if ($error !== UPLOAD_ERR_OK) {
            Response::validationError(['file' => 'That file did not upload completely. Try again.']);
        }

        // stat, not read: the size that matters is the one on disk, and it is
        // known before anything opens the file.
        $size = is_file($file['tmp_name']) ? (int) filesize($file['tmp_name']) : 0;
        if ($size < 1) {
            Response::validationError(['file' => 'That file is empty.']);
        }

        return [
            'name'     => is_string($file['name'] ?? null) ? $file['name'] : '',
            'type'     => is_string($file['type'] ?? null) ? $file['type'] : '',
            'size'     => $size,
            'tmp_name' => $file['tmp_name'],
        ];
    }

    /**
     * The multipart fields this endpoint reads, and no others.
     *
     * @return array<string,string>
     */
    private static function formFields(): array
    {
        $out = [];
        foreach (['contract_id', 'request_id', 'document_id', 'doc_kind', 'version_status', 'notes'] as $key) {
            $value = $_POST[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    private static function versionIdFromQuery(string $key): ?int
    {
        $raw = Request::query($key);
        if ($raw === null || ! preg_match('/^\d{1,19}$/', $raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    /**
     * A filename safe to store and to echo back.
     *
     * Path separators, control characters and a leading dot are removed rather
     * than escaped: nothing downstream needs them, and each one is a way for a
     * name to mean something other than a name.
     */
    private static function safeFilename(string $name): string
    {
        $name = str_replace(["\0", '\\'], '', $name);
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9 ._-]+/u', '_', $name) ?? '';
        $name = ltrim(preg_replace('/\s+/', ' ', $name) ?? '', '. ');
        $name = trim($name);

        if ($name === '') {
            return 'document';
        }

        return mb_substr($name, 0, 255);
    }

    private static function extensionOf(string $filename): ?string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension === '' ? null : $extension;
    }

    /**
     * The configured cap, clamped.
     *
     * A blank or absurd value in .env would otherwise read as "no limit", and
     * the cap is the only thing standing between an upload form and the disk.
     */
    private static function maxUploadMb(): int
    {
        return max(1, min(Env::int('CONTRACTS_MAX_UPLOAD_MB', 25), 200));
    }

    private static function maxUploadBytes(): int
    {
        return self::maxUploadMb() * 1024 * 1024;
    }

    private function documents(): DocumentService
    {
        return new DocumentService($this->db());
    }
}
