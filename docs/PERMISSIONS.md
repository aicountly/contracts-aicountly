# Permissions

Contracts owns its own RBAC. Nothing in the ecosystem offers a per-product role
grant: the portal answers "is this session live", Manage answers "may this user
act for this company", and neither knows what a contract approver is.

Roles are therefore company-scoped rows in `contract_user_roles`, resolved by
`RoleService` and expanded to permission slugs by `Support\Permissions`.

**Every slug is checked server-side.** Hiding a button is a courtesy to the
user; it is never the control. The SPA calls `useSession().can(PERMISSION.X)` so
it does not offer an action that will 403, and the endpoint checks the same slug
before doing anything.

## Getting a role

Three ways, in this order:

1. **An explicit grant** in `contract_user_roles` — the normal path, managed in
   Settings → Roles.
2. **Company ownership in Manage** — the bootstrap path. Without it, the first
   person to open Contracts for a new company would have no way to grant
   themselves anything, and the product would be unusable until someone edited
   the database by hand. A company owner is treated as `contract_admin`.
3. **The company's `default_role`** — anyone else. It ships as `read_only`.
   Defaulting to something permissive would mean every employee could approve
   contracts on day one.

A user may hold several roles; their permissions are the union.

## Permission slugs

### Reading
| Slug | Grants |
|---|---|
| `contract.view` | See contracts they own, created, or are an approver on |
| `contract.view_all` | See every contract in the company |
| `contract.commercials.view` | See values, recurring amounts, commercial summary |
| `contract.risk.view` | See risk findings and scores |
| `contract.audit.view` | See the audit trail |
| `contract.document.download` | Download a document or open a preview URL |

### Writing
| Slug | Grants |
|---|---|
| `contract.create` | Create a contract |
| `contract.edit` | Edit metadata |
| `contract.commercials.edit` | Edit values and commercial terms |
| `contract.document.upload` | Upload a document or a new version |
| `contract.delete` | Delete a **draft** (never an executed contract) |
| `contract.archive` | Archive and restore |
| `contract.terminate` | Record a termination |

### Lifecycle
| Slug | Grants |
|---|---|
| `request.create` | Raise a contract request |
| `request.review` | Decide on a request |
| `approval.act` | Act on an approval step assigned to them |
| `signature.act` | Send for signature, record execution |
| `obligation.manage` | Create, edit and complete obligations and milestones |
| `renewal.manage` | Record a renewal decision |
| `amendment.manage` | Create and apply amendments |

### Configuration
| Slug | Grants |
|---|---|
| `clause.manage` | Clause library and categories |
| `template.manage` | Template library |
| `playbook.manage` | Playbook rules |
| `workflow.manage` | Approval workflows |
| `settings.manage` | Everything under Settings, including role grants |

### Cross-cutting
| Slug | Grants |
|---|---|
| `ai.use` | Run extraction, summaries and Ask Contract |
| `export` | CSV export |
| `report.view` | Reports |

## Built-in roles

| Role | For | Notable |
|---|---|---|
| `contract_admin` | The person who runs Contracts for the company | Everything, including `settings.manage` |
| `legal` | Drafting, review, approval | Owns clauses, templates and the playbook; can terminate |
| `finance` | Commercial oversight | The only non-admin role with `commercials.edit` |
| `procurement` | Vendor-side contracts | Create, edit, obligations, renewals |
| `sales` | Customer-side contracts | Create, edit, renewals |
| `contract_owner` | Owns specific contracts end to end | No `view_all` — sees their own |
| `reviewer` | Comments without editing | `view_all` + risk, no write |
| `approver` | Acts on approval steps | `view_all` + `approval.act` |
| `signatory` | Executes contracts | `signature.act`, minimal read |
| `auditor` | Read-only oversight | `view_all` + `audit.view` + `export`, no write at all |
| `read_only` | Everyone else | `contract.view` + `report.view` |

### Why `settings.manage` is admin-only

The settings screen configures approval routing and risk rules. Granting it
widely would let a user route their own contract around the approver who is
supposed to see it — which is not a permissions bug so much as a control
failure, and it would not show up in any log as unusual.

## Where the checks are

**Endpoint level.** Every action names its permission before doing anything:

```php
public function store(): void
{
    $ctx = $this->requirePermission(Permissions::CONTRACT_CREATE);
    $this->respond(fn () => $this->service()->create($ctx, $this->body()), 201);
}
```

A new endpoint that forgets fails review rather than shipping open.

**Row level.** `contract.view_all` decides whether the visibility predicate is
applied. It is built once and used by both `find()` and `search()`, so a direct
read cannot become more permissive than the list.

**Field level.** Two cases:

- Commercial values are **stripped from the response** for a user without
  `contract.commercials.view`, and an edit by a user without
  `contract.commercials.edit` silently keeps the existing values rather than
  failing the whole save.
- Status transitions have their own gates on top of `contract.edit`: moving to
  `terminated` needs `contract.terminate`, and to `archived` needs
  `contract.archive`. Ending an agreement should not be reachable by changing a
  dropdown.

## Testing

`tests/CrossTenantIsolationTest.php` covers the row-level rule directly: a user
with only `contract.view` cannot see a colleague's contract in the list **or**
by id, and can see their own.
