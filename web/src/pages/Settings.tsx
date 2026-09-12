import { useCallback, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useParams } from 'react-router-dom'
import {
  ArrowUpRight,
  Check,
  Lock,
  Minus,
  Pencil,
  Plus,
  Settings as SettingsIcon,
  Trash2,
} from 'lucide-react'

import {
  Button,
  Card,
  Checkbox,
  Chip,
  ConfirmDialog,
  DataTable,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  PageHeader,
  Select,
  Skeleton,
  StatusChip,
  Textarea,
} from '../components/ui'
import type { Column } from '../components/ui'
import {
  DEFAULT_SETTINGS_SECTION,
  SettingsNav,
  findSettingsSection,
  sectionIsOpen,
} from '../components/settings/SettingsNav'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import type { Resource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import type { FieldErrors } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import {
  APPROVAL_WORKFLOW_SUBJECTS,
  APPROVER_TYPES,
  COUNTERPARTY_SIDES,
  CURRENCIES,
  CUSTOM_FIELD_TYPES,
  PLAYBOOK_RULE_TYPES,
  RENEWAL_TYPES,
  RISK_CATEGORIES,
  RISK_RULE_OPERATORS,
  RISK_RULE_SUBJECTS,
  RISK_SEVERITIES,
  TAG_COLOURS,
  UNARY_RISK_OPERATORS,
  type AiStatus,
  type ApprovalWorkflow,
  type ApprovalWorkflowStep,
  type ContractSettings,
  type ContractTypeRow,
  type CustomFieldRow,
  type DepartmentRow,
  type IntegrationStatus,
  type IntegrationsPayload,
  type PlaybookRule,
  type PlaybookSummary,
  type RiskRuleRow,
  type RolesPayload,
  type SettingsPayload,
  type TagRow,
} from '../types/contracts'
import { formatDate, humanise } from '../utils/format'

/**
 * Everything a company configures about Contracts.
 *
 * One screen with a section rail rather than sixteen routes: the sections are
 * short, they refer to each other constantly (a contract type points at a
 * workflow, a workflow at a department), and someone setting the product up
 * moves between them repeatedly. Only the open section fetches, so the rail
 * costs nothing.
 *
 * Two rules hold throughout. A PUT here replaces the resource, so any field the
 * form does not show is read from the loaded row and sent back unchanged — a
 * screen that silently cleared a contract type's mandatory clauses because it
 * had no control for them would be worse than one that refused to save. And a
 * 422 lands on the field it names, never in a toast: the whole point of the
 * envelope carrying field messages is that the form can say which box is wrong.
 */

/* --- Shared plumbing ------------------------------------------------------ */

interface Mutation {
  busy: boolean
  errors: FieldErrors
  clearErrors: () => void
  run: (action: () => Promise<void>, successTitle?: string, successDetail?: string) => Promise<boolean>
}

function useMutation(): Mutation {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const run = useCallback(
    async (action: () => Promise<void>, successTitle?: string, successDetail?: string) => {
      setBusy(true)
      setErrors({})
      try {
        await action()
        if (successTitle) toast.success(successTitle, successDetail)
        return true
      } catch (err) {
        if (err instanceof ApiError && err.isValidation) {
          setErrors(err.fieldErrors)
        } else {
          toast.error(
            'That could not be saved',
            err instanceof Error ? err.message : undefined,
          )
        }
        return false
      } finally {
        setBusy(false)
      }
    },
    [toast],
  )

  return { busy, errors, clearErrors: () => setErrors({}), run }
}

function Panel({
  title,
  description,
  action,
  padded = true,
  children,
}: {
  title: string
  description?: string
  action?: ReactNode
  padded?: boolean
  children: ReactNode
}) {
  return (
    <section className="ct-card" style={{ padding: 0 }}>
      <header
        style={{
          display: 'flex',
          alignItems: 'flex-start',
          justifyContent: 'space-between',
          gap: 12,
          flexWrap: 'wrap',
          padding: '14px 16px',
          borderBottom: '1px solid rgb(var(--color-border))',
        }}
      >
        <div style={{ minWidth: 0 }}>
          <h2 style={{ fontSize: 14.5, fontWeight: 700, color: 'var(--color-text)' }}>{title}</h2>
          {description ? (
            <p
              style={{
                fontSize: 12.5,
                color: 'var(--color-text-secondary)',
                marginTop: 3,
                maxWidth: 620,
                lineHeight: 1.55,
              }}
            >
              {description}
            </p>
          ) : null}
        </div>
        {action ? <div className="ct-no-print">{action}</div> : null}
      </header>
      <div style={{ padding: padded ? 16 : 0 }}>{children}</div>
    </section>
  )
}

/** Loading, error and loaded for one section's resource. */
function Loaded<T>({
  resource,
  children,
  skeletonRows = 4,
}: {
  resource: Resource<T>
  children: (data: T) => ReactNode
  skeletonRows?: number
}) {
  if (resource.error) {
    return (
      <ErrorState
        title="That did not load"
        detail={resource.error.message}
        onRetry={resource.reload}
        compact
      />
    )
  }

  if (resource.loading || resource.data === null) {
    return (
      <div role="status" aria-label="Loading" style={{ display: 'grid', gap: 10, padding: 4 }}>
        <span className="ct-sr-only">Loading…</span>
        {Array.from({ length: skeletonRows }).map((_, index) => (
          <Skeleton key={index} height={34} />
        ))}
      </div>
    )
  }

  return <>{children(resource.data)}</>
}

const FORM_GRID: React.CSSProperties = {
  display: 'grid',
  gap: 14,
  gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
}

function FullWidth({ children }: { children: ReactNode }) {
  return <div style={{ gridColumn: '1 / -1' }}>{children}</div>
}

function RowActions({
  onEdit,
  onDelete,
  editLabel,
  deleteLabel,
  canWrite,
}: {
  onEdit: () => void
  onDelete: () => void
  editLabel: string
  deleteLabel: string
  canWrite: boolean
}) {
  if (!canWrite) return null

  return (
    <div style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
      <Button size="sm" variant="ghost" onClick={onEdit} aria-label={editLabel}>
        <Pencil size={13} aria-hidden />
      </Button>
      <Button size="sm" variant="ghost" onClick={onDelete} aria-label={deleteLabel}>
        <Trash2 size={13} aria-hidden style={{ color: 'var(--color-danger)' }} />
      </Button>
    </div>
  )
}

function ActiveChip({ active }: { active: boolean }) {
  return (
    <Chip size="sm" tone={active ? 'success' : 'neutral'}>
      {active ? 'Active' : 'Inactive'}
    </Chip>
  )
}

function numberOrNull(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? parsed : null
}

/* --- The screen ----------------------------------------------------------- */

export default function Settings() {
  const { section } = useParams<{ section: string }>()
  const { canAny } = useSession()

  const activeId = section ?? DEFAULT_SETTINGS_SECTION
  const definition = findSettingsSection(activeId)
  const allowed = definition ? sectionIsOpen(definition, canAny) : false

  return (
    <>
      <PageHeader
        title="Settings"
        description="How Contracts behaves for this company. Changes here apply to everyone working in it."
      />

      <div
        style={{
          display: 'grid',
          gap: 20,
          gridTemplateColumns: 'minmax(0, 1fr)',
          alignItems: 'start',
        }}
      >
        <style>{`
          @media (min-width: 901px) {
            .ct-settings-layout { grid-template-columns: 236px minmax(0, 1fr); }
          }
        `}</style>

        <div
          className="ct-settings-layout"
          style={{ display: 'grid', gap: 20, alignItems: 'start' }}
        >
          <SettingsNav active={activeId} />

          <div style={{ minWidth: 0 }}>
            {!definition ? (
              <Card>
                <EmptyState
                  icon={<SettingsIcon size={22} />}
                  title="No such settings section"
                  description={`“${activeId}” is not a section of Settings. It may have been renamed since the link was made.`}
                  action={
                    <Link to={`/settings/${DEFAULT_SETTINGS_SECTION}`}>
                      <Button variant="primary">Go to general settings</Button>
                    </Link>
                  }
                />
              </Card>
            ) : !allowed ? (
              <Card>
                <EmptyState
                  icon={<Lock size={22} />}
                  title={`${definition.label} is not part of your access`}
                  description="Configuration decides how contracts are routed and assessed, so it is granted separately. Ask a contract administrator if you need to change it."
                />
              </Card>
            ) : (
              <SectionBody id={definition.id} />
            )}
          </div>
        </div>
      </div>
    </>
  )
}

function SectionBody({ id }: { id: string }) {
  switch (id) {
    case 'general':
      return <GeneralSection />
    case 'reminders':
      return <RemindersSection />
    case 'notifications':
      return <NotificationsSection />
    case 'contract-types':
      return <ContractTypesSection />
    case 'departments':
      return <DepartmentsSection />
    case 'custom-fields':
      return <CustomFieldsSection />
    case 'tags':
      return <TagsSection />
    case 'workflows':
      return <WorkflowsSection />
    case 'risk-rules':
      return <RiskRulesSection />
    case 'playbooks':
      return <PlaybooksSection />
    case 'roles':
      return <RolesSection />
    case 'clauses':
      return (
        <LinkSection
          title="Clause library"
          description="Approved wording lives with the rest of the library rather than in Settings, because the people who maintain it work there all day and it has its own versions, categories and approval state."
          to="/clauses"
          cta="Open the clause library"
        />
      )
    case 'templates':
      return (
        <LinkSection
          title="Templates"
          description="Contract templates are edited where they are previewed and rendered. A template carries versions and merge variables, which is more than a settings row."
          to="/templates"
          cta="Open templates"
        />
      )
    case 'ai':
      return <AiSection />
    case 'signatures':
      return <SignaturesSection />
    case 'integrations':
      return <IntegrationsSection />
    default:
      return null
  }
}

/* --- General & numbering -------------------------------------------------- */

function useSettingsResource() {
  return useApiResource<SettingsPayload>(
    (signal) => api.get<SettingsPayload>('/settings', undefined, signal),
    [],
  )
}

function GeneralSection() {
  const resource = useSettingsResource()
  const { busy, errors, run } = useMutation()
  const [draft, setDraft] = useState<ContractSettings | null>(null)

  useEffect(() => {
    if (resource.data) setDraft(resource.data.settings)
  }, [resource.data])

  const save = async () => {
    if (!draft) return
    await run(async () => {
      const payload = await api.put<SettingsPayload>('/settings', {
        number_prefix: draft.number_prefix,
        number_pad: draft.number_pad,
        number_include_year: draft.number_include_year,
        number_reset_yearly: draft.number_reset_yearly,
        default_currency: draft.default_currency,
        default_notice_days: draft.default_notice_days,
      })
      resource.setData(payload)
    }, 'Settings saved')
  }

  return (
    <Panel
      title="General & numbering"
      description="The shape of a contract number, and what a new contract starts from. Numbering changes apply to the next contract created, never to one already numbered."
    >
      <Loaded resource={resource}>
        {(payload) => {
          const settings = draft ?? payload.settings
          const preview = payload.numbering_preview

          return (
            <form
              onSubmit={(event) => {
                event.preventDefault()
                void save()
              }}
              style={{ display: 'grid', gap: 18 }}
            >
              <div style={FORM_GRID}>
                <Input
                  label="Number prefix"
                  value={settings.number_prefix}
                  error={errors.number_prefix}
                  maxLength={16}
                  onChange={(event) =>
                    setDraft({ ...settings, number_prefix: event.target.value.toUpperCase() })
                  }
                  hint="Letters and digits, e.g. CON"
                />
                <Select
                  label="Digits in the counter"
                  value={String(settings.number_pad)}
                  error={errors.number_pad}
                  onChange={(event) =>
                    setDraft({ ...settings, number_pad: Number(event.target.value) })
                  }
                  options={[3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map((value) => ({
                    value: String(value),
                    label: `${value} digits`,
                  }))}
                />
                <Select
                  label="Default currency"
                  value={settings.default_currency}
                  error={errors.default_currency}
                  onChange={(event) =>
                    setDraft({ ...settings, default_currency: event.target.value })
                  }
                  options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                />
                <Input
                  label="Default notice period"
                  type="number"
                  min={0}
                  max={3650}
                  value={String(settings.default_notice_days)}
                  error={errors.default_notice_days}
                  onChange={(event) =>
                    setDraft({
                      ...settings,
                      default_notice_days: numberOrNull(event.target.value) ?? 0,
                    })
                  }
                  hint="Days before expiry a decision is due"
                />
              </div>

              <div style={{ display: 'grid', gap: 10 }}>
                <Checkbox
                  label="Include the year in the number"
                  hint="CON-2026-000123 rather than CON-000123"
                  checked={settings.number_include_year}
                  onChange={(event) =>
                    setDraft({ ...settings, number_include_year: event.target.checked })
                  }
                />
                <Checkbox
                  label="Restart the counter each financial year"
                  hint="Turn this off to keep one continuous sequence for the life of the company"
                  checked={settings.number_reset_yearly}
                  onChange={(event) =>
                    setDraft({ ...settings, number_reset_yearly: event.target.checked })
                  }
                />
              </div>

              {preview ? (
                <div
                  style={{
                    display: 'flex',
                    flexWrap: 'wrap',
                    gap: 16,
                    padding: '12px 14px',
                    borderRadius: 'var(--radius-md)',
                    background: 'var(--color-bg-subtle)',
                    border: '1px solid rgb(var(--color-border))',
                  }}
                >
                  <PreviewValue label="Next contract number" value={preview.contract} />
                  <PreviewValue label="Next request number" value={preview.request} />
                </div>
              ) : null}

              <div style={{ display: 'flex', gap: 8 }}>
                <Button type="submit" variant="primary" loading={busy}>
                  Save changes
                </Button>
              </div>
            </form>
          )
        }}
      </Loaded>
    </Panel>
  )
}

function PreviewValue({ label, value }: { label: string; value?: string | null }) {
  return (
    <div>
      <div
        style={{
          fontSize: 10.5,
          fontWeight: 800,
          letterSpacing: '.06em',
          textTransform: 'uppercase',
          color: 'var(--color-text-muted)',
        }}
      >
        {label}
      </div>
      <div style={{ fontSize: 14, fontWeight: 700, marginTop: 2, fontVariantNumeric: 'tabular-nums' }}>
        {value ?? '—'}
      </div>
    </div>
  )
}

/* --- Reminder rules ------------------------------------------------------- */

function RemindersSection() {
  const resource = useSettingsResource()
  const { busy, errors, run } = useMutation()
  const [draft, setDraft] = useState<ContractSettings | null>(null)

  useEffect(() => {
    if (resource.data) setDraft(resource.data.settings)
  }, [resource.data])

  const save = async () => {
    if (!draft) return
    await run(async () => {
      const payload = await api.put<SettingsPayload>('/settings', {
        expiry_alert_days: draft.expiry_alert_days,
        obligation_alert_days: draft.obligation_alert_days,
        approval_escalation_days: draft.approval_escalation_days,
      })
      resource.setData(payload)
    }, 'Reminder rules saved')
  }

  return (
    <Panel
      title="Reminder rules"
      description="A ladder of days before a deadline, largest first. Each rung raises one notification, so 90,60,30 tells the owner three times and never four."
    >
      <Loaded resource={resource}>
        {(payload) => {
          const settings = draft ?? payload.settings

          return (
            <form
              onSubmit={(event) => {
                event.preventDefault()
                void save()
              }}
              style={{ display: 'grid', gap: 18 }}
            >
              <div style={FORM_GRID}>
                <Input
                  label="Expiry and renewal reminders"
                  value={settings.expiry_alert_days}
                  error={errors.expiry_alert_days}
                  onChange={(event) =>
                    setDraft({ ...settings, expiry_alert_days: event.target.value })
                  }
                  hint="Days before expiry, comma separated"
                />
                <Input
                  label="Obligation reminders"
                  value={settings.obligation_alert_days}
                  error={errors.obligation_alert_days}
                  onChange={(event) =>
                    setDraft({ ...settings, obligation_alert_days: event.target.value })
                  }
                  hint="Days before an obligation falls due"
                />
                <Input
                  label="Approval escalation"
                  type="number"
                  min={0}
                  max={365}
                  value={String(settings.approval_escalation_days)}
                  error={errors.approval_escalation_days}
                  onChange={(event) =>
                    setDraft({
                      ...settings,
                      approval_escalation_days: numberOrNull(event.target.value) ?? 0,
                    })
                  }
                  hint="Days an approval may sit before it is escalated"
                />
              </div>

              <LadderPreview label="Expiry" ladder={settings.expiry_alert_days} />
              <LadderPreview label="Obligation" ladder={settings.obligation_alert_days} />

              <div>
                <Button type="submit" variant="primary" loading={busy}>
                  Save reminder rules
                </Button>
              </div>
            </form>
          )
        }}
      </Loaded>
    </Panel>
  )
}

function LadderPreview({ label, ladder }: { label: string; ladder: string }) {
  const rungs = ladder
    .split(',')
    .map((entry) => entry.trim())
    .filter((entry) => /^\d+$/.test(entry))

  if (rungs.length === 0) return null

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
      <span style={{ fontSize: 12, color: 'var(--color-text-muted)', fontWeight: 600 }}>
        {label}:
      </span>
      {rungs.map((rung, index) => (
        <Chip key={`${rung}-${index}`} size="sm" tone="primary">
          {rung} days before
        </Chip>
      ))}
    </div>
  )
}

/* --- Contract types ------------------------------------------------------- */

function ContractTypesSection() {
  const { can } = useSession()
  const canWrite = can(PERMISSION.SETTINGS_MANAGE)
  const resource = useApiResource<ContractTypeRow[]>(
    (signal) => api.get<ContractTypeRow[]>('/settings/contract-types', undefined, signal),
    [],
  )
  const [editing, setEditing] = useState<ContractTypeRow | 'new' | null>(null)
  const [deleting, setDeleting] = useState<ContractTypeRow | null>(null)
  const remove = useMutation()

  const columns = useMemo<Column<ContractTypeRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Type',
        render: (row) => (
          <div style={{ minWidth: 180 }}>
            <span style={{ fontWeight: 600 }}>{row.name}</span>
            {row.is_system ? (
              <span style={{ marginLeft: 6 }}>
                <Chip size="sm">System</Chip>
              </span>
            ) : null}
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.code}
            </div>
          </div>
        ),
      },
      {
        key: 'category',
        header: 'Category',
        hideBelow: 'md',
        render: (row) => humanise(row.category),
      },
      {
        key: 'side',
        header: 'Counterparty',
        hideBelow: 'md',
        render: (row) => humanise(row.counterparty_side),
      },
      {
        key: 'defaults',
        header: 'Defaults',
        hideBelow: 'lg',
        render: (row) => (
          <span style={{ color: 'var(--color-text-secondary)' }}>
            {row.default_term_months ? `${row.default_term_months} months` : 'No term'}
            {row.default_notice_days ? ` · ${row.default_notice_days} days notice` : ''}
          </span>
        ),
      },
      {
        key: 'active',
        header: 'Status',
        width: 100,
        render: (row) => <ActiveChip active={row.is_active} />,
      },
      {
        key: 'actions',
        header: '',
        srLabel: 'Row actions',
        width: 92,
        align: 'right',
        render: (row) => (
          <RowActions
            canWrite={canWrite}
            onEdit={() => setEditing(row)}
            onDelete={() => setDeleting(row)}
            editLabel={`Edit ${row.name}`}
            deleteLabel={`Delete ${row.name}`}
          />
        ),
      },
    ],
    [canWrite],
  )

  return (
    <Panel
      title="Contract types"
      description="Every contract has a type, and the type carries the defaults a new contract starts from — its term, its notice period and the workflow it is routed through."
      padded={false}
      action={
        canWrite ? (
          <Button variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New type
          </Button>
        ) : null
      }
    >
      <Loaded resource={resource}>
        {(rows) =>
          rows.length === 0 ? (
            <EmptyState
              title="No contract types yet"
              description="A type is what tells Contracts that an NDA and a master services agreement are not the same thing. Add the handful this company actually signs."
              action={
                canWrite ? (
                  <Button variant="primary" onClick={() => setEditing('new')}>
                    Add the first type
                  </Button>
                ) : undefined
              }
              compact
            />
          ) : (
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              caption="Contract types configured for this company"
            />
          )
        }
      </Loaded>

      {editing ? (
        <ContractTypeDialog
          row={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            resource.reload()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        busy={remove.busy}
        tone="danger"
        title="Delete this contract type?"
        confirmLabel="Delete type"
        message={
          <>
            <strong>{deleting?.name}</strong> will be removed. Contracts already using it keep their
            history but lose the type, and the defaults it carried stop applying to new contracts.
          </>
        }
        onConfirm={() => {
          if (!deleting) return
          void remove
            .run(async () => {
              await api.delete(`/contract-types/${deleting.id}`)
            }, 'Contract type deleted')
            .then((ok) => {
              if (ok) {
                setDeleting(null)
                resource.reload()
              }
            })
        }}
      />
    </Panel>
  )
}

function ContractTypeDialog({
  row,
  onClose,
  onSaved,
}: {
  row: ContractTypeRow | null
  onClose: () => void
  onSaved: () => void
}) {
  const { busy, errors, run } = useMutation()
  const [form, setForm] = useState({
    code: row?.code ?? '',
    name: row?.name ?? '',
    description: row?.description ?? '',
    category: row?.category ?? 'general',
    counterparty_side: row?.counterparty_side ?? 'either',
    default_renewal_type: row?.default_renewal_type ?? '',
    default_notice_days: row?.default_notice_days == null ? '' : String(row.default_notice_days),
    default_term_months: row?.default_term_months == null ? '' : String(row.default_term_months),
    sort_order: String(row?.sort_order ?? 100),
    is_active: row?.is_active ?? true,
  })

  const submit = async () => {
    const body = {
      code: form.code,
      name: form.name,
      description: form.description || null,
      category: form.category,
      counterparty_side: form.counterparty_side,
      default_renewal_type: form.default_renewal_type || null,
      default_notice_days: numberOrNull(form.default_notice_days),
      default_term_months: numberOrNull(form.default_term_months),
      sort_order: numberOrNull(form.sort_order) ?? 100,
      is_active: form.is_active,
      // Resent unchanged: this form has no control for either, and the PUT
      // replaces the resource rather than patching it.
      required_fields: row?.required_fields ?? [],
      mandatory_clauses: row?.mandatory_clauses ?? [],
      default_template_id: row?.default_template_id ?? null,
      approval_workflow_id: row?.approval_workflow_id ?? null,
    }

    const ok = await run(
      async () => {
        if (row) await api.put(`/contract-types/${row.id}`, body)
        else await api.post('/settings/contract-types', body)
      },
      row ? 'Contract type updated' : 'Contract type added',
    )
    if (ok) onSaved()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? `Edit ${row.name}` : 'New contract type'}
      description="The defaults here are a starting point for a new contract, not a constraint on it."
      width={640}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            {row ? 'Save changes' : 'Add type'}
          </Button>
        </>
      }
    >
      <div style={FORM_GRID}>
        <Input
          label="Name"
          required
          value={form.name}
          error={errors.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
        />
        <Input
          label="Code"
          required
          value={form.code}
          error={errors.code}
          disabled={row !== null}
          onChange={(event) => setForm({ ...form, code: event.target.value })}
          hint={row ? 'A code is fixed once contracts use it' : 'Short, e.g. MSA or NDA'}
        />
        <Input
          label="Category"
          value={form.category}
          error={errors.category}
          onChange={(event) => setForm({ ...form, category: event.target.value })}
          hint="Used to group types in menus"
        />
        <Select
          label="Counterparty side"
          value={form.counterparty_side}
          error={errors.counterparty_side}
          onChange={(event) => setForm({ ...form, counterparty_side: event.target.value })}
          options={COUNTERPARTY_SIDES.map((side) => ({ value: side, label: humanise(side) }))}
        />
        <Select
          label="Default renewal"
          value={form.default_renewal_type}
          error={errors.default_renewal_type}
          onChange={(event) => setForm({ ...form, default_renewal_type: event.target.value })}
          options={RENEWAL_TYPES.map((type) => ({ value: type, label: humanise(type) }))}
          placeholder="Not set"
        />
        <Input
          label="Default term (months)"
          type="number"
          min={0}
          max={1200}
          value={form.default_term_months}
          error={errors.default_term_months}
          onChange={(event) => setForm({ ...form, default_term_months: event.target.value })}
        />
        <Input
          label="Default notice (days)"
          type="number"
          min={0}
          max={3650}
          value={form.default_notice_days}
          error={errors.default_notice_days}
          onChange={(event) => setForm({ ...form, default_notice_days: event.target.value })}
        />
        <Input
          label="Sort order"
          type="number"
          min={0}
          value={form.sort_order}
          error={errors.sort_order}
          onChange={(event) => setForm({ ...form, sort_order: event.target.value })}
          hint="Lower appears first"
        />
        <FullWidth>
          <Textarea
            label="Description"
            rows={3}
            value={form.description}
            error={errors.description}
            onChange={(event) => setForm({ ...form, description: event.target.value })}
          />
        </FullWidth>
        <FullWidth>
          <Checkbox
            label="Available for new contracts"
            hint="Turn this off to retire a type without deleting its history"
            checked={form.is_active}
            onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
          />
        </FullWidth>
      </div>
    </Modal>
  )
}

/* --- Departments ---------------------------------------------------------- */

function DepartmentsSection() {
  const { can } = useSession()
  const canWrite = can(PERMISSION.SETTINGS_MANAGE)
  const resource = useApiResource<DepartmentRow[]>(
    (signal) => api.get<DepartmentRow[]>('/settings/departments', undefined, signal),
    [],
  )
  const [editing, setEditing] = useState<DepartmentRow | 'new' | null>(null)
  const [deleting, setDeleting] = useState<DepartmentRow | null>(null)
  const remove = useMutation()

  const columns = useMemo<Column<DepartmentRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Department',
        render: (row) => (
          <div>
            <span style={{ fontWeight: 600 }}>{row.name}</span>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.code}
            </div>
          </div>
        ),
      },
      {
        key: 'head',
        header: 'Head',
        hideBelow: 'sm',
        render: (row) =>
          row.head_uuid ? (
            <code style={{ fontSize: 11.5 }}>{row.head_uuid}</code>
          ) : (
            <span style={{ color: 'var(--color-text-subtle)' }}>Not set</span>
          ),
      },
      {
        key: 'active',
        header: 'Status',
        width: 100,
        render: (row) => <ActiveChip active={row.is_active} />,
      },
      {
        key: 'actions',
        header: '',
        srLabel: 'Row actions',
        width: 92,
        align: 'right',
        render: (row) => (
          <RowActions
            canWrite={canWrite}
            onEdit={() => setEditing(row)}
            onDelete={() => setDeleting(row)}
            editLabel={`Edit ${row.name}`}
            deleteLabel={`Delete ${row.name}`}
          />
        ),
      },
    ],
    [canWrite],
  )

  return (
    <Panel
      title="Departments"
      description="Departments own contracts and receive approvals. A workflow step set to “department head” routes to the head named here."
      padded={false}
      action={
        canWrite ? (
          <Button variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New department
          </Button>
        ) : null
      }
    >
      <Loaded resource={resource}>
        {(rows) =>
          rows.length === 0 ? (
            <EmptyState
              title="No departments yet"
              description="Contracts can run without departments, but reporting by department and department-head approval both need them."
              action={
                canWrite ? (
                  <Button variant="primary" onClick={() => setEditing('new')}>
                    Add the first department
                  </Button>
                ) : undefined
              }
              compact
            />
          ) : (
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              caption="Departments in this company"
            />
          )
        }
      </Loaded>

      {editing ? (
        <DepartmentDialog
          row={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            resource.reload()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        busy={remove.busy}
        tone="danger"
        title="Delete this department?"
        confirmLabel="Delete department"
        message={
          <>
            <strong>{deleting?.name}</strong> will be removed. Contracts assigned to it keep their
            history, but they will no longer belong to a department.
          </>
        }
        onConfirm={() => {
          if (!deleting) return
          void remove
            .run(async () => {
              await api.delete(`/departments/${deleting.id}`)
            }, 'Department deleted')
            .then((ok) => {
              if (ok) {
                setDeleting(null)
                resource.reload()
              }
            })
        }}
      />
    </Panel>
  )
}

function DepartmentDialog({
  row,
  onClose,
  onSaved,
}: {
  row: DepartmentRow | null
  onClose: () => void
  onSaved: () => void
}) {
  const { busy, errors, run } = useMutation()
  const [form, setForm] = useState({
    name: row?.name ?? '',
    code: row?.code ?? '',
    head_uuid: row?.head_uuid ?? '',
    is_active: row?.is_active ?? true,
  })

  const submit = async () => {
    const body = {
      name: form.name,
      code: form.code,
      head_uuid: form.head_uuid || null,
      is_active: form.is_active,
    }
    const ok = await run(
      async () => {
        if (row) await api.put(`/departments/${row.id}`, body)
        else await api.post('/settings/departments', body)
      },
      row ? 'Department updated' : 'Department added',
    )
    if (ok) onSaved()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? `Edit ${row.name}` : 'New department'}
      width={520}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            {row ? 'Save changes' : 'Add department'}
          </Button>
        </>
      }
    >
      <div style={FORM_GRID}>
        <Input
          label="Name"
          required
          value={form.name}
          error={errors.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
        />
        <Input
          label="Code"
          required
          value={form.code}
          error={errors.code}
          onChange={(event) => setForm({ ...form, code: event.target.value })}
          hint="Short, e.g. LEGAL"
        />
        <FullWidth>
          <Input
            label="Department head"
            value={form.head_uuid}
            error={errors.head_uuid}
            onChange={(event) => setForm({ ...form, head_uuid: event.target.value })}
            hint="The head's AICOUNTLY user id; approvals routed to “department head” go here"
          />
        </FullWidth>
        <FullWidth>
          <Checkbox
            label="Active"
            checked={form.is_active}
            onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
          />
        </FullWidth>
      </div>
    </Modal>
  )
}

/* --- Custom fields -------------------------------------------------------- */

const OPTION_FIELD_TYPES = ['select', 'multi_select']

function CustomFieldsSection() {
  const { can } = useSession()
  const canWrite = can(PERMISSION.SETTINGS_MANAGE)
  const resource = useApiResource<CustomFieldRow[]>(
    (signal) => api.get<CustomFieldRow[]>('/settings/custom-fields', undefined, signal),
    [],
  )
  const types = useApiResource<ContractTypeRow[]>(
    (signal) => api.get<ContractTypeRow[]>('/settings/contract-types', undefined, signal),
    [],
  )
  const [editing, setEditing] = useState<CustomFieldRow | 'new' | null>(null)
  const [deleting, setDeleting] = useState<CustomFieldRow | null>(null)
  const remove = useMutation()

  const typeName = useCallback(
    (id?: number | null) => types.data?.find((type) => type.id === id)?.name ?? null,
    [types.data],
  )

  const columns = useMemo<Column<CustomFieldRow>[]>(
    () => [
      {
        key: 'label',
        header: 'Field',
        render: (row) => (
          <div style={{ minWidth: 160 }}>
            <span style={{ fontWeight: 600 }}>{row.label}</span>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              <code>{row.field_key}</code>
            </div>
          </div>
        ),
      },
      {
        key: 'type',
        header: 'Type',
        hideBelow: 'sm',
        render: (row) => <Chip size="sm">{humanise(row.field_type)}</Chip>,
      },
      {
        key: 'scope',
        header: 'Applies to',
        hideBelow: 'md',
        render: (row) =>
          row.contract_type_id ? (
            (typeName(row.contract_type_id) ?? `Type ${row.contract_type_id}`)
          ) : (
            <span style={{ color: 'var(--color-text-secondary)' }}>All contract types</span>
          ),
      },
      {
        key: 'required',
        header: 'Required',
        hideBelow: 'lg',
        width: 90,
        render: (row) =>
          row.is_required ? (
            <Check size={14} aria-label="Required" style={{ color: 'var(--color-success)' }} />
          ) : (
            <Minus size={14} aria-label="Optional" style={{ color: 'var(--color-text-subtle)' }} />
          ),
      },
      {
        key: 'active',
        header: 'Status',
        width: 100,
        render: (row) => <ActiveChip active={row.is_active} />,
      },
      {
        key: 'actions',
        header: '',
        srLabel: 'Row actions',
        width: 92,
        align: 'right',
        render: (row) => (
          <RowActions
            canWrite={canWrite}
            onEdit={() => setEditing(row)}
            onDelete={() => setDeleting(row)}
            editLabel={`Edit ${row.label}`}
            deleteLabel={`Delete ${row.label}`}
          />
        ),
      },
    ],
    [canWrite, typeName],
  )

  return (
    <Panel
      title="Custom fields"
      description="Anything this company records on a contract that Contracts does not model itself — a cost centre, a supplier reference, a review board."
      padded={false}
      action={
        canWrite ? (
          <Button variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New field
          </Button>
        ) : null
      }
    >
      <Loaded resource={resource}>
        {(rows) =>
          rows.length === 0 ? (
            <EmptyState
              title="No custom fields"
              description="Contracts already records the terms every agreement has. Add a field only for something specific to this company that reports need to filter on."
              action={
                canWrite ? (
                  <Button variant="primary" onClick={() => setEditing('new')}>
                    Add a field
                  </Button>
                ) : undefined
              }
              compact
            />
          ) : (
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              caption="Custom fields recorded on contracts"
            />
          )
        }
      </Loaded>

      {editing ? (
        <CustomFieldDialog
          row={editing === 'new' ? null : editing}
          types={types.data ?? []}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            resource.reload()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        busy={remove.busy}
        tone="danger"
        title="Delete this custom field?"
        confirmLabel="Delete field"
        message={
          <>
            <strong>{deleting?.label}</strong> will stop being collected. Values already recorded on
            contracts stay in the record but will no longer be shown or filterable.
          </>
        }
        onConfirm={() => {
          if (!deleting) return
          void remove
            .run(async () => {
              await api.delete(`/custom-fields/${deleting.id}`)
            }, 'Custom field deleted')
            .then((ok) => {
              if (ok) {
                setDeleting(null)
                resource.reload()
              }
            })
        }}
      />
    </Panel>
  )
}

function CustomFieldDialog({
  row,
  types,
  onClose,
  onSaved,
}: {
  row: CustomFieldRow | null
  types: ContractTypeRow[]
  onClose: () => void
  onSaved: () => void
}) {
  const { busy, errors, run } = useMutation()
  const [form, setForm] = useState({
    field_key: row?.field_key ?? '',
    label: row?.label ?? '',
    field_type: row?.field_type ?? 'text',
    contract_type_id: row?.contract_type_id == null ? '' : String(row.contract_type_id),
    options: (row?.options ?? []).join(', '),
    is_required: row?.is_required ?? false,
    is_filterable: row?.is_filterable ?? false,
    help_text: row?.help_text ?? '',
    sort_order: String(row?.sort_order ?? 100),
    is_active: row?.is_active ?? true,
  })

  const needsOptions = OPTION_FIELD_TYPES.includes(form.field_type)

  const submit = async () => {
    const body = {
      field_key: form.field_key,
      label: form.label,
      field_type: form.field_type,
      contract_type_id: numberOrNull(form.contract_type_id),
      options: needsOptions
        ? form.options
            .split(',')
            .map((option) => option.trim())
            .filter((option) => option !== '')
        : [],
      is_required: form.is_required,
      is_filterable: form.is_filterable,
      help_text: form.help_text || null,
      sort_order: numberOrNull(form.sort_order) ?? 100,
      is_active: form.is_active,
    }

    const ok = await run(
      async () => {
        if (row) await api.put(`/custom-fields/${row.id}`, body)
        else await api.post('/settings/custom-fields', body)
      },
      row ? 'Custom field updated' : 'Custom field added',
    )
    if (ok) onSaved()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? `Edit ${row.label}` : 'New custom field'}
      width={620}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            {row ? 'Save changes' : 'Add field'}
          </Button>
        </>
      }
    >
      <div style={FORM_GRID}>
        <Input
          label="Label"
          required
          value={form.label}
          error={errors.label}
          onChange={(event) => setForm({ ...form, label: event.target.value })}
        />
        <Input
          label="Key"
          required
          value={form.field_key}
          error={errors.field_key}
          disabled={row !== null}
          onChange={(event) => setForm({ ...form, field_key: event.target.value })}
          hint={row ? 'A key is fixed once contracts hold values for it' : 'Lowercase, e.g. cost_centre'}
        />
        <Select
          label="Type"
          value={form.field_type}
          error={errors.field_type}
          onChange={(event) => setForm({ ...form, field_type: event.target.value })}
          options={CUSTOM_FIELD_TYPES.map((type) => ({ value: type, label: humanise(type) }))}
        />
        <Select
          label="Applies to"
          value={form.contract_type_id}
          error={errors.contract_type_id}
          onChange={(event) => setForm({ ...form, contract_type_id: event.target.value })}
          options={types.map((type) => ({ value: String(type.id), label: type.name }))}
          placeholder="All contract types"
        />
        {needsOptions ? (
          <FullWidth>
            <Input
              label="Options"
              value={form.options}
              error={errors.options}
              onChange={(event) => setForm({ ...form, options: event.target.value })}
              hint="Comma separated, in the order they should appear"
            />
          </FullWidth>
        ) : null}
        <Input
          label="Sort order"
          type="number"
          min={0}
          value={form.sort_order}
          error={errors.sort_order}
          onChange={(event) => setForm({ ...form, sort_order: event.target.value })}
        />
        <FullWidth>
          <Input
            label="Help text"
            value={form.help_text}
            error={errors.help_text}
            onChange={(event) => setForm({ ...form, help_text: event.target.value })}
            hint="Shown under the field on the contract form"
          />
        </FullWidth>
        <FullWidth>
          <div style={{ display: 'grid', gap: 9 }}>
            <Checkbox
              label="Required"
              hint="A contract cannot be saved without it"
              checked={form.is_required}
              onChange={(event) => setForm({ ...form, is_required: event.target.checked })}
            />
            <Checkbox
              label="Filterable"
              hint="Offer it as a filter in the repository and in reports"
              checked={form.is_filterable}
              onChange={(event) => setForm({ ...form, is_filterable: event.target.checked })}
            />
            <Checkbox
              label="Active"
              checked={form.is_active}
              onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
            />
          </div>
        </FullWidth>
      </div>
    </Modal>
  )
}

/* --- Tags ----------------------------------------------------------------- */

function TagsSection() {
  const { can } = useSession()
  const canWrite = can(PERMISSION.SETTINGS_MANAGE)
  const resource = useApiResource<TagRow[]>(
    (signal) => api.get<TagRow[]>('/settings/tags', undefined, signal),
    [],
  )
  const create = useMutation()
  const remove = useMutation()
  const [name, setName] = useState('')
  const [colour, setColour] = useState<string>(TAG_COLOURS[0])
  const [deleting, setDeleting] = useState<TagRow | null>(null)

  const add = async () => {
    const ok = await create.run(async () => {
      await api.post('/settings/tags', { name, colour })
    }, 'Tag added')
    if (ok) {
      setName('')
      resource.reload()
    }
  }

  return (
    <Panel
      title="Tags"
      description="A label that cuts across types and departments — “strategic”, “renewal 2027”, “under dispute”. Anyone who can edit a contract can apply one; only an administrator can create one."
    >
      {canWrite ? (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            void add()
          }}
          style={{
            display: 'grid',
            gap: 12,
            gridTemplateColumns: 'minmax(160px, 2fr) minmax(120px, 1fr) auto',
            alignItems: 'end',
            marginBottom: 18,
          }}
        >
          <Input
            label="Tag name"
            value={name}
            error={create.errors.name}
            maxLength={64}
            onChange={(event) => setName(event.target.value)}
          />
          <Select
            label="Colour"
            value={colour}
            error={create.errors.colour}
            onChange={(event) => setColour(event.target.value)}
            options={TAG_COLOURS.map((option) => ({ value: option, label: humanise(option) }))}
          />
          <Button
            type="submit"
            variant="primary"
            icon={<Plus size={14} />}
            loading={create.busy}
            disabled={name.trim() === ''}
          >
            Add tag
          </Button>
        </form>
      ) : null}

      <Loaded resource={resource} skeletonRows={2}>
        {(rows) =>
          rows.length === 0 ? (
            <EmptyState
              title="No tags yet"
              description="Tags are for the groupings that do not fit a type or a department. Add one when you find yourself searching for the same set of contracts twice."
              compact
            />
          ) : (
            <ul
              style={{
                listStyle: 'none',
                display: 'flex',
                flexWrap: 'wrap',
                gap: 8,
              }}
            >
              {rows.map((tag) => (
                <li
                  key={tag.id}
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 8,
                    padding: '5px 6px 5px 11px',
                    borderRadius: 999,
                    border: '1px solid rgb(var(--color-border))',
                    background: 'var(--color-bg-subtle)',
                    fontSize: 12.5,
                    fontWeight: 600,
                  }}
                >
                  {tag.name}
                  <span style={{ color: 'var(--color-text-muted)', fontWeight: 600 }}>
                    {tag.usage_count ?? 0}
                  </span>
                  {canWrite ? (
                    <button
                      type="button"
                      onClick={() => setDeleting(tag)}
                      aria-label={`Delete tag ${tag.name}`}
                      style={{
                        display: 'inline-flex',
                        background: 'none',
                        border: 'none',
                        cursor: 'pointer',
                        color: 'var(--color-text-muted)',
                        padding: 2,
                        lineHeight: 0,
                      }}
                    >
                      <Trash2 size={12} aria-hidden />
                    </button>
                  ) : null}
                </li>
              ))}
            </ul>
          )
        }
      </Loaded>

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        busy={remove.busy}
        tone="danger"
        title="Delete this tag?"
        confirmLabel="Delete tag"
        message={
          <>
            <strong>{deleting?.name}</strong> is on {deleting?.usage_count ?? 0} contracts. Deleting
            it removes the label from all of them.
          </>
        }
        onConfirm={() => {
          if (!deleting) return
          void remove
            .run(async () => {
              await api.delete(`/tags/${deleting.id}`)
            }, 'Tag deleted')
            .then((ok) => {
              if (ok) {
                setDeleting(null)
                resource.reload()
              }
            })
        }}
      />
    </Panel>
  )
}

