# Approval engine

No approval chain is hard-coded. A workflow's conditions decide whether it
applies to a contract; its steps decide who acts and in what order. That is the
difference between a product a company can adopt and one it has to reorganise
around.

## Matching

`approval_workflows` are ordered by `priority`, lowest first, and the **first
matching workflow wins**. Without a deterministic order two overlapping
workflows would route the same contract differently on different days.

Conditions are stored as JSONB — a list of `{field, operator, value}` — because
the set of comparable attributes grows with the product. Both halves are
constrained:

**Fields:** `contract_type_id`, `department_id`, `total_value`, `currency`,
`risk_level`, `ai_risk_score`, `auto_renewal`, `governing_law`,
`notice_period_days`, `duration_months`, `has_non_standard_clauses`,
`has_data_processing`.

**Operators:** `eq`, `ne`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `is_true`,
`is_false`.

`match_mode` is `all` or `any`.

An unknown field or operator is treated as **unmatched** — never thrown, and
never evaluated as input. `WorkflowMatcher::matches()` is pure (no database, no
clock), which is what lets the test cover every operator without fixtures.

```json
{
  "match_mode": "all",
  "conditions": [
    {"field": "total_value", "operator": "gt", "value": 1000000},
    {"field": "risk_level", "operator": "in", "value": ["high", "critical"]}
  ]
}
```

## Steps

| Column | Meaning |
|---|---|
| `step_no` | order; steps sharing a number run together |
| `execution` | `sequential` waits for the previous step; `parallel` runs alongside its peers |
| `approver_type` | `user`, `role`, `department_head`, `contract_owner`, `manager` |
| `approver_value` | the uuid or role slug, for the first two |
| `min_approvals` | for a parallel step, how many of the assignees must approve |
| `escalation_days` | when an unanswered step escalates |
| `escalate_to_uuid` | to whom |

`resolveApprovers()` turns a step into actual people at submission time — a
`role` step expands through `RoleService::usersWithRole()`.

## The snapshot

`contract_approval_instances.steps_snapshot` freezes the step definitions when
the approval is submitted.

This matters more than it looks. A workflow edited mid-approval must not change
who was supposed to approve a contract already in the queue — otherwise editing
routing retroactively rewrites an approval that is half done, and the audit
trail no longer describes what actually happened.

## Assignments

`contract_approval_assignments` is created when a step **opens**, one row per
person who has to act. That is what makes "my pending approvals" a single
indexed read rather than a workflow replay for every contract in the company.

```
pending → approved | rejected | sent_back | reassigned | skipped
```

## Acting

`POST /approvals/{id}/act` with `approve`, `reject`, `send_back`,
`request_changes`, `comment` or `reassign`.

- Only an assignee of the **current** step may act, or a `contract_admin`.
- A parallel step advances once `min_approvals` is reached; the remaining
  assignments are marked `skipped`, not left pending forever.
- **Approval** on the final step sets the contract's `approval_status` to
  `approved` and its status to `approved`.
- **Rejection** returns the contract to `draft` with `approval_status` rejected.
  It does not delete the instance — a rejected approval is part of the record.
- **Reassignment** requires a target; without one the step would silently drop
  out of everyone's queue.

Every action writes a `contract_approval_actions` row. That table is
append-only in practice and is the answer to "who approved this, and when".

## Escalation

The nightly sweep (`database/cron.php approvals`):

1. `escalateOverdue()` stamps `escalated_at` on assignments past `due_at` and,
   where the step names an `escalate_to_uuid`, creates an assignment for that
   person.
2. The **assignee is reminded too**, daily. Escalating without reminding is how
   someone finds out their manager was told first.

Both are idempotent — `escalated_at` is set once, and the reminder's dedupe key
includes the date.

A contract stuck waiting on one person is the most common way an approval
workflow fails in practice, and it fails *silently*: the requester assumes it is
progressing.

## What is approved

`subject_type` covers `contract`, `request`, `amendment`, `termination` and
`renewal`, so the same engine routes a termination for approval as routes the
original contract. Only the conditions differ.

## Configuration

Settings → Approval workflows, behind `workflow.manage`. That permission is
deliberately separate from `approval.act`: editing routing is how someone would
arrange for their own contract to skip the approver who is supposed to see it,
and that would not look unusual in any log.

## Testing

`tests/ApprovalServiceTest.php` (69 assertions) covers workflow matching
including a non-matching condition and an unknown operator, sequential advance,
parallel `min_approvals`, rejection returning the contract to draft, a
non-assignee being refused, and escalation idempotency.
