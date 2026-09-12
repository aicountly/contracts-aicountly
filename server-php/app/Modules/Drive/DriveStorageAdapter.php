<?php

declare(strict_types=1);

namespace App\Modules\Drive;

use App\Core\Env;
use App\Support\DomainException;
use App\Support\TenantContext;

/**
 * The intended home for contract documents: AICOUNTLY Drive.
 *
 * This adapter is a translation layer and nothing more. It maps Contracts'
 * vocabulary (`doc_kind`, a contract id) onto Drive's (`module_code`, an
 * `entity_type`/`entity_id` pair) and hands everything else — quarantine,
 * virus scanning, the checksum, the object key, retention, signed URLs — to
 * Drive, which already does all of it for every product in the fleet.
 */
final class DriveStorageAdapter implements StorageAdapter
{
    public function __construct(private DriveClient $client)
    {
    }

    public static function make(): ?self
    {
        $client = DriveClient::make();

        return $client === null ? null : new self($client);
    }

    public function name(): string
    {
        return 'drive';
    }

    public function isConfigured(): bool
    {
        return DriveClient::isConfigured();
    }

    public function client(): DriveClient
    {
        return $this->client;
    }

    /** @inheritDoc */
    public function createUpload(TenantContext $ctx, array $spec): array
    {
        $session = $this->client->createUploadSession($ctx, [
            // Drive's sanitised display name, not our opaque storage name: the
            // filename becomes the last segment of the object key and shows in
            // Drive's own UI, and an unreadable one there is a support call
            // later. It is safe because it has already been stripped of path
            // separators and traversal — see DocumentService::sanitiseFilename().
            'filename'     => $spec['filename'],
            'content_type' => $spec['content_type'],
            'size_bytes'   => $spec['size_bytes'],
            'module_code'  => $spec['module_code'],
            'entity_type'  => $spec['entity_type'],
            'entity_id'    => $spec['entity_id'],
        ]);

        $sessionRef = isset($session['session_id']) ? (string) $session['session_id'] : '';
        $uploadUrl  = isset($session['upload_url']) ? (string) $session['upload_url'] : '';
        if ($sessionRef === '' || $uploadUrl === '') {
            throw DomainException::unavailable('Drive did not return a usable upload session.');
        }

        $headers = [];
        if (isset($session['headers']) && is_array($session['headers'])) {
            foreach ($session['headers'] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_numeric($value))) {
                    $headers[$name] = (string) $value;
                }
            }
        }
        if ($headers === []) {
            $headers = ['Content-Type' => $spec['content_type']];
        }

        return [
            'provider'    => 'drive',
            'session_ref' => $sessionRef,
            'upload_url'  => $uploadUrl,
            'method'      => isset($session['method']) ? strtoupper((string) $session['method']) : 'PUT',
            'headers'     => $headers,
            'expires_at'  => isset($session['expires_at']) && is_string($session['expires_at'])
                ? $session['expires_at']
                : gmdate('Y-m-d H:i:s', time() + 7200),
            'object_key'  => isset($session['object_key']) ? (string) $session['object_key'] : null,
            'local_path'  => null,
        ];
    }

    /** @inheritDoc */
    public function completeUpload(TenantContext $ctx, array $session, ?string $bytes = null): array
    {
        $sessionRef = (string) ($session['drive_session_id'] ?? '');
        if ($sessionRef === '') {
            throw DomainException::conflict('This upload session has no Drive session to complete.');
        }

        $size     = null;
        $checksum = null;

        // The browser normally PUTs straight to the presigned URL and never
        // sends the bytes here at all. When they do arrive — a server-side
        // import, a client that cannot do a cross-origin PUT — we relay them
        // and get a checksum for free, computed over the bytes we actually
        // handled rather than the ones a client claims it sent.
        if ($bytes !== null) {
            $uploadUrl = (string) ($session['upload_url'] ?? '');
            if ($uploadUrl === '') {
                throw DomainException::conflict('This upload session has no destination to write to.');
            }

            $headers = ['Content-Type' => (string) ($session['content_type'] ?? 'application/octet-stream')];
            $this->client->putBytes($uploadUrl, $headers, $bytes);

            $size     = strlen($bytes);
            $checksum = hash('sha256', $bytes);
        }

        $this->client->completeUpload($ctx, $sessionRef);

        return ['status' => 'uploaded', 'size_bytes' => $size, 'checksum' => $checksum];
    }

    /** @inheritDoc */
    public function finalizeUpload(TenantContext $ctx, array $session, array $meta): array
    {
        $sessionRef = (string) ($session['drive_session_id'] ?? '');
        if ($sessionRef === '') {
            throw DomainException::conflict('This upload session has no Drive session to finalise.');
        }

        $body = ['title' => $meta['title'], 'size_bytes' => $meta['size_bytes']];
        if (is_string($meta['checksum'] ?? null) && $meta['checksum'] !== '') {
            // Drive verifies this against its own hash of the stored object and
            // fails the finalize when they differ, which is what turns "the
            // upload succeeded" into "the right bytes arrived".
            $body['sha256'] = $meta['checksum'];
        }

        $document = $this->client->finalizeUpload($ctx, $sessionRef, $body);

        $documentId = isset($document['id']) ? (string) $document['id'] : '';
        if ($documentId === '') {
            throw DomainException::unavailable('Drive finalised the upload without returning a document id.');
        }

        $link = $this->client->linkDocument($ctx, $documentId, $meta['entity_type'], $meta['entity_id']);

        return [
            'drive_document_id' => $documentId,
            'drive_version_ref' => isset($document['doc_ref']) ? (string) $document['doc_ref'] : null,
            'local_path'        => null,
            'size_bytes'        => (int) ($document['size_bytes'] ?? $meta['size_bytes']),
            // Only Drive's own figure, never the caller's declared one: this
            // column is what "the counterparty returned it unchanged" is
            // decided on, and a checksum nobody computed would make that a lie.
            'checksum'          => $this->cleanChecksum($document['checksum_sha256'] ?? null),
            'link_id'           => isset($link['link_id']) && $link['link_id'] !== null ? (int) $link['link_id'] : null,
        ];
    }

    /** @inheritDoc */
    public function signedUrl(TenantContext $ctx, array $version, bool $inline): array
    {
        $documentId = (string) ($version['drive_document_id'] ?? '');
        if ($documentId === '') {
            throw DomainException::conflict('This version is not stored in Drive.');
        }

        $signed = $this->client->signedUrl($ctx, $documentId, $inline);

        // Drive knows the object; Contracts knows what the user called it. Ours
        // wins for display so a download is named after the contract document
        // rather than after whatever the object key ended up being.
        if (is_string($version['filename'] ?? null) && $version['filename'] !== '') {
            $signed['filename'] = (string) $version['filename'];
        }
        if (is_string($version['content_type'] ?? null) && $version['content_type'] !== '') {
            $signed['mime_type'] = (string) $version['content_type'];
        }

        return $signed;
    }

    /** @inheritDoc */
    public function delete(TenantContext $ctx, array $version): void
    {
        $documentId = (string) ($version['drive_document_id'] ?? '');
        if ($documentId === '') {
            return;
        }

        $this->client->deleteDocument($ctx, $documentId);
    }

    /** @inheritDoc */
    public function readBytes(TenantContext $ctx, array $version): ?string
    {
        $documentId = (string) ($version['drive_document_id'] ?? '');
        if ($documentId === '') {
            return null;
        }

        $signed = $this->client->signedUrl($ctx, $documentId, true);

        $max = Env::int('CONTRACTS_MAX_UPLOAD_MB', self::DEFAULT_MAX_UPLOAD_MB) * 1024 * 1024;

        return $this->client->fetchBytes($signed['url'], $max);
    }

    private function cleanChecksum(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $lower = strtolower(trim($value));

        // ck_document_versions_checksum only accepts 64 lowercase hex; anything
        // else would fail the insert, and a rejected contract upload because
        // Drive sent a padded or uppercase digest would be an absurd failure.
        return preg_match('/^[0-9a-f]{64}$/', $lower) === 1 ? $lower : null;
    }
}