/* --- Approval workflows --------------------------------------------------- */

function WorkflowsSection() {
  const { can } = useSession()
  const canWrite = can(PERMISSION.WORKFLOW_MANAGE)
  const resource = useApiResource<ApprovalWorkflow[]>(
    (signal) => api.get<ApprovalWorkflow[]>('/approval-workflows', undefined, signal),
    [],
  )
  const [editing, setEditing] = useState<ApprovalWorkflow | 'new' | null>(null)
  const [deleting, setDeleting] = useState<ApprovalWorkflow | null>(null)
  const remove = useMutation()

  return (
    <Panel
      title="Approval workflows"
      description="The first workflow whose conditions match a contract is the one that runs, lowest priority number first. A workflow edited while an approval is in flight does not change that approval — the steps were frozen when it was submitted."
      action={
        canWrite ? (
          <Button variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New workflow
          </Button>
        ) : null
      }
    >
      <Loaded resource={resource}>
        {(workflows) =>
          workflows.length === 0 ? (
            <EmptyState
              title="No approval workflows"
              description="Without a workflow, a contract moves straight to signature. Add one for the contracts that should not."
              action={
                canWrite ? (
                  <Button variant="primary" onClick={() => setEditing('new')}>
                    Add a workflow
                  </Button>
                ) : undefined
              }
              compact
            />
          ) : (
            <ul style={{ listStyle: 'none', display: 'grid', gap: 12 }}>
              {[...workflows]
                .sort((a, b) => a.priority - b.priority)
                .map((workflow) => (
                  <li
                    key={workflow.id}
                    style={{
                      border: '1px solid rgb(var(--color-border))',
                      borderRadius: 'var(--radius-md)',
                      padding: 14,
                    }}
                  >
                    <div
                      style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        gap: 12,
                        flexWrap: 'wrap',
                      }}
                    >
                      <div style={{ minWidth: 0 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                          <span style={{ fontWeight: 700, fontSize: 13.5 }}>{workflow.name}</span>
                          <ActiveChip active={workflow.is_active} />
                          <Chip size="sm" tone="info">
                            {humanise(workflow.applies_to)}
                          </Chip>
                          <Chip size="sm">Priority {workflow.priority}</Chip>
                        </div>
                        <p
                          style={{
                            fontSize: 12,
                            color: 'var(--color-text-secondary)',
                            marginTop: 5,
                          }}
                        >
                          {(workflow.conditions?.length ?? 0) === 0
                            ? 'Matches every contract it applies to'
                            : `Matches when ${workflow.match_mode === 'any' ? 'any' : 'all'} of ${workflow.conditions?.length} conditions hold`}
                          {workflow.escalation_days
                            ? ` · escalates after ${workflow.escalation_days} days`
                            : ''}
                        </p>
                      </div>
                      <RowActions
                        canWrite={canWrite}
                        onEdit={() => setEditing(workflow)}
                        onDelete={() => setDeleting(workflow)}
                        editLabel={`Edit ${workflow.name}`}
                        deleteLabel={`Delete ${workflow.name}`}
                      />
                    </div>

                    <ol
                      style={{
                        listStyle: 'none',
                        display: 'flex',
                        flexWrap: 'wrap',
                        gap: 6,
                        marginTop: 10,
                      }}
                    >
                      {(workflow.steps ?? []).map((step, index) => (
                        <li key={step.id ?? `${workflow.id}-${index}`}>
                          <Chip size="sm" tone="primary">
                            {step.step_no}. {step.name} → {describeApprover(step)}
                          </Chip>
                        </li>
                      ))}
                      {(workflow.steps ?? []).length === 0 ? (
                        <li style={{ fontSize: 12, color: 'var(--color-warning-text)' }}>
                          No steps — this workflow cannot route anything.
                        </li>
                      ) : null}
                    </ol>
                  </li>
                ))}
            </ul>
          )
        }
      </Loaded>

      {editing ? (
        <WorkflowDialog
          workflow={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            resource.reload()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        busy={remove.busy}
        tone="danger"
        title="Delete this workflow?"
        confirmLabel="Delete workflow"
        message={
          <>
            <strong>{deleting?.name}</strong> will stop routing new contracts. Approvals already in
            flight are unaffected — they hold their own copy of the steps.
          </>
        }
        onConfirm={() => {
          if (!deleting) return
          void remove
            .run(async () => {
              await api.delete(`/approval-workflows/${deleting.id}`)
            }, 'Workflow deleted')
            .then((ok) => {
              if (ok) {
                setDeleting(null)
                resource.reload()
              }
            })
        }}
      />
    </Panel>
  )
}

function describeApprover(step: ApprovalWorkflowStep): string {
  if (step.approver_type === 'user' || step.approver_type === 'role') {
    return `${humanise(step.approver_type)} ${step.approver_value ?? ''}`.trim()
  }
  return humanise(step.approver_type)
}

const BLANK_STEP: ApprovalWorkflowStep = {
  step_no: 1,
  name: 'Approval',
  execution: 'sequential',
  approver_type: 'role',
  approver_value: '',
  min_approvals: 1,
  can_edit: false,
}

function WorkflowDialog({
  workflow,
  onClose,
  onSaved,
}: {
  workflow: ApprovalWorkflow | null
  onClose: () => void
  onSaved: () => void
}) {
  const { busy, errors, run } = useMutation()
  const [form, setForm] = useState({
    name: workflow?.name ?? '',
    applies_to: workflow?.applies_to ?? 'contract',
    match_mode: workflow?.match_mode ?? 'all',
    priority: String(workflow?.priority ?? 100),
    escalation_days: workflow?.escalation_days == null ? '' : String(workflow.escalation_days),
    is_active: workflow?.is_active ?? true,
  })
  const [steps, setSteps] = useState<ApprovalWorkflowStep[]>(
    workflow?.steps && workflow.steps.length > 0 ? workflow.steps : [BLANK_STEP],
  )

  const updateStep = (index: number, patch: Partial<ApprovalWorkflowStep>) => {
    setSteps((current) =>
      current.map((step, position) => (position === index ? { ...step, ...patch } : step)),
    )
  }

  const submit = async () => {
    const body = {
      name: form.name,
      applies_to: form.applies_to,
      match_mode: form.match_mode,
      priority: numberOrNull(form.priority) ?? 100,
      escalation_days: numberOrNull(form.escalation_days),
      is_active: form.is_active,
      // The editor does not show conditions, and the endpoint rewrites them
      // wholesale, so they are carried through rather than dropped.
      conditions: workflow?.conditions ?? [],
      steps: steps.map((step, index) => ({
        step_no: index + 1,
        name: step.name,
        execution: step.execution,
        approver_type: step.approver_type,
        approver_value: step.approver_value || null,
        min_approvals: step.min_approvals,
        can_edit: step.can_edit ?? false,
        escalation_days: step.escalation_days ?? null,
      })),
    }

    const ok = await run(
      async () => {
        if (workflow) await api.put(`/approval-workflows/${workflow.id}`, body)
        else await api.post('/approval-workflows', body)
      },
      workflow ? 'Workflow updated' : 'Workflow added',
    )
    if (ok) onSaved()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={workflow ? `Edit ${workflow.name}` : 'New approval workflow'}
      description="Steps run in the order listed. A parallel step runs alongside the one before it."
      width={720}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            {workflow ? 'Save workflow' : 'Add workflow'}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 18 }}>
        <div style={FORM_GRID}>
          <Input
            label="Name"
            required
            value={form.name}
            error={errors.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
          />
          <Select
            label="Applies to"
            value={form.applies_to}
            error={errors.applies_to}
            onChange={(event) => setForm({ ...form, applies_to: event.target.value })}
            options={APPROVAL_WORKFLOW_SUBJECTS.map((subject) => ({
              value: subject,
              label: humanise(subject),
            }))}
          />
          <Input
            label="Priority"
            type="number"
            min={1}
            max={10000}
            value={form.priority}
            error={errors.priority}
            onChange={(event) => setForm({ ...form, priority: event.target.value })}
            hint="Lower runs first; the first match wins"
          />
          <Input
            label="Escalate after (days)"
            type="number"
            min={1}
            max={365}
            value={form.escalation_days}
            error={errors.escalation_days}
            onChange={(event) => setForm({ ...form, escalation_days: event.target.value })}
            hint="Leave blank to use the company default"
          />
          <FullWidth>
            <Checkbox
              label="Active"
              hint="An inactive workflow routes nothing"
              checked={form.is_active}
              onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
            />
          </FullWidth>
        </div>

        <div>
          <div
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              marginBottom: 8,
            }}
          >
            <h3 style={{ fontSize: 13, fontWeight: 700 }}>Steps</h3>
            <Button
              size="sm"
              variant="secondary"
              icon={<Plus size={13} />}
              onClick={() =>
                setSteps((current) => [...current, { ...BLANK_STEP, step_no: current.length + 1 }])
              }
            >
              Add step
            </Button>
          </div>

          {errors.steps ? (
            <p role="alert" style={{ fontSize: 12, color: 'var(--color-danger)', marginBottom: 8 }}>
              {errors.steps}
            </p>
          ) : null}

          <ol style={{ listStyle: 'none', display: 'grid', gap: 12 }}>
            {steps.map((step, index) => (
              <li
                key={index}
                style={{
                  border: '1px solid rgb(var(--color-border))',
                  borderRadius: 'var(--radius-md)',
                  padding: 12,
                }}
              >
                <div style={{ ...FORM_GRID, gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))' }}>
                  <Input
                    label={`Step ${index + 1} name`}
                    value={step.name}
                    error={errors[`steps.${index}.name`]}
                    onChange={(event) => updateStep(index, { name: event.target.value })}
                  />
                  <Select
                    label="Approver"
                    value={step.approver_type}
                    error={errors[`steps.${index}.approver_type`]}
                    onChange={(event) => updateStep(index, { approver_type: event.target.value })}
                    options={APPROVER_TYPES.map((type) => ({ value: type, label: humanise(type) }))}
                  />
                  {step.approver_type === 'user' || step.approver_type === 'role' ? (
                    <Input
                      label={step.approver_type === 'user' ? 'User id' : 'Role slug'}
                      value={step.approver_value ?? ''}
                      error={errors[`steps.${index}.approver_value`]}
                      onChange={(event) => updateStep(index, { approver_value: event.target.value })}
                    />
                  ) : null}
                  <Select
                    label="Execution"
                    value={step.execution}
                    onChange={(event) => updateStep(index, { execution: event.target.value })}
                    options={[
                      { value: 'sequential', label: 'After the previous step' },
                      { value: 'parallel', label: 'Alongside the previous step' },
                    ]}
                  />
                  <Input
                    label="Minimum approvals"
                    type="number"
                    min={1}
                    value={String(step.min_approvals)}
                    onChange={(event) =>
                      updateStep(index, { min_approvals: numberOrNull(event.target.value) ?? 1 })
                    }
                  />
                </div>

                <div
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    gap: 12,
                    marginTop: 10,
                  }}
                >
                  <Checkbox
                    label="This approver may edit the contract"
                    checked={step.can_edit ?? false}
                    onChange={(event) => updateStep(index, { can_edit: event.target.checked })}
                  />
                  {steps.length > 1 ? (
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() => setSteps((current) => current.filter((_, i) => i !== index))}
                      aria-label={`Remove step ${index + 1}`}
                    >
                      <Trash2 size={13} aria-hidden style={{ color: 'var(--color-danger)' }} />
                    </Button>
                  ) : null}
                </div>
              </li>
            ))}
          </ol>
        </div>
      </div>
    </Modal>
  )
}

