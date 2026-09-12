import { useMemo, useState } from 'react'
import { CheckCircle2, FileStack, Pencil, Plus, Trash2, X } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  ConfirmDialog,
  DateInput,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  Select,
  Skeleton,
  StatusChip,
  Textarea,
} from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract } from '../../../types/contracts'
import { formatDate, formatDateTime, formatMoney, humanise } from '../../../utils/format'

/**
 * The amendment register, and what the contract actually says today.
 *
 * The original agreement is never rewritten: each amendment is a record of what
 * changed and when it took effect, and the effective position is those records
 * overlaid in order. Showing which amendment supplied each field is the point —
 * "the notice period is 90 days" is only useful with "since amendment 2".
 */

type AmendmentStatus =
  | 'draft'
  | 'under_review'
  | 'awaiting_approval'
  | 'awaiting_signature'
  | 'executed'
  | 'cancelled'

interface FieldChange {
  from?: unknown
  to?: unknown
}

interface Amendment {
  id: number
  contract_id: number
  amendment_no: number
  title: string
  description: string | null
  effective_date: string | null
  execution_date: string | null
  status: AmendmentStatus
  affected_fields: Record<string, FieldChange>
  affected_clauses: unknown[]
  affected_commercials: Record<string, unknown>
  affected_obligations: unknown[]
  applied_at: string | null
  applied_by: string | null
  created_at: string
  updated_at: string
}

interface PositionOverride {
  amendment_id: number
  amendment_no: number
  title: string
  effective_date: string | null
  from?: unknown
  to?: unknown
}

/**
 * `GET /contracts/{id}/effective-position`.
 *
 * The API contract summarises this as `{fields, sources}` while the service
 * returns `{base, current, overrides}`; both spellings are accepted here so the
 * tab reads correctly against either.
 */
interface EffectivePosition {
  base?: Record<string, unknown>
  current?: Record<string, unknown>
  overrides?: Record<string, PositionOverride>
  fields?: Record<string, unknown>
  sources?: Record<string, PositionOverride>
}

/** The fields an amendment is allowed to change, in the order they read best. */
const AMENDABLE_FIELDS = [
  'title',
  'counterparty_name',
  'description',
  'effective_date',
  'commencement_date',
  'expiry_date',
  'renewal_type',
  'renewal_frequency',
  'auto_renewal',
  'notice_period_days',
  'currency',
  'total_value',
  'recurring_value',
  'payment_frequency',
  'billing_frequency',
  'commercial_summary',
  'governing_law',
  'jurisdiction',
  'notes',
] as const

type AmendableField = (typeof AMENDABLE_FIELDS)[number]

const DATE_FIELDS = new Set<string>(['effective_date', 'commencement_date', 'expiry_date'])
const MONEY_FIELDS = new Set<string>(['total_value', 'recurring_value'])
const NUMBER_FIELDS = new Set<string>(['notice_period_days'])
const BOOLEAN_FIELDS = new Set<string>(['auto_renewal'])

const EDITABLE_STATUSES: AmendmentStatus[] = ['draft', 'under_review', 'awaiting_approval', 'awaiting_signature']

const STATUS_OPTIONS = EDITABLE_STATUSES.map((status) => ({ value: status, label: humanise(status) }))

function displayValue(field: string, value: unknown, currency: string): string {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (BOOLEAN_FIELDS.has(field)) return value === true || value === 'true' ? 'Yes' : 'No'
  if (DATE_FIELDS.has(field)) return formatDate(String(value))
  if (MONEY_FIELDS.has(field)) return formatMoney(String(value), currency)
  if (NUMBER_FIELDS.has(field)) return `${value} days`
  return String(value)
}

