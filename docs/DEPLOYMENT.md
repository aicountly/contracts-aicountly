# Deployment

Two environments, deployed separately so releasing to one cannot disturb the
other.

| | App | API |
|---|---|---|
| Production | https://contracts.aicountly.com | https://contracts.aicountly.com/api |
| Sandbox | https://contracts.gh.aicountly.com | https://contracts.gh.aicountly.com/api |

Deployment is **manual only**. Both workflows trigger exclusively via
`workflow_dispatch` — merging or pushing never deploys.

**Actions** → pick a workflow → **Run workflow** → pick a branch → **Run**.

## What a run does

1. Builds `web/` on the runner (`npm ci && npm run build`).
2. `rsync --delete` of `web/dist/` into the document root.
3. `rsync --delete` of `server-php/` into `api/` inside that document root.
4. **Applies pending database migrations** over SSH.
5. Smoke-tests `GET /api/health`.

Web and API deploy in the same run because they always change in step. There is
deliberately no "API only" workflow to remember.

### Why the excludes matter

The **web** step syncs the document root with `--delete` and excludes:

- `api/` — the API lives inside the document root and is deployed by the next
  step in the same run. **Without this exclude the web step would delete the
  entire API.**
- `.well-known/` — Let's Encrypt / AutoSSL validation; removing it breaks
  certificate renewal
- `cgi-bin/` — cPanel-managed, present in every document root
- `.env`, `.env.*`, `.git*` — never published

The **API** step excludes `.env` and `.env.*`: the API's `.env` is created once
on the server and read at runtime, so it must survive every deploy.

### Migrations

Step 4 runs `php database/migrate.php` in `api/`. It is idempotent — already
applied files are skipped — and each file runs in its own transaction, so a
failure leaves the schema whole rather than half-migrated.

A migration failure **fails the deploy**. The code is already on the server at
that point, so the job's error message says exactly that: the app will report a
missing table until the schema catches up.

## One-time server setup

### 1. The document root

Point the domain (or subdomain) at a folder, and put that path in
`PROD_SSH_REMOTE_ROOT` / `SANDBOX_SSH_REMOTE_ROOT`. A relative path is normal on
cPanel — `public_html` resolves against the SSH user's home.

Because the deploy runs with `--delete`, the workflow refuses a value that would
resolve to the home directory itself (`.`, `~`, empty), a system directory, or
anything containing `..`.

### 2. PostgreSQL

In cPanel → PostgreSQL Databases:

1. Create a database, e.g. `<cpaneluser>_contracts`.
2. Create a user and give it **all privileges** on that database.
3. Confirm the `pdo_pgsql` PHP extension is enabled (cPanel → Select PHP
   Version → Extensions). `GET /api/health` reports it if it is missing.

### 3. `api/.env`

Created by hand, once. It is never uploaded and never deleted.

```bash
ssh <user>@<host>
cd ~/public_html/api
cp .env.example .env
nano .env          # fill in APP_ENV and the DB_* values at minimum
chmod 600 .env
```

Minimum for the app to work at all:

```env
APP_ENV=production
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=<cpaneluser>_contracts
DB_USER=<cpaneluser>_contracts
DB_PASS=<the password>
```

`APP_ENV` must match the host. The API **refuses to serve** when it does not,
because a sandbox `.env` copied onto the production box would tag production
rows `sandbox` and no report would find them again.

### 4. First migration

```bash
cd ~/public_html/api && php database/migrate.php
```

The deploy does this from step 5 onward; running it by hand the first time
confirms the credentials before a workflow depends on them.

### 5. Cron

See [`CRON_AND_JOBS.md`](./CRON_AND_JOBS.md) for the exact lines. Four entries:
nightly sweeps, nightly cleanup, and the two queue drains every five minutes.

### 6. Integrations

| Variable | Needed for | Without it |
|---|---|---|
| `DRIVE_API_BASE` | Document storage | Uploads unavailable unless the local fallback is enabled |
| `CONSOLE_API_URL` + `CONSOLE_SERVICE_KEY` | AI | Every AI feature reports "not configured" |
| `MANAGE_API_BASE` | Company context | Derived from the hostname; only set to override |
| `CONTACTS_API_BASE` | Counterparty lookup | Derived from the hostname; picker falls back to free text |
| `SIGNATURE_PROVIDER` | e-signature | Contracts still records an externally signed copy |
| `CONTRACTS_EMAIL_ENABLED` | Email reminders | In-app notifications only, reported honestly |

`GET /api/health` and Settings → Integrations both report which of these are
configured, so a missing one is visible rather than mysterious.

## Repository configuration

**Secrets** (Settings → Secrets and variables → Actions):

`PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`,
`PROD_SSH_REMOTE_ROOT` — and the same five with a `SANDBOX_` prefix.

**Variables** (optional): `PROD_API_BASE_URL`, `SANDBOX_API_BASE_URL`. Unset,
the app calls its own origin + `/api`, which is where the same workflow puts the
API. Set one only to point the app at a different API domain.

### npm and `--legacy-peer-deps`

`.npmrc` sets `legacy-peer-deps=true`. npm 10's resolver crashes
(`Cannot read properties of null (reading 'edgesOut')`) while walking vitest's
optional browser-runner peers, which pull in `@vitest/browser-playwright` and
`canvas`. Nothing here uses the browser runner; the legacy resolver walks the
same graph without crashing and produces the tree we want. Remove the line once
npm ships the fix.

## Build-time vs runtime configuration

These work in opposite directions, and mixing them up is the usual cause of "I
changed the endpoint and nothing happened".

| File | Read | Used by |
|---|---|---|
| `.env` (repo root) | **build time**, inlined into the bundle | `web/` |
| `server-php/.env` | **runtime**, on every request | `server-php/` |

Vite substitutes each `VITE_*` value into the JavaScript when the app is
compiled. The deployed result is static files — **the app never reads a `.env`
from disk**, so placing one next to it in the document root has no effect.
Changing a `VITE_*` value means rebuilding and redeploying.

Only `VITE_`-prefixed variables reach the browser, and Vite inlines them, so
**treat every one as public**. Never put a secret in a `VITE_` variable.

## Rolling back

Re-run the workflow against the previous tag or commit. The migration step is
forward-only: a rollback of code past a migration boundary needs a considered
down-migration, which is why migrations here are additive wherever possible
(`ADD COLUMN`, new tables) rather than destructive.

## Verifying a deploy

```bash
curl -s https://contracts.aicountly.com/api/health | python3 -m json.tool
```

`status: "ok"` with `database.ok`, `migrations.ok` and the integrations you
expect. `status: "degraded"` still returns 200 — the app is answering, and the
body says what is wrong.
