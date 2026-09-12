<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One model call, expressed the same way whatever is behind it.
 *
 * The point of this interface is that the rest of the product never learns
 * which vendor answered. Extraction, risk scoring, summarisation and the
 * contract Q&A all speak in messages and get back text plus a token count;
 * swapping Gemini for Claude is a Console configuration change, not a code
 * change, and no provider SDK is reachable from a service or a controller.
 *
 * Implementations throw `App\Support\DomainException::unavailable()` when the
 * provider cannot be reached or refuses the call. They never return a
 * plausible-looking empty answer, because a caller cannot tell that apart from
 * a contract that genuinely says nothing.
 */
interface ContractsAiProvider
{
    /** The provider family this speaks to: `google`, `openai` or `anthropic`. */
    public function name(): string;

    /**
     * Send a conversation and get the model's reply.
     *
     * `messages` is a list of `['role' => 'user'|'assistant'|'system', 'content' => string]`.
     * A system message may be given either in the list or as `options['system']`;
     * providers that carry it out of band (all three of them) move it there.
     *
     * `options`:
     *   max_tokens   int    output cap
     *   temperature  float  0.0 - 1.0
     *   system       string standing instruction, usually PromptGuard::systemPreamble()
     *   json_schema  array  a JsonSchemaValidator-shaped schema. Providers use their
     *                       own structured-output mechanism when they have one, and
     *                       the caller still validates the result — a schema sent to
     *                       a model is a request, not a guarantee.
     *   schema_name  string identifier for the schema, where the provider wants one
     *
     * @param  list<array{role: string, content: string}>                                                $messages
     * @param  array{max_tokens?: int, temperature?: float, system?: string, json_schema?: array<string,mixed>, schema_name?: string} $options
     * @return array{text: string, prompt_tokens: ?int, output_tokens: ?int, model: string, raw: array<string,mixed>}
     *
     * @throws \App\Support\DomainException
     */
    public function complete(array $messages, array $options = []): array;
}
