<?php

declare(strict_types=1);

namespace App\Modules\Contacts;

use App\Core\Env;
use App\Core\Http;
use App\Support\DomainException;
use App\Support\TenantContext;
use Throwable;

/**
 * The counterparty master, read from AICOUNTLY Contacts.
 *
 * Contracts never copies the contact master into its own tables. A party row
 * holds the contact id and nothing else that Contacts owns, and every screen
 * re-reads the live record through here — so a counterparty who moves office
 * is not still at the old address on eleven contract pages.
 *
 * The one exception is a party snapshot, and it is not a cache: it is evidence,
 * written once at execution and never refreshed. See PartyService.
 *
 * Every call carries the caller's own ses_key and company headers, never a
 * service credential. Contacts re-runs its own session check, so a user whose
 * session has ended cannot read the address book through Contracts.
 *
 * Failure has two distinct meanings here and they must not be collapsed.
 * `find()` returning null means Contacts answered and has no such contact for
 * this session; Contacts being unreachable throws instead, because a snapshot
 * written from an empty reply would be a fabricated legal record. `search()`
 * is the opposite case — a type-ahead that decorates a form somebody can fill
 * in by hand — so it degrades to an empty list rather than failing the request.
 */
final class ContactsClient
{
    /** Contacts is deployed under `public_html/api/`, so every route is /api/<path>. */
    private const PREFIX = '/api';

    /** A type-ahead must not hold a PHP-FPM worker while Contacts thinks about it. */
    private const TIMEOUT = 8;

    public static function base(): string
    {
        $fromEnv = trim(Env::get('CONTACTS_API_BASE'));
        if ($fromEnv !== '') {
            return self::normaliseBase($fromEnv);
        }

        return self::isSandboxHost() ? 'https://contacts.gh.aicountly.com' : 'https://contacts.aicountly.com';
    }

