<?php

declare(strict_types=1);

/**
 * Credential resolution and provider selection, with every HTTP call answered
 * by Http's test transport.
 *
 * Nothing here touches the network. That is not only about speed: a test that
 * could reach api.openai.com would need a real key to be meaningful, and a real
 * key in a test fixture is exactly what moving the estate to Console was meant
 * to stop.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Ai\AiCredentials;
use App\Ai\AiProviderFactory;
use App\Ai\AnthropicProvider;
use App\Ai\GeminiProvider;
use App\Ai\OpenAiProvider;
use App\Core\Env;
use App\Core\Http;

Env::configureForTests([
    'CONSOLE_API_URL'       => 'https://console.aicountly.org/api',
    'CONSOLE_SERVICE_KEY'   => 'svc-test-key',
    'AI_CREDENTIALS_SOURCE' => 'auto',
    'CONTRACTS_AI_API_KEY'  => '',
    'CONTRACTS_GEMINI_API_KEY' => '',
    'AICOUNTLY_GEMINI_API_KEY' => '',
]);

// Console failures are logged on purpose; pointing error_log at a scratch file
// keeps that noise out of the suite output without silencing the code under test.
ini_set('error_log', sys_get_temp_dir() . '/contracts-ai-test.log');

/** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> $calls */
$calls = [];

/** @var array<string,array{status: int, body: string, content_type: string, error: string}> $routes */
$routes = [];

Http::setTransportForTests(
    function (string $method, string $url, array $headers, ?string $body, int $timeout, int $connect) use (&$calls, &$routes): array {
        $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        foreach ($routes as $needle => $response) {
            if (str_contains($url, (string) $needle)) {
                return $response;
            }
        }

        return ['status' => 404, 'body' => '{"error":{"message":"no stub"}}', 'content_type' => 'application/json', 'error' => ''];
    }
);

/** @param list<array<string,mixed>> $credentials */
$console = static function (array $credentials): array {
    return [
        'status'       => 200,
        'body'         => (string) json_encode(['data' => ['credentials' => $credentials, 'ttl_seconds' => 300]]),
        'content_type' => 'application/json',
        'error'        => '',
    ];
};

$reply = static function (array $payload, int $status = 200): array {
    return [
        'status'       => $status,
        'body'         => (string) json_encode($payload),
        'content_type' => 'application/json',
        'error'        => '',
    ];
};

// ---------------------------------------------------------------------------
// Google, the estate default
// ---------------------------------------------------------------------------

$routes = ['/ai/credentials/resolve' => $console([
    ['api_key' => 'gem-secret-key-001', 'model' => 'gemini-3.7-flash', 'provider' => 'google'],
])];

$provider = AiProviderFactory::forModule('m_google');
assert_true($provider instanceof GeminiProvider, 'a google credential yields the Gemini provider');
assert_same('google', $provider->name(), 'the provider reports its family');

assert_contains('domain=contracts.aicountly.com', $calls[0]['url'], 'the resolve call names this product domain');
assert_contains('module=m_google', $calls[0]['url'], 'and the module asked for');
assert_true(
    in_array('Authorization: Bearer svc-test-key', $calls[0]['headers'], true),
    'the resolve call carries the Console service key'
);

$status = AiProviderFactory::status('m_google');
assert_true($status['configured'], 'status reports configured');
assert_same('google', $status['provider'], 'status reports the provider');
assert_same('gemini-3.7-flash', $status['model'], 'status reports the model');
assert_same('console', $status['source'], 'status reports where the credential came from');
assert_same(0, $status['fallbacks'], 'status counts the fallbacks');

// The one thing this array must never carry, however it is rendered.
assert_not_contains('gem-secret-key-001', (string) json_encode($status), 'status never exposes the API key');
assert_false(array_key_exists('api_key', $status), 'status has no api_key element at all');

// ---------------------------------------------------------------------------
// The Gemini call itself
// ---------------------------------------------------------------------------

