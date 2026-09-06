# Implementation status

What is built, what is half-built, what is deliberately absent, and what is
blocked. The rule this file is written under: **a feature is complete only when
it works end to end against the real database and the real API.** A screen that
renders convincingly against a fixture is *not started*, and is recorded here as
such.

Last verified against commit on branch `claude/aicountly-contracts-platform-tcvg9n`.

## How to re-verify every claim below

```bash
php server-php/tests/run.php               # backend suite
php server-php/scripts/audit.php --verbose # static self-audit
php server-php/scripts/smoke-routes.php    # every route answers intentionally
cd web && npx tsc -b && npm test -- --run  # frontend
```

Anything in this file that those four commands contradict is wrong, and the
commands win.

## Legend

| Mark | Meaning |
| --- | --- |
| **Done** | Works end to end against PostgreSQL and the HTTP API, and is covered by a test |
| **Partial** | Real, but with a named limit — the limit is stated, never implied |
| **Deferred** | Deliberately not built. The reason is given, not just the fact |
| **Blocked** | Cannot be finished from inside this repository |
| **Not started** | Neither built nor deliberately dropped |

---

## Foundation

| Area | Status | Notes |
| --- | --- | --- |
| PHP framework, routing, request/response | **Done** | Hand-rolled, no runtime Composer dependency. See `ARCHITECTURE.md` for why this and not CodeIgniter |
| PostgreSQL schema | **Done** | 13 migrations, 64 tables. Every status column has a CHECK constraint; the audit log is append-only by trigger |
| Multi-tenancy | **Done** | Every query filters `environment` and `cmp_id`. `CrossTenantIsolationTest` proves it for the read paths; the static audit catches new queries that omit it |
| Portal SSO, two-token auth | **Done** | Long-lived `auth_token`, short-lived in-memory `ses_key` |
| RBAC, 11 roles | **Done** | `PERMISSIONS.md`. Every controller action names a grant; the audit fails the build if one does not |
| Audit trail | **Done** | Append-only, enforced in the database rather than in application code |
| Rate limiting | **Done** | Per-action budgets, database-backed |

## Contract lifecycle

| Area | Status | Notes |
| --- | --- | --- |
| Contract record, 16-tab workspace | **Done** | |
| Configurable contract types and numbering | **Done** | Per-company counters, gapless within a series |
| Requests → draft → review → approval → signature → active | **Done** | Status graph enforced server-side; `EndToEndFlowTest` walks it |
| Documents and versioning | **Done** | Version rows are immutable; a new upload is a new version |
| Redline / diff | **Done** | Deterministic LCS diff, word-level within a paragraph. AI commentary on a diff is a separate, optional path |
| Templates and merge variables | **Done** | Variables resolve from an allow-list; an unknown variable is surfaced as unresolved, never blanked and never evaluated |
| Clause library and versions | **Done** | Editing published wording creates a version rather than rewriting history |
| Playbook and clause deviation | **Done** | |
| Approval engine | **Done** | Configurable workflows, parallel and sequential steps, delegation |
| Obligations and occurrences | **Done** | Recurrence generation, evidence requirement enforced server-side |
| Milestones | **Done** | |
| Commercial terms and payment schedules | **Done** | |
| Renewals | **Done** | Notice-deadline tracking is the part that matters and it is real |
| Amendments | **Done** | Traceable to the contract version they amend |
| Terminations | **Done** | |
| Contract health score | **Done** | |

## Risk and AI

| Area | Status | Notes |
| --- | --- | --- |
| Rules risk engine | **Done** | Diminishing-returns scoring with severity floors, so scores discriminate instead of saturating at 100 |
| AI provider abstraction | **Done** | Gemini, OpenAI and Anthropic adapters, provider-neutral interface |
| Credentials from Console | **Done** | Resolved per request from `console.aicountly.org`. No provider key is ever stored on this host |
| Output schema validation | **Done** | Every AI response is validated against a JSON schema before it is read |
| Prompt-injection defence | **Done** | `PromptGuard`, plus the rule that no model output writes a contract field directly |
| Staged document pipeline | **Done** | Queued jobs, `FOR UPDATE SKIP LOCKED`, retry with backoff |
| Extraction → human review → apply | **Done** | An extraction is a proposal. `AiReviewQueue` is where a person accepts it |
| Ask Your Contract | **Done** | Grounded in the contract's own text, returns citations, and answers "I don't know" rather than guessing |
| AI summaries, renewal advice, deviation analysis | **Done** | |
| **AI features in a fresh deployment** | **Blocked** | Until a Console administrator binds a credential to `contracts.aicountly.com` / `contract_ai`, every AI surface reports itself unconfigured. That is the correct state, not a bug — see below |

## Integrations

| Area | Status | Notes |
| --- | --- | --- |
| Drive (file storage) | **Done** | `product_code=contracts`, company scope, `contract` retention class. Drive's own docs were updated to register Contracts — see `DRIVE_INTEGRATION.md` |
| Drive unavailable | **Partial** | A local storage adapter keeps uploads working without Drive. It is a development fallback, not a supported production mode |
| Contacts (counterparty master) | **Done** | Live read-through, plus the legal snapshot written once at execution and never refreshed |
| Contacts — statutory identifiers | **Partial** | Contacts stores no GSTIN/PAN/CIN column. They are read from `integrationMeta` when an importer carried them and are otherwise empty. Nothing invents them |
| Manage (company master) | **Done** | Contracts was already in `AppCatalogService`; no change was needed |
| Console (AI credentials) | **Done** | One cross-repo migration was required — `console-react-app` `032_contracts_ai_registry`. Recorded in `CONSOLE_AI_CONFIG.md` |
| Linked records | **Done** | Generic `product_code` / `record_type` / `record_id` pointer. Deliberately dumb — no foreign keys across products |
| Write-back of contract links into Contacts | **Deferred** | Contacts' product-reference rows are scoped to the `owner_uuid` of whoever created them, and a contract belongs to a company, not to a user. A reference written under one person's uuid would be invisible to their colleagues and would vanish when they left. Wiring it would need an ownership change in Contacts, which is more than this product should impose |

