<?php

declare(strict_types=1);

namespace App\Ai;

use App\Core\Http;
use App\Support\DomainException;

/**
 * OpenAI, and anything that speaks its chat-completions dialect.
 *
 * The `base_url` override is what makes the second half of that sentence true:
 * Azure OpenAI, a gateway, or a self-hosted model behind an OpenAI-compatible
 * proxy all work by pointing Console's base_url at them, with no code here that
 * knows the difference.
 */
final class OpenAiProvider implements ContractsAiProvider
{
    private const DEFAULT_BASE = 'https://api.openai.com';

    private const TIMEOUT = 120;

    public function __construct(
        private string $apiKey,
        private string $model,
        private ?string $baseUrl = null,
        private ?string $authHeader = null,
    ) {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function complete(array $messages, array $options = []): array
    {
        $payload = [
            'model'    => $this->model,
            'messages' => $this->toMessages($messages, $options),
        ];

        if ($payload['messages'] === []) {
            throw DomainException::badRequest('An AI request needs at least one message.');
        }

        $maxTokens = (int) ($options['max_tokens'] ?? 4096);

        // The reasoning models renamed the output cap and refuse `temperature`
        // outright. Sending the older pair to one of them is a 400, not a
        // downgrade, so the family is checked rather than assumed.
        if ($this->isReasoningModel()) {
            $payload['max_completion_tokens'] = $maxTokens;
        } else {
            $payload['max_tokens']  = $maxTokens;
            $payload['temperature'] = (float) ($options['temperature'] ?? 0.1);
        }

        $schema = is_array($options['json_schema'] ?? null) ? $options['json_schema'] : null;
        if ($schema !== null) {
            $payload['response_format'] = [
                'type'        => 'json_schema',
                'json_schema' => [
                    'name' => self::schemaName($options['schema_name'] ?? 'contract_analysis'),
                    // Not strict: strict mode demands every property be listed
                    // in `required` and additionalProperties be false at every
                    // level, which would force our extraction schemas to claim
                    // that a contract states every field. JsonSchemaValidator
                    // does the real enforcement on the response.
                    'strict' => false,
                    'schema' => $schema,
                ],
            ];
        }

        $json = $this->post($this->base() . '/chat/completions', $payload);

        $choice = is_array($json['choices'][0] ?? null) ? $json['choices'][0] : [];
        $text   = (string) ($choice['message']['content'] ?? '');

        if (trim($text) === '') {
            $refusal = (string) ($choice['message']['refusal'] ?? '');
            $reason  = $refusal !== '' ? $refusal : (string) ($choice['finish_reason'] ?? 'unknown');

            throw DomainException::unavailable(
                'The AI provider returned no content (' . mb_substr($reason, 0, 120) . ').',
                'AI_EMPTY_RESPONSE'
            );
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];

        return [
            'text'          => $text,
            'prompt_tokens' => isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            'output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            'model'         => is_string($json['model'] ?? null) ? $json['model'] : $this->model,
            'raw'           => $json,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}> $messages
     * @param  array<string,mixed>                        $options
     * @return list<array{role: string, content: string}>
     */
    private function toMessages(array $messages, array $options): array
    {
        $out    = [];
        $system = trim((string) ($options['system'] ?? ''));
        if ($system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($messages as $message) {
            $content = (string) ($message['content'] ?? '');
            if (trim($content) === '') {
                continue;
            }

            $role  = strtolower(trim((string) ($message['role'] ?? 'user')));
            $out[] = [
                'role'    => in_array($role, ['system', 'assistant', 'user', 'developer'], true) ? $role : 'user',
                'content' => $content,
            ];
        }

        return $out;
    }

    /** o-series and GPT-5 style models: different parameter names, no temperature. */
    private function isReasoningModel(): bool
    {
        return preg_match('/^(?:o\d|gpt-5)/i', $this->model) === 1;
    }

    private static function schemaName(mixed $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', is_string($name) ? $name : '') ?? '';
        $clean = trim($clean, '_');

        return $clean === '' ? 'contract_analysis' : substr($clean, 0, 64);
    }

    /** The API root including the version segment, however Console spelled base_url. */
    private function base(): string
    {
        $base = rtrim(trim((string) $this->baseUrl), '/');
        if ($base === '') {
            return self::DEFAULT_BASE . '/v1';
        }

        return preg_match('#/v\d[a-z0-9]*$#i', $base) === 1 ? $base : $base . '/v1';
    }

    private function authorisation(): string
    {
        $header = trim((string) $this->authHeader);
        if ($header === '') {
            return 'Authorization: Bearer ' . $this->apiKey;
        }

        // Console may store either a bare header name — Azure wants `api-key` —
        // or the full "Name: Prefix" form.
        if (str_contains($header, ':')) {
            [$name, $prefix] = explode(':', $header, 2);
            $prefix          = trim($prefix);

            return trim($name) . ': ' . ($prefix === '' ? '' : $prefix . ' ') . $this->apiKey;
        }

        return strcasecmp($header, 'Authorization') === 0
            ? 'Authorization: Bearer ' . $this->apiKey
            : $header . ': ' . $this->apiKey;
    }

    /**
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function post(string $url, array $payload): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw DomainException::badRequest('The AI request could not be encoded.');
        }

        $result = Http::request('POST', $url, [
            $this->authorisation(),
            'Content-Type: application/json',
            'Accept: application/json',
        ], $body, self::TIMEOUT, 5);

        if ($result['status'] === 0) {
            throw DomainException::unavailable('The AI provider could not be reached.', 'AI_UNREACHABLE');
        }

        $json = json_decode($result['body'], true);
        $json = is_array($json) ? $json : [];

        if ($result['status'] < 200 || $result['status'] >= 300) {
            // Providers quote the offending key back in an auth error. Masked
            // by them or not, it does not belong in an exception that a
            // controller may log or render.
            $detail = str_replace($this->apiKey, '[redacted]', (string) ($json['error']['message'] ?? ''));
            $detail = $detail === '' ? '' : ' ' . mb_substr($detail, 0, 200);

            throw DomainException::unavailable(
                "The AI provider refused the request (HTTP {$result['status']}).{$detail}",
                $result['status'] === 429 ? 'AI_RATE_LIMITED' : 'AI_PROVIDER_ERROR'
            );
        }

        return $json;
    }
}
