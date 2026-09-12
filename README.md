# Aicountly Contracts

> Know what your business has agreed to — and ensure it actually happens.

AI-assisted Contract Lifecycle Management for the AICOUNTLY ecosystem. A
contract here is a **structured business record**, not a PDF in a folder: its
dates, obligations, commercial commitments, clauses and risks are data you can
query, and the deadlines that matter come and find you.

| Environment | App | API |
| --- | --- | --- |
| Production | https://contracts.aicountly.com | https://contracts.aicountly.com/api |
| Sandbox | https://contracts.gh.aicountly.com | https://contracts.gh.aicountly.com/api |

## What it answers

- What contracts does the company have, and which are expiring?
- Which renew automatically, and when does the cancellation window close?
- What obligations are due, and whose are they?
- What did we actually commit to commercially?
- Which clauses create risk, or deviate from our own standards?
- Which agreements need renegotiating before they roll over?
- What does Amendment 2 change about the original agreement?

## Lifecycle

```
REQUEST → DRAFT/UPLOAD → AI ANALYSIS → INTERNAL REVIEW → APPROVAL → NEGOTIATION
   → VERSIONING/REDLINING → SIGNATURE → ACTIVE → OBLIGATIONS
   → COMMERCIAL TRACKING → AMENDMENTS → RENEWAL / TERMINATION → ARCHIVE
```

See [`docs/CONTRACT_LIFECYCLE.md`](docs/CONTRACT_LIFECYCLE.md).

## Where it sits in AICOUNTLY

Contracts owns contract intelligence and the contract lifecycle. Everything else
around an agreement already has an owner, and duplicating it would create a
second source of truth that silently diverges.

| Contracts owns | Owned elsewhere |
|---|---|
| The contract record and its lifecycle | Company master — **Manage** |
| Parties, and their legal snapshot at execution | Contact master — **Contacts** |
| Document metadata, versions, lineage | Document bytes — **Drive** |
| Clauses, playbook, deviations, risk | Accounting — **Books** · Payments — **Pay** |
| Obligations, milestones, commercials | Calendar sync — **Calendar** |
| Approvals, renewals, amendments | Chat and meetings — **Connect** |
| Its own AI prompts and analysis | LLM credentials — **Console** |
| Its own jobs, cron and reminders | Identity — **my.aicountly.com** |

There is no shared agent runtime and no central AI brain: each product owns its
own AI logic, and Console owns only the provider credentials.

## Layout

```
web/          React 19 + TypeScript (Vite). Builds to web/dist, deployed to the document root.
server-php/   PHP 8.1+ API. No runtime dependencies. Deployed to api/ inside that document root.
docs/         architecture, engines, integrations, security, deployment
```

The SPA and its API are same-origin, which is what keeps the session bootstrap
free of CORS entirely.

## Getting started

Requires Node 22+, PHP 8.1+, and PostgreSQL 14+.

```bash
# API
cd server-php
cp .env.example .env          # set APP_ENV=local and the DB_* values
php database/migrate.php
php -S localhost:8000         # http://localhost:8000/index.php/health

# App
cd ../web
npm install
cp ../.env.example ../.env
npm run dev                   # http://localhost:5173
```

Point `VITE_API_BASE_URL` at the deployed sandbox API
(`https://contracts.gh.aicountly.com/api`) so the token exchange has somewhere to
go, and add `http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in that server's
`api/.env` — localhost is the one case where the app and API are not same-origin.

| Command | Does |
| --- | --- |
| `npm run dev` | Vite dev server |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm test` | Frontend tests (vitest) |
| `php server-php/tests/run.php` | Backend tests |
| `php server-php/database/migrate.php` | Apply pending migrations |
| `php server-php/database/migrate.php --status` | List applied and pending |
| `php server-php/database/cron.php daily` | Run the nightly sweeps by hand |

`.npmrc` sets `legacy-peer-deps=true`; the reason is in
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Signing in

The AICOUNTLY portal owns every token. The app redirects to the portal, the
portal returns an `auth_token`, and the app exchanges it for a short-lived
`ses_key`. A user already signed in to another AICOUNTLY product lands straight
on the dashboard.

