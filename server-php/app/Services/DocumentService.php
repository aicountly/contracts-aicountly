<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Modules\Drive\DriveClient;
use App\Modules\Drive\DriveStorageAdapter;
use App\Modules\Drive\LocalStorageAdapter;
use App\Modules\Drive\StorageAdapter;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use App\Support\ValidationFailed;
use App\Support\Validator;
use PDO;
use Throwable;

/**
 * Contract documents and their version history.
 *
 * The bytes are somebody else's problem — see StorageAdapter. What lives here
 * is the part a contract system cannot get wrong: which document belongs to
 * which contract, which version came after which, what was executed, and who
 * did all of that. A version is never overwritten and a version number is
 * never reused, because the answer to "what did we send them on the 4th" has to
 * survive every later upload, and a renumbered history answers it wrongly
 * rather than not at all.
 *
 * Every query filters `environment` and `cmp_id` from the TenantContext.
 * Versions carry both columns of their own, so a version lookup never has to
 * trust its document to be in the right tenant.
 */
final class DocumentService
{
    /** Mirrors ck_contract_documents_kind. */
    public const DOC_KINDS = [
        'contract', 'annexure', 'schedule', 'amendment', 'evidence', 'executed_copy',
        'correspondence', 'request_attachment', 'template', 'other',
    ];

    /** Where a version came from. Free text in the schema; a fixed list here. */
    public const VERSION_SOURCES = ['internal', 'counterparty', 'imported', 'signature_provider'];

    /**
     * SMALLINT ceiling on `version_no`.
     *
     * A document reaching this has a problem no version number can fix, and
     * silently overflowing into a constraint violation would report it as a
     * database error rather than as what it is.
     */
    private const MAX_VERSION_NO = 32767;

    /** How long an unfinished upload session stays usable. */
    private const SESSION_TTL_SECONDS = 7200;

    private AuditService $audit;

    private ActivityService $activity;

    private ContractService $contracts;

    private ?StorageAdapter $storage;

    public function __construct(private PDO $pdo, ?StorageAdapter $storage = null)
    {
        $this->audit     = new AuditService($pdo);
        $this->activity  = new ActivityService($pdo);
        $this->contracts = new ContractService($pdo);
        $this->storage   = $storage;
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    /**
     * The storage backend this deployment is using.
     *
     * Drive when it is configured, the local fallback when it is deliberately
     * enabled, and an error otherwise — never a silent no-op, because an
     * upload that appears to succeed and stores nothing is the worst of the
     * three outcomes.
     */
    public function storage(): StorageAdapter
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        $drive = DriveStorageAdapter::make();
        if ($drive !== null && $drive->isConfigured()) {
            return $this->storage = $drive;
        }

        $local = LocalStorageAdapter::make();
        if ($local !== null && $local->isConfigured()) {
            return $this->storage = $local;
        }

        throw DomainException::unavailable(
            'Document storage is not configured. Set DRIVE_API_BASE in api/.env.',
            'STORAGE_NOT_CONFIGURED'
        );
    }

    // -----------------------------------------------------------------------
    // Upload: session
    // -----------------------------------------------------------------------