$routes[':generateContent'] = $reply([
    'candidates'    => [[
        'content'      => ['parts' => [['text' => '{"risk_level":"medium"}']]],
        'finishReason' => 'STOP',
    ]],
    'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 40],
    'modelVersion'  => 'gemini-3.7-flash-002',
]);

$calls  = [];
$result = $provider->complete(
    [['role' => 'user', 'content' => 'What is the risk level?']],
    ['system' => 'You analyse contracts.', 'max_tokens' => 512, 'json_schema' => [
        'type'       => 'object',
        'properties' => ['risk_level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']]],
        'required'   => ['risk_level'],
    ]]
);

assert_same('{"risk_level":"medium"}', $result['text'], 'the reply text is returned');
assert_same(1200, $result['prompt_tokens'], 'prompt tokens are read from usageMetadata');
assert_same(40, $result['output_tokens'], 'output tokens are read from usageMetadata');
assert_same('gemini-3.7-flash-002', $result['model'], 'the model actually served is reported');

$request = $calls[0];
assert_contains('/v1beta/models/gemini-3.7-flash:generateContent', $request['url'], 'the generateContent endpoint is called');
assert_not_contains('key=', $request['url'], 'the API key is not put in the query string');
assert_true(
    in_array('x-goog-api-key: gem-secret-key-001', $request['headers'], true),
    'the API key travels in a header'
);

$sent = json_decode((string) $request['body'], true);
assert_same('You analyse contracts.', $sent['systemInstruction']['parts'][0]['text'], 'the system prompt becomes systemInstruction');
assert_same('user', $sent['contents'][0]['role'], 'the user turn is carried');
assert_same(512, $sent['generationConfig']['maxOutputTokens'], 'the output cap is passed through');
assert_same('application/json', $sent['generationConfig']['responseMimeType'], 'a schema switches the response to JSON');
assert_same('OBJECT', $sent['generationConfig']['responseSchema']['type'], 'the schema is translated to Gemini spelling');
assert_same('STRING', $sent['generationConfig']['responseSchema']['properties']['risk_level']['type'], 'nested types too');

// A provider that cannot be reached says so rather than returning nothing.
$routes[':generateContent'] = ['status' => 0, 'body' => '', 'content_type' => '', 'error' => 'could not connect'];
assert_throws(
    static fn () => $provider->complete([['role' => 'user', 'content' => 'hello']]),
    'an unreachable provider throws',
    'could not be reached'
);

$routes[':generateContent'] = $reply(['error' => ['message' => 'Quota exceeded for this project.']], 429);
assert_throws(
    static fn () => $provider->complete([['role' => 'user', 'content' => 'hello']]),
    'a rate limited provider throws with the upstream reason',
    'Quota exceeded'
);

// ---------------------------------------------------------------------------
// OpenAI
// ---------------------------------------------------------------------------

AiCredentials::forgetForTests();
$routes['/ai/credentials/resolve'] = $console([
    ['api_key' => 'sk-test-key-002', 'model' => 'gpt-4o-mini', 'provider' => 'openai'],
]);
$routes['/chat/completions'] = $reply([
    'model'   => 'gpt-4o-mini-2024-07-18',
    'choices' => [['message' => ['content' => '{"title":"MSA"}'], 'finish_reason' => 'stop']],
    'usage'   => ['prompt_tokens' => 900, 'completion_tokens' => 12],
]);

$provider = AiProviderFactory::forModule('m_openai');
assert_true($provider instanceof OpenAiProvider, 'an openai credential yields the OpenAI provider');
assert_same('openai', $provider->name(), 'the OpenAI provider reports its family');

$calls  = [];
$result = $provider->complete(
    [['role' => 'user', 'content' => 'Extract the title.']],
    ['system' => 'You analyse contracts.', 'json_schema' => ['type' => 'object'], 'schema_name' => 'contract extraction']
);

assert_same('{"title":"MSA"}', $result['text'], 'the OpenAI reply text is returned');
assert_same(900, $result['prompt_tokens'], 'OpenAI prompt tokens are read');
assert_same(12, $result['output_tokens'], 'OpenAI completion tokens are read');

