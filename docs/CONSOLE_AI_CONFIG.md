# Console AI configuration

LLM credentials live in AICOUNTLY Console (`console.aicountly.org`). **No
provider key is stored on the Contracts host**, in `.env` or anywhere else.

## Why

Before Console, every product carried its own provider key in its own
`server-php/.env` on its own cPanel host — and the deploy never writes `.env`, so
rotating one key meant editing eleven boxes by hand, and nothing could report
where a key was in use.

Console holds it encrypted and hands it over per request. The credential is held
in process memory and, where APCu is available, in shared memory. It never lands
in a file next to the code. That is the whole point: a compromised product host
no longer yields a long-lived provider key from disk.

## The call

```
GET {CONSOLE_API_URL}/ai/credentials/resolve
    ?domain=contracts.aicountly.com
    &module=contract_ai
Authorization: Bearer {CONSOLE_SERVICE_KEY}
```

```json
{
  "data": {
    "credentials": [
      {
        "api_key": "…",
        "model": "…",
        "provider": "google",
        "base_url": null,
        "auth_header": null,
        "ids": { "account_id": 12 }
      }
    ],
    "ttl_seconds": 300
  }
}
```

The first entry is the primary; the rest are fallbacks, returned in one lookup so
a caller stepping down from a rate-limited provider does not pay for a second
credential round trip.

## Providers

`AiProviderFactory` maps the `provider` string to an implementation, accepting the
common aliases because spellings vary by whoever typed the Connected Account:

| Provider strings | Implementation |
|---|---|
| `google`, `gemini`, `google-gemini`, `googleai`, `vertex` | `GeminiProvider` |
| `openai`, `open-ai`, `azure`, `azure-openai`, `openai-compatible` | `OpenAiProvider` |
| `anthropic`, `claude` | `AnthropicProvider` |

`base_url` and `auth_header` from Console override the defaults, which is what
makes an Azure or a self-hosted OpenAI-compatible endpoint work without a code
change.

## Migration mode

```env
AI_CREDENTIALS_SOURCE=auto      # default
AI_CREDENTIALS_SOURCE=console   # required, fail closed
```

`auto` tries Console first and falls back to a legacy `.env` key, **logging every
time it does**. It is the shipping default deliberately: flipping every product
to Console-only before the keys are actually loaded would take AI down across the
estate at once, and Console being briefly unreachable would do the same
afterwards.

The order to move in:

1. Deploy with `auto`. Behaviour is unchanged, and the logs show which products
   still answer from `.env`.
2. Load the key into Console → AI Connected Accounts.
3. Confirm the logs are quiet, then set `AI_CREDENTIALS_SOURCE=console` and
   delete the legacy key line from that host's `.env`.

Step 3 is what actually discontinues `.env`; steps 1 and 2 are what make it safe
to take.

## No credentials, no pretending

`AiProviderFactory::forModule()` returns **null** when nothing is configured, not
a stub that returns empty results.

- `GET /api/health` reports `ai.configured: false` with a message naming Console.
- `GET /api/ai/status` says the same, and the SPA hides the AI actions rather
  than offering a button that fails on click.
- Every generating endpoint answers `503 AI_NOT_CONFIGURED`.

A stub would be worse than an error, because an empty risk assessment is
indistinguishable from a clean contract.

## Usage reporting

```
POST {CONSOLE_API_URL}/ai/usage
{ "events": [ { module, provider, model, prompt_tokens, output_tokens, latency_ms, success } ] }
```

Fire-and-forget with a short timeout, and failures are swallowed. Telemetry is
never worth delaying or failing a user-facing AI response.

Locally, `ai_usage_log` records the same per call — including failures — which is
what answers "what did AI do on this contract". `error_message` there is
truncated and never carries prompt content: contract text is the customer's
confidential material and does not belong in a telemetry table.

## Registering Contracts in Console

Console needs a Connected Account for:

```
domain   contracts.aicountly.com
module   contract_ai
```

Until that exists, `/api/health` reports AI as unconfigured — which is the
correct state, not an error to work around.

### The cross-repo change this required

Console's AI registry is seeded by migration, and its migration `030_ai_registry`
seeded the domains that existed when it was written. Contracts was not among
them, so the resolve call above matched nothing: Console's Connected Accounts
screen offered no Contracts row to bind a key to, and there was no path to a
configured state at all.

One migration was added to `console-react-app` to close that:

| Repo | File | What it does |
| --- | --- | --- |
| `console-react-app` | `server-php/database/migrations/032_contracts_ai_registry.sql` | Inserts the `contracts.aicountly.com` domain and its one `contract_ai` module |

It seeds no key material, following `030`'s precedent — keys are typed into the
Console UI so plaintext never reaches a migration file, a git history, or a
deploy log. The migration creates only the shape a key can be bound to; an
administrator still has to bind one.

One module rather than several, because `AiProviderFactory::DEFAULT_MODULE` is
the only module key this product sends. Extraction, summarisation, risk
commentary, Ask Your Contract, deviation analysis and renewal advice all resolve
through `contract_ai`, so one binding configures the product and a finer split
in Console would create rows that nothing resolves against.

Nothing else in Console was touched. In particular Contracts is deliberately
**not** added to `SaasProductRegistry`: that registry is Console's bridge for
importing a product's notification templates and administering its platform
users over the product's own portal API. Contracts owns its notifications
outright and exposes no such bridge, so listing it there would advertise an
integration that does not exist.

## Configuration

```env
CONSOLE_API_URL=https://console.aicountly.org/api
CONSOLE_SERVICE_KEY=            # supplied by the Console administrator
AI_CREDENTIALS_SOURCE=auto

AI_RATE_LIMIT_PER_HOUR=120
AI_ASK_RATE_LIMIT_PER_HOUR=60
```

`CONSOLE_SERVICE_KEY` is the one secret this host holds, and it is a service
credential for Console — not a provider key. It must never appear in a `VITE_`
variable, a log line, or an API response.