`auth_token` is long-lived and stored; `ses_key` lives ~15 minutes and **never
leaves memory**. See [`docs/auth/AICOUNTLY_AUTH_WORKFLOW.md`](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md).

## Environment variables

Two `.env` files, working in opposite directions:

| File | Read | Used by |
| --- | --- | --- |
| `.env` (repo root) | **build time**, inlined into the bundle | `web/` |
| `server-php/.env` | **runtime**, on every request | `server-php/` |

Only `VITE_`-prefixed variables reach the browser, and Vite inlines them at build
time — **treat every one as public**. Never put a secret in a `VITE_` variable.
The deployed app never reads a `.env` from disk, so changing an endpoint means
rebuilding.

`server-php/.env.example` is the annotated template for the runtime side. The
values that must be supplied by hand are listed in
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Deployment

Manual only — both workflows trigger exclusively via `workflow_dispatch`.
**Actions** → pick a workflow → **Run workflow**.

A run builds the app, rsyncs `web/dist/` to the document root, rsyncs
`server-php/` to `api/` inside it, **applies pending migrations**, and
smoke-tests `/api/health`. Full setup — database, `api/.env`, cron — is in
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

## Documentation

| | |
|---|---|
| [`ARCHITECTURE.md`](docs/ARCHITECTURE.md) | How the product is put together, and why |
| [`DATABASE_SCHEMA.md`](docs/DATABASE_SCHEMA.md) | Every table and the decisions behind them |
| [`API_REFERENCE.md`](docs/API_REFERENCE.md) | Every endpoint |
| [`CONTRACT_LIFECYCLE.md`](docs/CONTRACT_LIFECYCLE.md) | Statuses, transitions, amendments, termination |
| [`AI_ARCHITECTURE.md`](docs/AI_ARCHITECTURE.md) | Provider layer, prompts, validation, grounding |
| [`RISK_ENGINE.md`](docs/RISK_ENGINE.md) | Deterministic rules, playbook deviation, health score |
| [`APPROVAL_ENGINE.md`](docs/APPROVAL_ENGINE.md) | Workflow matching, steps, escalation |
| [`OBLIGATION_ENGINE.md`](docs/OBLIGATION_ENGINE.md) | Recurrence, occurrences, evidence |
| [`INTEGRATIONS.md`](docs/INTEGRATIONS.md) | The ecosystem boundary |
| [`DRIVE_INTEGRATION.md`](docs/DRIVE_INTEGRATION.md) | Document storage |
| [`CONTACTS_INTEGRATION.md`](docs/CONTACTS_INTEGRATION.md) | Counterparties and legal snapshots |
| [`CONSOLE_AI_CONFIG.md`](docs/CONSOLE_AI_CONFIG.md) | Where LLM credentials come from |
| [`CRON_AND_JOBS.md`](docs/CRON_AND_JOBS.md) | Automation, queues, idempotency |
| [`SECURITY.md`](docs/SECURITY.md) | Tenant isolation, input, output, AI safety |
| [`PERMISSIONS.md`](docs/PERMISSIONS.md) | Roles and permission slugs |
| [`DEPLOYMENT.md`](docs/DEPLOYMENT.md) | cPanel setup and release |
| [`TESTING.md`](docs/TESTING.md) | How to run and write tests |
| [`IMPLEMENTATION_STATUS.md`](docs/IMPLEMENTATION_STATUS.md) | **What is done, what is not, and what is deferred** |

## A note on AI

AI-generated contract analysis is provided for assistance and information. It
does not constitute legal advice and should be reviewed by an authorized legal or
professional reviewer before reliance.

The product is built around that sentence rather than around it. Extraction is
marked as extraction: every AI-derived value carries a confidence, a source
excerpt, and a `verification_state` that stays `ai_extracted` until a person
confirms it. The risk engine is deterministic rules first; AI adds
interpretation on top and is labelled where it does. Ask Contract answers only
from the contract's own text, cites where it found the answer, and says plainly
when the text does not contain one.
