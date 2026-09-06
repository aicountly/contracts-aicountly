import { useMemo, useState } from 'react'
import {
  CalendarCheck,
  CalendarClock,
  ChevronDown,
  ChevronRight,
  ListChecks,
  Paperclip,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
} from 'lucide-react'

import {
  Button,
  Card,
  Checkbox,
  Chip,
  ConfirmDialog,
  DateInput,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  MoneyInput,
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
import type { Contract, Paged } from '../../../types/contracts'
import { formatDate, formatMoney, formatRelativeDays, humanise } from '../../../utils/format'

/**
 * What this contract obliges someone to do, and whether they have done it.
 *
 * The obligation is the rule; an occurrence is one instance of it falling due.
 * Both are shown, because "submit a quarterly SLA report" is a commitment and
 * "the report due on 31 March" is the thing somebody has to act on this week.
 */

type OccurrenceStatus =
  | 'upcoming'
  | 'due'
  | 'overdue'
  | 'completed'
  | 'waived'
  | 'not_applicable'
  | 'disputed'

type Frequency =
  | 'one_time'
  | 'daily'
  | 'weekly'
  | 'fortnightly'
  | 'monthly'
  | 'quarterly'
  | 'half_yearly'
  | 'annual'
  | 'custom'

interface Obligation {
  id: number
  contract_id: number
  clause_id: number | null
  obligation_type: string
  title: string
  description: string | null
  responsible_party: 'company' | 'counterparty' | 'both'
  owner_uuid: string | null
  frequency: Frequency
  custom_interval_days: number | null
  start_date: string | null
  end_date: string | null
  first_due_date: string | null
  grace_period_days: number
  amount: string | null
  currency: string | null
  evidence_required: boolean
  reminder_days: string
  status: OccurrenceStatus
  is_ai_extracted: boolean
  is_active: boolean
  next_due_date: string | null
  days_to_next_due: number | null
  occurrence_count: number
  overdue_count: number
  completed_count: number
}

interface ObligationOccurrence {
  id: number
  obligation_id: number
  contract_id: number
  sequence_no: number
  due_date: string
  grace_until: string | null
  status: OccurrenceStatus
  completed_at: string | null
  completion_note: string | null
  amount: string | null
  days_to_due: number | null
  is_overdue: boolean
  evidence_required?: boolean
  currency?: string | null
}

interface ContractDocumentOption {
  id: number
  title?: string | null
  file_name?: string | null
  doc_kind?: string | null
}

const FREQUENCY_WORDS: Record<Exclude<Frequency, 'custom'>, string> = {
  one_time: 'One time',
  daily: 'Daily',
  weekly: 'Weekly',
  fortnightly: 'Every two weeks',
  monthly: 'Monthly',
  quarterly: 'Quarterly',
  half_yearly: 'Every six months',
  annual: 'Annually',
}

const FREQUENCY_OPTIONS: { value: Frequency; label: string }[] = [
  { value: 'one_time', label: 'One time' },
  { value: 'daily', label: 'Daily' },
  { value: 'weekly', label: 'Weekly' },
  { value: 'fortnightly', label: 'Every two weeks' },
  { value: 'monthly', label: 'Monthly' },
  { value: 'quarterly', label: 'Quarterly' },
  { value: 'half_yearly', label: 'Every six months' },
  { value: 'annual', label: 'Annually' },
  { value: 'custom', label: 'Custom interval' },
]

const RESPONSIBLE_OPTIONS = [
  { value: 'company', label: 'Us' },
  { value: 'counterparty', label: 'The counterparty' },
  { value: 'both', label: 'Both parties' },
]

/**
 * The recurrence in words.
 *
 * A frequency code is meaningless to the person who has to do the thing:
 * "Quarterly, next due 31 Mar 2027" is the sentence they need, and it is one
 * function so every screen phrases it identically.
 */
export function describeRecurrence(
  obligation: Pick<Obligation, 'frequency' | 'custom_interval_days' | 'next_due_date' | 'first_due_date' | 'end_date'>,
): string {
  const base =
    obligation.frequency === 'custom'
      ? obligation.custom_interval_days
        ? `Every ${obligation.custom_interval_days} days`
        : 'Custom interval'
      : (FREQUENCY_WORDS[obligation.frequency] ?? humanise(obligation.frequency))

  if (obligation.next_due_date) {
    return `${base}, next due ${formatDate(obligation.next_due_date)}`
  }
  if (obligation.frequency === 'one_time' && obligation.first_due_date) {
    return `${base}, due ${formatDate(obligation.first_due_date)}`
  }
  if (obligation.end_date) {
    return `${base}, ended ${formatDate(obligation.end_date)}`
  }

  return `${base}, nothing outstanding`
}

export function ObligationsTab({
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
  const canManage = can(PERMISSION.OBLIGATION_MANAGE)

  const resource = useApiResource<{ obligations: Obligation[]; occurrences: ObligationOccurrence[] }>(
    async (signal) => {
      const [obligations, occurrences] = await Promise.all([
        api.get<Obligation[]>(`/contracts/${contractId}/obligations`, undefined, signal),
        api.get<Paged<ObligationOccurrence>>(
          '/obligations',
          { contract_id: contractId, per_page: 100 },
          signal,
        ),
      ])
      return { obligations: obligations ?? [], occurrences: occurrences?.items ?? [] }
    },
    [contractId],
  )

  const [editing, setEditing] = useState<Obligation | null | 'new'>(null)
  const [completing, setCompleting] = useState<ObligationOccurrence | null>(null)
  const [statusFor, setStatusFor] = useState<ObligationOccurrence | null>(null)
  const [deleting, setDeleting] = useState<Obligation | null>(null)
  const [busy, setBusy] = useState(false)

  const obligations = resource.data?.obligations ?? []
  const occurrences = resource.data?.occurrences ?? []

  const occurrencesByObligation = useMemo(() => {
    const map = new Map<number, ObligationOccurrence[]>()
    for (const occurrence of occurrences) {
      const bucket = map.get(occurrence.obligation_id) ?? []
      bucket.push(occurrence)
      map.set(occurrence.obligation_id, bucket)
    }
    for (const bucket of map.values()) {
      bucket.sort((a, b) => a.due_date.localeCompare(b.due_date))
    }
    return map
  }, [occurrences])

  const overdue = occurrences.filter((occurrence) => occurrence.is_overdue).length

  const refresh = () => {
    resource.reload()
    onChanged()
  }

  const remove = async () => {
    if (!deleting) return
    setBusy(true)
    try {
      await api.delete(`/obligations/${deleting.id}`)
      toast.success('Obligation removed', deleting.title)
      setDeleting(null)
      refresh()
    } catch (err) {
      toast.error('Could not remove that obligation', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(false)
    }
  }

  const generate = async (obligation: Obligation) => {
    setBusy(true)
    try {
      const result = await api.post<{ generated: number }>(`/obligations/${obligation.id}/generate`)
      toast.success(
        result.generated === 0 ? 'Nothing new to schedule' : `${result.generated} due date${result.generated === 1 ? '' : 's'} scheduled`,
      )
      refresh()
    } catch (err) {
      toast.error('Could not schedule due dates', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(false)
    }
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 14 }}>
        {[0, 1, 2].map((row) => (
          <Card key={row}>
            <Skeleton width="45%" height={14} />
            <div style={{ marginTop: 12, display: 'grid', gap: 8 }}>
              <Skeleton height={11} width="70%" />
              <Skeleton height={11} width="40%" />
            </div>
          </Card>
        ))}
      </div>
    )
  }

  if (resource.error) {
    return (
      <ErrorState
        title="Could not load obligations"
        detail={resource.error.message}
        onRetry={resource.reload}
      />
    )
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <p aria-live="polite" style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
          {obligations.length} obligation{obligations.length === 1 ? '' : 's'}
          {overdue > 0 ? ` · ${overdue} overdue` : occurrences.length > 0 ? ' · nothing overdue' : ''}
        </p>
        {canManage ? (
          <Button size="sm" variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New obligation
          </Button>
        ) : null}
      </header>

      {obligations.length === 0 ? (
        <EmptyState
          icon={<ListChecks size={22} />}
          title="No obligations recorded"
          description="This is where the things the agreement requires — reports, certificates, minimum spends, renewals of insurance — are tracked, each with its own due dates and compliance history."
          action={
            canManage ? (
              <Button variant="primary" icon={<Plus size={15} />} onClick={() => setEditing('new')}>
                Add the first obligation
              </Button>
            ) : undefined
          }
        />
      ) : (
        obligations.map((obligation) => (
          <ObligationCard
            key={obligation.id}
            obligation={obligation}
            occurrences={occurrencesByObligation.get(obligation.id) ?? []}
            currency={obligation.currency ?? contract.currency}
            canManage={canManage}
            busy={busy}
            onEdit={() => setEditing(obligation)}
            onDelete={() => setDeleting(obligation)}
            onGenerate={() => void generate(obligation)}
            onComplete={(occurrence) => setCompleting(occurrence)}
            onStatus={(occurrence) => setStatusFor(occurrence)}
          />
        ))
      )}

      {editing !== null ? (
        <ObligationFormModal
          contractId={contractId}
          currency={contract.currency}
          obligation={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      ) : null}

      {completing ? (
        <CompleteOccurrenceModal
          contractId={contractId}
          occurrence={completing}
          currency={completing.currency ?? contract.currency}
          onClose={() => setCompleting(null)}
          onCompleted={() => {
            setCompleting(null)
            refresh()
          }}
        />
      ) : null}

      {statusFor ? (
        <OccurrenceStatusModal
          occurrence={statusFor}
          onClose={() => setStatusFor(null)}
          onSaved={() => {
            setStatusFor(null)
            refresh()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onConfirm={() => void remove()}
        busy={busy}
        tone="danger"
        title="Remove this obligation?"
        confirmLabel="Remove"
        message={
          <>
            <strong>{deleting?.title}</strong> and every due date recorded against it will be removed
            from this contract. The compliance history goes with it.
          </>
        }
      />
    </div>
  )
}

function ObligationCard({
  obligation,
  occurrences,
  currency,
  canManage,
  busy,
  onEdit,
  onDelete,
  onGenerate,
  onComplete,
  onStatus,
}: {
  obligation: Obligation
  occurrences: ObligationOccurrence[]
  currency: string
  canManage: boolean
  busy: boolean
  onEdit: () => void
  onDelete: () => void
  onGenerate: () => void
  onComplete: (occurrence: ObligationOccurrence) => void
  onStatus: (occurrence: ObligationOccurrence) => void
}) {
  const [expanded, setExpanded] = useState(false)

  const open = occurrences.filter((occurrence) =>
    ['upcoming', 'due', 'overdue'].includes(occurrence.status),
  )
  const next = open[0] ?? null

  return (
    <Card>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0 }}>
          <h3 style={{ fontSize: 14.5, fontWeight: 700 }}>{obligation.title}</h3>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 7 }}>
            <StatusChip status={obligation.status} size="sm" />
            <Chip tone="neutral" size="sm">
              {humanise(obligation.obligation_type)}
            </Chip>
            <Chip tone="neutral" size="sm">
              {obligation.responsible_party === 'company'
                ? 'Our obligation'
                : obligation.responsible_party === 'counterparty'
                  ? 'Counterparty obligation'
                  : 'Both parties'}
            </Chip>
            {obligation.evidence_required ? (
              <Chip tone="info" size="sm">
                <Paperclip size={11} aria-hidden />
                Evidence required
              </Chip>
            ) : null}
            {!obligation.is_active ? (
              <Chip tone="neutral" size="sm">
                Inactive
              </Chip>
            ) : null}
          </div>
        </div>

        {canManage ? (
          <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
            <Button size="sm" variant="ghost" icon={<Pencil size={13} />} onClick={onEdit} aria-label={`Edit ${obligation.title}`}>
              Edit
            </Button>
            <Button
              size="sm"
              variant="ghost"
              icon={<RefreshCw size={13} />}
              disabled={busy || obligation.frequency === 'one_time'}
              onClick={onGenerate}
              aria-label={`Schedule due dates for ${obligation.title}`}
            >
              Schedule
            </Button>
            <Button
              size="sm"
              variant="ghost"
              icon={<Trash2 size={13} />}
              onClick={onDelete}
              aria-label={`Remove ${obligation.title}`}
            />
          </div>
        ) : null}
      </div>

      {obligation.description ? (
        <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 10, lineHeight: 1.65 }}>
          {obligation.description}
        </p>
      ) : null}

      <dl
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
          gap: '10px 20px',
          marginTop: 14,
          fontSize: 12.5,
        }}
      >
        <Meta label="Recurrence" value={describeRecurrence(obligation)} />
        <Meta
          label="Amount"
          value={obligation.amount ? formatMoney(obligation.amount, currency) : 'Not a payable obligation'}
        />
        <Meta
          label="Compliance"
          value={`${obligation.completed_count} of ${obligation.occurrence_count} completed${
            obligation.overdue_count > 0 ? ` · ${obligation.overdue_count} overdue` : ''
          }`}
        />
        <Meta
          label="Grace period"
          value={obligation.grace_period_days > 0 ? `${obligation.grace_period_days} days after the due date` : 'None'}
        />
      </dl>

      {next ? (
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
            flexWrap: 'wrap',
            marginTop: 14,
            padding: '11px 13px',
            borderRadius: 'var(--radius-md)',
            background: next.is_overdue ? 'var(--color-danger-bg)' : 'var(--color-bg-subtle)',
            border: `1px solid ${next.is_overdue ? 'var(--color-danger-border)' : 'rgb(var(--color-border))'}`,
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 9, minWidth: 0 }}>
            <CalendarClock
              size={16}
              aria-hidden
              style={{ color: next.is_overdue ? 'var(--color-danger)' : 'var(--color-text-muted)' }}
            />
            <div>
              <p style={{ fontSize: 13, fontWeight: 700 }}>
                Due {formatDate(next.due_date)}{' '}
                <span style={{ fontWeight: 600, color: 'var(--color-text-secondary)' }}>
                  ({formatRelativeDays(next.days_to_due)})
                </span>
              </p>
              <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                Occurrence {next.sequence_no} of {obligation.occurrence_count || 1}
              </p>
            </div>
          </div>
          {canManage ? (
            <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }}>
              <Button size="sm" variant="primary" icon={<CalendarCheck size={13} />} onClick={() => onComplete(next)}>
                Mark complete
              </Button>
              <Button size="sm" variant="secondary" onClick={() => onStatus(next)}>
                Change status
              </Button>
            </div>
          ) : null}
        </div>
      ) : null}

      {occurrences.length > 0 ? (
        <>
          <Button
            size="sm"
            variant="ghost"
            icon={expanded ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
            onClick={() => setExpanded((value) => !value)}
            style={{ marginTop: 10, paddingLeft: 0 }}
          >
            {expanded ? 'Hide' : 'Show'} all {occurrences.length} due date{occurrences.length === 1 ? '' : 's'}
          </Button>

          {expanded ? (
            <div className="ct-scroll-x" style={{ marginTop: 8 }}>
              <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5, minWidth: 480 }}>
                <caption className="ct-sr-only">Every due date recorded for {obligation.title}</caption>
                <thead>
                  <tr>
                    {['Due', 'Status', 'Completed', 'Note', ''].map((heading, index) => (
                      <th
                        key={heading || `actions-${index}`}
                        scope="col"
                        style={{
                          textAlign: 'left',
                          padding: '7px 10px',
                          fontSize: 11,
                          textTransform: 'uppercase',
                          letterSpacing: '.02em',
                          color: 'var(--color-text-muted)',
                          borderBottom: '1px solid rgb(var(--color-border))',
                        }}
                      >
                        {heading || <span className="ct-sr-only">Actions</span>}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {occurrences.map((occurrence) => (
                    <tr key={occurrence.id} style={{ borderBottom: '1px solid var(--color-border-light)' }}>
                      <td style={{ padding: '8px 10px', whiteSpace: 'nowrap' }}>{formatDate(occurrence.due_date)}</td>
                      <td style={{ padding: '8px 10px' }}>
                        <StatusChip status={occurrence.is_overdue ? 'overdue' : occurrence.status} size="sm" />
                      </td>
                      <td style={{ padding: '8px 10px', whiteSpace: 'nowrap' }}>
                        {occurrence.completed_at ? formatDate(occurrence.completed_at) : '—'}
                      </td>
                      <td style={{ padding: '8px 10px', color: 'var(--color-text-secondary)' }}>
                        {occurrence.completion_note ?? '—'}
                      </td>
                      <td style={{ padding: '8px 10px', textAlign: 'right' }}>
                        {canManage && !['completed', 'waived', 'not_applicable'].includes(occurrence.status) ? (
                          <Button size="sm" variant="ghost" onClick={() => onComplete(occurrence)}>
                            Complete
                          </Button>
                        ) : null}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </>
      ) : null}
    </Card>
  )
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.03em', color: 'var(--color-text-muted)', fontWeight: 700 }}>
        {label}
      </dt>
      <dd style={{ marginTop: 3, color: 'var(--color-text)' }}>{value}</dd>
    </div>
  )
}

function ObligationFormModal({
  contractId,
  currency,
  obligation,
  onClose,
  onSaved,
}: {
  contractId: number
  currency: string
  obligation: Obligation | null
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const [form, setForm] = useState({
    title: obligation?.title ?? '',
    description: obligation?.description ?? '',
    obligation_type: obligation?.obligation_type ?? 'general',
    responsible_party: obligation?.responsible_party ?? 'company',
    frequency: (obligation?.frequency ?? 'one_time') as Frequency,
    custom_interval_days: obligation?.custom_interval_days?.toString() ?? '',
    first_due_date: obligation?.first_due_date ?? '',
    start_date: obligation?.start_date ?? '',
    end_date: obligation?.end_date ?? '',
    grace_period_days: obligation?.grace_period_days?.toString() ?? '0',
    amount: obligation?.amount ?? '',
    currency: obligation?.currency ?? currency,
    evidence_required: obligation?.evidence_required ?? false,
    reminder_days: obligation?.reminder_days ?? '14,7,1',
  })

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((current) => ({ ...current, [key]: value }))

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      const body = {
        title: form.title.trim(),
        description: form.description.trim() || null,
        obligation_type: form.obligation_type.trim() || 'general',
        responsible_party: form.responsible_party,
        frequency: form.frequency,
        custom_interval_days:
          form.frequency === 'custom' && form.custom_interval_days ? Number(form.custom_interval_days) : null,
        first_due_date: form.first_due_date || null,
        start_date: form.start_date || null,
        end_date: form.end_date || null,
        grace_period_days: form.grace_period_days ? Number(form.grace_period_days) : 0,
        amount: form.amount.trim() || null,
        currency: form.amount.trim() ? form.currency : null,
        evidence_required: form.evidence_required,
        reminder_days: form.reminder_days.trim() || '14,7,1',
      }

      if (obligation) {
        await api.put(`/obligations/${obligation.id}`, body)
        toast.success('Obligation updated', body.title)
      } else {
        await api.post(`/contracts/${contractId}/obligations`, body)
        toast.success('Obligation added', body.title)
      }
      onSaved()
    } catch (err) {
      // A 422 names the fields it rejected; putting that in a toast would hide
      // it from the box the user has to fix.
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the obligation', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={obligation ? 'Edit obligation' : 'New obligation'}
      description="Recurring obligations generate their own due dates; a one-time obligation has the single date you give it."
      width={620}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            {obligation ? 'Save changes' : 'Add obligation'}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <Input
          label="What has to be done"
          required
          value={form.title}
          error={errors.title}
          onChange={(event) => set('title', event.target.value)}
          placeholder="Submit the quarterly SLA report"
        />
        <Textarea
          label="Detail"
          rows={3}
          value={form.description}
          error={errors.description}
          hint="What good looks like, and where it goes."
          onChange={(event) => set('description', event.target.value)}
        />

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14 }}>
          <Input
            label="Type"
            value={form.obligation_type}
            error={errors.obligation_type}
            onChange={(event) => set('obligation_type', event.target.value)}
          />
          <Select
            label="Who owes it"
            value={form.responsible_party}
            options={RESPONSIBLE_OPTIONS}
            error={errors.responsible_party}
            onChange={(event) => set('responsible_party', event.target.value as Obligation['responsible_party'])}
          />
          <Select
            label="How often"
            value={form.frequency}
            options={FREQUENCY_OPTIONS}
            error={errors.frequency}
            onChange={(event) => set('frequency', event.target.value as Frequency)}
          />
          {form.frequency === 'custom' ? (
            <Input
              label="Interval in days"
              type="number"
              min={1}
              required
              value={form.custom_interval_days}
              error={errors.custom_interval_days}
              onChange={(event) => set('custom_interval_days', event.target.value)}
            />
          ) : null}
          <DateInput
            label="First due"
            value={form.first_due_date}
            error={errors.first_due_date}
            onChange={(event) => set('first_due_date', event.target.value)}
          />
          <DateInput
            label="Starts"
            value={form.start_date}
            error={errors.start_date}
            onChange={(event) => set('start_date', event.target.value)}
          />
          <DateInput
            label="Ends"
            value={form.end_date}
            error={errors.end_date}
            onChange={(event) => set('end_date', event.target.value)}
          />
          <Input
            label="Grace period (days)"
            type="number"
            min={0}
            value={form.grace_period_days}
            error={errors.grace_period_days}
            onChange={(event) => set('grace_period_days', event.target.value)}
          />
          <MoneyInput
            label="Amount"
            currency={form.currency}
            value={form.amount}
            error={errors.amount}
            hint="Only where the obligation is a payment."
            onChange={(event) => set('amount', event.target.value)}
          />
          <Input
            label="Reminders (days before)"
            value={form.reminder_days}
            error={errors.reminder_days}
            hint="Comma separated, e.g. 14,7,1"
            onChange={(event) => set('reminder_days', event.target.value)}
          />
        </div>

        <Checkbox
          label="Evidence is required to complete this"
          hint="A due date cannot be marked complete without a document, note or reference."
          checked={form.evidence_required}
          onChange={(event) => set('evidence_required', event.target.checked)}
        />
      </div>
    </Modal>
  )
}

function CompleteOccurrenceModal({
  contractId,
  occurrence,
  currency,
  onClose,
  onCompleted,
}: {
  contractId: number
  occurrence: ObligationOccurrence
  currency: string
  onClose: () => void
  onCompleted: () => void
}) {
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [completionNote, setCompletionNote] = useState('')
  const [amount, setAmount] = useState(occurrence.amount ?? '')
  const [documentId, setDocumentId] = useState('')
  const [evidenceNote, setEvidenceNote] = useState('')
  const [externalRef, setExternalRef] = useState('')

  const documents = useApiResource<ContractDocumentOption[]>(
    (signal) => api.get<ContractDocumentOption[]>(`/contracts/${contractId}/documents`, undefined, signal),
    [contractId],
  )

  const documentOptions = (documents.data ?? []).map((document) => ({
    value: String(document.id),
    label: document.title?.trim() || document.file_name || `Document ${document.id}`,
  }))

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      // Field names follow the completion endpoint: the evidence document is
      // `document_id`, and `completion_note` is the record of what was done.
      await api.post(`/occurrences/${occurrence.id}/complete`, {
        completion_note: completionNote.trim() || null,
        amount: amount.trim() || null,
        document_id: documentId ? Number(documentId) : null,
        evidence_note: evidenceNote.trim() || null,
        external_ref: externalRef.trim() || null,
      })
      toast.success('Recorded as complete', `Due ${formatDate(occurrence.due_date)}`)
      onCompleted()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not record that', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Mark this obligation complete"
      description={`Due ${formatDate(occurrence.due_date)}. What is recorded here is what an auditor will read.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            Mark complete
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        {occurrence.evidence_required ? (
          <p
            style={{
              fontSize: 12.5,
              padding: '9px 12px',
              borderRadius: 'var(--radius-md)',
              background: 'var(--color-info-bg)',
              border: '1px solid var(--color-info-border)',
              color: 'var(--color-text)',
            }}
          >
            This obligation needs evidence: attach a document, or record a note or external reference.
          </p>
        ) : null}

        <Textarea
          label="What was done"
          rows={3}
          value={completionNote}
          error={errors.completion_note}
          onChange={(event) => setCompletionNote(event.target.value)}
        />

        <MoneyInput
          label="Amount settled"
          currency={currency}
          value={amount}
          error={errors.amount}
          onChange={(event) => setAmount(event.target.value)}
        />

        <Select
          label="Evidence document"
          value={documentId}
          placeholder={documents.loading ? 'Loading documents…' : 'No document'}
          options={documentOptions}
          error={errors.document_id}
          hint="Files are uploaded on the Documents tab; anything filed there can be attached here."
          onChange={(event) => setDocumentId(event.target.value)}
        />

        <Input
          label="Evidence note"
          value={evidenceNote}
          error={errors.evidence_note}
          onChange={(event) => setEvidenceNote(event.target.value)}
        />

        <Input
          label="External reference"
          value={externalRef}
          error={errors.external_ref}
          hint="A ticket, invoice or email reference, where the evidence lives elsewhere."
          onChange={(event) => setExternalRef(event.target.value)}
        />
      </div>
    </Modal>
  )
}

function OccurrenceStatusModal({
  occurrence,
  onClose,
  onSaved,
}: {
  occurrence: ObligationOccurrence
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [status, setStatus] = useState<OccurrenceStatus>(occurrence.status)
  const [note, setNote] = useState('')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      await api.post(`/occurrences/${occurrence.id}/status`, { status, note: note.trim() || null })
      toast.success(`Status set to ${humanise(status).toLowerCase()}`)
      onSaved()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not change the status', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Change status"
      description={`Due ${formatDate(occurrence.due_date)}`}
      width={460}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            Save
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <Select
          label="Status"
          value={status}
          error={errors.status}
          options={[
            { value: 'upcoming', label: 'Upcoming' },
            { value: 'due', label: 'Due' },
            { value: 'overdue', label: 'Overdue' },
            { value: 'waived', label: 'Waived' },
            { value: 'not_applicable', label: 'Not applicable' },
            { value: 'disputed', label: 'Disputed' },
          ]}
          onChange={(event) => setStatus(event.target.value as OccurrenceStatus)}
        />
        <Textarea
          label="Why"
          rows={3}
          value={note}
          error={errors.note}
          hint="Waiving or disputing a due date is a decision; say who agreed it."
          onChange={(event) => setNote(event.target.value)}
        />
      </div>
    </Modal>
  )
}
