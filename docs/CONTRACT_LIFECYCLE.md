# Contract lifecycle

```
REQUEST → DRAFT/UPLOAD → AI ANALYSIS → INTERNAL REVIEW → APPROVAL → NEGOTIATION
   → VERSIONING/REDLINING → SIGNATURE → ACTIVE → OBLIGATIONS
   → COMMERCIAL TRACKING → AMENDMENTS → RENEWAL / TERMINATION → ARCHIVE
```

Two fields track where a contract is. `status` is the controlled state a person
acts on; `lifecycle_stage` is the coarser phase used for grouping and reporting,
derived from the status by `ContractService::stageForStatus()`.

## Statuses

| Status | Meaning |
|---|---|
| `draft` | Being prepared. Freely editable, deletable. |
| `under_review` | With Legal or a reviewer. |
| `awaiting_approval` | An approval instance is open. |
| `approved` | Approved, not yet executed. |
| `negotiation` | With the counterparty. |
| `awaiting_signature` | Out for signature. |
| `active` | Executed and in force. Obligations run; the renewal clock starts. |
| `renewal_review` | Active, and a renewal decision is due. |
| `expired` | Past its expiry date. |
| `terminated` | Ended early, with a termination record behind it. |
| `cancelled` | Abandoned before execution. |
| `archived` | Out of the working set. |

## Transitions

Checked against a fixed graph in `ContractService::transitions()`. A contract
that jumps from `draft` to `active` has skipped approval and signature, and no
report afterwards can tell that it did — so the jump is refused with a 409.

```
draft              → under_review, awaiting_approval, negotiation,
                     awaiting_signature, active, cancelled, archived
under_review       → draft, awaiting_approval, negotiation, approved, cancelled, archived
awaiting_approval  → approved, under_review, draft, negotiation, cancelled, archived
approved           → negotiation, awaiting_signature, active, cancelled, archived
negotiation        → under_review, awaiting_approval, approved,
                     awaiting_signature, cancelled, archived
awaiting_signature → active, negotiation, approved, cancelled, archived
active             → renewal_review, expired, terminated, archived
renewal_review     → active, expired, terminated, archived
expired            → renewal_review, active, archived
terminated         → archived
cancelled          → draft, archived
archived           → draft, active, expired, terminated
```

`draft → active` is allowed deliberately: importing a contract that was signed
years ago should not require walking it through an approval that never happened.

A terminal state cannot quietly reopen — `terminated` leads only to `archived`.

## What happens on execution

`changeStatus(..., 'active')` does three things in one transaction:

1. sets the status and stage,
2. **materialises obligation occurrences** from every active obligation, and
3. **opens renewal cycle 1** with the expiry, notice deadline and decision date.

Both happen here rather than in a nightly sweep because a contract that goes
live on Monday should show its obligations on Monday, not Tuesday.

## Editing after execution

Refused. `assertEditable()` blocks metadata edits on a contract that has ended,
and archived contracts must be restored first.

Changing the value or expiry date of a signed agreement in place would silently
rewrite what the company believes it agreed to. Those changes go through an
**amendment**, which records `{field: {from, to}}` and leaves the original
readable. `GET /contracts/{id}/effective-position` composes the base contract
with every executed amendment in effect order and reports which amendment
supplied each overridden field.

## Requests

A request is the intake queue that precedes a draft:

```
draft → submitted → under_review → approved_for_drafting → converted
                 ↘ more_info_required ↗
                 ↘ rejected
```

Converting creates the contract and sets `converted_contract_id`. The request
row survives, so the contract can always answer "who asked for this, and why".

## Renewal

A renewal **cycle** is opened when the contract goes active and again after each
renewal. `RenewalService::scanDue()` moves `not_yet_due → review_due` when the
decision date arrives or the notice deadline enters the company's alert ladder.

```
not_yet_due → review_due → under_review → renew | renegotiate | terminate
                                            ↓
                                        renewed → (next cycle)
```

The **notice deadline** is the field this product exists for. A contract that
auto-renews with 90 days notice has to be decided 90 days before it ends, and
nobody remembers that unaided. It is stored on the contract row rather than
computed on read, because the nightly sweep filters on it across every tenant
and an index on a stored column is the difference between a range seek and a
full scan.

## Termination

Never a status change alone. A termination record carries the type, the reason,
the initiating party, the applicable clause, notice dates, settlement,
outstanding obligations, asset return, confidentiality survival, data deletion
and a closure checklist.

```
draft → pending_approval → approved → notice_issued → completed
```

`complete()` sets the contract to `terminated` through the same
`changeStatus()` path as everything else, so the audit trail and activity
timeline read the same as any other transition.

## Archive

Archiving takes a contract out of the working set without destroying it.
Deletion is only ever possible for a **draft that was never executed** — an
executed contract is business history, and the archive is how it leaves the
list.