/* --- Risk rules ----------------------------------------------------------- */

function RiskRulesSection() {
  const { can } = useSession()
  const canWrite = can(PERMISSION.SETTINGS_MANAGE)
  const resource = useApiResource<RiskRuleRow[]>(
    (signal) => api.get<RiskRuleRow[]>('/settings/risk-rules', undefined, signal),
    [],
  )
  const [editing, setEditing] = useState<RiskRuleRow | 'new' | null>(null)
  const [deleting, setDeleting] = useState<RiskRuleRow | null>(null)
  const remove = useMutation()

  const columns = useMemo<Column<RiskRuleRow>[]>(
    () => [
      {
        key: 'name',
        header: 'Rule',
        render: (row) => (
          <div style={{ minWidth: 200 }}>
            <span style={{ fontWeight: 600 }}>{row.name}</span>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              <code>{row.rule_key}</code>
            </div>
          </div>
        ),
      },
      {
        key: 'test',
        header: 'Test',
        hideBelow: 'md',
        render: (row) => (
          <span style={{ color: 'var(--color-text-secondary)' }}>{describeRule(row)}</span>
        ),
      },
      {
        key: 'category',
        header: 'Category',
        hideBelow: 'lg',
        render: (row) => <Chip size="sm">{humanise(row.risk_category)}</Chip>,
      },
      {
        key: 'severity',
        header: 'Severity',
        width: 118,
        render: (row) => <StatusChip status={row.severity} size="sm" />,
      },
      {
        key: 'active',
        header: 'Status',
        width: 100,
        render: (row) => <ActiveChip active={row.is_active} />,
      },
      {
        key: 'actions',
        header: '',
        srLabel: 'Row actions',
        width: 92,
        align: 'right',
        render: (row) => (
          <RowActions
            canWrite={canWrite}
            onEdit={() => setEditing(row)}
            onDelete={() => setDeleting(row)}
            editLabel={`Edit ${row.name}`}
            deleteLabel={`Delete ${row.name}`}
          />
        ),
      },
    ],
    [canWrite],
  )

  return (
    <Panel
      title="Risk rules"
      description="Deterministic checks run against every contract before any model is asked for an opinion. A rule names what it looks at and how it compares — it is never free-form code."
      padded={false}
      action={
        canWrite ? (
          <Button variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New rule
          </Button>
        ) : null
      }
    >
      <Loaded resource={resource}>
        {(rows) =>
          rows.length === 0 ? (
            <EmptyState
              title="No risk rules"
              description="Without rules, a risk assessment has nothing deterministic to say and falls back on what AI reads from the document. Add the checks this company actually cares about."
              action={
                canWrite ? (
                  <Button variant="primary" onClick={() => setEditing('new')}>
                    Add a rule
                  </Button>
                ) : undefined
              }
              compact
            />
          ) : (
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              caption="Risk rules every contract is assessed against"
            />
          )
        }
      </Loaded>

      {editing ? (
        <RiskRuleDialog
          row={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            resource.reload()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        busy={remove.busy}
        tone="danger"
        title="Delete this risk rule?"
        confirmLabel="Delete rule"
        message={
          <>
            <strong>{deleting?.name}</strong> will stop being evaluated. Findings it has already
            raised stay on their contracts.
          </>
        }
        onConfirm={() => {
          if (!deleting) return
          void remove
            .run(async () => {
              await api.delete(`/risk-rules/${deleting.id}`)
            }, 'Risk rule deleted')
            .then((ok) => {
              if (ok) {
                setDeleting(null)
                resource.reload()
              }
            })
        }}
      />
    </Panel>
  )
}

function describeRule(rule: RiskRuleRow): string {
  const subject = humanise(rule.subject)
  const operator = humanise(rule.operator).toLowerCase()

  if ((UNARY_RISK_OPERATORS as readonly string[]).includes(rule.operator)) {
    return `${subject} ${operator}`
  }

  const value =
    rule.value_numeric !== null && rule.value_numeric !== undefined && rule.value_numeric !== ''
      ? String(rule.value_numeric)
      : (rule.value_text ?? (rule.value_list ?? []).join(', '))

  return `${subject} ${operator} ${value || '—'}`
}

function RiskRuleDialog({
  row,
  onClose,
  onSaved,
}: {
  row: RiskRuleRow | null
  onClose: () => void
  onSaved: () => void
}) {
  const { busy, errors, run } = useMutation()
  const [form, setForm] = useState({
    rule_key: row?.rule_key ?? '',
    name: row?.name ?? '',
    description: row?.description ?? '',
    risk_category: row?.risk_category ?? 'legal',
    severity: row?.severity ?? 'medium',
    subject: row?.subject ?? 'auto_renewal',
    operator: row?.operator ?? 'is_true',
    value_text: row?.value_text ?? '',
    value_numeric:
      row?.value_numeric === null || row?.value_numeric === undefined
        ? ''
        : String(row.value_numeric),
    score_weight: String(row?.score_weight ?? 10),
    recommendation: row?.recommendation ?? '',
    is_active: row?.is_active ?? true,
  })

  const unary = (UNARY_RISK_OPERATORS as readonly string[]).includes(form.operator)

  const submit = async () => {
    const body = {
      rule_key: form.rule_key,
      name: form.name,
      description: form.description || null,
      risk_category: form.risk_category,
      severity: form.severity,
      subject: form.subject,
      operator: form.operator,
      value_text: unary ? null : form.value_text || null,
      value_numeric: unary ? null : numberOrNull(form.value_numeric),
      value_list: row?.value_list ?? [],
      applies_to_types: row?.applies_to_types ?? [],
      score_weight: numberOrNull(form.score_weight) ?? 10,
      recommendation: form.recommendation || null,
      is_active: form.is_active,
    }

    const ok = await run(
      async () => {
        if (row) await api.put(`/risk-rules/${row.id}`, body)
        else await api.post('/settings/risk-rules', body)
      },
      row ? 'Risk rule updated' : 'Risk rule added',
    )
    if (ok) onSaved()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? `Edit ${row.name}` : 'New risk rule'}
      description="A rule looks at one thing about a contract and compares it one way. Anything more expressive belongs in a playbook."
      width={680}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            {row ? 'Save rule' : 'Add rule'}
          </Button>
        </>
      }
    >
      <div style={FORM_GRID}>
        <Input
          label="Name"
          required
          value={form.name}
          error={errors.name}
          onChange={(event) => setForm({ ...form, name: event.target.value })}
        />
        <Input
          label="Key"
          required
          value={form.rule_key}
          error={errors.rule_key}
          disabled={row !== null}
          onChange={(event) => setForm({ ...form, rule_key: event.target.value })}
          hint={row ? 'Findings already reference this key' : 'Lowercase, e.g. unlimited_liability'}
        />
        <Select
          label="Looks at"
          value={form.subject}
          error={errors.subject}
          onChange={(event) => setForm({ ...form, subject: event.target.value })}
          options={RISK_RULE_SUBJECTS.map((subject) => ({
            value: subject,
            label: humanise(subject),
          }))}
        />
        <Select
          label="Comparison"
          value={form.operator}
          error={errors.operator}
          onChange={(event) => setForm({ ...form, operator: event.target.value })}
          options={RISK_RULE_OPERATORS.map((operator) => ({
            value: operator,
            label: humanise(operator),
          }))}
        />
        {unary ? null : (
          <>
            <Input
              label="Compared with (text)"
              value={form.value_text}
              error={errors.value_text}
              onChange={(event) => setForm({ ...form, value_text: event.target.value })}
            />
            <Input
              label="Compared with (number)"
              type="number"
              value={form.value_numeric}
              error={errors.value_numeric}
              onChange={(event) => setForm({ ...form, value_numeric: event.target.value })}
            />
          </>
        )}
        <Select
          label="Category"
          value={form.risk_category}
          error={errors.risk_category}
          onChange={(event) => setForm({ ...form, risk_category: event.target.value })}
          options={RISK_CATEGORIES.map((category) => ({
            value: category,
            label: humanise(category),
          }))}
        />
        <Select
          label="Severity"
          value={form.severity}
          error={errors.severity}
          onChange={(event) => setForm({ ...form, severity: event.target.value })}
          options={RISK_SEVERITIES.map((severity) => ({
            value: severity,
            label: humanise(severity),
          }))}
        />
        <Input
          label="Score weight"
          type="number"
          min={0}
          max={100}
          value={form.score_weight}
          error={errors.score_weight}
          onChange={(event) => setForm({ ...form, score_weight: event.target.value })}
          hint="How much this finding moves the contract's risk score"
        />
        <FullWidth>
          <Textarea
            label="What to do about it"
            rows={2}
            value={form.recommendation}
            error={errors.recommendation}
            onChange={(event) => setForm({ ...form, recommendation: event.target.value })}
            hint="Shown with the finding, so a reviewer knows the company's position"
          />
        </FullWidth>
        <FullWidth>
          <Checkbox
            label="Active"
            checked={form.is_active}
            onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
          />
        </FullWidth>
      </div>
    </Modal>
  )
}

/* --- Playbooks ------------------------------------------------------------ */

function PlaybooksSection() {
  const resource = useApiResource<PlaybookSummary[]>(
    (signal) => api.get<PlaybookSummary[]>('/playbooks', undefined, signal),
    [],
  )
  const [selectedId, setSelectedId] = useState<number | null>(null)

  const playbooks = resource.data ?? []
  const activeId = selectedId ?? playbooks[0]?.id ?? null

  const rules = useApiResource<PlaybookRule[]>(
    (signal) => api.get<PlaybookRule[]>(`/playbooks/${activeId}/rules`, undefined, signal),
    [activeId],
    { enabled: activeId !== null },
  )

  const [editing, setEditing] = useState<PlaybookRule | 'new' | null>(null)
  const [deleting, setDeleting] = useState<PlaybookRule | null>(null)
  const remove = useMutation()

  const columns = useMemo<Column<PlaybookRule>[]>(
    () => [
      {
        key: 'label',
        header: 'Rule',
        render: (row) => (
          <div style={{ minWidth: 200 }}>
            <span style={{ fontWeight: 600 }}>{row.label}</span>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              <code>{row.rule_key}</code>
            </div>
          </div>
        ),
      },
      {
        key: 'rule_type',
        header: 'Type',
        hideBelow: 'sm',
        render: (row) => <Chip size="sm">{humanise(row.rule_type)}</Chip>,
      },
      {
        key: 'expected',
        header: 'Expected',
        hideBelow: 'md',
        render: (row) => (
          <span style={{ color: 'var(--color-text-secondary)' }}>
            {row.expected_value ??
              (row.expected_numeric !== null && row.expected_numeric !== undefined
                ? String(row.expected_numeric)
                : (row.expected_list ?? []).join(', ')) ??
              '—'}
          </span>
        ),
      },
      {
        key: 'severity',
        header: 'Severity',
        width: 118,
        render: (row) => <StatusChip status={row.severity} size="sm" />,
      },
      {
        key: 'active',
        header: 'Status',
        width: 100,
        render: (row) => <ActiveChip active={row.is_active} />,
      },
      {
        key: 'actions',
        header: '',
        srLabel: 'Row actions',
        width: 92,
        align: 'right',
        render: (row) => (
          <RowActions
            canWrite
            onEdit={() => setEditing(row)}
            onDelete={() => setDeleting(row)}
            editLabel={`Edit ${row.label}`}
            deleteLabel={`Delete ${row.label}`}
          />
        ),
      },
    ],
    [],
  )

  return (
    <Panel
      title="Playbooks"
      description="What this company is willing to agree to, stated as rules a machine can check. A deviation from a playbook is what turns “the counterparty changed the liability clause” into something a reviewer is told about."
      padded={false}
      action={
        activeId !== null ? (
          <Button variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New rule
          </Button>
        ) : null
      }
    >
      <Loaded resource={resource}>
        {(list) =>
          list.length === 0 ? (
            <EmptyState
              title="No playbooks yet"
              description="A playbook is created against a contract type, from the clause library. Once one exists, its rules are maintained here."
              action={
                <Link to="/clauses">
                  <Button variant="secondary">Open the clause library</Button>
                </Link>
              }
              compact
            />
          ) : (
            <>
              <div style={{ padding: '12px 16px', borderBottom: '1px solid rgb(var(--color-border))' }}>
                <div style={{ maxWidth: 320 }}>
                  <Select
                    label="Playbook"
                    value={String(activeId ?? '')}
                    onChange={(event) => setSelectedId(Number(event.target.value))}
                    options={list.map((playbook) => ({
                      value: String(playbook.id),
                      label: playbook.is_default ? `${playbook.name} (default)` : playbook.name,
                    }))}
                  />
                </div>
              </div>

              <Loaded resource={rules}>
                {(ruleRows) =>
                  ruleRows.length === 0 ? (
                    <EmptyState
                      title="This playbook has no rules"
                      description="A playbook with no rules never raises a deviation. Add the positions that matter — a mandatory clause, a cap you will not exceed, a governing law you will not accept."
                      action={
                        <Button variant="primary" onClick={() => setEditing('new')}>
                          Add the first rule
                        </Button>
                      }
                      compact
                    />
                  ) : (
                    <DataTable
                      columns={columns}
                      rows={ruleRows}
                      rowKey={(row) => row.id}
                      caption="Rules in the selected playbook"
                    />
                  )
                }
              </Loaded>
            </>
          )
        }
      </Loaded>

      {editing && activeId !== null ? (
        <PlaybookRuleDialog
          playbookId={activeId}
          row={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            rules.reload()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        busy={remove.busy}
        tone="danger"
        title="Delete this playbook rule?"
        confirmLabel="Delete rule"
        message={
          <>
            <strong>{deleting?.label}</strong> will stop being checked. Deviations already raised
            against it stay on their contracts.
          </>
        }
        onConfirm={() => {
          if (!deleting) return
          void remove
            .run(async () => {
              await api.delete(`/playbook-rules/${deleting.id}`)
            }, 'Playbook rule deleted')
            .then((ok) => {
              if (ok) {
                setDeleting(null)
                rules.reload()
              }
            })
        }}
      />
    </Panel>
  )
}

function PlaybookRuleDialog({
  playbookId,
  row,
  onClose,
  onSaved,
}: {
  playbookId: number
  row: PlaybookRule | null
  onClose: () => void
  onSaved: () => void
}) {
  const { busy, errors, run } = useMutation()
  const [form, setForm] = useState({
    rule_key: row?.rule_key ?? '',
    label: row?.label ?? '',
    description: row?.description ?? '',
    rule_type: row?.rule_type ?? 'mandatory_clause',
    expected_value: row?.expected_value ?? '',
    expected_numeric:
      row?.expected_numeric === null || row?.expected_numeric === undefined
        ? ''
        : String(row.expected_numeric),
    severity: row?.severity ?? 'medium',
    risk_category: row?.risk_category ?? 'legal',
    recommendation: row?.recommendation ?? '',
    sort_order: String(row?.sort_order ?? 100),
    is_active: row?.is_active ?? true,
  })

  const submit = async () => {
    const body = {
      rule_key: form.rule_key,
      label: form.label,
      description: form.description || null,
      rule_type: form.rule_type,
      expected_value: form.expected_value || null,
      expected_numeric: numberOrNull(form.expected_numeric),
      expected_list: row?.expected_list ?? [],
      category_id: row?.category_id ?? null,
      severity: form.severity,
      risk_category: form.risk_category,
      recommendation: form.recommendation || null,
      sort_order: numberOrNull(form.sort_order) ?? 100,
      is_active: form.is_active,
    }

    const ok = await run(
      async () => {
        if (row) await api.put(`/playbook-rules/${row.id}`, body)
        else await api.post(`/playbooks/${playbookId}/rules`, body)
      },
      row ? 'Playbook rule updated' : 'Playbook rule added',
    )
    if (ok) onSaved()
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={row ? `Edit ${row.label}` : 'New playbook rule'}
      width={660}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            {row ? 'Save rule' : 'Add rule'}
          </Button>
        </>
      }
    >
      <div style={FORM_GRID}>
        <Input
          label="Label"
          required
          value={form.label}
          error={errors.label}
          onChange={(event) => setForm({ ...form, label: event.target.value })}
        />
        <Input
          label="Key"
          required
          value={form.rule_key}
          error={errors.rule_key}
          disabled={row !== null}
          onChange={(event) => setForm({ ...form, rule_key: event.target.value })}
        />
        <Select
          label="Rule type"
          value={form.rule_type}
          error={errors.rule_type}
          onChange={(event) => setForm({ ...form, rule_type: event.target.value })}
          options={PLAYBOOK_RULE_TYPES.map((type) => ({ value: type, label: humanise(type) }))}
        />
        <Select
          label="Severity"
          value={form.severity}
          error={errors.severity}
          onChange={(event) => setForm({ ...form, severity: event.target.value })}
          options={RISK_SEVERITIES.map((severity) => ({
            value: severity,
            label: humanise(severity),
          }))}
        />
        <Input
          label="Expected value"
          value={form.expected_value}
          error={errors.expected_value}
          onChange={(event) => setForm({ ...form, expected_value: event.target.value })}
          hint="Wording or value the contract should hold"
        />
        <Input
          label="Expected number"
          type="number"
          value={form.expected_numeric}
          error={errors.expected_numeric}
          onChange={(event) => setForm({ ...form, expected_numeric: event.target.value })}
          hint="For a cap or a minimum"
        />
        <Select
          label="Risk category"
          value={form.risk_category}
          error={errors.risk_category}
          onChange={(event) => setForm({ ...form, risk_category: event.target.value })}
          options={RISK_CATEGORIES.map((category) => ({
            value: category,
            label: humanise(category),
          }))}
        />
        <Input
          label="Sort order"
          type="number"
          min={0}
          value={form.sort_order}
          error={errors.sort_order}
          onChange={(event) => setForm({ ...form, sort_order: event.target.value })}
        />
        <FullWidth>
          <Textarea
            label="Fallback position"
            rows={2}
            value={form.recommendation}
            error={errors.recommendation}
            onChange={(event) => setForm({ ...form, recommendation: event.target.value })}
            hint="What the negotiator may offer when the counterparty refuses"
          />
        </FullWidth>
        <FullWidth>
          <Checkbox
            label="Active"
            checked={form.is_active}
            onChange={(event) => setForm({ ...form, is_active: event.target.checked })}
          />
        </FullWidth>
      </div>
    </Modal>
  )
}

/* --- Roles & permissions -------------------------------------------------- */

function RolesSection() {
  const resource = useApiResource<RolesPayload>(
    (signal) => api.get<RolesPayload>('/settings/roles', undefined, signal),
    [],
  )
  const settings = useSettingsResource()
  const grant = useMutation()
  const defaultRole = useMutation()
  const [userUuid, setUserUuid] = useState('')
  const [roleSlug, setRoleSlug] = useState('')
  const [revoking, setRevoking] = useState<{ user_uuid: string; role_slug: string } | null>(null)
  const revoke = useMutation()

  const submitGrant = async () => {
    const ok = await grant.run(async () => {
      await api.post('/settings/roles/grant', { user_uuid: userUuid, role_slug: roleSlug })
    }, 'Role granted')
    if (ok) {
      setUserUuid('')
      resource.reload()
    }
  }

  return (
    <div style={{ display: 'grid', gap: 18 }}>
      <Panel
        title="Default role"
        description="What a company member gets in Contracts when nobody has granted them anything. A company that has not been configured should still be usable, without that meaning everyone can approve their own contracts."
      >
        <Loaded resource={settings} skeletonRows={1}>
          {(payload) => (
            <div style={{ display: 'flex', gap: 12, alignItems: 'end', flexWrap: 'wrap' }}>
              <div style={{ minWidth: 220 }}>
                <Select
                  label="Default role"
                  value={payload.settings.default_role}
                  error={defaultRole.errors.default_role}
                  onChange={(event) => {
                    const next = event.target.value
                    void defaultRole.run(async () => {
                      const updated = await api.put<SettingsPayload>('/settings', {
                        default_role: next,
                      })
                      settings.setData(updated)
                    }, 'Default role updated')
                  }}
                  options={(resource.data?.roles ?? []).map((role) => ({
                    value: role.slug,
                    label: role.label,
                  }))}
                />
              </div>
              {defaultRole.busy ? (
                <span style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>Saving…</span>
              ) : null}
            </div>
          )}
        </Loaded>
      </Panel>

      <Panel
        title="Who has which role"
        description="A role is granted per company. The same person can be Legal here and read-only in another company."
      >
        <Loaded resource={resource}>
          {(payload) => (
            <div style={{ display: 'grid', gap: 18 }}>
              <form
                onSubmit={(event) => {
                  event.preventDefault()
                  void submitGrant()
                }}
                style={{
                  display: 'grid',
                  gap: 12,
                  gridTemplateColumns: 'minmax(180px, 2fr) minmax(150px, 1fr) auto',
                  alignItems: 'end',
                }}
              >
                <Input
                  label="User"
                  value={userUuid}
                  error={grant.errors.user_uuid}
                  onChange={(event) => setUserUuid(event.target.value)}
                  hint="The person's AICOUNTLY user id"
                />
                <Select
                  label="Role"
                  value={roleSlug}
                  error={grant.errors.role_slug}
                  onChange={(event) => setRoleSlug(event.target.value)}
                  options={payload.roles.map((role) => ({ value: role.slug, label: role.label }))}
                  placeholder="Choose a role"
                />
                <Button
                  type="submit"
                  variant="primary"
                  loading={grant.busy}
                  disabled={userUuid.trim() === '' || roleSlug === ''}
                >
                  Grant role
                </Button>
              </form>

              {payload.grants.length === 0 ? (
                <EmptyState
                  title="Nobody has been granted a role"
                  description="Everyone in this company is working with the default role above. Grant a role to give someone more than that."
                  compact
                />
              ) : (
                <ul style={{ listStyle: 'none', display: 'grid', gap: 8 }}>
                  {payload.grants.map((entry) => (
                    <li
                      key={entry.user_uuid}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: 12,
                        flexWrap: 'wrap',
                        padding: '10px 12px',
                        border: '1px solid rgb(var(--color-border))',
                        borderRadius: 'var(--radius-md)',
                      }}
                    >
                      <div style={{ minWidth: 0 }}>
                        <code style={{ fontSize: 12 }}>{entry.user_uuid}</code>
                        {entry.granted_at ? (
                          <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                            Since {formatDate(entry.granted_at)}
                          </div>
                        ) : null}
                      </div>
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        {entry.roles.map((slug) => (
                          <span
                            key={slug}
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
                          >
                            <Chip size="sm" tone="primary">
                              {payload.roles.find((role) => role.slug === slug)?.label ??
                                humanise(slug)}
                            </Chip>
                            <button
                              type="button"
                              onClick={() =>
                                setRevoking({ user_uuid: entry.user_uuid, role_slug: slug })
                              }
                              aria-label={`Revoke ${slug} from ${entry.user_uuid}`}
                              style={{
                                display: 'inline-flex',
                                background: 'none',
                                border: 'none',
                                cursor: 'pointer',
                                color: 'var(--color-text-muted)',
                                padding: 2,
                                lineHeight: 0,
                              }}
                            >
                              <Trash2 size={12} aria-hidden />
                            </button>
                          </span>
                        ))}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          )}
        </Loaded>
      </Panel>

      <Panel
        title="What each role can do"
        description="Roles are fixed: they are enforced by the API on every request, so they cannot be edited from a browser."
      >
        <Loaded resource={resource}>
          {(payload) => (
            <ul style={{ listStyle: 'none', display: 'grid', gap: 12 }}>
              {payload.roles.map((role) => (
                <li
                  key={role.slug}
                  style={{
                    border: '1px solid rgb(var(--color-border))',
                    borderRadius: 'var(--radius-md)',
                    padding: 12,
                  }}
                >
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontWeight: 700, fontSize: 13.5 }}>{role.label}</span>
                    <Chip size="sm">{role.permissions.length} permissions</Chip>
                  </div>
                  <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4 }}>
                    {role.description}
                  </p>
                  <details style={{ marginTop: 8 }}>
                    <summary style={{ fontSize: 12, cursor: 'pointer', color: 'var(--color-text-muted)' }}>
                      Show permissions
                    </summary>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 5, marginTop: 8 }}>
                      {role.permissions.map((permission) => (
                        <Chip key={permission} size="sm">
                          {permission}
                        </Chip>
                      ))}
                    </div>
                  </details>
                </li>
              ))}
            </ul>
          )}
        </Loaded>
      </Panel>

      <ConfirmDialog
        open={revoking !== null}
        onClose={() => setRevoking(null)}
        busy={revoke.busy}
        tone="danger"
        title="Revoke this role?"
        confirmLabel="Revoke role"
        message={
          <>
            <code>{revoking?.user_uuid}</code> will lose everything the{' '}
            <strong>{revoking ? humanise(revoking.role_slug) : ''}</strong> role granted them in this
            company.
          </>
        }
        onConfirm={() => {
          if (!revoking) return
          void revoke
            .run(async () => {
              await api.post('/settings/roles/revoke', revoking)
            }, 'Role revoked')
            .then((ok) => {
              if (ok) {
                setRevoking(null)
                resource.reload()
              }
            })
        }}
      />
    </div>
  )
}

