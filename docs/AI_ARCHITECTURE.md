# AI architecture

Contracts owns its own AI logic. There is no shared agent runtime and no central
AI brain in AICOUNTLY — each product owns its prompts and its analysis, and
Console owns only the provider credentials.

## The shape

```
Service  →  AiProviderFactory  →  ContractsAiProvider  →  Gemini | OpenAI | Anthropic
               ↑                        ↑
        AiCredentials              PromptGuard        (untrusted document text)
        (from Console)             ContractPrompts
                                   JsonSchemaValidator (every structured response)
                                   AiResponseRepair    (one recovery attempt)
```

No provider SDK call appears anywhere outside `app/Ai/`. A service asks for a
provider, gets the interface, and never learns which vendor answered.

## Credentials

Console (`console.aicountly.org`) is the system of record for LLM keys.

```
GET {CONSOLE_API_URL}/ai/credentials/resolve?domain=contracts.aicountly.com&module=contract_ai
Authorization: Bearer {CONSOLE_SERVICE_KEY}

→ {"data":{"credentials":[{api_key, model, provider, base_url, auth_header, ids}],
            "ttl_seconds": 300}}
```

`AiCredentials` holds the result in process memory and, where the extension is
present, in APCu. **Nothing is written to disk.** That is the point of the
arrangement: a compromised product host no longer yields a long-lived provider
key from a file sitting next to the code.

`AI_CREDENTIALS_SOURCE=console` requires Console and fails closed. The default,
`auto`, tries Console first and falls back to a legacy `.env` key, logging each
time — so a migration can be staged rather than taking AI down across the estate
at once. See [`CONSOLE_AI_CONFIG.md`](./CONSOLE_AI_CONFIG.md).

Usage is reported back with `POST {CONSOLE_API_URL}/ai/usage`, fire-and-forget:
telemetry must never delay or fail a user-facing response.

## No provider means no AI, said out loud

`AiProviderFactory::forModule()` returns **null** when nothing is configured, not
a stub.

That distinction carries all the way to the screen. A deployment with no AI key
says so on `/api/health` and in Settings → Integrations, and the AI actions are
hidden rather than offered and then failing on click. A stub returning empty
results would be worse still: **an empty risk assessment is indistinguishable
from a clean contract.**

Console's `fallbacks[]` become a step-down chain, returned in one lookup so a
caller retrying a rate-limited provider does not pay for a second credential
round trip.

## Contract text is untrusted

A contract arrives from a counterparty. It can contain anything, including text
aimed at the model rather than at a reader.

`PromptGuard` does three things:

- **`wrapUntrusted()`** fences the document in an explicit delimiter block, with
  a preamble stating that the block is data to analyse and never instructions.
- **`sanitise()`** strips control characters, collapses runaway whitespace,
  truncates with a visible marker, and neutralises the obvious injection markers
  a document might carry — lines impersonating a system or assistant turn,
  "ignore previous instructions", fenced role headers.
- **`systemPreamble()`** carries the standing instruction: answer only from the
  provided text, say when the text does not answer the question, and do not give
  legal advice.

Truncation is visible on purpose. Silently dropping the second half of a
50-page agreement and then answering confidently about its termination clause is
worse than saying the document was too long.

## Nothing unvalidated is stored

Every structured response is checked against a JSON Schema
(`Ai\Schemas\ExtractionSchema`) by `JsonSchemaValidator` before it goes near the
database.

On a mismatch:

1. `AiResponseRepair` attempts recovery — strip markdown fences, take the
   outermost balanced object or array, remove trailing commas. It repairs
   punctuation and **never invents a field**.
2. One retry with a stricter instruction.
3. Then the job fails, with the reason recorded.

The validator coerces only where it is safe and unambiguous — a numeric string
to a number, `"true"` to a boolean, an ISO timestamp to a date. It will not
guess.

## The extraction pipeline

Staged, and each stage is skippable and resumable, because a 60-page scanned PDF
does not fail in the same place twice:

