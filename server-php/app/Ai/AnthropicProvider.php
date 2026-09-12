<?php

declare(strict_types=1);

namespace App\Ai;

use App\Core\Http;
use App\Support\DomainException;

/**
 * Anthropic Claude, over the Messages API.
 *
 * Raw HTTP rather than the official PHP SDK: this repository ships without a
 * vendor/ directory on purpose (see App\Core\Autoloader), so a Composer
 * dependency here would turn every cPanel deploy from an rsync into an rsync
 * plus a build step. The wire format is stable and pinned by the
 * `anthropic-version` header, which is what that header is for.
 *
 * Two shape differences from the others: the system prompt is a top-level
 * `system` string rather than a message, and `max_tokens` is required rather
 * than optional.
 */
final class AnthropicProvider implements ContractsAiProvider
{
    private const DEFAULT_BASE = 'https://api.anthropic.com';

    /** Pinned, not floating: a version bump is a change we want to make deliberately and test. */
    private const API_VERSION = '2023-06-01';

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
        return 'anthropic';
    }

    public function complete(array $messages, array $options = []): array
    {
        [$turns, $system] = $this->toTurns($messages, $options);

        if ($turns === []) {
            throw DomainException::badRequest('An AI request needs at least one message.');
        }

        $payload = [
            'model'       => $this->model,
            'max_tokens'  => (int) ($options['max_tokens'] ?? 4096),
            'temperature' => (float) ($options['temperature'] ?? 0.1),
            'messages'    => $turns,
        ];

        $schema   = is_array($options['json_schema'] ?? null) ? $options['json_schema'] : null;
        $toolName = null;

        // Structured output here is a single tool whose input_schema is the
        // shape we want back. The call is not forced with tool_choice: the
        // newer models reject a forced choice outright, so the tool is offered
        // and named in the instruction instead, and the text branch below still
        // works when the model answers in prose.
        if ($schema !== null && ($schema['type'] ?? null) === 'object') {
            $toolName          = self::toolName($options['schema_name'] ?? 'contract_analysis');
            $payload['tools']  = [[
                'name'         => $toolName,
                'description'  => 'Return the requested fields from the contract text.',
                'input_schema' => $schema,
            ]];
            $system = trim($system . "\n\nReturn your answer by calling the {$toolName} tool exactly once.");
        }

        if ($system !== '') {
            $payload['system'] = $system;
        }

        $json = $this->post($this->base() . '/messages', $payload);

        $text = '';
        foreach ($json['content'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
            if ($toolName !== null && ($block['type'] ?? '') === 'tool_use' && is_array($block['input'] ?? null)) {
                // The tool call is the answer. Re-encoded so every provider
                // hands the caller the same thing: JSON text to validate.
                $encoded = json_encode($block['input'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($encoded !== false) {
                    $text = $encoded;
                    break;
                }
            }
        }

        if (trim($text) === '') {
            $reason = (string) ($json['stop_reason'] ?? 'unknown');

            throw DomainException::unavailable("The AI provider returned no content ({$reason}).", 'AI_EMPTY_RESPONSE');
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];

        return [
            'text'          => $text,
            'prompt_tokens' => isset($usage['input_tokens']) ? (int) $usage['input_tokens'] : null,
            'output_tokens' => isset($usage['output_tokens']) ? (int) $usage['output_tokens'] : null,
            'model'         => is_string($json['model'] ?? null) ? $json['model'] : $this->model,
            'raw'           => $json,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}> $messages
     * @param  array<string,mixed>                        $options
     * @return array{0: list<array{role: string, content: string}>, 1: string}
     */
    private function toTurns(array $messages, array $options): array
    {
        $system = trim((string) ($options['system'] ?? ''));
        $turns  = [];

        foreach ($messages as $message) {
            $content = (string) ($message['content'] ?? '');
            if (trim($content) === '') {
                continue;
            }

            $role = strtolower(trim((string) ($message['role'] ?? 'user')));
            if ($role === 'system' || $role === 'developer') {
                $system = $system === '' ? $content : $system . "\n\n" . $content;
                continue;
            }

            $turns[] = [
                'role'    => $role === 'assistant' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        // The API rejects a conversation that opens on an assistant turn. That
        // only happens when a caller replays a transcript from the wrong point,
        // and dropping the orphaned turns is friendlier than a 400 the user
        // sees as "AI unavailable".
        while ($turns !== [] && $turns[0]['role'] === 'assistant') {
            array_shift($turns);
        }

        return [array_values($turns), $system];
    }

    private static function toolName(mixed $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_-]/', '_', is_string($name) ? $name : '') ?? '';
        $clean = trim($clean, '_');

        return $clean === '' ? 'contract_analysis' : substr($clean, 0, 64);
    }

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
            return 'x-api-key: ' . $this->apiKey;
        }

        // Console may store either a bare header name or the full "Name: Prefix"
        // form; a gateway in front of Claude often wants Authorization: Bearer.
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
            'anthropic-version: ' . self::API_VERSION,
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
