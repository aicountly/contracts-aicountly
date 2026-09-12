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

    /** @var (callable(string):array<int,string>)|null */
    private static $resolverForTests = null;

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
        int $connectTimeout = 5,
        ?int $maxResponseBytes = null
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

        // A caller with a budget gets the body streamed through this callback
        // instead of CURLOPT_RETURNTRANSFER's whole-response buffer. Returning
        // a short count is libcurl's own signal to abort mid-transfer, so an
        // oversized response is cut off on the wire rather than fully read into
        // memory only to be discarded by a length check after curl_exec returns.
        $chunks     = [];
        $received   = 0;
        $overBudget = false;
        if ($maxResponseBytes !== null) {
            $options[CURLOPT_WRITEFUNCTION] = static function ($ch, string $chunk) use (&$chunks, &$received, &$overBudget, $maxResponseBytes): int {
                $received += strlen($chunk);
                if ($received > $maxResponseBytes) {
                    $overBudget = true;

                    return 0;
                }
                $chunks[] = $chunk;

                return strlen($chunk);
            };
        }

        curl_setopt_array($ch, $options);

        $raw         = curl_exec($ch);
        $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error       = curl_error($ch);
        curl_close($ch);

        if ($overBudget) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => 'Response exceeded the configured size limit.'];
        }

        if ($raw === false) {
            return ['status' => 0, 'body' => '', 'content_type' => '', 'error' => $error !== '' ? $error : 'transport failure'];
        }

        return [
            'status'       => $status,
            'body'         => $maxResponseBytes !== null ? implode('', $chunks) : (string) $raw,
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
            : self::resolveAddresses($host);

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

    /**
     * All addresses a hostname resolves to — A and AAAA both.
     *
     * gethostbynamel() alone is AF_INET only: it silently drops every AAAA
     * record. curl resolves the same name with getaddrinfo() and will connect
     * over whichever family the OS prefers, so an isSafeUrl() that vetted only
     * the A records would wave through a host whose public A record passes
     * and whose AAAA record is loopback or link-local — the guard and the
     * actual connection would not be looking at the same address.
     *
     * @return array<int,string>
     */
    private static function resolveAddresses(string $host): array
    {
        if (self::$resolverForTests !== null) {
            return (self::$resolverForTests)($host);
        }

        $addresses = gethostbynamel($host) ?: [];

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return $addresses;
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
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return true;
        }

        // NAT64 (64:ff9b::/96) and 6to4 (2002::/16) embed an IPv4 address in
        // their low bits. filter_var's reserved-range table does not know
        // either prefix, so a mapped 169.254.169.254 reads as a public IPv6
        // address unless the embedded address is pulled out and checked too.
        $embedded = self::embeddedIpv4($ip);

        return $embedded !== null && self::isNonPublicIp($embedded);
    }

    /** Trailing IPv4 address embedded in a NAT64 or 6to4 IPv6 address, or null if $ip is neither. */
    private static function embeddedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $nat64Prefix = inet_pton('64:ff9b::');
        if ($nat64Prefix !== false && substr($packed, 0, 12) === substr($nat64Prefix, 0, 12)) {
            $embedded = inet_ntop(substr($packed, 12, 4));

            return $embedded === false ? null : $embedded;
        }

        $sixToFourPrefix = inet_pton('2002::');
        if ($sixToFourPrefix !== false && substr($packed, 0, 2) === substr($sixToFourPrefix, 0, 2)) {
            $embedded = inet_ntop(substr($packed, 2, 4));

            return $embedded === false ? null : $embedded;
        }

        return null;
    }

    /** @internal tests only @param callable|null $transport */
    public static function setTransportForTests(?callable $transport): void
    {
        self::$transportForTests = $transport;
    }

    /** @internal tests only @param (callable(string):array<int,string>)|null $resolver */
    public static function setResolverForTests(?callable $resolver): void
    {
        self::$resolverForTests = $resolver;
    }
}
