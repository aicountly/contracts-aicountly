# Architecture

**Product:** Aicountly Contracts — `contracts.aicountly.com`
**Repository:** one repo, one deployable, one database.

## What this product owns, and what it does not

Contracts owns **contract intelligence and the contract lifecycle**. Everything
else in the agreement's orbit already has an owner in the AICOUNTLY ecosystem,
and duplicating any of it would create a second source of truth that silently
diverges.

| Owned here | Owned elsewhere |
|---|---|
| The contract record and its lifecycle | The company master — **Manage** |
| Parties, and the legal snapshot taken at execution | The contact master — **Contacts** |
| Documents' metadata, versions and lineage | The document bytes — **Drive** |
| Clauses, playbook, deviations | Accounting — **Books** |
| Obligations, occurrences, evidence | Payments — **Pay** |
| Milestones, commercial terms, payment schedule | Calendar presentation — **Calendar** |
| Approvals, renewals, amendments, terminations | Chat and meetings — **Connect** |
| Risk rules, findings, health score | LLM credentials — **Console** |
| Its own AI prompts, extraction and Q&A | Identity and sessions — **the portal** |
| Its own jobs, cron and reminders | |

There is deliberately **no shared agent runtime and no central AI brain**. Each
AICOUNTLY product owns its own AI logic; Console owns only the provider
credentials.

## Shape

```
contracts-aicountly/
├── web/                       React 19 + TypeScript, built by Vite
│   ├── src/components/ui/      the hand-rolled UI kit
│   ├── src/components/layout/  shell, sidebar, topbar
│   ├── src/context/            company, session, toast
│   ├── src/pages/              one file per screen
│   └── src/services/           the API client
├── server-php/                PHP 8.1+, no runtime dependencies
│   ├── app/Core/               Router, Database, Request, Response, Http, Env
│   ├── app/Controllers/Api/    thin — permission check, delegate, respond
│   ├── app/Services/           the domain logic
│   ├── app/Services/Automation/ the nightly sweeps
│   ├── app/Ai/                 provider abstraction, prompts, schemas, guards
│   ├── app/Modules/            one client per ecosystem product
│   ├── app/Support/            enums, permissions, validation, dates
│   ├── database/migrations/    plain SQL, applied in filename order
│   └── tests/                  plain PHP, run directly
└── docs/
```

`web/` builds to `web/dist` and deploys to the document root. `server-php/`
deploys to `api/` **inside** that document root, so the SPA and its API are
same-origin — which is what keeps the session bootstrap free of CORS entirely.

## Why this backend, and not CodeIgniter

The brief specified CodeIgniter 4.6. The fleet is split: Contacts and Console
are CI4; Drive and Manage are not. Drive is the newest product, the largest, and
the one Contracts integrates with most closely, and it uses a small hand-rolled
`app/Core` with CI4's directory naming. Matching Drive was worth more than
matching the description, because a reviewer moving between the two repos should
find the same shapes.

The consequence is that **Contracts has no runtime Composer dependencies**. Drive
owns object storage so nothing here needs the AWS SDK, and every integration is
a cURL call. The app ships its own PSR-4 autoloader, so a deploy is an rsync
with no `composer install` step that can be forgotten on a new host.
`composer.json` still declares the same map for anyone who prefers
`composer dump-autoload`; the front controller uses `vendor/autoload.php` when
it is present.

## Request path

```
Apache (.htaccess)  →  api/index.php
                          ├─ error + fatal handlers   (never render a trace)
                          ├─ CORS                     (exact allow-list, dev only)
                          ├─ environment check        (APP_ENV vs hostname)
                          └─ Router::dispatch
                                └─ Controller
                                     ├─ requirePermission()  ← the gate
                                     └─ Service              ← the logic
```

### The gate

`BaseController::requireContext()` is the only place three questions are
answered, and nothing downstream repeats them:

1. **Is this session live?** The portal decides
   (`POST my.aicountly.com/api/validatesession`).
