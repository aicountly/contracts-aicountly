# Security

Contracts holds a company's agreements: what it committed to, for how much, and
what it is exposed to if things go wrong. The controls below are written for
that, not for a generic CRUD app.

## The boundary

Three separate questions, answered in one place — `BaseController::requireContext()`:

1. **Is this session live?** The AICOUNTLY portal decides. A transport failure
   is treated as *not authenticated*, so a portal outage denies access rather
   than granting it.
2. **May this user act for this company?** Manage decides. Contracts never makes
   that call itself, so revoking access in Manage revokes it here immediately.
3. **What may they do here?** `contract_user_roles` decides, resolved to
   permission slugs by `Support\Permissions`.

The result is a `TenantContext`. It is the only legitimate source of `cmp_id`
and `environment` for a query, and nothing downstream may build one from request
input.

## Multi-tenancy

> Every tenant-scoped query filters on `environment` **and** `cmp_id` from the
> `TenantContext`.

Enforced by construction and tested from the outside.
`tests/CrossTenantIsolationTest.php` seeds two companies with identical titles,
counterparties and values, then attempts — from company 1 — to:

- read company 2's contract by id (`find`, `findOrFail`)
- reach it through full-text search and through the counterparty filter
- edit it, change its status, archive it, delete it, favourite it
- point a new contract at company 2's contract type and department

Every one is refused, and company 2's row is asserted unchanged afterwards. The
`environment` column is tested as a boundary too: a `production` context cannot
see a `sandbox` row with the same `cmp_id`.

A missing row and another tenant's row return **the same** answer — 404, "not
found". Distinguishing them would be an enumeration oracle over every contract
id in the system.

### Row-level visibility

Inside a company, a user without `contract.view_all` sees only contracts they
own, created, or are an approver on. The predicate is built once in
`ContractService::visibilityPredicate()` and applied by **both** `find()` and
`search()`.

That sharing is not tidiness. Applying it to the list but not to a direct read
leaves a plain IDOR — a user who cannot see a colleague's contract in the
repository could still open it by walking the id in the URL. That was a real bug
during development, caught by the isolation test.

## Input

- **Every SQL value is bound.** The only caller-influenced SQL *text* is the
  sort column, which is looked up in a fixed map (`ContractService::SORTABLE`);
  an unknown key falls back to the default rather than reaching the query.
  `tests/SecurityTest.php` fires six injection payloads through `q` and
  `counterparty` and asserts the table still exists afterwards.
- **Statuses and enums** are coerced against `Support\Enums`, which mirrors the
  database `CHECK` constraints. Free text never becomes a status.
- **Pagination is clamped** — `per_page` reaches a `LIMIT`, so an unbounded
  value is a way to ask for an entire tenant in one request. Max 100 (200 for
  audit), page max 100000.
- **Validation collects every error** rather than stopping at the first, and a
  422 carries `errors` as field → message so the form highlights the box rather
  than showing a toast.
- **Ids in URLs** must match `^\d{1,19}$`; anything else is a 404, not a 400, so
  probing `/contracts/abc` and `/contracts/999999` are indistinguishable.

## Output

- Errors are the envelope, never a stack trace. `index.php` installs an
  exception handler *and* a shutdown handler — a fatal (OOM, timeout) skips the
  exception handler, and without the second the caller would get a 200 with a
  truncated body, which reads as success.
- `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`,
  `Referrer-Policy: same-origin` on every response.
- Commercial values are **removed from the payload** for a user without
  `contract.commercials.view`, not hidden client-side. Hiding them in the UI
  leaves the numbers in the browser's network tab.
- CSV export neutralises formula injection: a cell beginning `=`, `+`, `-` or
  `@` is prefixed with an apostrophe. That is how an innocuous-looking export
  becomes code execution on a finance user's machine.

## Outbound requests

Everything leaving the app goes through `Core\Http`, which refuses:

- non-`http(s)` schemes
- any host resolving to a private, reserved, link-local or multicast address
- a hostname that resolves to nothing — a DNS failure is not evidence that a
  destination is safe
- redirects (`CURLOPT_FOLLOWLOCATION` is off) — a 30x from an integration is
  either a misconfiguration or an attempt to walk us somewhere else

`ALLOW_LOOPBACK_INTEGRATIONS=true` permits **loopback only** — `127.0.0.0/8`
and `::1`. It deliberately does not permit link-local, because that is where the
cloud metadata endpoint `169.254.169.254` lives, and no development convenience
is worth making instance credentials reachable through a config value. This was
narrowed after `SecurityTest` showed the original flag allowed the whole private
range.

## The auth relay

`POST /api/global/{path}` forwards three paths to the portal — `seskey`,
`seskey/refresh`, `refresh_authtoken` — and nothing else. It exists because a
new product domain is not in the portal's CORS allow-list on day one.

