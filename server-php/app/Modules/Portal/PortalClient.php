<?php

declare(strict_types=1);

namespace App\Modules\Portal;

use App\Core\Env;
use App\Core\Http;

/**
 * Server-to-server calls to the AICOUNTLY auth portal.
 *
 * The portal owns every token. Contracts never mints, signs or stores one — it
 * relays the browser's session-bootstrap calls and asks the portal whether a
 * ses_key is still good.
 */
final class PortalClient
{
    /**
     * Always my.aicountly.com, in sandbox as well as production.
     *
     * sandbox.aicountly.com serves the *login redirect* only; seskey, refresh
     * and validatesession live on my.aicountly.com in every environment.
     * See docs/auth/AICOUNTLY_AUTH_WORKFLOW.md.
     */
    private const DEFAULT_AUTH_BASE = 'https://my.aicountly.com';

    /** Per-process memo so one request validating twice costs one round trip. */
    private static array $sessionMemo = [];

    public static function base(): string
    {
        return rtrim(Env::get('PORTAL_AUTH_BASE', self::DEFAULT_AUTH_BASE), '/');
    }

    /**
     * Forward one request to the portal and return its raw answer.
     *
     * @param array<int,string> $headers
     * @return array{status: int, body: string, contentType: string}
     */
    public static function forward(string $method, string $path, array $headers, string $body): array
    {
        $result = Http::request(
            $method,
            self::base() . '/api/' . ltrim($path, '/'),
            $headers,
            $body,
            15,
            8
        );

        if ($result['status'] === 0) {
            return ['status' => 504, 'body' => '', 'contentType' => 'application/json'];
        }

        return [
            'status'      => $result['status'],
            'body'        => $result['body'],
            'contentType' => $result['content_type'],
        ];
    }

    /**
     * Validate a Bearer ses_key.
     *
     * The portal answers `status: 1` plus the caller's uuid when the key is
     * live. Anything else — a transport failure included — is "not
     * authenticated", so a portal outage denies access rather than granting it.
     *
     * @return array<string,mixed>|null
     */
    public static function validateSesKey(string $sesKey): ?array
    {
        if ($sesKey === '') {
            return null;
        }

        // Keyed by hash, not by the key itself: this array can end up in a
        // stack trace or a debug dump, and a live session key must not.
        $memoKey = hash('sha256', $sesKey);
        if (array_key_exists($memoKey, self::$sessionMemo)) {
            return self::$sessionMemo[$memoKey];
        }

        $result = self::forward('POST', 'validatesession', [
            'Authorization: Bearer ' . $sesKey,
            'Content-Type: application/json',
        ], '');

        if ($result['status'] !== 200 || $result['body'] === '') {
            return self::$sessionMemo[$memoKey] = null;
        }

        // A CodeIgniter error page is HTML with a 200; decoding it would yield
        // null anyway, but naming the case keeps the failure legible in logs.
        if (str_contains($result['body'], 'CodeIgniter')) {
            return self::$sessionMemo[$memoKey] = null;
        }

        $data = json_decode($result['body'], true);
        if (! is_array($data)) {
            return self::$sessionMemo[$memoKey] = null;
        }

        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        if ((int) ($data['status'] ?? 0) !== 1) {
            return self::$sessionMemo[$memoKey] = null;
        }

        return self::$sessionMemo[$memoKey] = $data;
    }

    /** @internal tests only */
    public static function resetMemo(): void
    {
        self::$sessionMemo = [];
    }
}