2. **May this user act for this company?** Manage decides
   (`GET manage.aicountly.com/api/companyinfo?comp_id=`). A refusal here is a
   403; Contracts never decides company access itself.
3. **What may they do inside Contracts?** `contract_user_roles` decides, via
   `RoleService` and `Support\Permissions`.

The result is a `TenantContext`, and it is the **only** legitimate source of
`cmp_id` and `environment` for a query. Nothing downstream may construct one
from request input.

## The rule everything else rests on

> Every tenant-scoped query filters on `environment` **and** `cmp_id` taken from
> the `TenantContext`, never from request input.

`tests/CrossTenantIsolationTest.php` seeds two companies with deliberately
identical data and then tries to read, edit, delete and cross-reference across
the boundary by id. A failure there is a data breach, not a bug.

Row-level visibility inside one company is the same class of rule: a user
without `contract.view_all` sees only contracts they own, created, or are an
approver on. That predicate is built once in `ContractService::visibilityPredicate()`
and shared by `find()` and `search()` — enforcing it on the list but not on a
direct read is exactly the IDOR this arrangement prevents, and it was a real bug
caught by the isolation test during development.

## Layering

**Controllers are thin.** Name a permission, parse and clamp input, delegate,
respond. `BaseController::run()` maps the three failure modes to HTTP so no
action repeats the catch blocks:

| Thrown | Answer |
|---|---|
| `ValidationFailed` | 422 with `errors` as field → message |
| `DomainException` | its own status — 404, 403, 409, 503 |
| anything else | 500, logged, never rendered |

**Services hold the logic** and know nothing about HTTP. They take a
`TenantContext`, return arrays, and throw. Multi-step writes run inside
`Database::transaction()`.

**Modules are the ecosystem boundary.** One client per product
(`Modules/Portal`, `Modules/Manage`, `Modules/Contacts`, `Modules/Drive`), each
using `Core\Http` so timeouts, redirect policy and the SSRF guard are decided
once.

## Data conventions

- `BIGINT` identity primary keys; a `UUID` on anything a URL or another product
  may reference, so sequential ids are not an enumeration hint.
- `(environment, cmp_id)` on every tenant-owned table. `environment` looks
  redundant while each deployment has its own database, and stops being
  redundant the first time a production dump is restored into sandbox.
- Bare `TIMESTAMP` holding UTC. The connection pins `SET TIME ZONE 'UTC'`, so
  every stored instant means one thing on every host.
- Money is `NUMERIC` and travels as a **string** end to end. A contract value
  that has been through a JavaScript float is a value you cannot reconcile.
- Statuses are `CHECK` constraints matching `Support\Enums`. The two change
  together, and a migration is what makes that visible in review.
- `JSONB` only for genuinely open-ended metadata. Anything filtered, summed or
  sorted gets a real column.

## Two logs, on purpose

`contract_activity_logs` is the readable story — "Approval requested from
Legal". Trimmable, presentational.

`contract_audit_logs` is the compliance record — before/after values, actor, IP.
A database trigger refuses `UPDATE` and `DELETE` on it. Merging the two would
mean either an unreadable timeline or a mutable audit trail.

## Determinism before AI

The risk engine, the playbook comparison and the version diff are all
deterministic: same input, same output, no model call. AI adds interpretation on
top and is always labelled as such, with a confidence and a source excerpt.
Nothing AI produces is stored as verified until a person has verified it —
`verification_state` carries that distinction on every affected row.

See [`AI_ARCHITECTURE.md`](./AI_ARCHITECTURE.md) and [`RISK_ENGINE.md`](./RISK_ENGINE.md).

## Related

- [`DATABASE_SCHEMA.md`](./DATABASE_SCHEMA.md)
- [`API_REFERENCE.md`](./API_REFERENCE.md)
- [`SECURITY.md`](./SECURITY.md)
- [`PERMISSIONS.md`](./PERMISSIONS.md)
- [`INTEGRATIONS.md`](./INTEGRATIONS.md)
- [`IMPLEMENTATION_STATUS.md`](./IMPLEMENTATION_STATUS.md)
