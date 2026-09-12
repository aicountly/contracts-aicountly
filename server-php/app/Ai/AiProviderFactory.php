<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Turns Console's credential record into something that can answer a prompt.
 *
 * This is the only place in the product that knows which vendor is behind the
 * AI features. Services ask for a provider, get the interface, and never learn
 * whether Gemini or Claude answered.
 *
 * `forModule()` returns null rather than a stub when nothing is configured.
 * That distinction carries all the way to the screen: a deployment with no AI
 * key says so on /api/health and hides the AI actions, instead of offering an
 * "Analyse contract" button that fails on click. A stub that returned empty
 * extractions would be worse still — an empty risk assessment is
 * indistinguishable from a clean contract.
 */
final class AiProviderFactory
{
    /** Console keys credentials by domain and module; this is Contracts' only module today. */
    public const DEFAULT_MODULE = 'contract_ai';

    /**
     * Provider strings Console may hand back, mapped to the class that speaks
     * that API. Spellings vary by who typed the Connected Account, so the
     * common aliases are accepted rather than requiring one exact word.
     *
     * @var array<string,class-string<ContractsAiProvider>>
     */
    private const PROVIDERS = [
        'google'           => GeminiProvider::class,
        'gemini'           => GeminiProvider::class,
        'google-gemini'    => GeminiProvider::class,
        'googleai'         => GeminiProvider::class,
        'google-ai'        => GeminiProvider::class,
        'vertex'           => GeminiProvider::class,
        'openai'           => OpenAiProvider::class,
        'open-ai'          => OpenAiProvider::class,
        'azure'            => OpenAiProvider::class,
        'azure-openai'     => OpenAiProvider::class,
        'openai-compatible' => OpenAiProvider::class,
        'anthropic'        => AnthropicProvider::class,
        'claude'           => AnthropicProvider::class,
    ];

    /** The provider to use, or null when this deployment has no usable AI configuration. */
    public static function forModule(string $module = self::DEFAULT_MODULE): ?ContractsAiProvider
    {
        return self::chain($module)[0] ?? null;
    }

    /**
     * The primary provider followed by Console's fallbacks, in order.
     *
     * A caller that can afford a second attempt walks this list when the first
     * provider is rate limited or down. Handing back the whole chain rather
     * than making the caller re-resolve keeps the step-down to one credential
     * lookup, which matters because a chain is only walked when things are
     * already going badly.
     *
     * @return list<ContractsAiProvider>
     */
    public static function chain(string $module = self::DEFAULT_MODULE): array
    {
        $credentials = AiCredentials::resolve($module);
        if ($credentials === null) {
            return [];
        }

        $out      = [];
        $fallbacks = is_array($credentials['fallbacks'] ?? null) ? $credentials['fallbacks'] : [];

        foreach (array_merge([$credentials], array_values($fallbacks)) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $provider = self::instantiate($entry);
            if ($provider !== null) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /**
     * What the health endpoint and the settings screen report.
     *
     * Deliberately never carries the API key, not even truncated: this array is
     * serialised into a response that a signed-in user can read, and a key
     * prefix is still a key prefix.
     *
     * @return array{configured: bool, provider: ?string, model: ?string, source: ?string, module: string, console: bool, fallbacks: int, message: ?string}
     */
    public static function status(string $module = self::DEFAULT_MODULE): array
    {
        $console = AiCredentials::isConfigured();
        $base    = [
            'configured' => false,
            'provider'   => null,
            'model'      => null,
            'source'     => null,
            'module'     => $module,
            'console'    => $console,
            'fallbacks'  => 0,
            'message'    => null,
        ];

        $credentials = AiCredentials::resolve($module);
        if ($credentials === null) {
            $base['message'] = $console
                ? 'Console has no AI credential for this product and module.'
                : 'Console is not configured on this host (CONSOLE_API_URL / CONSOLE_SERVICE_KEY).';

            return $base;
        }

        $provider  = (string) ($credentials['provider'] ?? '');
        $model     = (string) ($credentials['model'] ?? '');
        $supported = self::classFor($provider) !== null;
        $fallbacks = is_array($credentials['fallbacks'] ?? null) ? $credentials['fallbacks'] : [];

        $base['provider']  = $provider === '' ? null : $provider;
        $base['model']     = $model === '' ? null : $model;
        $base['source']    = (string) ($credentials['source'] ?? 'console');
        $base['fallbacks'] = count($fallbacks);
        $base['configured'] = $supported && $model !== '';

        if (! $supported) {
            $base['message'] = "This build has no client for the '{$provider}' provider.";
        } elseif ($model === '') {
            $base['message'] = 'The Console credential for this module has no model set.';
        }

        return $base;
    }

    /** @param array<string,mixed> $credential */
    private static function instantiate(array $credential): ?ContractsAiProvider
    {
        $apiKey = trim((string) ($credential['api_key'] ?? ''));
        $model  = trim((string) ($credential['model'] ?? ''));
        $class  = self::classFor((string) ($credential['provider'] ?? ''));

        // A credential with no model is a half-finished Console entry. Calling
        // with a guessed model name would bill someone for a request that was
        // never going to be the one they configured.
        if ($apiKey === '' || $model === '' || $class === null) {
            return null;
        }

        $baseUrl    = isset($credential['base_url']) && is_string($credential['base_url']) ? $credential['base_url'] : null;
        $authHeader = isset($credential['auth_header']) && is_string($credential['auth_header']) ? $credential['auth_header'] : null;

        return new $class($apiKey, $model, $baseUrl, $authHeader);
    }

    /** @return class-string<ContractsAiProvider>|null */
    private static function classFor(string $provider): ?string
    {
        $key = strtolower(trim($provider));
        $key = str_replace([' ', '_', '.'], '-', $key);

        return self::PROVIDERS[$key] ?? null;
    }
}
