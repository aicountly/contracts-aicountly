# API reference

Base: `https://contracts.aicountly.com/api` — same origin as the SPA, which is
what keeps the session bootstrap free of CORS entirely.

Every request carries `Authorization: Bearer <ses_key>` and, except where noted,
the company context as headers:

```
Authorization: Bearer <ses_key>
X-AIC-CMP-ID: 318
X-AIC-FY-ID:  7
X-AIC-BO-ID:  12
```

The context may also arrive as query parameters or in the JSON body — different
callers use different ones: the SPA's fetch wrapper sends headers, a link opened
in a new tab carries query parameters, and a server-to-server caller puts them in
the body.

The route map is `server-php/app/Config/Routes.php`; it is the authority if this
document and the code ever disagree.

## Envelope

Success `{ "success": true, "data": <payload>, "errors": [] }`
Failure `{ "success": false, "message": "...", "error": "CODE", "data": null, "errors": {…} }`

Statuses: 400 bad request · 401 unauthenticated · 403 forbidden ·
404 not found (also used for another tenant's row) · 409 business-rule refusal ·
422 validation (`errors` is field → message) · 429 rate limited ·
503 dependency unavailable.

Paged payloads: `{ items, total, page, per_page, total_pages }`.

Every endpoint names a permission before it does anything — see
[`PERMISSIONS.md`](./PERMISSIONS.md). A 403 means the caller's Contracts role
does not allow the action; a 404 means the record does not exist **or belongs to
another company**, deliberately indistinguishable.

## Endpoints

### Session and context
```
GET  /health                                   (no company context)
GET  /me                                       -> {uuid, roles[], permissions[], counts{approvals,obligations,renewals,review_queue,notifications}, ai{configured,provider,model}, integrations{}}
GET  /manage/companies                         (no company context) -> CompanySummary[]
GET  /manage/company?cmp_id=                   (no company context) -> {company, branches[], financial_years[]}
```

### Dashboard
```
GET  /dashboard/kpis?<filters>                 -> KPI object
GET  /dashboard/charts?<filters>               -> {by_status,by_type,by_department,value_by_category,expiry_timeline,renewal_pipeline,risk_distribution,obligations_timeline,customer_vs_vendor,monthly_executed}
GET  /dashboard/my-actions                     -> {approvals[],obligations[],renewals[],ai_reviews[]}
GET  /dashboard/activity?limit=                -> activity[]
```

### Contracts
```
GET    /contracts?<filters,page,per_page,sort,dir>   -> paged ContractListItem
POST   /contracts                                    -> Contract
GET    /contracts/{id}                               -> Contract (+ counts per tab)
PUT    /contracts/{id}                               -> Contract
DELETE /contracts/{id}                               -> {deleted:true}     (drafts only)
POST   /contracts/{id}/status      {status,note}     -> Contract
POST   /contracts/{id}/archive     {archived:bool}   -> Contract
POST   /contracts/{id}/favourite   {favourite:bool}  -> {favourite:bool}
GET    /contracts/{id}/activity?page=                -> paged activity
GET    /contracts/{id}/audit?page=                   -> paged audit
GET    /contracts/export?<filters>                   -> text/csv
```
Repository filters: `q, status[], contract_type_id, department_id, owner_uuid,
counterparty, risk_level, currency, auto_renewal, approval_status,
signing_status, effective_from, effective_to, expiry_from, expiry_to, value_min,
value_max, tag_id, favourites_only, expiring_within_days, obligation_status,
archived=(no|only|all)`.
Sort keys: `updated_at, created_at, title, contract_number, counterparty,
effective_date, expiry_date, total_value, risk, status`.

### Parties
```
GET    /contracts/{id}/parties            -> party[]
POST   /contracts/{id}/parties            -> party
PUT    /parties/{partyId}                 -> party
DELETE /parties/{partyId}                 -> {deleted:true}
POST   /parties/{partyId}/snapshot        -> snapshot
GET    /parties/{partyId}/snapshots       -> snapshot[]
GET    /counterparties/search?q=&limit=   -> contact[] (proxied to Contacts)
```

### Documents and versions
```
GET    /contracts/{id}/documents                    -> document[] (each with versions[])
POST   /uploads/sessions      {contract_id|request_id, filename, content_type, size_bytes, doc_kind, version_status, document_id?}
                                                    -> {session_id, upload_url, method, headers, expires_at, storage_provider}
POST   /uploads/sessions/{id}/complete              -> {status}
POST   /uploads/sessions/{id}/finalize {notes,version_status} -> {document, version}
POST   /uploads/sessions/{id}/abort                 -> {status}
POST   /uploads/direct        (multipart, local-storage fallback only)
GET    /versions/{versionId}/url?inline=1           -> {url, expires_at}
POST   /versions/{versionId}/executed                -> {version}
GET    /versions/{versionId}/text                    -> {text, pages, scanned}
POST   /documents/link        {contract_id, drive_document_id, title, doc_kind}
GET    /contracts/{id}/compare?base=&target=         -> {segments[],stats,classified[],ai_explanation}
```

### Requests
```
GET    /requests?<status,requester,page>   -> paged
POST   /requests                           -> request
GET    /requests/{id}                      -> request (+activity)
PUT    /requests/{id}                      -> request
POST   /requests/{id}/submit               -> request
POST   /requests/{id}/decision {decision, notes}  decision: approve|reject|more_info
POST   /requests/{id}/convert  {contract_type_id,title}   -> {contract}
```

### Approvals
```
GET  /approvals/queue?page=                          -> paged assignments
GET  /approvals/instances?subject_type=&subject_id=  -> instance[]
POST /approvals/submit {subject_type,subject_id}     -> instance
POST /approvals/{instanceId}/act {action,comment,reassign_to} -> instance
POST /approvals/{instanceId}/cancel {reason}         -> instance
GET  /approval-workflows                             -> workflow[]
POST /approval-workflows                             -> workflow
PUT  /approval-workflows/{id}                        -> workflow
DELETE /approval-workflows/{id}                      -> {deleted:true}
```

### Obligations and milestones
```
GET    /obligations?<filters,page>              -> paged occurrences (with obligation + contract)
GET    /contracts/{id}/obligations              -> obligation[] (with next occurrence)
POST   /contracts/{id}/obligations              -> obligation
PUT    /obligations/{id}                        -> obligation
DELETE /obligations/{id}                        -> {deleted:true}
POST   /obligations/{id}/generate               -> {generated:int}
POST   /occurrences/{id}/complete {note,amount,evidence_document_id} -> occurrence
POST   /occurrences/{id}/status  {status,note}  -> occurrence
GET    /contracts/{id}/milestones               -> milestone[]
POST   /contracts/{id}/milestones               -> milestone
PUT    /milestones/{id}                         -> milestone
DELETE /milestones/{id}                         -> {deleted:true}
POST   /milestones/{id}/complete                -> milestone
```

### Commercials
```
GET  /contracts/{id}/commercials   -> {terms, payment_schedules[]}
PUT  /contracts/{id}/commercials   -> {terms}
POST /contracts/{id}/payment-schedules       -> schedule
PUT  /payment-schedules/{id}                 -> schedule
DELETE /payment-schedules/{id}               -> {deleted:true}
```

### Renewals, amendments, terminations
```
GET  /renewals?bucket=&status=&page=       -> paged
GET  /contracts/{id}/renewals              -> renewal[]
POST /contracts/{id}/renewals/ensure       -> renewal
POST /renewals/{id}/decision {decision,notes,renewal_term_months,proposed_expiry} -> renewal
POST /renewals/{id}/recommend              -> renewal    (AI recommendation)

GET    /amendments?<status,page>           -> paged
GET    /contracts/{id}/amendments          -> amendment[]
POST   /contracts/{id}/amendments          -> amendment
PUT    /amendments/{id}                    -> amendment
DELETE /amendments/{id}                    -> {deleted:true}
POST   /amendments/{id}/apply              -> {amendment, contract}
GET    /contracts/{id}/effective-position  -> {fields{}, sources{}}

GET  /contracts/{id}/terminations          -> termination[]
POST /contracts/{id}/terminations          -> termination
PUT  /terminations/{id}                    -> termination
POST /terminations/{id}/approve            -> termination
POST /terminations/{id}/notice             -> termination
POST /terminations/{id}/complete           -> {termination, contract}
```

### Risk, clauses, playbook
```
GET  /contracts/{id}/risk                  -> {assessment, findings[]}
POST /contracts/{id}/risk/assess           -> {assessment, findings[]}
POST /risk-findings/{id}/review {status,notes} -> finding
GET  /risks?<severity,category,page>       -> paged findings across the portfolio
GET  /contracts/{id}/health                -> {overall, categories{}, explanations[]}

GET    /contracts/{id}/clauses             -> clause[]
POST   /contracts/{id}/clauses             -> clause
PUT    /contract-clauses/{id}              -> clause
DELETE /contract-clauses/{id}              -> {deleted:true}
GET    /contracts/{id}/deviations          -> deviation[]
POST   /contracts/{id}/deviations/evaluate -> deviation[]
POST   /deviations/{id}/review {status,notes} -> deviation

GET    /clause-categories                  -> category[]
GET    /clauses?<q,category_id,page>       -> paged library clauses
POST   /clauses                            -> clause
PUT    /clauses/{id}                       -> clause
DELETE /clauses/{id}                       -> {deleted:true}
GET    /clauses/{id}/versions              -> version[]

GET    /playbooks                          -> playbook[]
GET    /playbooks/{id}/rules               -> rule[]
POST   /playbooks/{id}/rules               -> rule
PUT    /playbook-rules/{id}                -> rule
DELETE /playbook-rules/{id}                -> {deleted:true}
```

### Templates
```
GET    /templates?<q,status,page>          -> paged
POST   /templates                          -> template
GET    /templates/{id}                     -> template (+versions)
PUT    /templates/{id}                     -> template
DELETE /templates/{id}                     -> {deleted:true}
POST   /templates/{id}/preview {contract_id?} -> {html, missing[], used[]}
POST   /templates/{id}/create-contract     -> {contract}
GET    /template-variables                 -> variable[]
```

### AI
```
GET  /ai/status                                  -> {configured, provider, model, disclaimer}
POST /ai/contracts/{id}/extract                  -> {job}
POST /ai/contracts/{id}/summarize                -> {job|summary}
GET  /ai/contracts/{id}/summary                  -> summary
PUT  /ai/contracts/{id}/summary  {sections}      -> summary
POST /ai/contracts/{id}/ask  {question, conversation_id?} -> {answer, citations[], grounded, disclaimer, conversation_id}
GET  /ai/contracts/{id}/conversations            -> conversation[]
GET  /ai/conversations/{id}/messages             -> message[]
POST /ai/contracts/{id}/renewal-advice           -> {recommendation, reason}
GET  /ai/jobs?<status,contract_id,page>          -> paged jobs
GET  /ai/jobs/{id}                               -> job
POST /ai/jobs/{id}/retry                         -> job
GET  /ai/review-queue?<page>                     -> paged extractions needing review
POST /ai/extractions/{id}/accept {value?}        -> extraction
POST /ai/extractions/{id}/reject                 -> extraction
POST /ai/contracts/{id}/apply-verified           -> {contract, applied:int}
POST /ai/import           {files[]}              -> {jobs[]}
```

### Reports and search
```
GET /reports                                     -> definition[]
GET /reports/{key}?<filters,page>                -> {columns,rows,total,summary}
GET /reports/{key}/export?<filters>              -> text/csv
GET /search?q=&limit=                            -> {contracts[],clauses[],documents[],total}
```

### Notifications, comments, links
```
GET  /notifications?<unread_only,page>   -> paged
POST /notifications/{id}/read            -> {read:true}
POST /notifications/read-all             -> {read:int}

GET    /contracts/{id}/comments          -> comment[] (threaded)
POST   /contracts/{id}/comments          -> comment
PUT    /comments/{id}                    -> comment
DELETE /comments/{id}                    -> {deleted:true}
POST   /comments/{id}/resolve {resolved} -> comment

GET    /contracts/{id}/links             -> link[]
POST   /contracts/{id}/links             -> link
DELETE /links/{id}                       -> {deleted:true}
```

### Signatures
```
GET  /contracts/{id}/signatures                 -> request[]
POST /contracts/{id}/signatures                 -> request
POST /signatures/{id}/send                      -> request
POST /signatures/{id}/cancel                    -> request
POST /signatures/{id}/mark-signed {execution_date, signers[]} -> request
POST /webhooks/signature/{provider}             (no auth; signature-verified)
```

### Settings
```
GET  /settings                     -> {settings, numbering_preview}
PUT  /settings                     -> settings
GET  /settings/contract-types      -> type[]
POST /settings/contract-types      -> type
PUT  /contract-types/{id}          -> type
DELETE /contract-types/{id}        -> {deleted:true}
GET  /settings/departments  POST /settings/departments  PUT/DELETE /departments/{id}
GET  /settings/custom-fields POST /settings/custom-fields PUT/DELETE /custom-fields/{id}
GET  /settings/tags          POST /settings/tags          DELETE /tags/{id}
GET  /settings/roles               -> {roles[], grants[]}
POST /settings/roles/grant  {user_uuid, role_slug}
POST /settings/roles/revoke {user_uuid, role_slug}
GET  /settings/risk-rules    POST /settings/risk-rules   PUT/DELETE /risk-rules/{id}
GET  /settings/integrations        -> {drive,contacts,manage,console,signature,email}
```

## Shared shapes

```ts
ContractListItem = {
  id, uuid, contract_number, title, status, lifecycle_stage,
  counterparty_name, effective_date, expiry_date, notice_deadline,
  renewal_type, auto_renewal, currency, total_value,
  risk_level, ai_risk_score, health_score, owner_uuid,
  approval_status, signing_status, archived_at,
  contract_type_id, contract_type_name, department_id, department_name,
  is_favourite, days_to_expiry, days_to_notice, created_at, updated_at
}

Contract = ContractListItem & {
  description, commencement_date, execution_date, renewal_frequency,
  notice_period_days, governing_law, jurisdiction, recurring_value,
  payment_frequency, billing_frequency, commercial_summary, notes,
  custom_fields, verification_state, parent_contract_id, request_id,
  template_id, created_by, updated_by,
  tabs: { parties, documents, clauses, obligations, milestones,
          approvals, versions, amendments, risks, comments, links }   // counts
}
```

Disclaimer string, shown wherever AI analysis is presented:
> AI-generated contract analysis is provided for assistance and information. It
> does not constitute legal advice and should be reviewed by an authorized legal
> or professional reviewer before reliance.


## Rate limits

| Bucket | Limit |
|---|---|
| `POST /ai/*` | `AI_RATE_LIMIT_PER_HOUR` (default 120) per user per company |
| `POST /ai/contracts/{id}/ask` | additionally `AI_ASK_RATE_LIMIT_PER_HOUR` (default 60) |
| `GET /contracts/export`, `GET /reports/{key}/export` | 10 per 5 minutes |
| `POST /contracts/{id}/risk/assess` | 30 per 5 minutes |

Exceeding one returns `429` with a `Retry-After` header. The limiter is counted
in PostgreSQL rather than APCu, because PHP-FPM runs many workers and a
per-worker counter would let a caller multiply their budget by the pool size. It
**fails open** — a 429 because the database blinked is a worse outage than the
abuse it prevents.

## Pagination

`?page=` and `?per_page=`, clamped to a maximum of 100 (200 for audit). The clamp
is a denial-of-service control, not a nicety: `per_page` reaches a `LIMIT`, so an
unbounded value is a way to ask for an entire tenant in one request.

## Sorting

`?sort=` and `?dir=asc|desc`. The sort key is looked up in a fixed allow-list;
an unknown key falls back to the default rather than reaching the query.

## Errors worth knowing

| Code | Means |
|---|---|
| `UNAUTHORIZED` | No `ses_key`, or the portal rejected it |
| `MISSING_COMPANY_CONTEXT` | No `cmp_id`. Contracts are always company-scoped |
| `COMPANY_ACCESS_DENIED` | Manage refused this company for this session |
| `INVALID_FINANCIAL_YEAR` / `INVALID_BRANCH` | Not valid for this company |
| `PERMISSION_DENIED` | The Contracts role does not allow it |
| `VALIDATION_FAILED` | 422; `errors` is field → message |
| `INVALID_STATUS_TRANSITION` | The lifecycle graph refuses that move |
| `DELETE_NOT_ALLOWED` | Only an unexecuted draft can be deleted |
| `CONTRACT_CLOSED` | Ended contracts are amended, not edited |
| `AI_NOT_CONFIGURED` | No provider in Console for this deployment |
| `DRIVE_UNAVAILABLE` | Document storage is not configured |
| `RATE_LIMITED` | 429; see `Retry-After` |
| `ENVIRONMENT_MISCONFIGURED` | `APP_ENV` disagrees with the hostname |
| `DB_UNAVAILABLE` | 503; the body says what is wrong |
