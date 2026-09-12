<?php

declare(strict_types=1);

namespace App\Modules\Drive;

use App\Core\Env;
use App\Support\DomainException;
use App\Support\TenantContext;

/**
 * The documented fallback for a deployment where Drive is not yet provisioned.
 *
 * AICOUNTLY Drive is where contract documents belong, and this class exists
 * only so that the product is usable on day one of an installation whose Drive
 * buckets have not been created yet: a contract repository that cannot accept
 * a PDF until another product is deployed is not a repository. Everything it
 * gives up relative to Drive — virus scanning, quarantine, retention classes,
 * legal hold, cross-product search, off-host durability — is given up
 * knowingly, which is why `GET /api/health` reports which adapter is live and
 * why it is refused outright unless `CONTRACTS_ALLOW_LOCAL_STORAGE=true`.
 *
 * Three rules make the fallback survivable:
 *
 *   1. Files are written under CONTRACTS_LOCAL_STORAGE_PATH, which must be
 *      outside the document root. A contract PDF served as a static file by
 *      Apache would be readable by anyone who guessed the URL.
 *   2. The stored name is generated, never the user's. The user's name is kept
 *      as metadata for display and download only, so a filename that is really
 *      a traversal attempt, a shell metacharacter or a `.php` suffix never
 *      reaches the filesystem.
 *   3. The size cap and the MIME allow-list are enforced here too, over the
 *      bytes actually received rather than the size a client declared.
 *
 * Migrating away is a copy of these files into Drive plus a rewrite of
 * `storage_provider` and `drive_document_id` per version; nothing else in the
 * product knows which adapter stored what.
 */
final class LocalStorageAdapter implements StorageAdapter
{
    /** How long a locally served file link stays valid. */
    private const URL_TTL_SECONDS = 900;

    public function __construct(private string $root)
    {
    }

    public static function make(): ?self
    {
        if (! self::isAllowed()) {
            return null;
        }

        $root = self::rootPath();

        return $root === '' ? null : new self($root);
    }

    /**
     * Opt-in, and only opt-in.
     *
     * A deployment that has simply forgotten to set DRIVE_API_BASE must fail
     * loudly rather than quietly start writing contracts to local disk — that
     * is exactly the state nobody notices until the server is replaced.
     */
    public static function isAllowed(): bool
    {
        return Env::bool('CONTRACTS_ALLOW_LOCAL_STORAGE', false);
    }

    public static function rootPath(): string
    {
        return rtrim(trim(Env::get('CONTRACTS_LOCAL_STORAGE_PATH')), '/\\');
    }

    public function name(): string
    {
        return 'local';
    }

    public function isConfigured(): bool
    {
        if (! self::isAllowed() || $this->root === '') {
            return false;
        }

        if (self::isInsideDocumentRoot($this->root)) {
            return false;
        }

        return $this->ensureDirectory($this->root);
    }

    // -----------------------------------------------------------------------
    // Upload lifecycle
    // -----------------------------------------------------------------------