```
 1  validate we have text            → fail with a clear code, not an empty prompt
 2  sanitise and chunk
 3  classify the document type
 4  extract parties, dates, monetary values, renewal terms, notice period,
    governing law
 5  extract clauses, obligations, milestones, payment terms, SLA terms
 6  validate every response against its schema
 7  write ai_extractions (one row per field, with confidence and source excerpt)
    and contract_clauses marked is_ai_extracted
 8  present for human review
```

Text is extracted **deterministically first** — `.txt` directly, `.docx` from
the zip, `.pdf` via an embedded-text reader. Only when that yields nothing is the
version marked `is_scanned` and OCR recorded as required. The product never
stores text it did not actually extract.

## Human verification is a state, not a convention

Every AI-derived value carries three things: `is_ai_extracted`,
`ai_confidence`, and `verification_state` — one of `ai_extracted`,
`human_verified`, `human_edited`, `rejected`.

Two rules follow, and they are enforced in code rather than trusted to callers:

- Nothing is presented as verified until a person has verified it. The AI review
  queue (`/ai/review`) is where that happens, low-confidence fields highlighted.
- **An AI value never overwrites a `human_verified` one.** A re-run after a
  correction must not silently undo the correction.

## Grounded question answering

`AskContractService` retrieves **only the contract in question**, within the
caller's tenant. A question cannot reach another contract, let alone another
company — the retrieval is a `WHERE contract_id = ? AND cmp_id = ?`, not a
similarity search over an index that happens to be filtered afterwards.

An answer returns `{answer, citations[], grounded, disclaimer}`. Citations carry
the page or clause the text came from. When the contract does not contain the
answer, `grounded` is false and the answer says so plainly rather than
generalising from what a contract of that type usually says.

## Structured summaries

`ai_contract_summaries` holds the model's own sections verbatim, and a separate
`edited_sections` for human edits. Regenerating never destroys a reviewer's
wording, and reviewing never destroys the original for comparison.

Sections: Executive Summary, Parties, Purpose, Effective Period, Commercial
Terms, Payment Terms, Renewal, Termination, Key Obligations, Key Rights, SLA,
Liability, Indemnity, IP, Confidentiality, Data Protection, Dispute Resolution,
Governing Law, High-Risk Clauses, Missing Protections, Management Action Items.

## Jobs

AI work runs on its own queue (`ai_jobs`), separate from the general job queue,
because it costs real money per call, needs more conservative retries, and
carries provider, model and token counts a general job has no use for.

- **Idempotency:** the key defaults to
  `sha256(kind|contractId|versionId|hash(payload))`. An identical request
  returns the existing job rather than paying twice.
- **Claiming:** `SELECT ... FOR UPDATE SKIP LOCKED`, so two workers never serve
  the same job.
- **Failure:** exponential backoff; `failed` only after `max_attempts`.
- **Crashed workers:** `reapStale()` releases a lock with no process behind it.

## Audit

Every model call writes an `ai_usage_log` row — success or failure — with the
provider, model, credential source, token counts and latency. `error_message` is
truncated and **never carries prompt content**: contract text is the customer's
confidential material and does not belong in a telemetry table.

## Rate limiting

AI endpoints are limited separately from everything else
(`AI_RATE_LIMIT_PER_HOUR`, `AI_ASK_RATE_LIMIT_PER_HOUR`), per company and per
user, counted in PostgreSQL. These calls spend the company's provider quota, so
the limit is a cost control as much as an abuse control.

## The disclaimer

> AI-generated contract analysis is provided for assistance and information. It
> does not constitute legal advice and should be reviewed by an authorized legal
> or professional reviewer before reliance.

Returned with every AI payload and shown once per AI surface — not beside every
sentence, which trains people to stop reading it.

The prompts reinforce it: findings are phrased as elevated or reduced risk, never
as advice, and the model is instructed never to assert that a contract is legally
valid or invalid.