$request = $calls[0];
assert_contains('/v1/chat/completions', $request['url'], 'the chat completions endpoint is called');
assert_true(in_array('Authorization: Bearer sk-test-key-002', $request['headers'], true), 'OpenAI is authenticated with a bearer token');

$sent = json_decode((string) $request['body'], true);
assert_same('system', $sent['messages'][0]['role'], 'the system prompt is prepended as a message');
assert_same('json_schema', $sent['response_format']['type'], 'a schema switches on structured output');
assert_same('contract_extraction', $sent['response_format']['json_schema']['name'], 'the schema name is made API-safe');

// ---------------------------------------------------------------------------
// Anthropic
// ---------------------------------------------------------------------------

AiCredentials::forgetForTests();
$routes['/ai/credentials/resolve'] = $console([
    ['api_key' => 'ant-test-key-003', 'model' => 'claude-opus-5', 'provider' => 'anthropic'],
]);
$routes['/v1/messages'] = $reply([
    'model'       => 'claude-opus-5',
    'stop_reason' => 'tool_use',
    'content'     => [
        ['type' => 'text', 'text' => 'Calling the tool.'],
        ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'contract_analysis', 'input' => ['risk_level' => 'high']],
    ],
    'usage'       => ['input_tokens' => 2000, 'output_tokens' => 30],
]);

$provider = AiProviderFactory::forModule('m_anthropic');
assert_true($provider instanceof AnthropicProvider, 'an anthropic credential yields the Anthropic provider');
assert_same('anthropic', $provider->name(), 'the Anthropic provider reports its family');

$calls  = [];
$result = $provider->complete(
    [['role' => 'system', 'content' => 'You analyse contracts.'], ['role' => 'user', 'content' => 'Rate the risk.']],
    ['json_schema' => ['type' => 'object', 'properties' => ['risk_level' => ['type' => 'string']]]]
);

assert_same('{"risk_level":"high"}', $result['text'], 'a tool_use answer is returned as JSON text');
assert_same(2000, $result['prompt_tokens'], 'Anthropic input tokens are read');
assert_same(30, $result['output_tokens'], 'Anthropic output tokens are read');

$request = $calls[0];
assert_contains('/v1/messages', $request['url'], 'the messages endpoint is called');
assert_true(in_array('x-api-key: ant-test-key-003', $request['headers'], true), 'Anthropic is authenticated with x-api-key');
assert_true(in_array('anthropic-version: 2023-06-01', $request['headers'], true), 'the pinned API version is sent');

$sent = json_decode((string) $request['body'], true);
assert_contains('You analyse contracts.', $sent['system'], 'a system message is lifted out of the turns');
assert_same('user', $sent['messages'][0]['role'], 'the conversation opens on a user turn');
assert_same('contract_analysis', $sent['tools'][0]['name'], 'the schema is offered as a tool');
assert_false(array_key_exists('tool_choice', $sent), 'the tool call is not forced, which newer models reject');

// ---------------------------------------------------------------------------
// The step-down chain
// ---------------------------------------------------------------------------

AiCredentials::forgetForTests();
$routes['/ai/credentials/resolve'] = $console([
    ['api_key' => 'gem-primary', 'model' => 'gemini-3.7-flash', 'provider' => 'google'],
    ['api_key' => 'ant-secondary', 'model' => 'claude-opus-5', 'provider' => 'anthropic'],
    ['api_key' => '', 'model' => 'gpt-4o', 'provider' => 'openai'],
]);

$chain = AiProviderFactory::chain('m_chain');
assert_count(2, $chain, 'the chain drops the credential with no key');
assert_true($chain[0] instanceof GeminiProvider, 'the primary comes first');
assert_true($chain[1] instanceof AnthropicProvider, 'the fallback comes second');
assert_same(1, AiProviderFactory::status('m_chain')['fallbacks'], 'status counts only the usable fallbacks');