## Signatures

| Area | Status | Notes |
| --- | --- | --- |
| Signature request tracking | **Done** | Signers, order, status, per-contract history |
| Provider abstraction | **Done** | `SignatureProvider`, resolved by factory |
| Manual provider | **Done** | Ships as the default: no vendor, no envelope sent, but the register of who still has to sign is real and execution is recorded when the signed copy is uploaded |
| Inbound webhook | **Done** | Signature verified over the raw body before anything is trusted; idempotent by vendor event id, or by body hash for vendors that do not number deliveries; tenant resolved from the stored request row, never from the payload |
| DocuSign / Adobe Sign adapters | **Not started** | The interface and the webhook route are built and the factory will pick an adapter up, but no vendor adapter is written. Writing one against a vendor account nobody has yet would be untested code with a vendor's name on it |

## Notifications and automation

| Area | Status | Notes |
| --- | --- | --- |
| In-app notifications | **Done** | Deduplicated by a unique key bound to the reminder ladder band, so a sweep that runs twice does not notify twice |
| Cron sweeps | **Done** | Expiry, obligations, renewals, approvals, jobs, AI, cleanup. CLI-only — the entry point refuses a web request |
| Background job queue | **Done** | `SELECT ... FOR UPDATE SKIP LOCKED`, visible run history |
| Email delivery | **Partial** | The channel interface exists and notifications record whether email was really sent. With no transport configured the null channel does nothing **and says so** — `email_sent_at` stays null. No SMTP transport is shipped: claiming a mail was sent when nothing left the server turns a missed renewal into an argument about logs |

## Reporting and search

| Area | Status | Notes |
| --- | --- | --- |
| Dashboard, 13 KPIs and 12 charts | **Done** | Every figure is a server-side aggregate over rows the caller can already open, narrowed by the same visibility predicate the repository uses. Six of the categorical series come out of one `GROUPING SETS` pass, so they cannot disagree with each other |
| Global search | **Done** | tsvector ranking plus trigram similarity, so a typo still finds the contract |
| Reports | **Done** | Uniform `{columns, rows}` envelope, one renderer, CSV export with the formula-injection guard |
| Saved views, favourites, recent | **Done** | |

## Frontend

| Area | Status | Notes |
| --- | --- | --- |
| Design system | **Done** | Token-driven, brand green `#25b003`, dark mode, Nunito |
| App shell, 14-item navigation | **Done** | |
| All product screens | **Done** | Every screen calls the real API. None renders fixture data |
| Loading / empty / error states | **Done** | Enforced by the page tests |
| Permission gating | **Done** | A control the server would refuse is not rendered |
| Accessibility | **Partial** | Labels, keyboard operation and aria are in place and tested in the UI kit. No screen-reader pass has been done on a real assistive stack, and that is the check that finds what static tests cannot |

## Testing

| Area | Status | Notes |
| --- | --- | --- |
| Backend suite | **Done** | 24 files against a real PostgreSQL — no mocked database |
| Cross-tenant isolation | **Done** | Dedicated suite |
| Security tests | **Done** | IDOR, SSRF, injection, prompt injection |
| End-to-end flows | **Done** | The named lifecycle flows walked through the service layer |
| Route smoke test | **Done** | All 193 routes answer intentionally over HTTP |
| Static self-audit | **Done** | Missing classes, unresolved routes, unguarded actions, tenant scope, debug code, secrets, migration numbering |
| Frontend tests | **Done** | UI kit and page tests |
| Browser end-to-end | **Not started** | Every layer is tested, but nothing drives a real browser through a full flow |
| Load / performance testing | **Not started** | Indexes are in place and queries are bounded, but no figure here has been measured under load, so none is claimed |

## Known limits, stated plainly

1. **AI is unconfigured until Console has a bound credential.** Not a defect — the
   product refuses to fabricate. An empty risk assessment is indistinguishable
   from a clean contract, so no AI feature falls back to a stub.
2. **No e-signature vendor adapter ships.** Manual signature tracking works from
   day one; sending envelopes needs an adapter written against a real account.
3. **No email transport ships.** In-app notifications are the guaranteed channel.
4. **The local storage adapter is for development.** Production storage is Drive.
5. **Statutory identifiers can be empty** where Contacts never held them. Empty is
   honest; a guessed GSTIN in a legal snapshot is evidence of something that
   never happened.
6. **This product is not legal advice.** Risk scores, deviation findings and AI
   summaries are drafting aids for a person who is qualified to judge them.

## Not claimed

For the avoidance of the specific doubt this file exists to answer: nothing in
this repository renders fixture data as though it were live, no screen is a
placeholder awaiting an API, and no service returns a canned response in place
of a query. Where a capability is absent it is listed above as absent.