It is an **allow-list and must stay one**. Forwarding arbitrary paths would make
this host an open proxy for the portal's whole auth surface — login, signup,
OTP, user lookup — with the portal seeing this server's IP instead of the
caller's, so anything it rate-limits per IP could be driven through here. Paths
are percent-decoded before the check so `%2e%2e` cannot smuggle a traversal
segment past it.

## Tokens

Two tokens, and the split is the point:

| Token | Lifetime | Stored | Used for |
|---|---|---|---|
| `auth_token` | long | `localStorage` + a shared cookie | minting a `ses_key` |
| `ses_key` | ~15 min | **memory only** | `Bearer` on API calls |

`ses_key` never reaches `localStorage` or `sessionStorage`. The API client mints
a fresh one when the in-memory copy has expired, so a page left open overnight
recovers instead of 401ing.

## File upload

Enforced server-side regardless of what the browser claims:

- extension allow-list: `pdf, doc, docx, txt, rtf, png, jpg, jpeg, tiff`
- MIME allow-list matching those
- a size cap (`CONTRACTS_MAX_UPLOAD_MB`, default 25)
- the filename is **never** trusted — sanitised for display, and the storage
  name is generated
- SHA-256 stored per version, which is also how "the counterparty returned it
  unchanged" is detected without diffing megabytes of PDF

Where Drive is configured the bytes go to Drive under its own security model.
The local fallback exists only for a deployment that has not provisioned Drive
yet, is refused unless `CONTRACTS_ALLOW_LOCAL_STORAGE=true`, and writes outside
the document root.

## AI

Contract text is **untrusted input**. It arrives from a counterparty and can
contain anything, including instructions aimed at the model.

- `Ai\PromptGuard::wrapUntrusted()` fences document text in an explicit
  delimiter block with a preamble stating that the block is data to analyse and
  never instructions.
- `sanitise()` strips control characters, collapses runaway whitespace,
  truncates with a marker, and neutralises the obvious injection markers a
  document might carry — lines impersonating a system or assistant turn, "ignore
  previous instructions", fenced role headers.
- Every structured response is validated against a JSON Schema before it is
  stored. A malformed response gets one repair attempt, then one stricter retry,
  then fails. Unvalidated output is never written.
- Retrieval for Ask Contract is scoped to **one contract** within one tenant. A
  question cannot reach another contract, let alone another company.
- AI endpoints are rate-limited separately (`AI_RATE_LIMIT_PER_HOUR`,
  `AI_ASK_RATE_LIMIT_PER_HOUR`) because each call costs real money against the
  company's provider quota.
- No provider key is ever written to disk here. Console holds them; the resolved
  credential lives in process memory and, where available, APCu. Keys are never
  logged, and `error_message` in `ai_usage_log` is truncated and never carries
  prompt content — contract text is the customer's confidential material.

## Rate limiting

Counted in PostgreSQL, not APCu. PHP-FPM runs many workers and APCu is
per-worker, so an in-process counter lets a caller multiply their budget by the
pool size. The limiter **fails open**: a 429 because the database blinked is a
worse outage than the abuse it prevents, and everything behind it is already
authenticated and tenant-scoped.

## Audit

`contract_audit_logs` is append-only at the database level — a trigger raises on
`UPDATE` and `DELETE`, and `DELETE` is revoked from `PUBLIC`. Values that look
like a secret (`api_key`, `password`, `token`, `ses_key`, `secret`,
`authorization`) are redacted before they are written.

An audit write that fails is logged but never aborts the business write. Losing
a contract edit because the audit insert hit a constraint is worse than the
missing row, and the failure is visible in the log either way.

## Webhooks

Signature-provider callbacks are unauthenticated by necessity — the provider has
no session. Each delivery is:

1. verified against the provider's own signature
   (`SIGNATURE_WEBHOOK_SECRET`),
2. **stored before it is acted on**, keyed `(provider, event_id)` with a unique
   index,
3. then processed.

Every provider retries and none guarantees exactly-once, so a duplicate delivery
must be a no-op rather than a second state transition.

## Environment mismatch

The API refuses to serve when `APP_ENV` disagrees with the hostname. A sandbox
`.env` copied onto the production host would have production traffic writing
rows tagged `sandbox`, which no report would then find. Failing loudly on the
first request is far cheaper than discovering it at a month end.

## What is not done

- No malware scanning. Drive's `ScanService` is the ecosystem's hook for it; the
  adapter interface exists here but no scanner is configured.
- No field-level encryption at rest. Contract text is stored as extracted text
  for search and AI grounding, protected by database access control.
- Email delivery is off by default and honestly reported as off, rather than
  silently dropping messages.

## Running the security tests

```bash
php server-php/tests/SecurityTest.php
php server-php/tests/CrossTenantIsolationTest.php
```
