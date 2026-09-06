<?php

declare(strict_types=1);

namespace App\Modules\Drive;

use App\Core\Env;
use App\Core\Http;
use App\Support\DomainException;
use App\Support\TenantContext;

/**
 * Server-to-server calls to AICOUNTLY Drive.
 *
 * Drive is the fleet's object store: it owns buckets, quarantine, virus
 * scanning, checksums, retention and signed URLs. Contracts stores a document
 * id and nothing else, which is why none of the S3 vocabulary appears anywhere
 * in this product.
 *
 * Every call carries the caller's own ses_key plus the company/branch/year
 * headers, never a service credential. That is deliberate: Drive re-runs the
 * same company-access check the portal and Manage ran for us, so a user who
 * has lost access to a company cannot reach its files through Contracts even
 * if a stale row here still points at them.
 */
final class DriveClient
{
    /**
     * The storage identifier for this product.
     *
     * Permanent, and not a display name — it is written into every object key
     * Drive creates for us (see Drive's docs/S3_PATH_SPEC.md). Renaming it
     * would strand every file already stored.
     */
    public const PRODUCT_CODE = 'contracts';

    /**
     * `doc_kind` (ours, a CHECK constraint) to `module_code` (Drive's, a path
     * segment).
     *
     * Two vocabularies rather than one because they answer different
     * questions: ours says what the document is to a contract, Drive's decides
     * where the bytes are filed and which retention class they inherit.
     */
    private const MODULE_CODES = [
        'contract'           => 'contract-document',
        'executed_copy'      => 'executed-copy',
        'annexure'           => 'annexure',
        'schedule'           => 'schedule',
        'amendment'          => 'amendment',
        'evidence'           => 'obligation-evidence',
        'request_attachment' => 'request-attachment',
        'correspondence'     => 'correspondence',
    ];

    /** Uploads can be large and Drive copies out of quarantine synchronously. */
    private const UPLOAD_TIMEOUT = 120;

    public function __construct(private string $base)
    {
    }

    public static function make(): ?self
    {
        $base = self::baseUrl();

        return $base === '' ? null : new self($base);
    }

