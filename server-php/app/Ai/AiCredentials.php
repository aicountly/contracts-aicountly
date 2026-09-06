<?php

declare(strict_types=1);

namespace App\Ai;

use App\Core\Env;
use App\Core\Http;
use Throwable;

/**
 * Fetches this product's AI provider credentials from AICOUNTLY Console.
 *
 * Console (console.aicountly.org) is the system of record for AI keys across
 * the estate. Before it, every product carried its own provider key in
 * server-php/.env on its own cPanel host — and the deploy never writes .env, so
 * rotating one key meant editing eleven boxes by hand and nothing could report
 * where a key was in use.
 *
 * ## Where the key lives now
 *
 * Console holds it encrypted and hands it over on GET /ai/credentials/resolve,
 * authenticated with the shared service key. Nothing is written to disk here:
 * the response is held in process memory, and in APCu when the extension is
 * present, so a key never lands in a file on the product host. That is the
 * whole point of the move — a compromised product host no longer yields a
 * long-lived provider key from a file sitting next to the code. For the same
 * reason nothing in this class puts a key in a log line, a URL, an exception
 * message or the status array the health endpoint renders.
 *
 * ## Why the .env fallback still exists
 *
 * Set AI_CREDENTIALS_SOURCE=console to require Console and fail closed when it
 * cannot answer. The default, `auto`, tries Console first and falls back to a
 * legacy .env key, logging each time it does.
 *
 * `auto` is the shipping default deliberately. Flipping every product to
 * Console-only before the keys are actually loaded into Console would take AI
 * down across the estate at once, and Console being briefly unreachable would
 * do the same afterwards. The migration runs in this order:
 *
 *   1. deploy with `auto` — behaviour is unchanged, and the logs show which
 *      products are still answering from .env
 *   2. load each product's key into Console → AI Connected Accounts
 *   3. confirm the logs are quiet, then set AI_CREDENTIALS_SOURCE=console and
 *      delete the CONTRACTS_*_API_KEY line from that host's .env
 *
 * Step 3 is what actually discontinues .env; steps 1 and 2 are what make it
 * safe to take.
 */
final class AiCredentials
{
    /** How Console keys this product's entries. */
    public const DOMAIN = 'contracts.aicountly.com';

    /** The .env names checked, in order, when Console cannot answer and `auto` is in force. */
    private const LEGACY_ENV_KEYS = ['CONTRACTS_AI_API_KEY', 'CONTRACTS_GEMINI_API_KEY', 'AICOUNTLY_GEMINI_API_KEY'];

    private const DEFAULT_TTL = 300;

    /** Short: an AI call the user is waiting on must not sit behind a slow credential lookup. */
    private const TIMEOUT = 4;

    /**
     * Per-process memo. PHP-FPM reuses a worker for many requests, and a
     * contract analysis makes several model calls in one request.
     *
     * @var array<string,array{value: array<string,mixed>|null, expires: int}>
     */
    private static array $memo = [];

    /**
     * Credentials for one module, or null when none can be obtained.
     *
     * @return array{
     *     api_key: string, model: string, provider: string, source: string,
     *     base_url: ?string, auth_header: ?string, ids: array<string,mixed>,
     *     fallbacks: list<array<string,mixed>>
     * }|null
     */
    public static function resolve(string $module, ?string $domain = null): ?array
    {
        $domain   = $domain !== null && trim($domain) !== '' ? trim($domain) : self::DOMAIN;
        $cacheKey = $domain . '|' . $module;

        if (isset(self::$memo[$cacheKey]) && self::$memo[$cacheKey]['expires'] > time()) {
            return self::$memo[$cacheKey]['value'];
        }

        $shared = self::apcuGet($cacheKey);
        if ($shared !== null) {
            self::$memo[$cacheKey] = ['value' => $shared, 'expires' => time() + 30];

            return $shared;
        }

        $fromConsole = self::fetch($domain, $module);
        if ($fromConsole !== null) {
            $primary = self::shape($fromConsole);
            if ($primary !== null) {
                $ttl = max(30, (int) ($fromConsole['ttl_seconds'] ?? self::DEFAULT_TTL));
                self::$memo[$cacheKey] = ['value' => $primary, 'expires' => time() + $ttl];
                self::apcuSet($cacheKey, $primary, $ttl);

                return $primary;
            }
        }

        if (self::consoleOnly()) {
            self::log("AI credentials for {$domain}/{$module} are unavailable and "
                . 'AI_CREDENTIALS_SOURCE=console forbids the .env fallback.');

            return null;
        }

        $legacy = self::legacyEnvKey();
        if ($legacy === '') {
            return null;
        }

        // Not cached: the legacy path is the one we want to stop using, so it
        // should keep re-attempting Console rather than settling into .env for
        // the life of the worker.
        self::log("AI credentials for {$domain}/{$module} came from .env because Console "
            . 'did not answer. Load this key into Console → AI Connected Accounts.');

        return [
            'api_key'     => $legacy,
            'model'       => Env::get('CONTRACTS_AI_MODEL', 'gemini-3.7-flash'),
            'provider'    => Env::get('CONTRACTS_AI_PROVIDER', 'google'),
            'source'      => 'env',
            'base_url'    => null,
            'auth_header' => null,
            'ids'         => [],
            'fallbacks'   => [],
        ];
    }

    /** True when this product is fully migrated and must not read .env. */
    public static function consoleOnly(): bool
    {
        return strtolower(trim(Env::get('AI_CREDENTIALS_SOURCE', 'auto'))) === 'console';
    }