// ---------------------------------------------------------------------------
// base_url and auth_header overrides
// ---------------------------------------------------------------------------

AiCredentials::forgetForTests();
$routes['/ai/credentials/resolve'] = $console([[
    'api_key'     => 'gw-key-004',
    'model'       => 'gpt-4o',
    'provider'    => 'openai',
    'base_url'    => 'https://llm-gateway.example.com',
    'auth_header' => 'api-key',
]]);
$routes['llm-gateway.example.com'] = $reply([
    'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
    'usage'   => ['prompt_tokens' => 1, 'completion_tokens' => 1],
]);

$provider = AiProviderFactory::forModule('m_gateway');
assert_true($provider instanceof OpenAiProvider, 'the gateway credential still selects by provider string');

$calls = [];
$provider->complete([['role' => 'user', 'content' => 'hi']]);

$request = $calls[0];
assert_same('https://llm-gateway.example.com/v1/chat/completions', $request['url'], 'the base_url override is used');
assert_true(in_array('api-key: gw-key-004', $request['headers'], true), 'the auth_header override names the header');
assert_false(in_array('Authorization: Bearer gw-key-004', $request['headers'], true), 'and replaces the default scheme');

// ---------------------------------------------------------------------------
// Nothing configured, and configured with something we cannot drive
// ---------------------------------------------------------------------------

AiCredentials::forgetForTests();
$routes['/ai/credentials/resolve'] = $console([
    ['api_key' => 'key-005', 'model' => 'llama-3-70b', 'provider' => 'ollama'],
]);

assert_null(AiProviderFactory::forModule('m_unknown'), 'an unsupported provider yields no provider');
$status = AiProviderFactory::status('m_unknown');
assert_false($status['configured'], 'and status says so');
assert_contains("no client for the 'ollama' provider", (string) $status['message'], 'the reason is specific');

AiCredentials::forgetForTests();
$routes['/ai/credentials/resolve'] = $console([
    ['api_key' => 'key-006', 'model' => '', 'provider' => 'google'],
]);
assert_null(AiProviderFactory::forModule('m_nomodel'), 'a credential with no model is not usable');
assert_false(AiProviderFactory::status('m_nomodel')['configured'], 'and is reported as unconfigured');

// Console down, `auto`: the legacy .env key still answers.
AiCredentials::forgetForTests();
$routes['/ai/credentials/resolve'] = ['status' => 503, 'body' => '', 'content_type' => '', 'error' => ''];
Env::configureForTests(['CONTRACTS_AI_API_KEY' => 'env-fallback-key', 'AI_CREDENTIALS_SOURCE' => 'auto']);

$provider = AiProviderFactory::forModule('m_envfallback');
assert_true($provider instanceof GeminiProvider, 'the .env fallback still produces a provider under auto');
assert_same('env', AiProviderFactory::status('m_envfallback')['source'], 'and status says the key came from .env');

// Console down, `console`: fail closed rather than reach for .env.
AiCredentials::forgetForTests();
Env::configureForTests(['AI_CREDENTIALS_SOURCE' => 'console']);

assert_null(AiProviderFactory::forModule('m_failclosed'), 'AI_CREDENTIALS_SOURCE=console refuses the .env fallback');
$status = AiProviderFactory::status('m_failclosed');
assert_false($status['configured'], 'and reports unconfigured');
assert_true($status['console'], 'while still showing that Console itself is wired up');

// No Console at all: honest about why.
AiCredentials::forgetForTests();
Env::configureForTests(['CONSOLE_API_URL' => '', 'CONSOLE_SERVICE_KEY' => '', 'AI_CREDENTIALS_SOURCE' => 'console']);

$status = AiProviderFactory::status('m_nothing');
assert_false($status['configured'], 'with no Console and no key there is no AI');
assert_false($status['console'], 'and Console is reported as not configured');
assert_contains('CONSOLE_API_URL', (string) $status['message'], 'the message names what is missing');

Http::setTransportForTests(null);

t_done('AiProviderFactoryTest');
