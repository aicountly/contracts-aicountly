# Risk engine

Rules first, AI second. That order is the design, not an implementation detail.

A rule fires on a fact about the structured record — no liability cap recorded,
auto-renewal with 90 days notice, governing law outside the approved list. It
produces the same finding for the same input, every time, with no model call and
no cost. A reviewer can check *why* it fired.

AI adds interpretation on top and is labelled where it does, so
"unlimited liability" (a rule matched a clause) can be weighed differently from
"this indemnity looks broad" (a model's reading). `detected_by` is `rules`, `ai`
or `manual` on every finding, and it is never guessed.

## Rules

`contract_risk_rules`, seeded per company by `CompanyBootstrapService` and
editable in Settings → Risk rules.

A rule is three fields plus a comparison:

| Field | Meaning |
|---|---|
| `subject` | what to look at — one of a fixed enum |
| `operator` | how to compare — one of a fixed enum |
| `value_text` / `value_numeric` / `value_list` | what to compare against |

**Subjects:** `clause_text`, `clause_missing`, `liability_cap`, `auto_renewal`,
`notice_period`, `governing_law`, `jurisdiction`, `payment_terms`,
`contract_value`, `duration_months`, `termination_right`, `indemnity`,
`data_protection`, `insurance`, `sla_defined`, `expiry_date`,
`counterparty_missing`, `signature_missing`, `document_missing`.

**Operators:** `contains`, `not_contains`, `equals`, `not_equals`,
`greater_than`, `less_than`, `in_list`, `not_in_list`, `is_true`, `is_false`,
`is_null`, `is_not_null`, `regex`.

Both are `CHECK` constraints in the schema. A rule can never become arbitrary
code — the subject names a branch in the evaluator, and the operator names a
comparison. There is no expression to evaluate.

`regex` is guarded: the pattern is length-capped, the subject is length-capped,
and the pattern is compiled defensively. A company admin writing a rule is not an
attacker, but a catastrophically backtracking pattern still takes the site down.

## Evaluation

`RiskEngine::evaluateRule(array $rule, array $subject): ?array` is **pure** — no
database, no clock, no network. That is what makes the 152-assertion
`RiskEngineTest` possible without fixtures for every case.

`buildSubject()` assembles the bag once per assessment from the contract, its
clauses, commercial terms, parties and documents, so N rules cost one round of
reads rather than N.

## Scoring

Each fired rule contributes `score_weight` scaled by severity. The total is
clamped to 0–100 and mapped:

| Score | Level |
|---|---|
| 0–39 | `low` |
| 40–59 | `medium` |
| 60–79 | `high` |
| 80–100 | `critical` |

Per-category scores are kept alongside the overall, so "we are fine legally but
exposed commercially" is visible rather than averaged away.

## Assessments are versioned

`assess()` writes a new `contract_risk_assessments` row and demotes the previous
`is_current`. A partial unique index enforces one current assessment per
contract — a stale "current" alongside a fresh one would make the dashboard's
risk counts depend on row order.

The history stays. "This contract was high risk before the amendment" is a
question the register can answer.

## Findings

One row per fired rule, carrying severity, the source clause and excerpt, the
recommendation, and a review status: `open`, `accepted`, `mitigated`,
`false_positive`, `resolved`.

Marking a finding `false_positive` is how a company teaches the register about
its own context without editing the rule for everyone — and the rule stays
visible, so the next contract is still checked.

## The playbook

Where the risk engine asks "is this dangerous", the playbook asks "is this
*ours*". `playbook_rules` state the company's own positions:

| Rule type | Example |
|---|---|
| `mandatory_clause` | A limitation of liability is required |
| `prohibited_clause` | Unlimited liability is prohibited |
| `preferred_wording` | Use the standard confidentiality text |
| `max_numeric` | Payment terms must not exceed 45 days |
| `min_numeric` | Notice period at least 30 days |
| `allowed_list` | Governing law must be India |
| `prohibited_list` | These jurisdictions are refused |
| `boolean_flag` | Automatic renewal is discouraged |

`PlaybookService::evaluate()` writes `clause_deviations`, each carrying the
contract's wording, the preferred wording, the deviation, a severity and a
recommendation:

```
Limitation of Liability

Playbook:  Maximum liability = fees paid in the preceding 12 months.
Contract:  Liability is unlimited.
Severity:  HIGH
Action:    Negotiate a liability cap.
```

A deviation is reviewed, not just reported: `open`, `accepted`, `rejected`,
`negotiating`, `resolved`. That review status is what makes the list shrink as
negotiation proceeds instead of showing the same twelve items forever.

## Contract health

`HealthScoreService` turns findings and completeness into a 0–100 score with a
per-category breakdown:

```
Contract Health

Overall            78/100
Legal              72
Commercial         85
Compliance         64
Operational        91
Financial          76
```

It is **derived, not decorative**. The inputs are open findings weighted by
severity, playbook deviations, and factual completeness — is there a document, a
counterparty, an expiry date, an executed copy, a recorded approval. Every score
comes with the explanations that produced it, so a low number can be acted on
rather than argued with.

## When it runs

- On demand — `POST /contracts/{id}/risk/assess`, rate-limited because it writes.
- After AI extraction completes, so a newly uploaded contract arrives assessed.
- In bulk via `recalculateAll()`, after a rule or playbook change.

It is deliberately **not** run on every page view: an assessment is a write, and
rewriting the history on each read would destroy the thing that makes it useful.

## Configuration

Settings → Risk rules and Settings → Playbook. Both are per company, both seeded
from `Support\SeedCatalog`, and both are editable without touching code — a
company that never signs outside India can add its jurisdictions to the allowed
list rather than living with a finding it will always dismiss.

## Testing

`tests/RiskEngineTest.php` (152 assertions) covers every operator, the regex
guard, the scoring boundaries, and the demotion of the previous current
assessment. `tests/PlaybookServiceTest.php` (89 assertions) covers a mandatory
clause deviation being raised, reviewed and resolved.