/* --- Library links -------------------------------------------------------- */

function LinkSection({
  title,
  description,
  to,
  cta,
}: {
  title: string
  description: string
  to: string
  cta: string
}) {
  return (
    <Panel title={title} description={description}>
      <Link to={to}>
        <Button variant="primary" icon={<ArrowUpRight size={14} />}>
          {cta}
        </Button>
      </Link>
    </Panel>
  )
}

/* --- Platform status ------------------------------------------------------ */

function useIntegrations() {
  return useApiResource<IntegrationsPayload>(
    (signal) => api.get<IntegrationsPayload>('/settings/integrations', undefined, signal),
    [],
  )
}

function StatusRow({
  name,
  status,
  detail,
}: {
  name: string
  status: IntegrationStatus | undefined
  detail?: string
}) {
  const configured = status?.configured ?? false

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'space-between',
        gap: 12,
        flexWrap: 'wrap',
        padding: '12px 0',
        borderTop: '1px solid var(--color-border-light)',
      }}
    >
      <div style={{ minWidth: 0 }}>
        <div style={{ fontWeight: 600, fontSize: 13.5 }}>{name}</div>
        <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3, maxWidth: 560 }}>
          {status?.detail ?? detail ?? 'No detail reported.'}
        </p>
      </div>
      <Chip tone={configured ? 'success' : 'warning'}>
        {configured ? 'Configured' : 'Not configured'}
      </Chip>
    </div>
  )
}

