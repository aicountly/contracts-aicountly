<?php

declare(strict_types=1);

namespace App\Ai;

use App\Core\Http;
use App\Support\DomainException;

/**
 * Google Gemini, over the generativelanguage v1beta REST API.
 *
 * The default across the AICOUNTLY estate, and the one whose long context makes
 * a 60-page master services agreement analysable in a single call rather than
 * chunk by chunk.
 *
 * Two shape differences from the other two providers are handled here and
 * nowhere else: the system prompt is a separate `systemInstruction` field
 * rather than a message, and the assistant's role is spelled `model`.
 */
final class GeminiProvider implements ContractsAiProvider
{
    private const DEFAULT_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /** Contract analysis on a long document is slow; the client polls a job rather than holding a request open. */
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
        return 'google';
    }

    public function complete(array $messages, array $options = []): array
    {
        [$contents, $system] = $this->toContents($messages, $options);

        if ($contents === []) {
            throw DomainException::badRequest('An AI request needs at least one message.');
        }

        $payload = [
            'contents'         => $contents,
            'generationConfig' => [
                // Low by default: an extraction that returns a different expiry
                // date each time it runs is not an extraction.
                'temperature'     => (float) ($options['temperature'] ?? 0.1),
                'maxOutputTokens' => (int) ($options['max_tokens'] ?? 4096),
            ],
        ];

        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        $schema = is_array($options['json_schema'] ?? null) ? $options['json_schema'] : null;
        if ($schema !== null) {
            $payload['generationConfig']['responseMimeType'] = 'application/json';
            $payload['generationConfig']['responseSchema']   = self::toGeminiSchema($schema);
        }

        $url  = $this->base() . '/models/' . rawurlencode($this->model) . ':generateContent';
        $json = $this->post($url, $payload);

        $text = '';
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                $text .= $part['text'];
            }
        }

        if (trim($text) === '') {
            // No candidate at all means a safety block or a prompt the model
            // refused. Reported rather than returned as an empty answer: an
            // empty extraction and a contract with nothing in it look the same
            // to every caller downstream.
            $reason = (string) ($json['candidates'][0]['finishReason'] ?? $json['promptFeedback']['blockReason'] ?? 'unknown');

            throw DomainException::unavailable("The AI provider returned no content ({$reason}).", 'AI_EMPTY_RESPONSE');
        }

        $usage = is_array($json['usageMetadata'] ?? null) ? $json['usageMetadata'] : [];

        return [
            'text'          => $text,
            'prompt_tokens' => isset($usage['promptTokenCount']) ? (int) $usage['promptTokenCount'] : null,
            'output_tokens' => isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
            'model'         => is_string($json['modelVersion'] ?? null) ? $json['modelVersion'] : $this->model,
            'raw'           => $json,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}> $messages
     * @param  array<string,mixed>                        $options
     * @return array{0: list<array<string,mixed>>, 1: string}
     */
    private function toContents(array $messages, array $options): array
    {
        $system   = trim((string) ($options['system'] ?? ''));
        $contents = [];

        foreach ($messages as $message) {
            $role    = strtolower(trim((string) ($message['role'] ?? 'user')));
            $content = (string) ($message['content'] ?? '');
            if (trim($content) === '') {
                continue;
            }

            if ($role === 'system' || $role === 'developer') {
                $system = $system === '' ? $content : $system . "\n\n" . $content;
                continue;
            }

            $contents[] = [
                'role'  => in_array($role, ['assistant', 'model'], true) ? 'model' : 'user',
                'parts' => [['text' => $content]],
            ];
        }

        return [$contents, $system];
    }

    /**
     * Translate our schema subset into the OpenAPI subset Gemini accepts.
     *
     * Gemini rejects a responseSchema carrying keywords it does not know, so
     * the validation-only ones (pattern, minLength, additionalProperties) are
     * dropped here and enforced by JsonSchemaValidator on the way back. The
     * schema sent to a model is a hint about shape; the check on the response
     * is what actually holds.
     *
     * @param  array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private static function toGeminiSchema(array $schema): array
    {
        $types    = $schema['type'] ?? 'string';
        $types    = is_array($types) ? $types : [$types];
        $nullable = ($schema['nullable'] ?? false) === true;

        $type = 'string';
        foreach ($types as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }
            if (strtolower($candidate) === 'null') {
                $nullable = true;
                continue;
            }
            $type = strtolower($candidate);
        }

        $out = ['type' => strtoupper($type)];
        if ($nullable) {
            $out['nullable'] = true;
        }
        if (isset($schema['description']) && is_string($schema['description'])) {
            $out['description'] = $schema['description'];
        }
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $out['enum']   = array_values(array_map(static fn (mixed $v): string => (string) $v, $schema['enum']));
            $out['format'] = 'enum';
        }

        if ($type === 'object' && is_array($schema['properties'] ?? null)) {
            $properties = [];
            foreach ($schema['properties'] as $name => $sub) {
                if (is_string($name) && is_array($sub)) {
                    $properties[$name] = self::toGeminiSchema($sub);
                }
            }
            $out['properties'] = $properties;
            if (is_array($schema['required'] ?? null) && $schema['required'] !== []) {
                $out['required'] = array_values(array_filter($schema['required'], 'is_string'));
            }
        }

        if ($type === 'array' && is_array($schema['items'] ?? null)) {
            $out['items'] = self::toGeminiSchema($schema['items']);
        }

        return $out;
    }

    /** The API root, with the version segment Console's base_url may or may not carry. */
    private function base(): string
    {
        $base = rtrim(trim((string) $this->baseUrl), '/');
        if ($base === '') {
            return self::DEFAULT_BASE;
        }

        return preg_match('#/v\d[a-z0-9]*$#i', $base) === 1 ? $base : $base . '/v1beta';
    }

    /**
     * The key travels in a header, never in the query string.
     *
     * Gemini accepts `?key=`, and that is how the older products call it, but a
     * URL is written to access logs, proxy logs and error reports on the way
     * past. A header is not.
     */
    private function authorisation(): string
    {
        $header = trim((string) $this->authHeader);
        if ($header === '') {
            return 'x-goog-api-key: ' . $this->apiKey;
        }

        // Console may store either a bare header name or "Name: Prefix".
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
            $detail = (string) ($json['error']['message'] ?? '');
            $detail = $detail === '' ? '' : ' ' . mb_substr($detail, 0, 200);

            throw DomainException::unavailable(
                "The AI provider refused the request (HTTP {$result['status']}).{$detail}",
                $result['status'] === 429 ? 'AI_RATE_LIMITED' : 'AI_PROVIDER_ERROR'
            );
        }

        return $json;
    }
}