    /** Whether Console is even wired up here. For health endpoints; says nothing about the key. */
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && trim(Env::get('CONSOLE_SERVICE_KEY')) !== '';
    }

    /**
     * Report one model call back to Console.
     *
     * Fire-and-forget by design: usage telemetry must never delay or fail a
     * user-facing AI response, so the timeout is short and every failure is
     * swallowed. The local ai_usage_log row is the record that matters for the
     * tenant; this is the estate-wide view of spend.
     *
     * @param array<string,mixed> $event
     */
    public static function reportUsage(array $event): void
    {
        $base = self::baseUrl();
        $key  = trim(Env::get('CONSOLE_SERVICE_KEY'));

        if ($base === '' || $key === '') {
            return;
        }

        // Defensive: no caller should be putting a key in a telemetry event,
        // and if one ever does it stops here rather than at Console's log.
        unset($event['api_key'], $event['key'], $event['authorization']);
        $event['domain'] = $event['domain'] ?? self::DOMAIN;

        try {
            $body = json_encode(['events' => [$event]], JSON_UNESCAPED_SLASHES);
            Http::request(
                'POST',
                $base . '/ai/usage',
                ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
                $body === false ? '{"events":[]}' : $body,
                2,
                1
            );
        } catch (Throwable $e) {
            // Telemetry is never worth an exception on the caller's path.
        }
    }

    /**
     * @return array<string,mixed>|null the decoded `data` envelope
     */
    private static function fetch(string $domain, string $module): ?array
    {
        $base = self::baseUrl();
        $key  = trim(Env::get('CONSOLE_SERVICE_KEY'));

        if ($base === '' || $key === '') {
            return null;
        }

        $url = $base . '/ai/credentials/resolve?domain=' . rawurlencode($domain)
            . '&module=' . rawurlencode($module);

        $result = Http::request('GET', $url, [
            'Authorization: Bearer ' . $key,
            'Accept: application/json',
        ], null, self::TIMEOUT, 2);

        if ($result['status'] !== 200 || $result['body'] === '') {
            self::log(sprintf(
                'Console AI resolve for %s/%s failed: %s',
                $domain,
                $module,
                $result['error'] !== '' ? $result['error'] : 'HTTP ' . $result['status']
            ));

            return null;
        }

        $json = json_decode($result['body'], true);

        return is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : null;
    }

    /**
     * Flatten Console's response to the primary credential, keeping the
     * fallbacks alongside so a caller with a step-down chain can use them
     * without a second round trip.
     *
     * @param  array<string,mixed>     $data
     * @return array<string,mixed>|null
     */
    private static function shape(array $data): ?array
    {
        $list = $data['credentials'] ?? null;
        if (! is_array($list) || $list === []) {
            return null;
        }

        $shaped = [];
        foreach (array_values($list) as $entry) {
            if (is_array($entry)) {
                $one = self::one($entry);
                if ($one !== null) {
                    $shaped[] = $one;
                }
            }
        }

        if ($shaped === []) {
            return null;
        }

        $primary              = $shaped[0];
        $primary['fallbacks'] = array_slice($shaped, 1);

        return $primary;
    }

    /**
     * @param  array<string,mixed>     $entry
     * @return array<string,mixed>|null
     */
    private static function one(array $entry): ?array
    {
        $apiKey = trim((string) ($entry['api_key'] ?? ''));
        if ($apiKey === '') {
            return null;
        }

        $baseUrl    = trim((string) ($entry['base_url'] ?? ''));
        $authHeader = trim((string) ($entry['auth_header'] ?? ''));

        return [
            'api_key'     => $apiKey,
            'model'       => trim((string) ($entry['model'] ?? '')),
            'provider'    => trim((string) ($entry['provider'] ?? 'google')),
            'source'      => 'console',
            'base_url'    => $baseUrl === '' ? null : $baseUrl,
            'auth_header' => $authHeader === '' ? null : $authHeader,
            'ids'         => is_array($entry['ids'] ?? null) ? $entry['ids'] : [],
            'fallbacks'   => [],
        ];
    }

    private static function legacyEnvKey(): string
    {
        foreach (self::LEGACY_ENV_KEYS as $name) {
            $value = trim(Env::get($name));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private static function baseUrl(): string
    {
        return rtrim(trim(Env::get('CONSOLE_API_URL')), '/');
    }

    /** @return array<string,mixed>|null */
    private static function apcuGet(string $key): ?array
    {
        if (! function_exists('apcu_fetch') || ! self::apcuEnabled()) {
            return null;
        }

        $hit   = false;
        $value = apcu_fetch('contracts_ai_cred:' . $key, $hit);

        return $hit && is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $value */
    private static function apcuSet(string $key, array $value, int $ttl): void
    {
        if (function_exists('apcu_store') && self::apcuEnabled()) {
            // Shared memory only — deliberately never a file, so a provider key
            // does not come to rest on the product host's disk.
            apcu_store('contracts_ai_cred:' . $key, $value, $ttl);
        }
    }

    private static function apcuEnabled(): bool
    {
        return function_exists('apcu_enabled') ? apcu_enabled() : (bool) ini_get('apc.enabled');
    }

    private static function log(string $message): void
    {
        error_log('[contracts.ai] ' . $message);
    }

    /** @internal tests only */
    public static function forgetForTests(): void
    {
        foreach (array_keys(self::$memo) as $key) {
            if (function_exists('apcu_delete') && self::apcuEnabled()) {
                apcu_delete('contracts_ai_cred:' . $key);
            }
        }

        self::$memo = [];
    }
}