function AiSection() {
  const { can } = useSession()
  const canWrite = can(PERMISSION.SETTINGS_MANAGE)
  const status = useApiResource<AiStatus>(
    (signal) => api.get<AiStatus>('/ai/status', undefined, signal),
    [],
  )
  const settings = useApiResource<SettingsPayload>(
    (signal) => api.get<SettingsPayload>('/settings', undefined, signal),
    [],
    { enabled: canWrite },
  )
  const toggle = useMutation()

  const setFlag = (patch: Partial<ContractSettings>) => {
    void toggle.run(async () => {
      const updated = await api.put<SettingsPayload>('/settings', patch)
      settings.setData(updated)
    }, 'AI settings saved')
  }

  return (
    <div style={{ display: 'grid', gap: 18 }}>
      <Panel
        title="AI provider"
        description="Contracts does not hold provider credentials. A key is issued and rotated in AICOUNTLY Console, and this product asks Console for one when it needs to run a model — so there is nothing to enter here, and nothing here to leak."
      >
        <Loaded resource={status} skeletonRows={2}>
          {(payload) => (
            <div style={{ display: 'grid', gap: 12 }}>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                <Chip tone={payload.configured ? 'success' : 'warning'}>
                  {payload.configured ? 'Configured' : 'Not configured'}
                </Chip>
                {payload.provider ? <Chip>{humanise(payload.provider)}</Chip> : null}
                {payload.model ? <Chip>{payload.model}</Chip> : null}
                {payload.source ? <Chip size="sm">via {humanise(payload.source)}</Chip> : null}
              </div>

              <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
                {payload.message ??
                  (payload.configured
                    ? 'AI features are available. Every answer is grounded in the contract it was asked about and carries a citation back to it.'
                    : 'No provider is configured for this environment, so AI features report themselves as unavailable rather than guessing. An administrator can configure one in Console.')}
              </p>

              {payload.disclaimer ? (
                <p
                  style={{
                    fontSize: 12,
                    color: 'var(--color-text-muted)',
                    borderLeft: '3px solid rgb(var(--color-border-strong))',
                    paddingLeft: 10,
                    lineHeight: 1.6,
                  }}
                >
                  {payload.disclaimer}
                </p>
              ) : null}

              <div>
                <a
                  href="https://console.aicountly.com"
                  target="_blank"
                  rel="noreferrer"
                  style={{ fontSize: 13, fontWeight: 600, display: 'inline-flex', gap: 5 }}
                >
                  Open Console
                  <ArrowUpRight size={14} aria-hidden />
                </a>
              </div>
            </div>
          )}
        </Loaded>
      </Panel>

      {canWrite ? (
        <Panel
          title="What AI may do here"
          description="These switches are this company's, not the provider's. Turning one off stops the work being scheduled at all — it does not merely hide the result."
        >
          <Loaded resource={settings} skeletonRows={3}>
            {(payload) => (
              <div style={{ display: 'grid', gap: 10 }}>
                <Checkbox
                  label="AI features enabled"
                  hint="Turns off summarisation, extraction, risk reading and the contract Q&A"
                  checked={payload.settings.ai_enabled}
                  onChange={(event) => setFlag({ ai_enabled: event.target.checked })}
                />
                <Checkbox
                  label="Extract fields from uploaded documents"
                  hint="Extractions always go to the review queue before they touch a contract"
                  checked={payload.settings.ai_auto_extract}
                  disabled={!payload.settings.ai_enabled}
                  onChange={(event) => setFlag({ ai_auto_extract: event.target.checked })}
                />
                <Checkbox
                  label="Assess risk automatically"
                  hint="Runs the rules and the AI reading when a document is added"
                  checked={payload.settings.ai_auto_risk}
                  disabled={!payload.settings.ai_enabled}
                  onChange={(event) => setFlag({ ai_auto_risk: event.target.checked })}
                />
              </div>
            )}
          </Loaded>
        </Panel>
      ) : null}
    </div>
  )
}

