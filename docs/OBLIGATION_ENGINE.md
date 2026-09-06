# Obligation engine

An executed contract creates ongoing work: monthly payments, quarterly SLA
reports, insurance renewals, annual audits, certificate submissions, minimum
purchase commitments. Tracking that is most of what a CLM product is for — the
signature is the easy part.

## Obligations and occurrences

Two tables, and the split is the whole design:

- **`contract_obligations`** is the *rule*: "submit a quarterly SLA report",
  owned by someone, recurring, with a grace period and a reminder ladder.
- **`obligation_occurrences`** is one *instance* falling due: Q1 2027, due 15
  April, completed on the 12th with the report attached.

Collapsing them into one row with a mutable due date would mean a recurring
obligation has no compliance history — you could not answer "did we submit that
report last quarter", which is exactly the question an audit asks.

## Recurrence

`one_time`, `daily`, `weekly`, `fortnightly`, `monthly`, `quarterly`,
`half_yearly`, `annual`, `custom` (with an interval in days).

Occurrences are materialised, not computed on read. A stored row can carry a
completion, evidence and a reminder state; a computed date cannot.

Generation is **bounded** — never more than 24 months ahead or 200 rows per
obligation. An evergreen contract with a daily obligation would otherwise
generate rows forever.

Generation is **idempotent**: `(obligation_id, due_date)` is unique and inserts
are `ON CONFLICT DO NOTHING`, so re-running never duplicates a due date.

### Month ends

`Dates::addMonths()` handles the case that a naive implementation gets wrong.
PHP's `+1 month` from 31 January lands on 3 March. For a monthly obligation that
is wrong twice over — it skips February entirely and then drifts.

```
31 Jan + 1 month  →  28 Feb    (29 Feb in a leap year)
31 Mar + 1 month  →  30 Apr
31 Aug + 6 months →  28 Feb
```

Pinned in `tests/SecurityTest.php`, because a due date computed wrongly is a
missed obligation, and the failure is silent.

## Status

```
upcoming → due → overdue → completed
                        ↘ waived | not_applicable | disputed
```

`ObligationService::refreshDueStatuses()` moves `upcoming → due` when the due
date arrives, and `due → overdue` once the due date **plus the grace period**
has passed. Both transitions are conditional `UPDATE`s, so the sweep is safe to
run many times a day.

The grace period is per obligation. A report due on the 15th that everyone
files on the 17th is not overdue if the contract allows five days.

## Completion and evidence

`POST /occurrences/{id}/complete` records who completed it, when, an optional
amount, a note, and — when the obligation sets `evidence_required` — a document.

Evidence is a `contract_documents` row like any other, so it goes to Drive under
the `obligation-evidence` module code and inherits the same retention and access
control as the contract itself.

## Reminders and escalation

The nightly sweep (`database/cron.php obligations`) notifies against the
company's ladder, default `14,7,1` days:

- Each ladder band notifies **once**. The dedupe key is
  `obligation_due:<occurrence>:<band>`, so a contract 8 days out reports against
  the "14" band today and "7" tomorrow — and a second cron run in between says
  nothing.
- An **overdue** obligation repeats daily, because it describes a situation that
  is actively wrong. The key includes the overdue day count, capped at 30 so it
  eventually stops shouting.
- When `escalation_days` is set and passed, the named `escalate_to_uuid` is
  notified separately. Escalation without also reminding the owner is how
  someone finds out their manager was told first.

An obligation with **no owner** still notifies — it goes to the company's
contract administrators. An unwatched obligation is the failure mode this whole
subsystem exists to prevent, so silence is never the answer.

## Milestones

Separate from recurring obligations, because they are different things. A
milestone happens once: project kickoff, phase completion, go-live, an
acceptance deadline, a renewal decision date, a payment milestone.

Milestones support dependencies (`depends_on_id`), an amount, an owner, and the
same reminder ladder. `pending → in_progress → completed`, or `missed` once the
date passes.

## AI-extracted obligations

Extraction proposes obligations from the contract text, marked
`is_ai_extracted = true` with a confidence and `verification_state =
'ai_extracted'`. They appear in the AI review queue and become live only once a
person confirms them.

An obligation nobody verified is a suggestion. Generating occurrences and firing
reminders from an unverified extraction would train people to ignore the
reminders, which costs more than the missing obligation would have.

## Queries this makes cheap

- Everything due this month, across the portfolio, by owner
- Everything overdue, with the contract and the counterparty
- One contract's compliance history for an audit
- Payment obligations by direction, for a cash forecast

The indexes are built for those shapes: `(environment, cmp_id, status,
due_date)` on occurrences, plus a partial index on the open statuses that the
nightly sweep uses.

## Testing

`tests/ObligationServiceTest.php` (98 assertions) covers monthly generation
across a year boundary and a month end, idempotent regeneration, the due and
overdue transitions including grace, completion writing evidence, and that
company 2's obligations are invisible to company 1.
