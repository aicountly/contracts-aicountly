# AICOUNTLY Contracts — Mobile (Expo)

Companion app for [Contracts](../README.md), built with **Expo (managed) + React Native + TypeScript + Expo Router**, on the same Expo SDK line and dependency set as [Smart Books' own mobile app](https://github.com/aicountly/books-react-app/tree/main/mobile) for one consistent toolchain across the AICOUNTLY mobile fleet.

This app is source-only in the monorepo — it is **not** part of the cPanel deploy pipeline (`web/` and `server-php/` only, see `.github/workflows/deploy-*.yml`). It ships to TestFlight / Play Store via **EAS Build**.

## Scope

Full feature parity with `web/`: dashboard, contract repository and the full contract workspace (17 tabs — Overview through Signatures), every "needs your attention" queue (requests, approvals, obligations, renewals, amendments, risks, AI review), templates, clause library, playbooks, reports with CSV export, notifications, global search, and settings (contract types, departments, custom fields, tags, risk rules, plus read-only roles/workflows/platform status — see [Settings + Account](#settings--account) below for what stays read-only and why).

## Auth (live)

Full native auth suite, matching what `books-react-app/mobile` already proved in production:

1. Obtain an `auth_token` — email + password (`POST /api/login`), mobile OTP (`POST /api/auth/otp/send` → `/verify`), the social handoff (Google / LinkedIn / Microsoft through the portal's in-app-browser flow), or native Sign in with Apple (`POST /api/auth/social_login`)
2. `POST /api/seskey` with `Bearer auth_token` → short-lived `ses_key` (~15 min, memory-only, never persisted)
3. Contracts API calls (`contracts.gh.aicountly.com` / `contracts.aicountly.com`) use `Authorization: Bearer ses_key` plus `X-AIC-CMP-ID` / `X-AIC-FY-ID` / `X-AIC-BO-ID` headers for company/branch/FY context — the same headers `web/src/services/apiClient.ts` sends, not books' query-param convention.

Full details: [`docs/MOBILE_AUTH.md`](docs/MOBILE_AUTH.md)

## Setup

```bash
cd mobile
npm install
cp .env.example .env   # defaults to sandbox Contracts API
npm start                # Expo Dev Tools; press i/a for simulators, or scan QR with Expo Go
```

Requires Node 20+. iOS builds/simulators require macOS + Xcode; Android requires Android Studio (or Expo Go on a physical device). This project is **Expo SDK 57** — keep Expo Go updated to match.

## Environment variables (`.env`)

| Variable | Default | Purpose |
|---|---|---|
| `EXPO_PUBLIC_CONTRACTS_ENV` | `sandbox` | `sandbox` → `contracts.gh.aicountly.com`, `production` → `contracts.aicountly.com` |
| `EXPO_PUBLIC_API_ORIGIN` | _(unset)_ | Optional override of the Contracts API origin |
| `EXPO_PUBLIC_LOGIN_PORTAL_ORIGIN` | _(unset)_ | Optional override of the portal used for the SSO in-app-browser handoff |
| `EXPO_PUBLIC_ACCOUNT_DELETION_URL` | _(unset)_ | Optional override of the page the Account screen's "Delete account" button opens |

`my.aicountly.com` (auth/global APIs — login, OTP, seskey, session validation) is not configurable — same origin in both environments; see `src/config/env.ts`.

## Features

### Dashboard
KPI tiles, "Needs your attention" card, chart series across the full portfolio (status/type/risk/expiry breakdowns), recent activity feed.

### Contracts
Repository search/filter (~20 filter fields) with favourites, create/edit form (all 24 fields), and the full contract workspace — Overview, Document (upload/mark-executed), Parties, Commercials, Clauses, Obligations (complete with photo/file evidence), Milestones, Payments, Approvals, Versions (diff viewer), Amendments, Renewal, Risk, AI Insights + Ask Your Contract, Linked Records, Activity + Comments, and Signatures.

### Attention
Requests (create/track), Approvals (act + workflow viewer), Obligations (mark complete with evidence), Renewals, Amendments, Risks (accept/review findings), and the AI Review Queue (accept/reject extracted fields).

### More
- **Templates** — merge-variable editor with an insert-at-cursor palette, live preview against a real contract, version history, and create-a-contract-from-template.
- **Clause Library** — standard/fallback/prohibited wording, category browsing, applicable contract types, version history.
- **Playbooks** — rule management (mandatory/prohibited clauses, numeric caps, allowed/prohibited lists) per playbook.
- **AI Insights** — portfolio-wide findings.
- **Reports** — catalogue, per-report filters, client-side sort, CSV export via the native share sheet.
- **Notifications** — unread/everything inbox, mark read / mark all read.
- **Search** — across contracts, clause wording, and extracted document text.
- **Settings + Account** — see below.

### Settings + Account
General & numbering, reminders, contract types, departments, custom fields, tags, and risk rules are fully editable — matching the web app's own API shapes exactly. Roles & permissions, approval workflow steps, and integrations/AI platform status are **read-only on mobile**: role grants are security-sensitive and better done on a screen you can't fat-finger, and the workflow step builder is a desktop-scale editor neither of which is worth cramming onto a phone. Account has profile, a shortcut into the company/branch/FY picker, sign out, and account deletion (opens the account portal — this app never creates the AICOUNTLY account, so it can't delete it either; see `getAccountDeletionUrl()` in `src/config/env.ts`).

## Architecture

```
app/                  Expo Router screens
  (auth)/             Login (email/password, OTP, SSO, Apple)
  auth/callback.tsx   Deep-link landing for aicountlycontracts://auth/callback
  company-picker.tsx
  (app)/               Tabs: Dashboard / Contracts / Attention / Reports / More
    contracts/[id]/    Contract workspace (single-screen tab switcher, not one route per tab)
    attention/         Requests, approvals, obligations, renewals, amendments, risks, ai-review
    more/settings/     Contract types, custom fields, risk rules (each its own list+form route group)
src/
  api/
    client.ts          ensureSesKey, company-context headers, {data:T} envelope unwrap
    endpoints/         One thin typed file per domain
  auth/                AuthProvider, nativeAuthProvider, ssoFlow, appleSignIn
  state/               CompanyContext
  components/          Shared UI (ScreenContainer, FormField, SelectField, BottomSheet, …)
    contracts/tabs/     The 17 contract workspace tabs
    settings/           Shared editor screens for contract types, custom fields, risk rules
  theme/               Brand colours, status-colour palettes, chart palette
  types/contracts.ts   Ported from web/src/types/contracts.ts — the shape source of truth
```

## Building (EAS)

`app.json`'s `extra.eas.projectId` is linked to a real EAS project.

```bash
npm install -g eas-cli
eas login
eas build --platform ios --profile testflight
eas submit --platform ios --profile testflight --latest

npm run eas:build:android:preview
npm run eas:build:android:production
npm run eas:submit:android:production
npm run eas:submit:android:open-testing
```

`eas build` packages whatever is in your local working directory, not what's on GitHub — always `git pull` before building, and rebuild after any `app.json`/source change before uploading to a store console.

No GitHub Actions build/submit workflow exists yet either (unlike `mobile-quality.yml` below).

### Versioning

- `expo.version` in `app.json` (e.g. `1.0.0`) is the user-facing marketing version. It's managed **manually and only on request** — never bumped automatically by tooling or by an agent working in this repo. A fresh app starts at `1.0.0`.
- `ios.buildNumber` / `android.versionCode` in `app.json` are internal build identifiers, not shown to users. Because `eas.json`'s `cli.appVersionSource` is `"remote"` and the `production`/`testflight` profiles set `autoIncrement: true`, EAS ignores the static values in `app.json` and tracks/auto-increments both numbers on its own servers, once per build. That counter only ever goes up — required by both stores, which reject an upload whose build number isn't strictly greater than the last one — so it will not match or reset alongside a `version` change, and that's expected, not a bug.

## Out of scope / web-only

- **Redlining/diff authoring** — reading a version diff is in scope (the Versions tab); writing a template or clause library entry's *wording* from scratch on a phone is not a great editing surface either, but is built anyway for parity — what's genuinely skipped is a rich-text redlining editor beyond a plain multi-line field.
- **Console admin config, signature-vendor configuration** — see Settings + Account above.
- **Not product scope** (matches books' own mobile app): push notifications, offline sync, biometric unlock.

## Scripts

```bash
npm run typecheck
npm run lint
npm test           # no .test.ts files exist yet in this app
```

Typecheck, lint and test run in CI on every PR touching `mobile/**` — see [`.github/workflows/mobile-quality.yml`](../.github/workflows/mobile-quality.yml).
