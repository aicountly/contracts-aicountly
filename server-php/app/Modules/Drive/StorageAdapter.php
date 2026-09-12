<?php

declare(strict_types=1);

namespace App\Modules\Drive;

use App\Support\TenantContext;

/**
 * Where the bytes of a contract document live.
 *
 * Contracts owns the metadata, the version lineage and the audit trail; it
 * never owns file content. This interface is the seam between the two, so the
 * service layer can be written once against "an upload session, then bytes,
 * then a finalised object" without knowing whether that object ended up in
 * AICOUNTLY Drive or on the server's own disk.
 *
 * The file-safety vocabulary lives here rather than in the service because
 * both sides of the seam must agree on it: the service refuses a bad upload
 * before it costs a round trip, and the adapter refuses it again at the moment
 * it would touch storage. One list, checked twice.
 */
interface StorageAdapter
{
    /**
     * What a contract document may be.
     *
     * Deliberately short. A CLM repository is agreements, signed copies,
     * annexures and correspondence — every executable, archive and script
     * format is absent because nothing in the product ever needs to store one,
     * and an allow-list is the only kind of list that stays correct as new
     * dangerous extensions are invented.
     */
    public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'txt', 'rtf', 'png', 'jpg', 'jpeg', 'tiff'];

    /**
     * The content types each extension may claim.
     *
     * Checked as a pair, not independently: `payload.exe` renamed to
     * `payload.pdf` and declared `application/pdf` gets through an
     * extension-only check, and a real PDF declared `text/html` gets through a
     * MIME-only one. Neither passes when the two must match each other.
     */
    public const ALLOWED_MIME_TYPES = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'txt'  => ['text/plain'],
        'rtf'  => ['application/rtf', 'text/rtf', 'text/richtext'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'tiff' => ['image/tiff'],
    ];

    /** Used when CONTRACTS_MAX_UPLOAD_MB is unset. */
    public const DEFAULT_MAX_UPLOAD_MB = 25;

    /** Identifier stored in `storage_provider`; matches ck_document_versions_provider. */
    public function name(): string;

    /**
     * Whether this adapter can actually accept an upload right now.
     *
     * False is a configuration answer, not a failure: the service picks the
     * adapter that says true, and reports the product as degraded when none
     * does.
     */
    public function isConfigured(): bool;

    /**
     * Reserve a destination for bytes that do not exist yet.
     *
     * @param array{filename: string, storage_name: string, content_type: string,
     *              size_bytes: int, doc_kind: string, module_code: string,
     *              entity_type: string, entity_id: string} $spec
     * @return array{provider: string, session_ref: ?string, upload_url: ?string,
     *               method: string, headers: array<string,string>, expires_at: string,
     *               object_key: ?string, local_path: ?string}
     *         `upload_url` is null when the adapter cannot take bytes directly and
     *         the caller must send them through this API instead.
     */
    public function createUpload(TenantContext $ctx, array $spec): array;

    /**
     * Record that the bytes have arrived, and take them when they come via us.
     *
     * @param array<string,mixed> $session the `contract_upload_sessions` row
     * @param string|null         $bytes   present only when the client uploaded through this API
     * @return array{status: string, size_bytes: ?int, checksum: ?string}
     */
    public function completeUpload(TenantContext $ctx, array $session, ?string $bytes = null): array;

    /**
     * Turn the uploaded object into a permanent one and bind it to the record.
     *
     * The adapter is what links the stored file back to the Contracts record it
     * belongs to, because only it knows whether that link is a Drive
     * `document-links` row or nothing at all.
     *
     * @param array<string,mixed>                                        $session
     * @param array{title: string, size_bytes: int, checksum: ?string,
     *              entity_type: string, entity_id: string}              $meta
     * @return array{drive_document_id: ?string, drive_version_ref: ?string,
     *               local_path: ?string, size_bytes: int, checksum: ?string, link_id: ?int}
     */
    public function finalizeUpload(TenantContext $ctx, array $session, array $meta): array;

    /**
     * A time-limited URL for one stored version.
     *
     * @param array<string,mixed> $version the `contract_document_versions` row
     * @return array{url: string, expires_in: int, filename: string, mime_type: string}
     */
    public function signedUrl(TenantContext $ctx, array $version, bool $inline): array;

    /** @param array<string,mixed> $version */
    public function delete(TenantContext $ctx, array $version): void;

    /**
     * The stored bytes, or null when this adapter cannot reach them.
     *
     * Text extraction needs the file itself, not a URL for a browser, and the
     * two storage backends answer that question very differently — one reads a
     * path, the other has to fetch what it just signed.
     *
     * @param array<string,mixed> $version
     */
    public function readBytes(TenantContext $ctx, array $version): ?string;
}
