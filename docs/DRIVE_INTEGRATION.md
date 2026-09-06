# Drive integration

Contracts owns contract metadata, versions and lineage. **AICOUNTLY Drive owns
the bytes.** Contracts stores `drive_document_id` as the handle and nothing else
about the object — it never reads Drive's tables and never talks to S3.

## Registered as a Drive product

Contracts was onboarded through Drive's own documented pattern
(`AICOUNTLY_DRIVE_STORAGE_ARCHITECTURE.md` §29) rather than by inventing a path
for it. The cross-repo change is committed in `drive-react-app` and is listed in
[`IMPLEMENTATION_STATUS.md`](./IMPLEMENTATION_STATUS.md).

```
product_code   contracts
scope          company only
entity         entity_type = contract | contract-request | amendment
               entity_id   = the Contracts record id
modules        contract-document, executed-copy, annexure, schedule, amendment,
               obligation-evidence, request-attachment, correspondence, template
```

**Company scope only.** A contract is always an agreement a company is party to,
so there is no personal form and Contracts refuses Drive's
`cmp_id = fy_id = bo_id = 0` sentinel outright.

### Key shape

The generic company builder, unchanged — **no `ObjectKeyBuilder` branch was
added to Drive**, because the entity model fits `entity_type` + `entity_id`.
That is the onboarding pattern working as designed.

```
tenant/company/{cmp_id}/product/contracts/branch/{bo_id}/fy/{fy_id}/
  module/contract-document/entity/contract/{contract_id}/doc/DOC04417/v/4/{filename}
```

### The one Drive code change

A `contract` retention class in `RetentionPolicyService`, default 10 years via
`RETENTION_CONTRACT_YEARS`.

Under the `standard` class an executed agreement becomes permanently deletable
as soon as the trash grace period lapses — and that document is the evidence of
what the company committed to. Disputes surface years after a contract has
expired. This is squarely the "statutory or contractual requirement" bar that
Drive's onboarding pattern reserves a retention rule for; everything else about
the integration needed no Drive change at all.

> **`contracts` and `contacts` are one letter apart.** They are different
> products with different key shapes, and a mistyped `product_code` is
> unrecoverable once an object is written. Read the value twice when reviewing
> anything that touches either.

## The upload flow

Server-side proxy with a browser presigned PUT — the same shape Books and
Secretarial use, so Drive credentials never reach the browser but contract PDFs
do not transit the Contracts server either.

```
 1  POST  contracts/api/uploads/sessions
       { contract_id, filename, content_type, size_bytes, doc_kind, version_status }
    Contracts validates the MIME, extension and size, then calls Drive:

       POST drive/api/upload-sessions
         Authorization: Bearer <ses_key>
         X-AIC-CMP-ID / X-AIC-FY-ID / X-AIC-BO-ID
         { filename, content_type, size_bytes, scope: 'company',
           product_code: 'contracts', module_code, entity_type, entity_id, branch_key }
       → { session_id, upload_url, method: 'PUT', headers, expires_at }

 2  PUT   <upload_url>                       the browser sends the bytes
 3  POST  contracts/api/uploads/sessions/{id}/complete
 4  POST  contracts/api/uploads/sessions/{id}/finalize
       Creates the Drive document, then the Contracts
       contract_documents + contract_document_versions rows, then
       POST drive/api/document-links to bind them, then queues text extraction.
```

`contract_upload_sessions` records the pending upload **before** the bytes exist,
so an abandoned upload leaves a reapable row rather than an orphaned object. The
nightly cleanup expires them.

## Versions are never overwritten

Every upload to an existing document is a **new version**. `version_no` is
allocated per document and never reused.

```
v1  Internal draft
v2  Legal review
v3  Sent to counterparty
v4  Counterparty redline
v5  Final draft
v6  Executed
```

Each version carries its own filename, content type, size, SHA-256, uploader,
timestamp, note and status. An executed version cannot be deleted — it is
evidence, not a working file.

The checksum earns its place beyond integrity: two versions with the same hash
are the same file, which is how "the counterparty returned it unchanged" is
detected without diffing megabytes of PDF.

## Reading a document

```
GET /api/versions/{id}/url?inline=1
→ { url, expires_at }
```

Contracts confirms the version belongs to the caller's tenant, checks
`contract.document.download`, then asks Drive for a signed URL. The id is
caller-supplied and is the obvious IDOR target here, so the tenant check comes
first and a foreign id is a 404.

## Text extraction

Deterministic first, and honest when it fails:

- `.txt` — read directly
- `.docx` — `word/document.xml` out of the zip via `ZipArchive`
- `.pdf` — embedded text, handling the common uncompressed and FlateDecode cases
- **a scanned PDF** yields little or nothing, so the version is marked
  `is_scanned` and OCR is recorded as required

The product **never stores text it did not actually extract**. A scanned contract
with fabricated text would produce confident, wrong AI answers about clauses that
were never read.

Extracted text is kept for search, deterministic diffing and AI grounding.

## The local fallback

`LocalStorageAdapter` exists so a deployment is usable before Drive is
provisioned. It is:

- refused entirely unless `CONTRACTS_ALLOW_LOCAL_STORAGE=true` **and**
  `DRIVE_API_BASE` is unset,
- written outside the document root, under `CONTRACTS_LOCAL_STORAGE_PATH`,
- given generated opaque filenames — never the user's,
- subject to the same size cap and MIME allow-list,
- **reported** by `/api/health` and Settings → Integrations, which name the
  active adapter.

It is not an alternative home for contract documents. A production deployment
should report `provider: drive`, and the documentation says so in every place
the fallback is mentioned.

## Adapter interface

```php
interface StorageAdapter
{
    public function name(): string;
    public function isConfigured(): bool;
    public function createUpload(array $ctx, array $params): array;
    public function completeUpload(array $ctx, array $session): array;
    public function finalizeUpload(array $ctx, array $session, array $params): array;
    public function signedUrl(array $ctx, array $version, bool $inline): array;
    public function delete(array $ctx, array $version): bool;
}
```

`DocumentService` speaks only to this interface. Swapping the storage backend is
a factory change, not a rewrite — which is also what keeps the local fallback
from leaking its assumptions into the rest of the product.

## File safety

Enforced server-side regardless of what the browser claims:
extension and MIME allow-lists (`pdf, doc, docx, txt, rtf, png, jpg, jpeg,
tiff`), a size cap, a generated storage name, and a SHA-256 per version. The
filename is never trusted — it is sanitised for display only.

## Configuration

```env
DRIVE_API_BASE=https://drive.aicountly.com
CONTRACTS_MAX_UPLOAD_MB=25

# Temporary, for a deployment without Drive
CONTRACTS_ALLOW_LOCAL_STORAGE=false
# CONTRACTS_LOCAL_STORAGE_PATH=/home/<user>/contracts-storage
```