    public static function baseUrl(): string
    {
        return rtrim(trim(Env::get('DRIVE_API_BASE')), '/');
    }

    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '';
    }

    public function base(): string
    {
        return $this->base;
    }

    public static function moduleCodeFor(string $docKind): string
    {
        return self::MODULE_CODES[$docKind] ?? 'contract-document';
    }

    // -----------------------------------------------------------------------
    // Upload lifecycle
    // -----------------------------------------------------------------------

    /**
     * @param array{filename: string, content_type: string, size_bytes: int,
     *              module_code: string, entity_type: string, entity_id: string} $spec
     * @return array<string,mixed> Drive's session descriptor
     */
    public function createUploadSession(TenantContext $ctx, array $spec): array
    {
        return $this->call($ctx, 'POST', '/api/upload-sessions', [
            'filename'     => $spec['filename'],
            'content_type' => $spec['content_type'],
            'size_bytes'   => $spec['size_bytes'],
            // Always company scope. A contract belongs to the company, not to
            // whoever happened to upload it, and a personal-scope object would
            // vanish from the company's repository the day that person leaves.
            'scope'        => 'company',
            'product_code' => self::PRODUCT_CODE,
            'module_code'  => $spec['module_code'],
            'entity_type'  => $spec['entity_type'],
            'entity_id'    => $spec['entity_id'],
            'branch_key'   => (string) $ctx->boId,
        ]);
    }

    /**
     * Send the bytes to the presigned destination Drive handed back.
     *
     * The URL is Drive's, not ours, and it is signed for exactly one object —
     * which is why this is a bare PUT with no Contracts credentials attached.
     *
     * @param array<string,string> $headers
     */
    public function putBytes(string $uploadUrl, array $headers, string $bytes): void
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $result = Http::request('PUT', $uploadUrl, $lines, $bytes, self::UPLOAD_TIMEOUT, 10);

        if ($result['status'] === 0) {
            throw DomainException::unavailable('Could not reach storage to upload the file: ' . $result['error']);
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw DomainException::unavailable('Storage refused the upload (HTTP ' . $result['status'] . ').');
        }
    }

    /** @return array<string,mixed> */
    public function completeUpload(TenantContext $ctx, string $sessionRef): array
    {
        return $this->call($ctx, 'POST', '/api/upload-sessions/' . rawurlencode($sessionRef) . '/complete', []);
    }

    /**
     * @param array{title: string, size_bytes: int, sha256?: string} $body
     * @return array<string,mixed> the created Drive document
     */
    public function finalizeUpload(TenantContext $ctx, string $sessionRef, array $body): array
    {
        return $this->call(
            $ctx,
            'POST',
            '/api/upload-sessions/' . rawurlencode($sessionRef) . '/finalize',
            $body,
            self::UPLOAD_TIMEOUT
        );
    }

    /**
     * Abandon a session.
     *
     * Failures are swallowed by the caller rather than surfaced: aborting is
     * housekeeping, and Drive reaps unfinalised quarantine objects on its own
     * schedule anyway.
     */
    public function abortUpload(TenantContext $ctx, string $sessionRef): void
    {
        $this->call($ctx, 'POST', '/api/upload-sessions/' . rawurlencode($sessionRef) . '/abort', []);
    }

    // -----------------------------------------------------------------------
    // Documents
    // -----------------------------------------------------------------------

    /**
     * Point a Drive document back at the Contracts record it belongs to.
     *
     * Without this the file is reachable from Contracts but orphaned in Drive:
     * someone opening it there sees a contract PDF with nothing saying which
     * contract, and Drive's own retention and legal-hold reporting cannot tell
     * what business record it is evidence for.
     *
     * @return array<string,mixed>
     */
    public function linkDocument(TenantContext $ctx, string $documentId, string $recordType, string $recordId): array
    {
        return $this->call($ctx, 'POST', '/api/document-links', [
            'doc_id'               => (int) $documentId,
            'product_code'         => self::PRODUCT_CODE,
            'external_record_type' => $recordType,
            'external_record_id'   => $recordId,
        ]);
    }

    /** @return array{url: string, expires_in: int, filename: string, mime_type: string} */
    public function signedUrl(TenantContext $ctx, string $documentId, bool $inline): array
    {
        $path = '/api/documents/' . rawurlencode($documentId) . ($inline ? '/view' : '/download');
        $data = $this->call($ctx, 'GET', $path);

        $url = isset($data['url']) && is_string($data['url']) ? $data['url'] : '';
        if ($url === '') {
            throw DomainException::unavailable('Drive did not return a link for this document.');
        }

        return [
            'url'        => $url,
            'expires_in' => (int) ($data['expires_in'] ?? 900),
            'filename'   => (string) ($data['filename'] ?? ''),
            'mime_type'  => (string) ($data['mime_type'] ?? 'application/octet-stream'),
        ];
    }

    public function deleteDocument(TenantContext $ctx, string $documentId): void
    {
        $this->call($ctx, 'DELETE', '/api/documents/' . rawurlencode($documentId));
    }

    /**
     * Read an object back through a URL Drive signed for us.
     *
     * Capped, because the only callers are text extraction and checksum
     * verification and both would rather fail than pull an unbounded body into
     * a PHP-FPM worker's memory.
     */
    public function fetchBytes(string $url, int $maxBytes): ?string
    {
        $result = Http::request('GET', $url, [], null, 60, 10);

        if ($result['status'] < 200 || $result['status'] >= 300) {
            return null;
        }

        return strlen($result['body']) > $maxBytes ? null : $result['body'];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function headers(TenantContext $ctx): array
    {
        return [
            'Authorization: Bearer ' . $ctx->sesKey,
            'X-AIC-CMP-ID: ' . $ctx->cmpId,
            'X-AIC-FY-ID: ' . $ctx->fyId,
            'X-AIC-BO-ID: ' . $ctx->boId,
            'Accept: application/json',
        ];
    }

    /**
     * One call, with Drive's envelope unwrapped and its failures translated.
     *
     * Drive answers `{success, data, errors}` on both outcomes, so the HTTP
     * status alone is not the whole story — a body saying `success: false`
     * with a 200 is still a refusal. The status is what decides *whose*
     * problem it is: 5xx and a dead socket are Drive's (503 here, so the caller
     * can retry), 4xx is ours or the user's and keeps its own meaning.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function call(TenantContext $ctx, string $method, string $path, ?array $body = null, int $timeout = 20): array
    {
        $headers = $this->headers($ctx);
        $encoded = null;
        if ($body !== null) {
            $encoded   = json_encode($body, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        $result = Http::request($method, $this->base . $path, $headers, $encoded, $timeout);

        if ($result['status'] === 0) {
            throw DomainException::unavailable(
                'AICOUNTLY Drive is not reachable' . ($result['error'] !== '' ? ': ' . $result['error'] : '.')
            );
        }

        $decoded = json_decode($result['body'], true);
        $payload = is_array($decoded) ? $decoded : [];
        $message = is_string($payload['message'] ?? null) && $payload['message'] !== ''
            ? $payload['message']
            : 'Drive refused the request.';

        if ($result['status'] >= 500) {
            throw DomainException::unavailable('AICOUNTLY Drive returned an error: ' . $message);
        }

        if ($result['status'] >= 400 || ($payload['success'] ?? true) === false) {
            throw match (true) {
                $result['status'] === 401,
                $result['status'] === 403 => DomainException::forbidden('Drive refused access to this file: ' . $message),
                $result['status'] === 404 => DomainException::notFound('That file is no longer in Drive.'),
                default                   => DomainException::badRequest($message, 'DRIVE_REJECTED'),
            };
        }

        $data = $payload['data'] ?? $payload;

        return is_array($data) ? $data : [];
    }
}