function SignaturesSection() {
  const resource = useIntegrations()

  return (
    <Panel
      title="Signature providers"
      description="How a contract is sent out to be signed. A provider is configured for the whole environment rather than per company, so this panel reports what is available rather than offering a choice."
    >
      <Loaded resource={resource} skeletonRows={2}>
        {(payload) => (
          <div>
            <StatusRow name="Signature provider" status={payload.signature} />
            <p
              style={{
                fontSize: 12.5,
                color: 'var(--color-text-secondary)',
                marginTop: 12,
                lineHeight: 1.6,
              }}
            >
              With no provider configured, a contract can still be recorded as executed: upload the
              signed copy against the contract and mark that version executed. The signing status,
              the execution date and the signatories are captured either way.
            </p>
          </div>
        )}
      </Loaded>
    </Panel>
  )
}

function IntegrationsSection() {
  const resource = useIntegrations()

  return (
    <Panel
      title="Integrations"
      description="What Contracts depends on, and whether each dependency is reachable from this environment. Anything not configured reports itself as unavailable rather than falling back to a stub."
    >
      <Loaded resource={resource} skeletonRows={5}>
        {(payload) => (
          <div>
            <StatusRow name="Manage Account" status={payload.manage} />
            <StatusRow name="Contacts" status={payload.contacts} />
            <StatusRow name="Drive" status={payload.drive} />
            <StatusRow name="Console" status={payload.console} />
            <StatusRow name="Signature provider" status={payload.signature} />
            <StatusRow name="Email" status={payload.email} />
          </div>
        )}
      </Loaded>
    </Panel>
  )
}

function NotificationsSection() {
  const resource = useIntegrations()

  return (
    <Panel
      title="Notifications"
      description="Contracts writes every reminder in-app, always. Email is a second channel on top of that, and it either works or says so — a mail reported as sent that never left the server turns “nobody told me” into an argument about logs."
    >
      <Loaded resource={resource} skeletonRows={2}>
        {(payload) => (
          <div style={{ display: 'grid', gap: 14 }}>
            <div>
              <StatusRow
                name="In-app"
                status={{ configured: true, detail: 'Every reminder is written to the recipient’s inbox in Contracts.' }}
              />
              <StatusRow name="Email" status={payload.email} />
            </div>

            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <Link to="/notifications">
                <Button variant="secondary">Open my notifications</Button>
              </Link>
              <Link to="/settings/reminders">
                <Button variant="ghost">Change reminder timing</Button>
              </Link>
            </div>
          </div>
        )}
      </Loaded>
    </Panel>
  )
}
