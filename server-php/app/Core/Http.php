<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Outbound HTTP for the ecosystem integrations.
 *
 * Every call out of Contracts — the portal, Manage, Contacts, Drive, Console,
 * an LLM provider — goes through here so timeouts, redirect policy and the
 * SSRF guard are decided once rather than per integration.
 */
final class Http
{
    /** @var callable(string,string,array<int,string>,?string,int,int):array{status:int,body:string,content_type:string,error:string}|null */
    private static $transportForTests = null;

    /**
     * @param array<int,string> $headers
     * @return array{status: int, body: string, content_type: string, error: string}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 15,
        int $connectTimeout = 5
    ): array {
        if (self::$transportForTests !== null) {
            return (self::$transportForTests)($method, $url, $headers, $body, $timeout, $connectTimeout);
        }

        if (! self::isSafeUrl($url)) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => 'Refused unsafe outbound URL.'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => 'curl_init failed'];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HEADER         => false,
            // No redirect following. A 30x from an integration is either a
            // misconfiguration or an attempt to walk us somewhere else; either
            // way the caller should see it rather than have it silently obeyed.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS_STR  => 'https,http',
        ];

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            // Set even when empty: a bodiless CUSTOMREQUEST POST goes out with
            // no Content-Length, which some upstreams reject outright.
            $options[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        curl_setopt_array($ch, $options);

        $raw         = curl_exec($ch);
        $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error       = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => $error !== '' ? $error : 'transport failure'];
        }

        return [
            'status'       => $status,
            'body'         => (string) $raw,
            'content_type' => $contentType !== '' ? $contentType : 'application/json',
            'error'        => '',
        ];
    }

    /**
     * @param array<int,string> $headers
     * @return array<string,mixed>|null decoded JSON, or null on any failure
     */
    public static function json(
        string $method,
        string $url,
        array $headers = [],
        mixed $body = null,
        int $timeout = 15
    ): ?array {
        $encoded = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($encoded !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        $headers[] = 'Accept: application/json';

        $result = self::request($method, $url, $headers, $encoded, $timeout);
        if ($result['status'] < 200 || $result['status'] >= 300 || $result['body'] === '') {
            return null;
        }

        $decoded = json_decode($result['body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Reject URLs that point back inside the network.
     *
     * The only user-influenced outbound URLs in this product are provider base
     * URLs handed over by Console, but "only" is exactly the assumption that
     * stops being true later, and an SSRF through a config value is as good as
     * one through a form field.
     */
    public static function isSafeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower(trim($parts['host'], '[]'));

        // Literal IPs are checked directly; hostnames are resolved first, so a
        // name that happens to map to 169.254.169.254 is caught as well as the
        // raw address. A host that resolves to nothing is refused rather than
        // allowed — a DNS failure is not evidence that a destination is safe.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false
            ? [$host]
            : (gethostbynamel($host) ?: []);

        if ($addresses === [] && ! in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return false;
        }

        if (in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            return self::allowLoopback();
        }

        foreach ($addresses as $ip) {
            if (self::isLoopbackIp($ip)) {
                // Local development points an integration at 127.0.0.1. That is
                // the only case the escape hatch covers.
                if (! self::allowLoopback()) {
                    return false;
                }
                continue;
            }

            // Everything else non-public is refused unconditionally. Link-local
            // in particular is where the cloud metadata endpoint
            // (169.254.169.254) lives, and no development convenience is worth
            // making instance credentials reachable through a config value.
            if (self::isNonPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function allowLoopback(): bool
    {
        return Env::bool('ALLOW_LOOPBACK_INTEGRATIONS', false);
    }

    private static function isLoopbackIp(string $ip): bool
    {
        if ($ip === '::1') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && str_starts_with($ip, '127.');
    }

    /** Private, reserved, link-local, multicast — anything not routable on the internet. */
    private static function isNonPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /** @internal tests only @param callable|null $transport */
    public static function setTransportForTests(?callable $transport): void
    {
        self::$transportForTests = $transport;
    }
}