    /**
     * Reserve a place for a file that has not been uploaded yet.
     *
     * The row is written before the bytes exist so that an abandoned upload
     * leaves something reapable here rather than an orphaned object in Drive
     * that nothing remembers asking for.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed> the descriptor the browser uploads with
     */
    public function createUploadSession(TenantContext $ctx, array $body): array
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_UPLOAD);

        $v            = new Validator($body);
        $rawFilename  = $v->requiredString('filename', 255);
        $contentType  = strtolower($v->requiredString('content_type', 128));
        $size         = $v->optionalInt('size_bytes', 1);
        $docKind      = $v->optionalEnum('doc_kind', self::DOC_KINDS, 'contract') ?? 'contract';
        $status       = $v->optionalEnum('version_status', Enums::DOCUMENT_VERSION_STATUSES, 'internal_draft') ?? 'internal_draft';
        $contractId   = $v->optionalId('contract_id');
        $requestId    = $v->optionalId('request_id');
        $amendmentId  = $v->optionalId('amendment_id');
        $documentId   = $v->optionalId('document_id');

        if ($size === null) {
            $v->fail('size_bytes', 'Tell us how big the file is.');
        }
        $v->assert();

        $filename  = self::sanitiseFilename($rawFilename);
        $extension = self::extensionOf($filename);
        self::assertAcceptable($filename, $contentType, (int) $size);

        // A new version of an existing document inherits that document's owner
        // and kind. Letting the caller re-state them would let version 2 of a
        // contract's executed copy be filed against a different contract.
        if ($documentId !== null) {
            $document    = $this->findDocumentOrFail($ctx, $documentId);
            $contractId  = $document['contract_id'] !== null ? (int) $document['contract_id'] : null;
            $requestId   = $document['request_id'] !== null ? (int) $document['request_id'] : null;
            $amendmentId = $document['amendment_id'] !== null ? (int) $document['amendment_id'] : null;
            $docKind     = (string) $document['doc_kind'];
        }

        [$entityType, $entityId] = $this->resolveOwner($ctx, $contractId, $requestId, $amendmentId);

        $storage     = $this->storage();
        $storageName = self::storageNameFor($extension);

        $reservation = $storage->createUpload($ctx, [
            'filename'     => $filename,
            'storage_name' => $storageName,
            'content_type' => $contentType,
            'size_bytes'   => (int) $size,
            'doc_kind'     => $docKind,
            'module_code'  => DriveClient::moduleCodeFor($docKind),
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
        ]);

        $st = $this->pdo->prepare(
            'INSERT INTO contract_upload_sessions
             (session_token, environment, cmp_id, bo_id, fy_id, created_by,
              contract_id, request_id, document_id, doc_kind, version_status,
              filename, content_type, declared_size, status, storage_provider,
              drive_session_id, upload_url, local_path, expires_at)
             VALUES (:token, :env, :cmp, :bo, :fy, :actor,
                     :contract, :request, :document, :kind, :vstatus,
                     :filename, :ctype, :size, \'pending\', :provider,
                     :drive_session, :upload_url, :local_path, :expires)
             RETURNING id'
        );
        $st->execute([
            'token'         => bin2hex(random_bytes(16)),
            'env'           => $ctx->environment,
            'cmp'           => $ctx->cmpId,
            'bo'            => $ctx->boId,
            'fy'            => $ctx->fyId,
            'actor'         => $ctx->uuid,
            'contract'      => $contractId,
            'request'       => $requestId,
            'document'      => $documentId,
            'kind'          => $docKind,
            'vstatus'       => $status,
            'filename'      => $filename,
            'ctype'         => $contentType,
            'size'          => (int) $size,
            'provider'      => $storage->name(),
            'drive_session' => $reservation['session_ref'],
            'upload_url'    => $reservation['upload_url'],
            'local_path'    => $reservation['local_path'],
            'expires'       => $this->expiryFrom($reservation['expires_at']),
        ]);

        $sessionId = (int) $st->fetchColumn();

        // An adapter with no presigned destination (local storage) leaves this
        // null and the client sends the bytes to us instead.
        $uploadUrl = $reservation['upload_url'];
        if ($uploadUrl === null) {
            $uploadUrl = '/api/uploads/sessions/' . $sessionId . '/complete';
            $this->pdo->prepare('UPDATE contract_upload_sessions SET upload_url = ? WHERE id = ?')
                ->execute([$uploadUrl, $sessionId]);
        }

        return [
            'session_id'   => $sessionId,
            'upload_url'   => $uploadUrl,
            'method'       => $reservation['method'],
            'headers'      => $reservation['headers'],
            'expires_at'   => $reservation['expires_at'],
            'provider'     => $storage->name(),
            'filename'     => $filename,
            'content_type' => $contentType,
            'doc_kind'     => $docKind,
            'contract_id'  => $contractId,
            'request_id'   => $requestId,
            // Echoed back because the session table has nowhere to keep it; the
            // client must return it at finalize for the document to be filed
            // against the amendment. See the note on finalizeUpload().
            'amendment_id' => $amendmentId,
            'document_id'  => $documentId,
        ];
    }

    /**
     * The bytes have arrived.
     *
     * `$bytes` is present only when the client could not upload directly to
     * storage and sent the file through this API instead — which is always the
     * case on the local fallback, and occasionally the case behind a proxy that
     * blocks cross-origin PUTs.
     *
     * @return array<string,mixed>
     */
    public function completeUpload(TenantContext $ctx, int $sessionId, ?string $bytes = null): array
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_UPLOAD);

        $session = $this->sessionOrFail($ctx, $sessionId);
        $this->assertSessionOpen($session);

        $result = $this->storage()->completeUpload($ctx, $session, $bytes);

        $this->pdo->prepare(
            'UPDATE contract_upload_sessions
             SET status = \'uploaded\', declared_size = COALESCE(?, declared_size), completed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$result['size_bytes'], $sessionId, $ctx->environment, $ctx->cmpId]);

        return [
            'session_id' => $sessionId,
            'status'     => 'uploaded',
            'size_bytes' => $result['size_bytes'] ?? (int) $session['declared_size'],
            'checksum'   => $result['checksum'],
        ];
    }

    /**
     * Turn a completed upload into a document version.
     *
     * The storage call happens before the transaction opens, deliberately: it
     * is a network round trip, and holding a row lock across one is how a busy
     * contract ends up serialised behind somebody else's slow upload. The cost
     * is that a database failure after it leaves a file in Drive that nothing
     * references — recoverable, and far better than a version row pointing at
     * bytes that were never promoted out of quarantine.
     *
     * `amendment_id` is read from the body here rather than from the session
     * because `contract_upload_sessions` has no column for it; see the note in
     * the report accompanying this service.
     *
     * @param array<string,mixed> $body
     * @return array{document: array<string,mixed>, version: array<string,mixed>}
     */
    public function finalizeUpload(TenantContext $ctx, int $sessionId, array $body): array
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_UPLOAD);

        $session = $this->sessionOrFail($ctx, $sessionId);
        $this->assertSessionOpen($session);

        $v             = new Validator($body);
        $title         = $v->optionalString('title', 255);
        $notes         = $v->optionalText('notes', 20000);
        $versionStatus = $v->optionalEnum('version_status', Enums::DOCUMENT_VERSION_STATUSES, (string) $session['version_status'])
            ?? 'internal_draft';
        $source        = $v->optionalEnum('source', self::VERSION_SOURCES, 'internal') ?? 'internal';
        $size          = $v->optionalInt('size_bytes', 1, null, (int) $session['declared_size']);
        $declaredHash  = $v->optionalString('sha256', 64);
        $amendmentId   = $v->optionalId('amendment_id');

        if ($declaredHash !== null && preg_match('/^[0-9a-f]{64}$/i', $declaredHash) !== 1) {
            $v->fail('sha256', 'A SHA-256 checksum is 64 hexadecimal characters.');
        }
        $v->assert();

        $filename = (string) $session['filename'];
        $title    = $title ?? self::titleFrom($filename);

        $contractId = $session['contract_id'] !== null ? (int) $session['contract_id'] : null;
        $requestId  = $session['request_id'] !== null ? (int) $session['request_id'] : null;
        $documentId = $session['document_id'] !== null ? (int) $session['document_id'] : null;

        if ($documentId !== null) {
            // A further version of an existing document is filed exactly where
            // that document already is; the body has no say in it.
            $document    = $this->findDocumentOrFail($ctx, $documentId);
            $contractId  = $document['contract_id'] !== null ? (int) $document['contract_id'] : null;
            $requestId   = $document['request_id'] !== null ? (int) $document['request_id'] : null;
            $amendmentId = $document['amendment_id'] !== null ? (int) $document['amendment_id'] : null;
        } else {
            $amendmentId = $this->resolveAmendment($ctx, $amendmentId, $contractId);
        }

        [$entityType, $entityId] = $this->resolveOwner($ctx, $contractId, $requestId, $amendmentId);

        $stored = $this->storage()->finalizeUpload($ctx, $session, [
            'title'       => $title,
            'size_bytes'  => (int) $size,
            'checksum'    => $declaredHash === null ? null : strtolower($declaredHash),
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
        ]);

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $session,
            $sessionId,
            $stored,
            $documentId,
            $contractId,
            $requestId,
            $amendmentId,
            $filename,
            $title,
            $notes,
            $versionStatus,
            $source
        ): array {
            $document = $documentId === null
                ? $this->insertDocument($ctx, $contractId, $requestId, $amendmentId, (string) $session['doc_kind'], $title)
                : $this->lockDocument($ctx, $documentId);

            $versionNo = $this->nextVersionNo($ctx, (int) $document['id']);
            $executed  = $versionStatus === 'executed';

            $st = $pdo->prepare(
                'INSERT INTO contract_document_versions
                 (document_id, environment, cmp_id, version_no, version_status, source, notes,
                  filename, content_type, size_bytes, checksum_sha256, storage_provider,
                  drive_document_id, drive_version_ref, local_path, is_executed, uploaded_by)
                 VALUES (:doc, :env, :cmp, :no, :vstatus, :source, :notes,
                         :filename, :ctype, :size, :checksum, :provider,
                         :drive_doc, :drive_ref, :local, :executed, :actor)
                 RETURNING id'
            );
            $st->execute([
                'doc'       => (int) $document['id'],
                'env'       => $ctx->environment,
                'cmp'       => $ctx->cmpId,
                'no'        => $versionNo,
                'vstatus'   => $versionStatus,
                'source'    => $source,
                'notes'     => $notes,
                'filename'  => $filename,
                'ctype'     => (string) $session['content_type'],
                'size'      => $stored['size_bytes'],
                'checksum'  => $stored['checksum'],
                'provider'  => (string) $session['storage_provider'],
                'drive_doc' => $stored['drive_document_id'],
                'drive_ref' => $stored['drive_version_ref'],
                'local'     => $stored['local_path'],
                'executed'  => $executed ? 'true' : 'false',
                'actor'     => $ctx->uuid,
            ]);
            $versionId = (int) $st->fetchColumn();

            $this->supersedePrevious($ctx, (int) $document['id'], $versionId);

            $pdo->prepare(
                'UPDATE contract_documents
                 SET current_version_id = ?,
                     version_count = version_count + 1,
                     is_executed_copy = is_executed_copy OR ?,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$versionId, $executed ? 'true' : 'false', (int) $document['id'], $ctx->environment, $ctx->cmpId]);

            $pdo->prepare(
                'UPDATE contract_upload_sessions
                 SET status = \'finalized\', document_id = ?, drive_document_id = ?,
                     completed_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([(int) $document['id'], $stored['drive_document_id'], $sessionId, $ctx->environment, $ctx->cmpId]);

            $this->audit->log($ctx, 'document_version', $versionId, 'document.version_uploaded', $contractId, [
                'document_id' => ['from' => null, 'to' => (int) $document['id']],
                'version_no'  => ['from' => null, 'to' => $versionNo],
                'filename'    => ['from' => null, 'to' => $filename],
                'checksum'    => ['from' => null, 'to' => $stored['checksum']],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'document.version_uploaded',
                sprintf('%s uploaded as version %d', $title, $versionNo),
                ['document_id' => (int) $document['id'], 'version_id' => $versionId, 'version_no' => $versionNo],
                null,
                $requestId
            );

            $this->queueTextExtraction($ctx, $versionId);

            $version = $this->findVersion($ctx, $versionId);

            return [
                'document' => $this->findDocumentOrFail($ctx, (int) $document['id']),
                'version'  => $version ?? [],
            ];
        });
    }

    /**
     * Give up on a session.
     *
     * Best effort against storage: the session is marked aborted either way,
     * because a row stuck at `pending` forever is worse than a quarantine
     * object that Drive's own lifecycle rules will reap.
     */
    public function abort(TenantContext $ctx, int $sessionId): void
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_UPLOAD);

        $session = $this->sessionOrFail($ctx, $sessionId);

        if ((string) $session['status'] === 'finalized') {
            throw DomainException::conflict('That upload has already been filed and cannot be abandoned.');
        }

        $driveSession = (string) ($session['drive_session_id'] ?? '');
        if ($driveSession !== '') {
            try {
                $client = DriveClient::make();
                $client?->abortUpload($ctx, $driveSession);
            } catch (Throwable $e) {
                error_log('[contracts][documents] abort in Drive failed: ' . $e->getMessage());
            }
        }

        $this->pdo->prepare(
            'UPDATE contract_upload_sessions SET status = \'aborted\'
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$sessionId, $ctx->environment, $ctx->cmpId]);
    }

    // -----------------------------------------------------------------------
    // Linking a file that is already in Drive
    // -----------------------------------------------------------------------

    /**
     * Record a Drive document that already exists as a contract document.
     *
     * The path for a file someone uploaded to Drive first and is now attaching
     * to a contract. Nothing is copied: the same object gains a second link,
     * so the two products do not end up with divergent copies of the executed
     * agreement.
     *
     * @param array<string,mixed> $body
     * @return array{document: array<string,mixed>, version: array<string,mixed>}
     */
    public function linkExistingDriveFile(TenantContext $ctx, array $body): array
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_UPLOAD);

        $v           = new Validator($body);
        $driveDocId  = $v->requiredString('drive_document_id', 64);
        $rawFilename = $v->requiredString('filename', 255);
        $contentType = strtolower($v->requiredString('content_type', 128));
        $title       = $v->optionalString('title', 255);
        $notes       = $v->optionalText('notes', 20000);
        $size        = $v->optionalInt('size_bytes', 0, null, 0) ?? 0;
        $docKind     = $v->optionalEnum('doc_kind', self::DOC_KINDS, 'contract') ?? 'contract';
        $status      = $v->optionalEnum('version_status', Enums::DOCUMENT_VERSION_STATUSES, 'internal_draft') ?? 'internal_draft';
        $source      = $v->optionalEnum('source', self::VERSION_SOURCES, 'imported') ?? 'imported';
        $contractId  = $v->optionalId('contract_id');
        $requestId   = $v->optionalId('request_id');
        $amendmentId = $v->optionalId('amendment_id');
        $documentId  = $v->optionalId('document_id');
        $v->assert();

        $filename = self::sanitiseFilename($rawFilename);
        self::assertAcceptable($filename, $contentType, $size);

        if ($documentId !== null) {
            $document    = $this->findDocumentOrFail($ctx, $documentId);
            $contractId  = $document['contract_id'] !== null ? (int) $document['contract_id'] : null;
            $requestId   = $document['request_id'] !== null ? (int) $document['request_id'] : null;
            $amendmentId = $document['amendment_id'] !== null ? (int) $document['amendment_id'] : null;
            $docKind     = (string) $document['doc_kind'];
        }

        [$entityType, $entityId] = $this->resolveOwner($ctx, $contractId, $requestId, $amendmentId);

        $client = DriveClient::make();
        if ($client === null) {
            throw DomainException::unavailable('Drive is not configured, so an existing Drive file cannot be attached.');
        }

        // Drive re-checks the caller's access to the document before creating
        // the link, so this doubles as the authorisation check: a user cannot
        // attach a file they are not allowed to read.
        $link = $client->linkDocument($ctx, $driveDocId, $entityType, $entityId);

        return Database::transaction($this->pdo, function (PDO $pdo) use (
            $ctx,
            $documentId,
            $contractId,
            $requestId,
            $amendmentId,
            $docKind,
            $driveDocId,
            $filename,
            $contentType,
            $size,
            $title,
            $notes,
            $status,
            $source,
            $link
        ): array {
            $resolvedTitle = $title ?? self::titleFrom($filename);

            $document = $documentId === null
                ? $this->insertDocument($ctx, $contractId, $requestId, $amendmentId, $docKind, $resolvedTitle)
                : $this->lockDocument($ctx, $documentId);

            $versionNo = $this->nextVersionNo($ctx, (int) $document['id']);
            $executed  = $status === 'executed';

            $st = $pdo->prepare(
                'INSERT INTO contract_document_versions
                 (document_id, environment, cmp_id, version_no, version_status, source, notes,
                  filename, content_type, size_bytes, storage_provider, drive_document_id,
                  is_executed, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'drive\', ?, ?, ?)
                 RETURNING id'
            );
            $st->execute([
                (int) $document['id'],
                $ctx->environment,
                $ctx->cmpId,
                $versionNo,
                $status,
                $source,
                $notes,
                $filename,
                $contentType,
                $size,
                $driveDocId,
                $executed ? 'true' : 'false',
                $ctx->uuid,
            ]);
            $versionId = (int) $st->fetchColumn();

            $this->supersedePrevious($ctx, (int) $document['id'], $versionId);

            $pdo->prepare(
                'UPDATE contract_documents
                 SET current_version_id = ?, version_count = version_count + 1,
                     is_executed_copy = is_executed_copy OR ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$versionId, $executed ? 'true' : 'false', (int) $document['id'], $ctx->environment, $ctx->cmpId]);

            $this->audit->log($ctx, 'document_version', $versionId, 'document.drive_file_linked', $contractId, [
                'drive_document_id' => ['from' => null, 'to' => $driveDocId],
                'link_id'           => ['from' => null, 'to' => $link['link_id'] ?? null],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'document.linked',
                sprintf('%s attached from Drive', $resolvedTitle),
                ['document_id' => (int) $document['id'], 'version_id' => $versionId],
                null,
                $requestId
            );

            $this->queueTextExtraction($ctx, $versionId);

            return [
                'document' => $this->findDocumentOrFail($ctx, (int) $document['id']),
                'version'  => $this->findVersion($ctx, $versionId) ?? [],
            ];
        });
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /**
     * Every document filed against a contract, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function listForContract(TenantContext $ctx, int $contractId, array $filters = []): array
    {
        // Through ContractService so that document visibility follows contract
        // visibility: a user who cannot open the contract cannot list its
        // documents by knowing its id.
        $this->contracts->findOrFail($ctx, $contractId);

        $sql = 'SELECT d.*, v.version_no AS current_version_no, v.filename AS current_filename,
                       v.content_type AS current_content_type, v.size_bytes AS current_size_bytes,
                       v.version_status AS current_version_status, v.checksum_sha256 AS current_checksum,
                       v.is_scanned AS current_is_scanned, v.created_at AS current_uploaded_at,
                       v.uploaded_by AS current_uploaded_by
                FROM contract_documents d
                LEFT JOIN contract_document_versions v ON v.id = d.current_version_id
                WHERE d.environment = :env AND d.cmp_id = :cmp AND d.contract_id = :contract';

        $params = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId, 'contract' => $contractId];

        if (! empty($filters['doc_kind']) && Enums::isValid($filters['doc_kind'], self::DOC_KINDS)) {
            $sql .= ' AND d.doc_kind = :kind';
            $params['kind'] = (string) $filters['doc_kind'];
        }
        if (! empty($filters['executed_only'])) {
            $sql .= ' AND d.is_executed_copy = TRUE';
        }

        $sql .= ' ORDER BY d.updated_at DESC, d.id DESC';

        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return array_map(fn (array $r): array => $this->hydrateDocument($r), $st->fetchAll() ?: []);
    }

    /** @return array<string,mixed>|null */
    public function findDocument(TenantContext $ctx, int $documentId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_documents
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$documentId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrateDocument($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findDocumentOrFail(TenantContext $ctx, int $documentId): array
    {
        $row = $this->findDocument($ctx, $documentId);
        if ($row === null) {
            throw DomainException::notFound('Document not found.');
        }

        return $row;
    }

    /**
     * The full version history of a document, oldest first.
     *
     * `extracted_text` is left out: it can be megabytes per row and no caller
     * listing a history wants it. `GET /api/versions/{id}/text` is the way to
     * ask for it.
     *
     * @return list<array<string,mixed>>
     */
    public function versions(TenantContext $ctx, int $documentId): array
    {
        $this->findDocumentOrFail($ctx, $documentId);

        $st = $this->pdo->prepare(
            'SELECT id, uuid, document_id, version_no, version_status, source, notes,
                    filename, content_type, size_bytes, checksum_sha256, storage_provider,
                    drive_document_id, drive_version_ref, extracted_pages, text_extracted_at,
                    is_scanned, is_executed, uploaded_by, created_at,
                    (extracted_text IS NOT NULL) AS has_text
             FROM contract_document_versions
             WHERE document_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY version_no ASC'
        );
        $st->execute([$documentId, $ctx->environment, $ctx->cmpId]);

        return array_map(fn (array $r): array => $this->hydrateVersion($r), $st->fetchAll() ?: []);
    }

    /**
     * One version, or null when it belongs to another tenant.
     *
     * Null rather than a distinguishable refusal, for the same reason
     * ContractService::find() returns null: a caller walking version ids must
     * not learn which ones exist elsewhere.
     *
     * @return array<string,mixed>|null
     */
    public function findVersion(TenantContext $ctx, int $versionId): ?array
    {
        $row = $this->rawVersion($ctx, $versionId);

        return $row === null ? null : $this->hydrateVersion($row);
    }

    /**
     * The version row as stored, including where the bytes are.
     *
     * Kept private and separate from findVersion(): `local_path` is a fact
     * about this server's filesystem and has no business in an API response,
     * but every storage operation needs it.
     *
     * @return array<string,mixed>|null
     */
    private function rawVersion(TenantContext $ctx, int $versionId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT v.*, d.contract_id, d.request_id, d.amendment_id, d.doc_kind, d.title AS document_title
             FROM contract_document_versions v
             JOIN contract_documents d ON d.id = v.document_id
             WHERE v.id = ? AND v.environment = ? AND v.cmp_id = ? LIMIT 1'
        );
        $st->execute([$versionId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findVersionOrFail(TenantContext $ctx, int $versionId): array
    {
        $row = $this->findVersion($ctx, $versionId);
        if ($row === null) {
            throw DomainException::notFound('Document version not found.');
        }

        return $row;
    }

    /**
     * A link to open or download one version.
     *
     * @return array{url: string, expires_in: int, filename: string, mime_type: string}
     */
    public function signedUrl(TenantContext $ctx, int $versionId, bool $inline = true): array
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_DOWNLOAD);

        $version = $this->rawVersion($ctx, $versionId);
        if ($version === null) {
            throw DomainException::notFound('Document version not found.');
        }

        $signed = $this->adapterFor($version)->signedUrl($ctx, $version, $inline);

        // Audited rather than merely logged: "who had a copy of the signed
        // agreement, and when" is a question disputes actually ask.
        $this->audit->log(
            $ctx,
            'document_version',
            $versionId,
            $inline ? 'document.viewed' : 'document.downloaded',
            $version['contract_id'] === null ? null : (int) $version['contract_id']
        );

        return $signed;
    }

    /**
     * The stored bytes of a version, or null when storage cannot reach them.
     *
     * Takes an id rather than a row because the row it needs is the raw one,
     * which callers outside this class deliberately never see.
     */
    public function readVersionBytes(TenantContext $ctx, int $versionId): ?string
    {
        $version = $this->rawVersion($ctx, $versionId);
        if ($version === null) {
            throw DomainException::notFound('Document version not found.');
        }

        return $this->adapterFor($version)->readBytes($ctx, $version);
    }

    // -----------------------------------------------------------------------
    // Version state
    // -----------------------------------------------------------------------

    /**
     * Mark one version as the executed copy.
     *
     * Exactly one version of a document can be the executed one, so the others
     * are cleared in the same statement — two rows claiming to be the signed
     * agreement is a question no report can answer.
     *
     * There is no way back. An executed copy is evidence: if the wrong file was
     * marked, the right one is uploaded as a new version and marked instead,
     * and the audit trail shows both.
     *
     * @return array<string,mixed>
     */
    public function setExecutedCopy(TenantContext $ctx, int $versionId, ?string $note = null): array
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_UPLOAD);

        $version = $this->findVersionOrFail($ctx, $versionId);

        if ($version['is_executed'] === true) {
            return $version;
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $versionId, $version): array {
            $documentId = (int) $version['document_id'];

            $pdo->prepare(
                'UPDATE contract_document_versions
                 SET is_executed = FALSE
                 WHERE document_id = ? AND environment = ? AND cmp_id = ? AND id <> ?'
            )->execute([$documentId, $ctx->environment, $ctx->cmpId, $versionId]);

            $pdo->prepare(
                'UPDATE contract_document_versions
                 SET is_executed = TRUE, version_status = \'executed\'
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$versionId, $ctx->environment, $ctx->cmpId]);

            $pdo->prepare(
                'UPDATE contract_documents
                 SET is_executed_copy = TRUE, current_version_id = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$versionId, $documentId, $ctx->environment, $ctx->cmpId]);

            $contractId = $version['contract_id'] === null ? null : (int) $version['contract_id'];

            $this->audit->log($ctx, 'document_version', $versionId, 'document.marked_executed', $contractId, [
                'is_executed'    => ['from' => false, 'to' => true],
                'version_status' => ['from' => $version['version_status'], 'to' => 'executed'],
            ]);
            $this->activity->record(
                $ctx,
                $contractId,
                'document.executed',
                sprintf('Version %d marked as the executed copy', (int) $version['version_no']),
                array_filter(['note' => $note])
            );

            return $this->findVersionOrFail($ctx, $versionId);
        });
    }

    /**
     * Remove a draft version.
     *
     * Refused for anything executed. An executed copy is the evidence that the
     * agreement exists in the form the company believes it does, and no
     * housekeeping reason is good enough to delete it — the archive is how
     * documents leave the working set.
     *
     * The version number it used is not returned to the pool. `version_count`
     * is a high-water mark of numbers issued, not a live count, precisely so
     * that a deleted version 2 is never followed by a second, different
     * version 2 in an audit trail that already mentions the first.
     */
    public function deleteDraftVersion(TenantContext $ctx, int $versionId): void
    {
        $this->assertMay($ctx, Permissions::DOCUMENT_UPLOAD);

        $version = $this->rawVersion($ctx, $versionId);
        if ($version === null) {
            throw DomainException::notFound('Document version not found.');
        }

        if (ContractService::toBool($version['is_executed']) || (string) $version['version_status'] === 'executed') {
            throw DomainException::conflict(
                'An executed copy cannot be deleted. Upload a corrected version instead.',
                'VERSION_EXECUTED'
            );
        }

        $documentId = (int) $version['document_id'];
        $contractId = $version['contract_id'] === null ? null : (int) $version['contract_id'];

        // Audited before the row goes: afterwards there is nothing to reference,
        // and the audit table is append-only so the record outlives the file.
        $this->audit->log($ctx, 'document_version', $versionId, 'document.version_deleted', $contractId, [
            'version_no' => ['from' => (int) $version['version_no'], 'to' => null],
            'filename'   => ['from' => $version['filename'], 'to' => null],
        ]);

        Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $versionId, $documentId, $contractId, $version): void {
            $pdo->prepare('DELETE FROM contract_document_versions WHERE id = ? AND environment = ? AND cmp_id = ?')
                ->execute([$versionId, $ctx->environment, $ctx->cmpId]);

            $st = $pdo->prepare(
                'SELECT id FROM contract_document_versions
                 WHERE document_id = ? AND environment = ? AND cmp_id = ?
                 ORDER BY version_no DESC LIMIT 1'
            );
            $st->execute([$documentId, $ctx->environment, $ctx->cmpId]);
            $remaining = $st->fetchColumn();

            if ($remaining === false) {
                // A document with no versions points at nothing and can only be
                // reached to be looked at and found empty.
                $pdo->prepare('DELETE FROM contract_documents WHERE id = ? AND environment = ? AND cmp_id = ?')
                    ->execute([$documentId, $ctx->environment, $ctx->cmpId]);
            } else {
                $pdo->prepare(
                    'UPDATE contract_documents SET current_version_id = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND environment = ? AND cmp_id = ?'
                )->execute([(int) $remaining, $documentId, $ctx->environment, $ctx->cmpId]);
            }

            $this->activity->record(
                $ctx,
                $contractId,
                'document.version_deleted',
                sprintf('Version %d of %s deleted', (int) $version['version_no'], (string) $version['document_title'])
            );
        });

        // After the row is gone, so a storage failure cannot leave the database
        // pointing at a file that has already been removed.
        try {
            $this->adapterFor($version)->delete($ctx, $version);
        } catch (Throwable $e) {
            error_log('[contracts][documents] storage delete failed for version ' . $versionId . ': ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // File safety
    // -----------------------------------------------------------------------

    public static function maxUploadBytes(): int
    {
        $mb = Env::int('CONTRACTS_MAX_UPLOAD_MB', StorageAdapter::DEFAULT_MAX_UPLOAD_MB);

        return max(1, $mb) * 1024 * 1024;
    }

    /**
     * A filename fit to store, display and put in a Content-Disposition header.
     *
     * `basename()` after normalising separators removes every traversal form at
     * once; the rest strips control characters, collapses dot runs so a
     * `..` cannot reappear, and replaces anything outside a conservative set
     * rather than dropping it, so two different uploads do not collapse into
     * the same name.
     */
    public static function sanitiseFilename(string $raw): string
    {
        $name = basename(str_replace('\\', '/', trim($raw)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = preg_replace('/\.{2,}/', '.', $name) ?? '';
        $name = preg_replace('/[^\p{L}\p{N} ._()\[\]&+\-]/u', '_', $name) ?? '';
        $name = trim($name, " ._\t");

        if ($name === '') {
            $name = 'document';
        }

        return mb_substr($name, 0, 200);
    }

    public static function extensionOf(string $filename): string
    {
        return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    }

    /** A human title for a file, when the caller supplied none. */
    public static function titleFrom(string $filename): string
    {
        $base = (string) pathinfo($filename, PATHINFO_FILENAME);
        $base = trim(str_replace(['_', '-'], ' ', $base));

        return $base === '' ? 'Document' : mb_substr($base, 0, 255);
    }

    /**
     * The extension, the declared type and the size, checked together.
     *
     * Reported as field errors rather than as a refusal, because all three are
     * things the person picking the file can act on.
     *
     * @throws ValidationFailed
     */
    public static function assertAcceptable(string $filename, string $contentType, int $size): void
    {
        $errors    = [];
        $extension = self::extensionOf($filename);

        if (! in_array($extension, StorageAdapter::ALLOWED_EXTENSIONS, true)) {
            $errors['filename'] = 'Upload one of: ' . implode(', ', StorageAdapter::ALLOWED_EXTENSIONS) . '.';
        } else {
            $allowed = StorageAdapter::ALLOWED_MIME_TYPES[$extension] ?? [];
            if (! in_array(strtolower(trim($contentType)), $allowed, true)) {
                $errors['content_type'] = 'That file type does not match a .' . $extension . ' file.';
            }
        }

        $max = self::maxUploadBytes();
        if ($size > $max) {
            $errors['size_bytes'] = sprintf('Files must be under %d MB.', intdiv($max, 1024 * 1024));
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /** An opaque name for storage. The user's own name never reaches a filesystem. */
    private static function storageNameFor(string $extension): string
    {
        return bin2hex(random_bytes(16)) . ($extension === '' ? '' : '.' . $extension);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function assertMay(TenantContext $ctx, string $permission): void
    {
        if (! $ctx->has($permission)) {
            throw DomainException::forbidden('You do not have permission to do that with contract documents.');
        }
    }

    /**
     * Which record this document hangs off, in Drive's vocabulary.
     *
     * Exactly one owner, enforced here as well as by
     * ck_contract_documents_owner, because the constraint can only refuse the
     * row after the file has already been promoted in Drive.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveOwner(TenantContext $ctx, ?int $contractId, ?int $requestId, ?int $amendmentId): array
    {
        $given = array_filter([$contractId, $requestId, $amendmentId], static fn (?int $v): bool => $v !== null);

        if (count($given) !== 1) {
            throw new ValidationFailed([
                'contract_id' => 'Attach this to exactly one of a contract, a request or an amendment.',
            ]);
        }

        if ($contractId !== null) {
            $this->contracts->findOrFail($ctx, $contractId);

            return ['contract', (string) $contractId];
        }

        if ($requestId !== null) {
            $this->assertBelongsToTenant($ctx, 'contract_requests', $requestId, 'request_id', 'That request is not in your company.');

            return ['contract-request', (string) $requestId];
        }

        $this->assertBelongsToTenant($ctx, 'contract_amendments', (int) $amendmentId, 'amendment_id', 'That amendment is not in your company.');

        return ['amendment', (string) $amendmentId];
    }

    /**
     * A foreign key would only catch an id that exists nowhere. This is what
     * stops one company filing a document against another company's record.
     */
    private function assertBelongsToTenant(TenantContext $ctx, string $table, int $id, string $field, string $message): void
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM ' . $table . ' WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw new ValidationFailed([$field => $message]);
        }
    }

    /**
     * The amendment a finalised upload belongs to.
     *
     * Taken from the finalize body because the session row has no column for
     * it, so it is re-validated here — both that it is this tenant's and that
     * it is an amendment *of the contract the session was opened against*,
     * which is what stops the body being used to move a file onto a record the
     * upload was never authorised for.
     */
    private function resolveAmendment(TenantContext $ctx, ?int $amendmentId, ?int $contractId): ?int
    {
        if ($amendmentId === null) {
            return null;
        }

        $st = $this->pdo->prepare(
            'SELECT contract_id FROM contract_amendments
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$amendmentId, $ctx->environment, $ctx->cmpId]);
        $owner = $st->fetchColumn();

        if ($owner === false) {
            throw new ValidationFailed(['amendment_id' => 'That amendment is not in your company.']);
        }
        if ($contractId !== null && (int) $owner !== $contractId) {
            throw new ValidationFailed(['amendment_id' => 'That amendment belongs to a different contract.']);
        }

        return $amendmentId;
    }

    /** @return array<string,mixed> the upload session row */
    private function sessionOrFail(TenantContext $ctx, int $sessionId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_upload_sessions
             WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$sessionId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Upload session not found.');
        }

        // The uploader is the only one who may drive their own session forward.
        // Anyone else with the id is either confused or probing.
        if ((string) $row['created_by'] !== $ctx->uuid) {
            throw DomainException::notFound('Upload session not found.');
        }

        return $row;
    }

    /** @param array<string,mixed> $session */
    private function assertSessionOpen(array $session): void
    {
        $status = (string) $session['status'];

        if (! in_array($status, ['pending', 'uploaded'], true)) {
            throw DomainException::conflict('That upload has already been ' . $status . '.', 'UPLOAD_SESSION_CLOSED');
        }

        if (strtotime((string) $session['expires_at']) < time()) {
            $this->pdo->prepare('UPDATE contract_upload_sessions SET status = \'expired\' WHERE id = ?')
                ->execute([(int) $session['id']]);

            throw DomainException::conflict('That upload took too long. Start it again.', 'UPLOAD_SESSION_EXPIRED');
        }
    }

    private function expiryFrom(string $adapterExpiry): string
    {
        $parsed = strtotime($adapterExpiry);
        $ours   = time() + self::SESSION_TTL_SECONDS;

        // The earlier of the two. Outliving the storage reservation would leave
        // a session that looks usable and fails at the last step.
        return gmdate('Y-m-d H:i:s', $parsed === false ? $ours : min($parsed, $ours));
    }

    /** @return array<string,mixed> */
    private function insertDocument(
        TenantContext $ctx,
        ?int $contractId,
        ?int $requestId,
        ?int $amendmentId,
        string $docKind,
        string $title
    ): array {
        $st = $this->pdo->prepare(
            'INSERT INTO contract_documents
             (environment, cmp_id, contract_id, request_id, amendment_id, doc_kind, title, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             RETURNING *'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId, $requestId, $amendmentId, $docKind, $title, $ctx->uuid]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw new DomainException('The document could not be created.', 'DOCUMENT_CREATE_FAILED', 500);
        }

        return $row;
    }

    /**
     * Take the document row and hold it for the rest of the transaction.
     *
     * The lock is what makes version numbering correct: two uploads finalising
     * at once would otherwise both read the same highest number and one of them
     * would fail on uq_document_versions_no after its file was already promoted.
     *
     * @return array<string,mixed>
     */
    private function lockDocument(TenantContext $ctx, int $documentId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contract_documents
             WHERE id = ? AND environment = ? AND cmp_id = ?
             FOR UPDATE'
        );
        $st->execute([$documentId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Document not found.');
        }

        return $row;
    }

    /**
     * The next version number for a document.
     *
     * The high-water mark, not the count and not the maximum surviving number:
     * deleting a draft must not hand its number to the next upload, or the
     * audit trail ends up with two different files both called version 2.
     */
    private function nextVersionNo(TenantContext $ctx, int $documentId): int
    {
        $st = $this->pdo->prepare(
            'SELECT GREATEST(
                        d.version_count,
                        COALESCE((SELECT MAX(v.version_no) FROM contract_document_versions v
                                  WHERE v.document_id = d.id), 0)
                    )
             FROM contract_documents d
             WHERE d.id = ? AND d.environment = ? AND d.cmp_id = ?'
        );
        $st->execute([$documentId, $ctx->environment, $ctx->cmpId]);

        $next = ((int) $st->fetchColumn()) + 1;

        if ($next > self::MAX_VERSION_NO) {
            throw DomainException::conflict(
                'This document has too many versions. Start a new document.',
                'VERSION_LIMIT_REACHED'
            );
        }

        return $next;
    }

    /**
     * Retire whatever version was current before this one.
     *
     * Executed versions are left alone: "superseded" is a drafting state, and
     * writing it over a signed copy would make the record say the agreement was
     * replaced when only the working draft was.
     */
    private function supersedePrevious(TenantContext $ctx, int $documentId, int $newVersionId): void
    {
        $this->pdo->prepare(
            'UPDATE contract_document_versions
             SET version_status = \'superseded\'
             WHERE document_id = ? AND environment = ? AND cmp_id = ? AND id <> ?
               AND version_status NOT IN (\'executed\', \'superseded\')
               AND is_executed = FALSE'
        )->execute([$documentId, $ctx->environment, $ctx->cmpId, $newVersionId]);
    }

    /**
     * Ask for the text of a version to be extracted.
     *
     * Queued rather than done inline: a 200-page PDF takes seconds, and the
     * person who pressed upload should not wait for it. The idempotency key
     * means a retried finalize does not queue the same work twice.
     */
    private function queueTextExtraction(TenantContext $ctx, int $versionId): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO contract_jobs
                 (environment, cmp_id, queue, job_type, payload, priority, idempotency_key)
                 VALUES (?, ?, \'documents\', \'document.extract_text\', ?::jsonb, 80, ?)
                 ON CONFLICT DO NOTHING'
            )->execute([
                $ctx->environment,
                $ctx->cmpId,
                json_encode(['version_id' => $versionId], JSON_UNESCAPED_SLASHES),
                'document.extract_text:' . $versionId,
            ]);
        } catch (Throwable $e) {
            // Losing the extraction job costs search and AI grounding on one
            // file; failing the upload for it would cost the document.
            error_log('[contracts][documents] could not queue text extraction: ' . $e->getMessage());
        }
    }

    /**
     * The adapter that can actually reach a given version's bytes.
     *
     * A version records the provider that stored it, so a deployment that has
     * since moved to Drive can still open the files written while the local
     * fallback was in use.
     *
     * @param array<string,mixed> $version
     */
    private function adapterFor(array $version): StorageAdapter
    {
        $provider = (string) ($version['storage_provider'] ?? 'drive');
        $current  = $this->storage();

        if ($current->name() === $provider) {
            return $current;
        }

        $adapter = $provider === 'local' ? LocalStorageAdapter::make() : DriveStorageAdapter::make();
        if ($adapter === null || ! $adapter->isConfigured()) {
            throw DomainException::unavailable(
                'This file was stored by the ' . $provider . ' backend, which is not configured on this server.',
                'STORAGE_NOT_CONFIGURED'
            );
        }

        return $adapter;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateDocument(array $row): array
    {
        foreach (['id', 'cmp_id', 'contract_id', 'request_id', 'amendment_id', 'current_version_id', 'version_count', 'current_version_no', 'current_size_bytes'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        foreach (['is_executed_copy', 'current_is_scanned'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = ContractService::toBool($row[$key]);
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydrateVersion(array $row): array
    {
        foreach (['id', 'cmp_id', 'document_id', 'version_no', 'size_bytes', 'extracted_pages', 'contract_id', 'request_id', 'amendment_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        foreach (['is_executed', 'is_scanned', 'has_text'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = ContractService::toBool($row[$key]);
            }
        }

        // The stored path is a server-side detail. Publishing it tells a caller
        // the directory layout of the host for no benefit to them.
        unset($row['local_path']);

        return $row;
    }
}