export function AmendmentsTab({
  contractId,
  contract,
  onChanged,
}: {
  contractId: number
  contract: Contract
  onChanged: () => void
}) {
  const { can } = useSession()
  const toast = useToast()
  const canManage = can(PERMISSION.AMENDMENT_MANAGE)

  const resource = useApiResource<{ amendments: Amendment[]; position: EffectivePosition }>(
    async (signal) => {
      const [amendments, position] = await Promise.all([
        api.get<Amendment[]>(`/contracts/${contractId}/amendments`, undefined, signal),
        api.get<EffectivePosition>(`/contracts/${contractId}/effective-position`, undefined, signal),
      ])
      return { amendments: amendments ?? [], position: position ?? {} }
    },
    [contractId],
  )

  const [editing, setEditing] = useState<Amendment | null | 'new'>(null)
  const [applying, setApplying] = useState<Amendment | null>(null)
  const [deleting, setDeleting] = useState<Amendment | null>(null)
  const [busy, setBusy] = useState(false)

  const amendments = resource.data?.amendments ?? []
  const position = resource.data?.position ?? {}

  const fields = position.current ?? position.fields ?? {}
  const overrides = position.overrides ?? position.sources ?? {}
  const currency = String(fields.currency ?? contract.currency)

  const rows = useMemo(
    () =>
      AMENDABLE_FIELDS.filter((field) => {
        const value = fields[field]
        return overrides[field] !== undefined || (value !== null && value !== undefined && value !== '')
      }).map((field) => ({ field, value: fields[field], source: overrides[field] })),
    [fields, overrides],
  )

  const refresh = () => {
    resource.reload()
    onChanged()
  }

  const apply = async () => {
    if (!applying) return
    setBusy(true)
    try {
      await api.post(`/amendments/${applying.id}/apply`)
      toast.success(`Amendment ${applying.amendment_no} applied`, 'The contract now reflects these terms.')
      setApplying(null)
      refresh()
    } catch (err) {
      toast.error('Could not apply the amendment', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(false)
    }
  }

  const remove = async () => {
    if (!deleting) return
    setBusy(true)
    try {
      await api.delete(`/amendments/${deleting.id}`)
      toast.success('Amendment deleted', deleting.title)
      setDeleting(null)
      refresh()
    } catch (err) {
      toast.error('Could not delete the amendment', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(false)
    }
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 16 }}>
        <Card>
          <Skeleton width="35%" height={14} />
          <div style={{ marginTop: 16, display: 'grid', gap: 12 }}>
            {[0, 1, 2].map((row) => (
              <Skeleton key={row} height={52} radius={10} />
            ))}
          </div>
        </Card>
      </div>
    )
  }

  if (resource.error) {
    return <ErrorState title="Could not load amendments" detail={resource.error.message} onRetry={resource.reload} />
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <p aria-live="polite" style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
          {amendments.length === 0
            ? 'No amendments — the contract stands as originally agreed'
            : `${amendments.length} amendment${amendments.length === 1 ? '' : 's'} · ${
                amendments.filter((amendment) => amendment.status === 'executed').length
              } in force`}
        </p>
        {canManage ? (
          <Button size="sm" variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New amendment
          </Button>
        ) : null}
      </header>

      <Card>
        <CardHeader
          level={3}
          title="Amendment chain"
          description="Each amendment hangs off the master contract in the order it takes effect."
        />

        <ol style={{ listStyle: 'none', display: 'grid', gap: 0 }}>
          <ChainNode
            title={`Master contract · ${contract.contract_number}`}
            subtitle={`Effective ${formatDate(contract.effective_date)}`}
            tone="primary"
            last={amendments.length === 0}
          >
            <StatusChip status={contract.status} size="sm" />
          </ChainNode>

          {amendments.map((amendment, index) => (
            <ChainNode
              key={amendment.id}
              title={`Amendment ${amendment.amendment_no} · ${amendment.title}`}
              subtitle={[
                amendment.effective_date ? `Effective ${formatDate(amendment.effective_date)}` : 'No effective date yet',
                amendment.applied_at ? `applied ${formatDateTime(amendment.applied_at)}` : null,
              ]
                .filter(Boolean)
                .join(' · ')}
              tone={amendment.status === 'executed' ? 'success' : amendment.status === 'cancelled' ? 'muted' : 'neutral'}
              last={index === amendments.length - 1}
            >
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                <StatusChip status={amendment.status} size="sm" />
                {Object.keys(amendment.affected_fields ?? {}).length > 0 ? (
                  <Chip tone="info" size="sm">
                    {Object.keys(amendment.affected_fields).length} field
                    {Object.keys(amendment.affected_fields).length === 1 ? '' : 's'} changed
                  </Chip>
                ) : null}
              </div>

              {amendment.description ? (
                <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 8, lineHeight: 1.6 }}>
                  {amendment.description}
                </p>
              ) : null}

              {Object.keys(amendment.affected_fields ?? {}).length > 0 ? (
                <ul style={{ listStyle: 'none', display: 'grid', gap: 4, marginTop: 9 }}>
                  {Object.entries(amendment.affected_fields).map(([field, change]) => (
                    <li key={field} style={{ fontSize: 12.5, color: 'var(--color-text-secondary)' }}>
                      <strong style={{ color: 'var(--color-text)' }}>{humanise(field)}: </strong>
                      <span style={{ textDecoration: 'line-through', opacity: 0.7 }}>
                        {displayValue(field, change.from, currency)}
                      </span>{' '}
                      → {displayValue(field, change.to, currency)}
                    </li>
                  ))}
                </ul>
              ) : null}

              {canManage ? (
                <div style={{ display: 'flex', gap: 7, marginTop: 11, flexWrap: 'wrap' }}>
                  {amendment.status !== 'executed' && amendment.status !== 'cancelled' ? (
                    <Button
                      size="sm"
                      variant="secondary"
                      icon={<CheckCircle2 size={13} />}
                      onClick={() => setApplying(amendment)}
                    >
                      Apply to contract
                    </Button>
                  ) : null}
                  {EDITABLE_STATUSES.includes(amendment.status) ? (
                    <>
                      <Button size="sm" variant="ghost" icon={<Pencil size={13} />} onClick={() => setEditing(amendment)}>
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        icon={<Trash2 size={13} />}
                        aria-label={`Delete amendment ${amendment.amendment_no}`}
                        onClick={() => setDeleting(amendment)}
                      />
                    </>
                  ) : null}
                </div>
              ) : null}
            </ChainNode>
          ))}
        </ol>

        {amendments.length === 0 ? (
          <EmptyState
            compact
            icon={<FileStack size={19} />}
            title="Nothing has been amended"
            description="An amendment records what changed, when it took effect and which clause it touched — without rewriting the original agreement."
            action={
              canManage ? (
                <Button variant="secondary" icon={<Plus size={15} />} onClick={() => setEditing('new')}>
                  Draft an amendment
                </Button>
              ) : undefined
            }
          />
        ) : null}
      </Card>

      <Card padded={false}>
        <div style={{ padding: '16px 18px 8px' }}>
          <CardHeader
            level={3}
            title="Current effective position"
            description="What the contract says today, and which amendment made it say so."
          />
        </div>

        {rows.length === 0 ? (
          <EmptyState compact title="Nothing recorded yet" description="Add contract terms and they will appear here with their source." />
        ) : (
          <div className="ct-scroll-x">
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13, minWidth: 560 }}>
              <caption className="ct-sr-only">
                Each contract term as it stands today and the amendment that supplied it
              </caption>
              <thead>
                <tr>
                  {['Term', 'Current position', 'Source'].map((heading) => (
                    <th
                      key={heading}
                      scope="col"
                      style={{
                        textAlign: 'left',
                        padding: '9px 18px',
                        fontSize: 11.5,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '.02em',
                        color: 'var(--color-text-muted)',
                        borderBottom: '1px solid rgb(var(--color-border))',
                      }}
                    >
                      {heading}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map(({ field, value, source }) => (
                  <tr key={field} style={{ borderBottom: '1px solid var(--color-border-light)' }}>
                    <th
                      scope="row"
                      style={{ textAlign: 'left', padding: '10px 18px', fontWeight: 600, whiteSpace: 'nowrap' }}
                    >
                      {humanise(field)}
                    </th>
                    <td style={{ padding: '10px 18px' }}>{displayValue(field, value, currency)}</td>
                    <td style={{ padding: '10px 18px' }}>
                      {source ? (
                        <div style={{ display: 'grid', gap: 3 }}>
                          <Chip tone="info" size="sm">
                            Amendment {source.amendment_no}
                          </Chip>
                          <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                            {source.title}
                            {source.effective_date ? ` · from ${formatDate(source.effective_date)}` : ''}
                          </span>
                          <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                            was {displayValue(field, source.from, currency)}
                          </span>
                        </div>
                      ) : (
                        <span style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>Master contract</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {editing !== null ? (
        <AmendmentFormModal
          contractId={contractId}
          currency={currency}
          currentFields={fields}
          amendment={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={applying !== null}
        onClose={() => setApplying(null)}
        onConfirm={() => void apply()}
        busy={busy}
        title="Apply this amendment?"
        confirmLabel="Apply"
        message={
          <>
            The terms in <strong>{applying?.title}</strong> are written onto the contract and the
            amendment is marked executed. The record of what it changed is kept, so the earlier
            position stays readable.
          </>
        }
      />

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onConfirm={() => void remove()}
        busy={busy}
        tone="danger"
        title="Delete this amendment?"
        confirmLabel="Delete"
        message={
          <>
            <strong>{deleting?.title}</strong> has not been applied, so nothing on the contract
            changes. The draft itself is removed.
          </>
        }
      />
    </div>
  )
}

function ChainNode({
  title,
  subtitle,
  tone,
  last,
  children,
}: {
  title: string
  subtitle: string
  tone: 'primary' | 'success' | 'neutral' | 'muted'
  last: boolean
  children: React.ReactNode
}) {
  const dot = {
    primary: 'rgb(var(--color-primary))',
    success: 'var(--color-success)',
    neutral: 'rgb(var(--color-border-strong))',
    muted: 'var(--color-neutral)',
  }[tone]

  return (
    <li style={{ display: 'grid', gridTemplateColumns: '16px 1fr', gap: 14, paddingBottom: last ? 0 : 18 }}>
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
        <span
          aria-hidden
          style={{ width: 11, height: 11, borderRadius: '50%', background: dot, marginTop: 5, flexShrink: 0 }}
        />
        {last ? null : (
          <span aria-hidden style={{ flex: 1, width: 2, background: 'rgb(var(--color-border))', marginTop: 6 }} />
        )}
      </div>
      <div style={{ minWidth: 0 }}>
        <h4 style={{ fontSize: 13.5, fontWeight: 700 }}>{title}</h4>
        <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', margin: '2px 0 8px' }}>{subtitle}</p>
        {children}
      </div>
    </li>
  )
}

function AmendmentFormModal({
  contractId,
  currency,
  currentFields,
  amendment,
  onClose,
  onSaved,
}: {
  contractId: number
  currency: string
  currentFields: Record<string, unknown>
  amendment: Amendment | null
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const [form, setForm] = useState({
    title: amendment?.title ?? '',
    description: amendment?.description ?? '',
    effective_date: amendment?.effective_date ?? '',
    execution_date: amendment?.execution_date ?? '',
    status: (amendment?.status ?? 'draft') as AmendmentStatus,
  })

  const [changes, setChanges] = useState<{ field: string; value: string }[]>(() => {
    const existing = Object.entries(amendment?.affected_fields ?? {})
    return existing.length > 0
      ? existing.map(([field, change]) => ({ field, value: change.to === null || change.to === undefined ? '' : String(change.to) }))
      : [{ field: '', value: '' }]
  })

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((current) => ({ ...current, [key]: value }))

  const setChange = (index: number, patch: Partial<{ field: string; value: string }>) =>
    setChanges((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)))

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      const affected: Record<string, { to: unknown }> = {}
      for (const change of changes) {
        if (!change.field) continue
        affected[change.field] = {
          to: BOOLEAN_FIELDS.has(change.field)
            ? change.value === 'true'
            : NUMBER_FIELDS.has(change.field)
              ? Number(change.value)
              : change.value,
        }
      }

      const body = {
        title: form.title.trim(),
        description: form.description.trim() || null,
        effective_date: form.effective_date || null,
        execution_date: form.execution_date || null,
        status: form.status,
        // The server stamps the previous value onto each change, so the form
        // only ever states where the term is going.
        affected_fields: affected,
      }

      if (amendment) {
        await api.put(`/amendments/${amendment.id}`, body)
        toast.success('Amendment updated', body.title)
      } else {
        await api.post(`/contracts/${contractId}/amendments`, body)
        toast.success('Amendment drafted', body.title)
      }
      onSaved()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the amendment', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={amendment ? `Edit amendment ${amendment.amendment_no}` : 'Draft an amendment'}
      description="Record what changes. Nothing is written onto the contract until the amendment is applied."
      width={660}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            {amendment ? 'Save changes' : 'Create amendment'}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <Input
          label="What this amendment does"
          required
          value={form.title}
          error={errors.title}
          placeholder="Extend the term to 31 March 2028"
          onChange={(event) => set('title', event.target.value)}
        />
        <Textarea
          label="Detail"
          rows={3}
          value={form.description}
          error={errors.description}
          onChange={(event) => set('description', event.target.value)}
        />

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 14 }}>
          <DateInput
            label="Effective from"
            value={form.effective_date}
            error={errors.effective_date}
            hint="Required before it can be applied."
            onChange={(event) => set('effective_date', event.target.value)}
          />
          <DateInput
            label="Signed on"
            value={form.execution_date}
            error={errors.execution_date}
            onChange={(event) => set('execution_date', event.target.value)}
          />
          <Select
            label="Status"
            value={form.status}
            options={STATUS_OPTIONS}
            error={errors.status}
            hint="Executed is reached by applying it."
            onChange={(event) => set('status', event.target.value as AmendmentStatus)}
          />
        </div>

        <fieldset style={{ border: 'none' }}>
          <legend style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--color-text-secondary)', marginBottom: 8 }}>
            Terms this amendment changes
          </legend>
          {errors.affected_fields ? (
            <p role="alert" style={{ fontSize: 12, color: 'var(--color-danger)', marginBottom: 8 }}>
              {errors.affected_fields}
            </p>
          ) : null}

          <div style={{ display: 'grid', gap: 10 }}>
            {changes.map((change, index) => (
              <div
                key={index}
                style={{ display: 'grid', gridTemplateColumns: 'minmax(150px, 1fr) minmax(150px, 1fr) auto', gap: 10, alignItems: 'end' }}
              >
                <Select
                  label="Term"
                  value={change.field}
                  placeholder="Choose a term"
                  options={AMENDABLE_FIELDS.map((field: AmendableField) => ({ value: field, label: humanise(field) }))}
                  onChange={(event) => setChange(index, { field: event.target.value, value: '' })}
                />

                {BOOLEAN_FIELDS.has(change.field) ? (
                  <Select
                    label="New value"
                    value={change.value}
                    options={[
                      { value: 'true', label: 'Yes' },
                      { value: 'false', label: 'No' },
                    ]}
                    placeholder="Choose"
                    onChange={(event) => setChange(index, { value: event.target.value })}
                  />
                ) : DATE_FIELDS.has(change.field) ? (
                  <DateInput
                    label="New value"
                    value={change.value}
                    onChange={(event) => setChange(index, { value: event.target.value })}
                  />
                ) : (
                  <Input
                    label="New value"
                    value={change.value}
                    hint={
                      change.field
                        ? `Currently ${displayValue(change.field, currentFields[change.field], currency)}`
                        : undefined
                    }
                    onChange={(event) => setChange(index, { value: event.target.value })}
                  />
                )}

                <Button
                  variant="ghost"
                  icon={<X size={14} />}
                  aria-label="Remove this change"
                  onClick={() => setChanges((current) => current.filter((_, i) => i !== index))}
                />
              </div>
            ))}
          </div>

          <Button
            size="sm"
            variant="secondary"
            icon={<Plus size={13} />}
            style={{ marginTop: 10 }}
            onClick={() => setChanges((current) => [...current, { field: '', value: '' }])}
          >
            Add another change
          </Button>
        </fieldset>
      </div>
    </Modal>
  )
}
