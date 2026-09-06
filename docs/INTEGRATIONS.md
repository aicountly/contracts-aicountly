# Integrations

Contracts talks to five AICOUNTLY products. Every one of them goes through a
client in `app/Modules/`, and every client uses `Core\Http` so timeouts, redirect
policy and the SSRF guard are decided once rather than per integration.

The rule throughout: **reference, never copy.** Contracts stores an id and asks.
Copying another product's record creates a second source of truth that silently
diverges, and every one of those products already answers for its own data.

The single exception is the **legal snapshot** taken at execution — and it is an
exception on purpose. See [`CONTACTS_INTEGRATION.md`](./CONTACTS_INTEGRATION.md).

## Status at a glance

| Product | Purpose | State |
|---|---|---|
| **Portal** (`my.aicountly.com`) | Session validation | Live, required |
| **Manage** (`manage.aicountly.com`) | Company, branch, financial year | Live, required |
| **Contacts** (`contacts.aicountly.com`) | Counterparty lookup | Live, degrades |
| **Drive** (`drive.aicountly.com`) | Document storage | Live, with a documented fallback |
| **Console** (`console.aicountly.org`) | LLM credentials | Live, AI off without it |
| Books, Sales, Purchases, Billing, Pay, Calendar, Connect | Linked records | Architected, not implemented |

`GET /api/health` and Settings → Integrations report which of these are
configured on this deployment. A missing one is visible rather than mysterious.

## Portal — identity

The portal owns every token. Contracts never mints, signs or stores one.

```
POST my.aicountly.com/api/validatesession
Authorization: Bearer <ses_key>
→ {"status": 1, "uuid_aictly": "..."}
```

Anything other than `status: 1` — including a transport failure — is treated as
**not authenticated**, so a portal outage denies access rather than granting it.
Results are memoised per process, keyed by a hash of the key rather than the key
itself, because that array can end up in a stack trace.

`POST /api/global/{path}` relays three bootstrap paths for the browser
(`seskey`, `seskey/refresh`, `refresh_authtoken`) because a new product domain is
not in the portal's CORS allow-list on day one. It is an allow-list and must stay
one — see [`SECURITY.md`](./SECURITY.md).

## Manage — company context

```
GET manage.aicountly.com/api/companyinfo?comp_id=<id>
Authorization: Bearer <ses_key>
```

This call **is** the authorisation check. Manage refuses a company the session
may not read, so a null answer means "no access", not just "no data". Contracts
never decides company access on its own, which is what makes revoking access in
Manage take effect here immediately.

`ManageClient` normalises the payload, because the shape varies by endpoint
version — the financial-year list appears under five different key names across
deployments, and guessing one would reject every request rather than fail
visibly.

The host is derived from the caller's hostname (a `*.gh.aicountly.com` host
resolves to `manage.gh.aicountly.com`) unless `MANAGE_API_BASE` overrides it.
`GET /api/manage/companies` and `/api/manage/company` are relayed for the SPA,
which cannot call Manage directly for the same CORS reason as the portal.

## Contacts — counterparties

`GET /api/counterparties/search?q=` proxies to Contacts with the caller's own
session, so a user sees exactly the contacts they are allowed to see.

Contracts **never caches the contact master**. It stores `contact_ref_id` on the
party row and reads the live record for display.

Contacts being unreachable **degrades rather than breaks**: the search returns
empty, the picker accepts a free-text counterparty name, and the integration is
reported as unavailable. Blocking contract creation because a lookup service is
down would be the wrong trade.

## Drive — documents

Contracts owns document metadata, versions and lineage. Drive owns the bytes.

```
POST /api/upload-sessions      → presigned PUT
PUT  <upload_url>              → the browser sends the bytes
POST /api/upload-sessions/{id}/complete
POST /api/upload-sessions/{id}/finalize
POST /api/document-links       → bind the Drive document to the contract
```

`product_code=contracts`, company scope only. Full detail, including the
cross-repo change made to register Contracts in Drive's documented product plan,
is in [`DRIVE_INTEGRATION.md`](./DRIVE_INTEGRATION.md).

## Console — AI credentials

```
GET console.aicountly.org/api/ai/credentials/resolve?domain=contracts.aicountly.com&module=contract_ai
Authorization: Bearer <CONSOLE_SERVICE_KEY>
```

No provider key is ever stored on this host. Without Console, every AI feature
reports itself as unavailable — it does not fall back to a stub, because an
empty risk assessment is indistinguishable from a clean contract.

Registering this domain and module in Console's AI registry required one
cross-repo change — `console-react-app` migration `032_contracts_ai_registry` —
recorded, with the rest of the Console contract, in
[`CONSOLE_AI_CONFIG.md`](./CONSOLE_AI_CONFIG.md).

## Linked records — the extension point

`contract_linked_records` is a generic pointer: `product_code`, `record_type`,
`record_id`, a label, a relationship and an optional URL.

That is the whole mechanism, and it is deliberately dumb. A contract can point at
a Books voucher, a Sales order or a Calendar event without Contracts knowing
anything about those products' schemas, and adding a product is a row, not a
migration.

What it enables, once those products expose the reads:

| Product | Records |
|---|---|
| Books | voucher, invoice, ledger, payment, receipt |
| Sales | lead, opportunity, quotation, sales order |
| Purchases | RFQ, vendor, purchase order, purchase invoice |
| Billing | billing profile, recurring schedule, invoice |
| Pay | payment request, collection |
| Calendar | milestone event, renewal event |
| Connect | conversation, meeting |

### Contract vs actual

The architecture that makes this worth building:

```
Contract value        ₹12,00,000     ← Contracts
Invoices raised        ₹9,00,000     ← Books
Receipts               ₹7,50,000     ← Books
Outstanding            ₹1,50,000     ← derived
Unbilled               ₹3,00,000     ← derived
```

And on the buy side: committed spend against actual purchase orders, a contract
rate of ₹54/kg against a PO at ₹58/kg.

**None of this is implemented.** The link table, the relationship vocabulary and
the UI tab exist; the reads into Books and Purchases do not, and the product
does not pretend otherwise — there is no screen showing a fabricated
"actual" figure. Implementing it means calling those products, not copying
their tables into this one.

## Calendar

Contracts owns its deadlines and remains the source of truth for them. Pushing a
milestone or a renewal date to Calendar is presentation, and Calendar being
unavailable must never affect whether a reminder fires. Architected, not
implemented.

## Signature providers

`app/Signature/` is a provider abstraction, not an implementation of signing.
`ManualProvider` ships as the default and is honest about what it is: it records
who must sign and tracks status, and execution is recorded by uploading the
signed copy. Indian eSign, DocuSign and Adobe Sign plug in behind the same
interface. See [`IMPLEMENTATION_STATUS.md`](./IMPLEMENTATION_STATUS.md).

## Failure policy

| Integration | Unavailable means |
|---|---|
| Portal | 401 — deny, never assume |
| Manage | 403 on company context — deny |
| Contacts | Empty search, free-text fallback, reported |
| Drive | 503 on upload unless the local fallback is enabled |
| Console | AI reported unavailable; nothing else affected |

The distinction is deliberate. Authentication and authorisation fail **closed**.
Everything else degrades and says so.