    /** @inheritDoc */
    public function createUpload(TenantContext $ctx, array $spec): array
    {
        $this->assertUsable();
        $this->assertAcceptable($spec['filename'], $spec['content_type'], (int) $spec['size_bytes']);

        $relative = $this->relativePathFor($ctx, $spec['storage_name']);
        $this->ensureDirectory(dirname($this->absolutePath($relative)));

        return [
            'provider'    => 'local',
            'session_ref' => null,
            // Null on purpose: there is no presigned destination to PUT to, so
            // the caller sends the bytes through this API instead and
            // DocumentService fills in its own endpoint.
            'upload_url'  => null,
            'method'      => 'POST',
            'headers'     => ['Content-Type' => $spec['content_type']],
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + 7200),
            'object_key'  => null,
            'local_path'  => $relative,
        ];
    }

    /** @inheritDoc */
    public function completeUpload(TenantContext $ctx, array $session, ?string $bytes = null): array
    {
        $this->assertUsable();

        if ($bytes === null) {
            throw DomainException::badRequest(
                'This deployment stores files locally, so the file itself has to be sent to complete the upload.',
                'UPLOAD_BODY_REQUIRED'
            );
        }

        $relative = (string) ($session['local_path'] ?? '');
        if ($relative === '') {
            throw DomainException::conflict('This upload session has no destination on disk.');
        }

        $size = strlen($bytes);
        $max  = self::maxUploadBytes();
        if ($size === 0) {
            throw DomainException::badRequest('That file is empty.', 'FILE_EMPTY');
        }
        if ($size > $max) {
            throw DomainException::badRequest(
                sprintf('That file is %s and the limit is %s.', self::humanSize($size), self::humanSize($max)),
                'FILE_TOO_LARGE'
            );
        }

        $filename  = (string) ($session['filename'] ?? '');
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (! self::contentLooksLike($extension, $bytes)) {
            // The declared type and the extension already agreed; this catches
            // the case where both were lies. A renamed executable is the whole
            // reason an allow-list exists.
            throw DomainException::badRequest(
                'That file is not really a ' . strtoupper($extension) . ' file.',
                'FILE_TYPE_MISMATCH'
            );
        }

        $absolute = $this->absolutePath($relative);
        $this->ensureDirectory(dirname($absolute));

        // Written to a temporary name and renamed into place: rename is atomic
        // on the same filesystem, so a half-written file is never visible to a
        // reader and a crashed upload leaves a `.part` to reap rather than a
        // truncated contract.
        $temp = $absolute . '.part';
        if (file_put_contents($temp, $bytes, LOCK_EX) !== $size || ! rename($temp, $absolute)) {
            @unlink($temp);

            throw DomainException::unavailable('The file could not be written to local storage.');
        }
        @chmod($absolute, 0o600);

        return ['status' => 'uploaded', 'size_bytes' => $size, 'checksum' => hash('sha256', $bytes)];
    }

    /** @inheritDoc */
    public function finalizeUpload(TenantContext $ctx, array $session, array $meta): array
    {
        $this->assertUsable();

        $relative = (string) ($session['local_path'] ?? '');
        $absolute = $relative === '' ? '' : $this->absolutePath($relative);

        if ($absolute === '' || ! is_file($absolute)) {
            throw DomainException::conflict('The uploaded file is no longer on disk.', 'UPLOAD_MISSING');
        }

        // Hashed from the file rather than trusted from the session: the bytes
        // are right here, and a checksum is only worth storing when it was
        // computed over what is actually stored.
        $checksum = hash_file('sha256', $absolute);
        $size     = (int) filesize($absolute);

        return [
            'drive_document_id' => null,
            'drive_version_ref' => null,
            'local_path'        => $relative,
            'size_bytes'        => $size,
            'checksum'          => is_string($checksum) ? $checksum : null,
            'link_id'           => null,
        ];
    }

    /**
     * A short-lived link this API serves itself.
     *
     * Relative and same-origin because there is no object store to presign
     * against: the bytes are on this host, behind this application's
     * authentication, and the token below is what stops the resulting URL from
     * being a permanent unauthenticated handle if it is copied out of a browser
     * history or a chat message.
     *
     * @inheritDoc
     */
    public function signedUrl(TenantContext $ctx, array $version, bool $inline): array
    {
        $versionId = (int) ($version['id'] ?? 0);
        $expires   = time() + self::URL_TTL_SECONDS;

        $url = sprintf(
            '/api/versions/%d/file?expires=%d&inline=%d&token=%s',
            $versionId,
            $expires,
            $inline ? 1 : 0,
            self::signViewToken($versionId, $inline, $expires)
        );

        return [
            'url'        => $url,
            'expires_in' => self::URL_TTL_SECONDS,
            'filename'   => (string) ($version['filename'] ?? 'document'),
            'mime_type'  => (string) ($version['content_type'] ?? 'application/octet-stream'),
        ];
    }

    /** @inheritDoc */
    public function delete(TenantContext $ctx, array $version): void
    {
        $relative = (string) ($version['local_path'] ?? '');
        if ($relative === '') {
            return;
        }

        $absolute = $this->absolutePath($relative);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /** @inheritDoc */
    public function readBytes(TenantContext $ctx, array $version): ?string
    {
        $relative = (string) ($version['local_path'] ?? '');
        if ($relative === '') {
            return null;
        }

        $absolute = $this->absolutePath($relative);
        if (! is_file($absolute) || filesize($absolute) > self::maxUploadBytes()) {
            return null;
        }

        $bytes = file_get_contents($absolute);

        return is_string($bytes) ? $bytes : null;
    }

    // -----------------------------------------------------------------------
    // Link signing
    // -----------------------------------------------------------------------

    public static function signViewToken(int $versionId, bool $inline, int $expiresAt): string
    {
        return hash_hmac('sha256', $versionId . '|' . ($inline ? '1' : '0') . '|' . $expiresAt, self::signingSecret());
    }

    /** Constant-time, and expiry-checked before anything reads a file. */
    public static function verifyViewToken(int $versionId, bool $inline, int $expiresAt, string $token): bool
    {
        if ($expiresAt < time()) {
            return false;
        }

        return hash_equals(self::signViewToken($versionId, $inline, $expiresAt), $token);
    }

    /**
     * The key the link tokens are signed with.
     *
     * CONTRACTS_LOCAL_STORAGE_SECRET when set, which is what a deployment
     * should do. The fallback is derived from server-side configuration that a
     * caller cannot see or guess; it is weaker than a real secret, so a token
     * signed with it stops being valid the moment the storage path changes —
     * which is acceptable for links that live fifteen minutes.
     */
    private static function signingSecret(): string
    {
        $configured = trim(Env::get('CONTRACTS_LOCAL_STORAGE_SECRET'));
        if ($configured !== '') {
            return $configured;
        }

        return hash('sha256', 'contracts-local|' . self::rootPath() . '|' . Env::get('DB_NAME'));
    }

    // -----------------------------------------------------------------------
    // File safety
    // -----------------------------------------------------------------------

    public static function maxUploadBytes(): int
    {
        $mb = Env::int('CONTRACTS_MAX_UPLOAD_MB', self::DEFAULT_MAX_UPLOAD_MB);

        return max(1, $mb) * 1024 * 1024;
    }

    /**
     * The same three checks the service already made, made again here.
     *
     * Not redundant: this adapter is the last thing standing between a byte
     * stream and the filesystem, and a future caller that reaches it by another
     * route must not be able to skip the allow-list by skipping the service.
     */
    private function assertAcceptable(string $filename, string $contentType, int $declaredSize): void
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw DomainException::badRequest('That kind of file cannot be stored here.', 'FILE_TYPE_NOT_ALLOWED');
        }

        $allowed = self::ALLOWED_MIME_TYPES[$extension] ?? [];
        if (! in_array(strtolower(trim($contentType)), $allowed, true)) {
            throw DomainException::badRequest('That file type does not match its content type.', 'FILE_TYPE_MISMATCH');
        }

        $max = self::maxUploadBytes();
        if ($declaredSize > $max) {
            throw DomainException::badRequest(
                sprintf('That file is %s and the limit is %s.', self::humanSize($declaredSize), self::humanSize($max)),
                'FILE_TOO_LARGE'
            );
        }
    }

    /**
     * Whether the leading bytes match what the extension promised.
     *
     * True for anything with no recognisable signature — this is a check for a
     * confident mismatch, not a demand for proof. `txt` is the one exception:
     * plain text with an embedded NUL is not plain text.
     */
    public static function contentLooksLike(string $extension, string $bytes): bool
    {
        $head = substr($bytes, 0, 16);

        return match ($extension) {
            'pdf'         => str_starts_with($head, '%PDF-'),
            'png'         => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'tiff'        => str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*"),
            'docx'        => str_starts_with($head, "PK\x03\x04"),
            'doc'         => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            'rtf'         => str_starts_with($head, '{\\rtf'),
            'txt'         => ! str_contains(substr($bytes, 0, 8192), "\x00"),
            default       => true,
        };
    }

    // -----------------------------------------------------------------------
    // Paths
    // -----------------------------------------------------------------------

    /**
     * Where one file goes, relative to the configured root.
     *
     * Relative rather than absolute in the database: the root is a deployment
     * detail, and storing it in every row would break every document the day an
     * operator moves the directory or restores onto a host with a different
     * home path.
     */
    private function relativePathFor(TenantContext $ctx, string $storageName): string
    {
        return sprintf(
            '%s/%d/%s/%s',
            preg_replace('/[^a-z0-9_-]/i', '', $ctx->environment) ?: 'unknown',
            $ctx->cmpId,
            gmdate('Y/m'),
            $storageName
        );
    }

    /**
     * Resolve a stored relative path, refusing anything that tries to leave the root.
     *
     * The path never comes from a user — it is generated here — but it does
     * come back out of the database, and a service that will happily read
     * `../../etc/passwd` if a row says so is one SQL injection away from being
     * a file-disclosure primitive.
     */
    private function absolutePath(string $relative): string
    {
        $clean = str_replace('\\', '/', trim($relative, '/'));

        foreach (explode('/', $clean) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw DomainException::badRequest('That storage path is not valid.', 'BAD_STORAGE_PATH');
            }
        }

        return $this->root . '/' . $clean;
    }

    private function assertUsable(): void
    {
        if (! self::isAllowed()) {
            throw DomainException::unavailable(
                'Document storage is not configured. Set DRIVE_API_BASE, or enable the local fallback with CONTRACTS_ALLOW_LOCAL_STORAGE.',
                'STORAGE_NOT_CONFIGURED'
            );
        }
        if ($this->root === '') {
            throw DomainException::unavailable(
                'Local storage is enabled but CONTRACTS_LOCAL_STORAGE_PATH is not set.',
                'STORAGE_NOT_CONFIGURED'
            );
        }
        if (self::isInsideDocumentRoot($this->root)) {
            throw DomainException::unavailable(
                'CONTRACTS_LOCAL_STORAGE_PATH is inside the document root, where the web server would serve contract files to anyone who guessed the URL.',
                'STORAGE_PATH_UNSAFE'
            );
        }
        if (! $this->ensureDirectory($this->root)) {
            throw DomainException::unavailable('Local storage path is not writable.', 'STORAGE_NOT_WRITABLE');
        }
    }

    private static function isInsideDocumentRoot(string $path): bool
    {
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])
            ? rtrim($_SERVER['DOCUMENT_ROOT'], '/\\')
            : '';

        if ($docRoot === '' || $docRoot === '/') {
            return false;
        }

        return $path === $docRoot || str_starts_with($path . '/', $docRoot . '/');
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        // 0700: nothing but this application's user has any business reading a
        // directory of contract documents.
        return mkdir($path, 0o700, true) && is_writable($path);
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return max(1, (int) round($bytes / 1024)) . ' KB';
    }
}
