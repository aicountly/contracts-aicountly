<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Portal\PortalClient;

/**
 * The browser's session bootstrap, relayed to the AICOUNTLY portal.
 *
 * The relay exists because a new product domain is not in the portal's CORS
 * allow-list on day one, so the SPA cannot call it directly. It is an
 * allow-list of three paths and must stay one: forwarding arbitrary paths would
 * turn this host into an open proxy for the portal's whole auth surface —
 * login, signup, OTP, user lookup — with the portal seeing this server's IP
 * instead of the caller's, so anything it rate-limits per IP could be driven
 * through here instead.
 */
final class AuthRelayController
{
    /** @var list<string> */
    private const RELAYED_PATHS = [
        'seskey',
        'seskey/refresh',
        'refresh_authtoken',
    ];

    public function relay(?string $path = null): void
    {
        $portalPath = self::normalisePath((string) $path);

        if (! in_array($portalPath, self::RELAYED_PATHS, true)) {
            Response::error('PATH_NOT_RELAYED', 'This path is not relayed. Call the portal API directly.', 404);
        }

        $headers = [];

        // The auth_token, not a ses_key — this is the call that mints one.
        $authorization = Request::header('Authorization');
        if ($authorization !== null && $authorization !== '') {
            $headers[] = 'Authorization: ' . $authorization;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (is_string($contentType) && $contentType !== '') {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        $result = PortalClient::forward(
            Request::method(),
            $portalPath,
            $headers,
            (string) file_get_contents('php://input')
        );

        if ($result['status'] === 504) {
            Response::error('PORTAL_UNAVAILABLE', 'Auth service unavailable — please retry.', 504);
        }

        // The portal's body is returned verbatim: the SPA's token handling reads
        // its exact shape, and reshaping it here would make this file a second
        // place the auth contract lives.
        http_response_code($result['status']);
        header('Content-Type: ' . $result['contentType']);
        header('Cache-Control: no-store');
        echo $result['body'];
        exit;
    }

    /** Who the caller is, per the portal. Used by the SPA to confirm a session. */
    public function session(): void
    {
        $sesKey = Request::bearerToken();
        if ($sesKey === '') {
            Response::unauthorized('Missing bearer session key.');
        }

        $session = PortalClient::validateSesKey($sesKey);
        if ($session === null) {
            Response::unauthorized();
        }

        Response::success([
            'authenticated' => true,
            'uuid'          => $session['uuid_aictly'] ?? ($session['uuid'] ?? ''),
        ]);
    }

    /**
     * Collapse a routed path to the exact form the allow-list is written in.
     *
     * Percent-escapes are decoded first so `%2e%2e` cannot smuggle a traversal
     * segment past the check; exact matching does the rest.
     */
    private static function normalisePath(string $path): string
    {
        $decoded  = str_replace('\\', '/', rawurldecode($path));
        $segments = array_values(array_filter(
            explode('/', $decoded),
            static fn (string $segment): bool => $segment !== ''
        ));

        return strtolower(implode('/', $segments));
    }
}