    /**
     * Contacts matching a free-text query, in the stable shape below.
     *
     * @return list<array<string,mixed>>
     */
    public static function search(TenantContext $ctx, string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 50));

        try {
            $payload = self::call($ctx, '/contacts?' . http_build_query([
                'q'        => $query,
                'per_page' => $limit,
                'page'     => 1,
            ]));
        } catch (DomainException $e) {
            // Degrade rather than break. Somebody recording a counterparty can
            // always type the name; failing the whole form because the lookup
            // that decorates it is down would be a worse outage than the one
            // actually happening.
            error_log('[contracts][contacts] search unavailable: ' . $e->getMessage());

            return [];
        }

        $rows = $payload['data'] ?? null;
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) && $row !== []) {
                $out[] = self::normalise($row);
            }
        }

        return $out;
    }

    /**
     * One contact, or null when Contacts has none by that id for this session.
     *
     * @return array<string,mixed>|null
     * @throws DomainException when Contacts cannot be reached
     */
    public static function find(TenantContext $ctx, string $contactId): ?array
    {
        $contactId = trim($contactId);
        if ($contactId === '') {
            return null;
        }

        $payload = self::call($ctx, '/contacts/' . rawurlencode($contactId), true);
        if ($payload === null) {
            return null;
        }

        $row = $payload['data'] ?? null;

        return is_array($row) && $row !== [] ? self::normalise($row) : null;
    }

    /**
     * Contacts' camelCase envelope reduced to the fields a contract needs.
     *
     * The shape is fixed here rather than at each call site because it is what
     * a party snapshot is written from, and a snapshot is read back years
     * later by someone who cannot ask what a key meant.
     *
     * Three of these fields have no home in Contacts. It stores no GSTIN, PAN
     * or CIN column — the ecosystem importers deliberately leave statutory
     * identifiers behind in Books — so they are read from `integrationMeta`
     * when an importer happened to carry them and are otherwise empty. Nothing
     * here invents them: an empty statutory identifier in a snapshot is honest,
     * a guessed one is evidence of something that never happened.
     *
     * `contact_persons` is the same story from the other side. Contacts models
     * a person at an organisation as the contact itself — Books' importer puts
     * the human in `displayName` and the entity in `organizationName` — so the
     * list has one entry for an organisation whose contact is a named person,
     * and is empty for a contact who *is* the person.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public static function normalise(array $raw): array
    {
        $meta = is_array($raw['integrationMeta'] ?? null) ? $raw['integrationMeta'] : [];

        $displayName = self::text($raw, ['displayName', 'display_name']);
        $legalName   = self::text($raw, ['organizationName', 'organization_name']);
        $companyName = self::text($raw, ['companyName', 'company_name']);
        $type        = self::text($raw, ['contactKind', 'contact_kind']) ?: 'personal';

        // An organisation's legal name is the entity, not the person answering
        // the phone for it. Only when Contacts holds no entity at all does the
        // display name stand in for one.
        if ($legalName === '') {
            $legalName = $displayName;
        }

        $email = self::firstValue($raw, ['emails']);
        $phone = self::firstValue($raw, ['phones']);

        return [
            'id'                 => self::text($raw, ['id']),
            'display_name'       => $displayName,
            'legal_name'         => $legalName,
            // The company tag is a trading name only when it says something the
            // legal name does not; repeating the legal name in both fields
            // would read as a corroboration it is not.
            'trading_name'       => $companyName !== '' && $companyName !== $legalName ? $companyName : '',
            'type'               => $type,
            'email'              => $email,
            'phone'              => $phone,
            'registered_address' => self::firstValue($raw, ['addresses']),
            'gstin'              => self::text($meta, ['gstin', 'gst_no', 'gst_number']),
            'pan'                => self::text($meta, ['pan', 'pan_no', 'pan_number']),
            'cin'                => self::text($meta, ['cin', 'cin_no']),
            'contact_persons'    => self::contactPersons($displayName, $legalName, $email, $phone, $meta),
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * One GET against Contacts, with its envelope returned and its refusals
     * translated.
     *
     * A 404 is the caller's answer when `$nullOn404` is set — "no such contact"
     * is information, not a failure. Everything else that is not a 2xx is this
     * integration being unavailable, including a rejected session: from here
     * there is no way to tell a dead ses_key from Contacts having lost its own
     * session backend, and treating a maybe-transient failure as an
     * authorisation decision would be the wrong guess to make silently.
     *
     * @return array<string,mixed>|null
     * @throws DomainException
     */
    private static function call(TenantContext $ctx, string $path, bool $nullOn404 = false): ?array
    {
        try {
            $result = Http::request('GET', self::base() . self::PREFIX . $path, self::headers($ctx), null, self::TIMEOUT);
        } catch (Throwable $e) {
            throw DomainException::unavailable('Contacts could not be reached.');
        }

        if ($nullOn404 && $result['status'] === 404) {
            return null;
        }

        if ($result['status'] === 0) {
            // Http reports a dead socket, a refused connection and a blocked
            // URL alike as status 0, and none of them is an answer from
            // Contacts.
            throw DomainException::unavailable('Contacts could not be reached.');
        }

        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw DomainException::unavailable(sprintf('Contacts is not answering (HTTP %d).', $result['status']));
        }

        $decoded = json_decode($result['body'], true);
        if (! is_array($decoded)) {
            throw DomainException::unavailable('Contacts returned a reply this product could not read.');
        }

        return $decoded;
    }

    /** @return list<string> */
    private static function headers(TenantContext $ctx): array
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
     * @param array<string,mixed> $meta
     * @return list<array{name: string, designation: string, email: string, phone: string}>
     */
    private static function contactPersons(
        string $displayName,
        string $legalName,
        string $email,
        string $phone,
        array $meta
    ): array {
        if ($displayName === '' || $displayName === $legalName) {
            return [];
        }

        return [[
            'name'        => $displayName,
            'designation' => self::text($meta, ['designation', 'job_title', 'title', 'role']),
            'email'       => $email,
            'phone'       => $phone,
        ]];
    }

    /**
     * The first usable entry of one of Contacts' `[{value: …}]` lists.
     *
     * @param array<string,mixed> $raw
     * @param list<string>        $keys
     */
    private static function firstValue(array $raw, array $keys): string
    {
        foreach ($keys as $key) {
            if (! isset($raw[$key]) || ! is_array($raw[$key])) {
                continue;
            }
            foreach ($raw[$key] as $entry) {
                $value = is_array($entry)
                    ? (string) ($entry['value'] ?? '')
                    : (is_string($entry) || is_numeric($entry) ? (string) $entry : '');
                $value = trim($value);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private static function text(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && (is_string($row[$key]) || is_numeric($row[$key]))) {
                $value = trim((string) $row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function isSandboxHost(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = preg_replace('/^www\./i', '', $host) ?? '';

        return str_ends_with($host, '.gh.aicountly.com')
            || str_starts_with($host, 'gh-')
            || $host === 'localhost'
            || $host === '127.0.0.1';
    }

    /** The gh-* sandbox hosts were retired; remap so a stale .env still resolves. */
    private static function normaliseBase(string $base): string
    {
        $base   = rtrim(trim($base), '/');
        $legacy = [
            'https://gh-contacts.aicountly.com' => 'https://contacts.gh.aicountly.com',
            'http://gh-contacts.aicountly.com'  => 'https://contacts.gh.aicountly.com',
        ];

        return $legacy[$base] ?? $base;
    }
}
