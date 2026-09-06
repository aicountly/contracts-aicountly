import { useMemo, useState } from 'react'
import { CalendarCheck, Flag, Link2, Pencil, Play, Plus, Trash2 } from 'lucide-react'

import {
  Button,
  Card,
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
  Tooltip,
} from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract } from '../../../types/contracts'
import { formatDate, formatMoney, formatRelativeDays, humanise } from '../../../utils/format'

/**
 * Dated deliverables under the contract.
 *
 * Unlike an obligation, a milestone happens once and often gates the next one —
 * acceptance cannot be signed off before delivery is recorded. The dependency
 * is shown and the action is blocked, rather than letting the server refuse it
 * after the click.
 */

type MilestoneStatus = 'pending' | 'in_progress' | 'completed' | 'missed' | 'cancelled'

interface Milestone {
  id: number
  contract_id: number
  clause_id: number | null
  title: string
  description: string | null
  milestone_type: string
  due_date: string
  owner_uuid: string | null
  amount: string | null
  currency: string | null
  status: MilestoneStatus
  completed_at: string | null
  depends_on_id: number | null
  depends_on_title?: string | null
  depends_on_status?: string | null
  reminder_days: string
  is_ai_extracted: boolean
  days_to_due: number | null
  is_overdue: boolean
  created_at: string
  updated_at: string
}

const STATUS_DOT: Record<MilestoneStatus, string> = {
  completed: 'var(--color-success)',
  in_progress: 'var(--color-info)',
  pending: 'rgb(var(--color-border-strong))',
  missed: 'var(--color-danger)',
  cancelled: 'var(--color-neutral)',
}

const STATUS_OPTIONS: { value: MilestoneStatus; label: string }[] = [
  { value: 'pending', label: 'Pending' },
  { value: 'in_progress', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'missed', label: 'Missed' },
  { value: 'cancelled', label: 'Cancelled' },
]

export function MilestonesTab({
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

  const resource = useApiResource<Milestone[]>(
    (signal) => api.get<Milestone[]>(`/contracts/${contractId}/milestones`, undefined, signal),
    [contractId],
  )

  const [editing, setEditing] = useState<Milestone | null | 'new'>(null)
  const [completing, setCompleting] = useState<Milestone | null>(null)
  const [deleting, setDeleting] = useState<Milestone | null>(null)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [removing, setRemoving] = useState(false)

  const milestones = resource.data ?? []

  const progress = useMemo(() => {
    const counted = milestones.filter((milestone) => milestone.status !== 'cancelled')
    const done = counted.filter((milestone) => milestone.status === 'completed').length
    return {
      done,
      total: counted.length,
      percent: counted.length === 0 ? 0 : Math.round((done / counted.length) * 100),
      overdue: milestones.filter((milestone) => milestone.is_overdue).length,
    }
  }, [milestones])

  const refresh = () => {
    resource.reload()
    onChanged()
  }

  const setStatus = async (milestone: Milestone, status: MilestoneStatus) => {
    setBusyId(milestone.id)
    try {
      await api.put(`/milestones/${milestone.id}`, { status })
      toast.success(`${milestone.title} is now ${humanise(status).toLowerCase()}`)
      refresh()
    } catch (err) {
      toast.error('Could not change the status', err instanceof Error ? err.message : undefined)
    } finally {
      setBusyId(null)
    }
  }

  const remove = async () => {
    if (!deleting) return
    setRemoving(true)
    try {
      await api.delete(`/milestones/${deleting.id}`)
      toast.success('Milestone removed', deleting.title)
      setDeleting(null)
      refresh()
    } catch (err) {
      toast.error('Could not remove that milestone', err instanceof Error ? err.message : undefined)
    } finally {
      setRemoving(false)
    }
  }

  if (resource.loading) {
    return (
      <Card>
        <Skeleton width="30%" height={13} />
        <div style={{ marginTop: 16, display: 'grid', gap: 14 }}>
          {[0, 1, 2, 3].map((row) => (
            <Skeleton key={row} height={44} radius={10} />
          ))}
        </div>
      </Card>
    )
  }

  if (resource.error) {
    return (
      <ErrorState title="Could not load milestones" detail={resource.error.message} onRetry={resource.reload} />
    )
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <header style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <p aria-live="polite" style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
          {progress.total === 0
            ? 'No milestones scheduled'
            : `${progress.done} of ${progress.total} complete${progress.overdue > 0 ? ` · ${progress.overdue} overdue` : ''}`}
        </p>
        {canManage ? (
          <Button size="sm" variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
            New milestone
          </Button>
        ) : null}
      </header>

      {milestones.length === 0 ? (
        <EmptyState
          icon={<Flag size={22} />}
          title="No milestones yet"
          description="Milestones are the dated deliverables the contract turns on — go-live, acceptance, the first invoice. Adding them here puts each one on the timeline and on the owner's list."
          action={
            canManage ? (
              <Button variant="primary" icon={<Plus size={15} />} onClick={() => setEditing('new')}>
                Add the first milestone
              </Button>
            ) : undefined
          }
        />
      ) : (
        <Card>
          {progress.total > 0 ? (
            <div style={{ marginBottom: 18 }}>
              <svg
                width="100%"
                height={8}
                role="img"
                aria-label={`${progress.percent}% of milestones complete`}
                style={{ display: 'block' }}
              >
                <rect x={0} y={0} width="100%" height={8} rx={4} fill="rgb(var(--color-border))" />
                <rect
                  x={0}
                  y={0}
                  width={`${progress.percent}%`}
                  height={8}
                  rx={4}
                  fill="rgb(var(--color-primary))"
                />
              </svg>
            </div>
          ) : null}

          <ol style={{ listStyle: 'none', display: 'grid', gap: 2 }}>
            {milestones.map((milestone, index) => {
              const blocked =
                milestone.depends_on_id !== null && milestone.depends_on_status !== 'completed'
              const closed = ['completed', 'cancelled'].includes(milestone.status)

              return (
                <li
                  key={milestone.id}
                  style={{
                    display: 'grid',
                    gridTemplateColumns: '18px 1fr',
                    gap: 14,
                    paddingBottom: index === milestones.length - 1 ? 0 : 16,
                  }}
                >
                  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                    <span
                      aria-hidden
                      style={{
                        width: 12,
                        height: 12,
                        borderRadius: '50%',
                        marginTop: 5,
                        background: STATUS_DOT[milestone.status],
                        border: '2px solid var(--color-bg-card)',
                        boxShadow: `0 0 0 2px ${STATUS_DOT[milestone.status]}`,
                        flexShrink: 0,
                      }}
                    />
                    {index === milestones.length - 1 ? null : (
                      <span
                        aria-hidden
                        style={{ flex: 1, width: 2, background: 'rgb(var(--color-border))', marginTop: 6 }}
                      />
                    )}
                  </div>

                  <div style={{ minWidth: 0 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                      <div style={{ minWidth: 0 }}>
                        <h3 style={{ fontSize: 14, fontWeight: 700 }}>{milestone.title}</h3>
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 6 }}>
                          <StatusChip status={milestone.is_overdue ? 'missed' : milestone.status} size="sm" />
                          <Chip tone="neutral" size="sm">
                            {humanise(milestone.milestone_type)}
                          </Chip>
                          <Chip tone={milestone.is_overdue ? 'danger' : 'neutral'} size="sm">
                            Due {formatDate(milestone.due_date)} · {formatRelativeDays(milestone.days_to_due)}
                          </Chip>
                          {milestone.amount ? (
                            <Chip tone="primary" size="sm">
                              {formatMoney(milestone.amount, milestone.currency ?? contract.currency)}
                            </Chip>
                          ) : null}
                        </div>
                      </div>

                      {canManage ? (
                        <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
                          {milestone.status === 'pending' ? (
                            <Button
                              size="sm"
                              variant="ghost"
                              icon={<Play size={13} />}
                              disabled={busyId === milestone.id}
                              onClick={() => void setStatus(milestone, 'in_progress')}
                            >
                              Start
                            </Button>
                          ) : null}
                          {!closed ? (
                            blocked ? (
                              <Tooltip content={`Complete “${milestone.depends_on_title ?? 'the milestone before it'}” first`}>
                                <Button size="sm" variant="secondary" disabled icon={<CalendarCheck size={13} />}>
                                  Complete
                                </Button>
                              </Tooltip>
                            ) : (
                              <Button
                                size="sm"
                                variant="secondary"
                                icon={<CalendarCheck size={13} />}
                                onClick={() => setCompleting(milestone)}
                              >
                                Complete
                              </Button>
                            )
                          ) : null}
                          <Button
                            size="sm"
                            variant="ghost"
                            icon={<Pencil size={13} />}
                            onClick={() => setEditing(milestone)}
                            aria-label={`Edit ${milestone.title}`}
                          />
                          <Button
                            size="sm"
                            variant="ghost"
                            icon={<Trash2 size={13} />}
                            onClick={() => setDeleting(milestone)}
                            aria-label={`Remove ${milestone.title}`}
                          />
                        </div>
                      ) : null}
                    </div>

                    {milestone.description ? (
                      <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 8, lineHeight: 1.6 }}>
                        {milestone.description}
                      </p>
                    ) : null}

                    {milestone.depends_on_id !== null ? (
                      <p
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: 5,
                          fontSize: 12,
                          color: blocked ? 'var(--color-warning-text)' : 'var(--color-text-muted)',
                          marginTop: 8,
                        }}
                      >
                        <Link2 size={12} aria-hidden />
                        Depends on {milestone.depends_on_title ?? `milestone ${milestone.depends_on_id}`}
                        {milestone.depends_on_status ? ` (${humanise(milestone.depends_on_status).toLowerCase()})` : ''}
                      </p>
                    ) : null}

                    {milestone.completed_at ? (
                      <p style={{ fontSize: 12, color: 'var(--color-text-muted)', marginTop: 8 }}>
                        Completed {formatDate(milestone.completed_at)}
                      </p>
                    ) : null}
                  </div>
                </li>
              )
            })}
          </ol>
        </Card>
      )}

      {editing !== null ? (
        <MilestoneFormModal
          contractId={contractId}
          currency={contract.currency}
          milestone={editing === 'new' ? null : editing}
          siblings={milestones}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
            refresh()
          }}
        />
      ) : null}

      {completing ? (
        <CompleteMilestoneModal
          milestone={completing}
          onClose={() => setCompleting(null)}
          onCompleted={() => {
            setCompleting(null)
            refresh()
          }}
        />
      ) : null}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onConfirm={() => void remove()}
        busy={removing}
        tone="danger"
        title="Remove this milestone?"
        confirmLabel="Remove"
        message={
          <>
            <strong>{deleting?.title}</strong> will be removed from the timeline. Anything scheduled to
            depend on it will lose that link.
          </>
        }
      />
    </div>
  )
}

function MilestoneFormModal({
  contractId,
  currency,
  milestone,
  siblings,
  onClose,
  onSaved,
}: {
  contractId: number
  currency: string
  milestone: Milestone | null
  siblings: Milestone[]
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const [form, setForm] = useState({
    title: milestone?.title ?? '',
    description: milestone?.description ?? '',
    milestone_type: milestone?.milestone_type ?? 'general',
    due_date: milestone?.due_date ?? '',
    amount: milestone?.amount ?? '',
    currency: milestone?.currency ?? currency,
    status: (milestone?.status ?? 'pending') as MilestoneStatus,
    depends_on_id: milestone?.depends_on_id?.toString() ?? '',
    reminder_days: milestone?.reminder_days ?? '14,7,1',
  })

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((current) => ({ ...current, [key]: value }))

  const dependencyOptions = siblings
    .filter((candidate) => candidate.id !== milestone?.id)
    .map((candidate) => ({ value: String(candidate.id), label: `${candidate.title} — ${formatDate(candidate.due_date)}` }))

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      const body = {
        title: form.title.trim(),
        description: form.description.trim() || null,
        milestone_type: form.milestone_type.trim() || 'general',
        due_date: form.due_date || null,
        amount: form.amount.trim() || null,
        currency: form.amount.trim() ? form.currency : null,
        status: form.status,
        depends_on_id: form.depends_on_id ? Number(form.depends_on_id) : null,
        reminder_days: form.reminder_days.trim() || '14,7,1',
      }

      if (milestone) {
        await api.put(`/milestones/${milestone.id}`, body)
        toast.success('Milestone updated', body.title)
      } else {
        await api.post(`/contracts/${contractId}/milestones`, body)
        toast.success('Milestone added', body.title)
      }
      onSaved()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the milestone', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={milestone ? 'Edit milestone' : 'New milestone'}
      width={600}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            {milestone ? 'Save changes' : 'Add milestone'}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <Input
          label="Milestone"
          required
          value={form.title}
          error={errors.title}
          placeholder="Go-live sign-off"
          onChange={(event) => set('title', event.target.value)}
        />
        <Textarea
          label="Detail"
          rows={3}
          value={form.description}
          error={errors.description}
          onChange={(event) => set('description', event.target.value)}
        />

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14 }}>
          <DateInput
            label="Due"
            required
            value={form.due_date}
            error={errors.due_date}
            onChange={(event) => set('due_date', event.target.value)}
          />
          <Input
            label="Type"
            value={form.milestone_type}
            error={errors.milestone_type}
            onChange={(event) => set('milestone_type', event.target.value)}
          />
          <Select
            label="Status"
            value={form.status}
            options={STATUS_OPTIONS}
            error={errors.status}
            onChange={(event) => set('status', event.target.value as MilestoneStatus)}
          />
          <MoneyInput
            label="Amount"
            currency={form.currency}
            value={form.amount}
            error={errors.amount}
            hint="Where the milestone releases a payment."
            onChange={(event) => set('amount', event.target.value)}
          />
          <Select
            label="Depends on"
            value={form.depends_on_id}
            placeholder="Nothing — it can start any time"
            options={dependencyOptions}
            error={errors.depends_on_id}
            onChange={(event) => set('depends_on_id', event.target.value)}
          />
          <Input
            label="Reminders (days before)"
            value={form.reminder_days}
            error={errors.reminder_days}
            hint="Comma separated, e.g. 14,7,1"
            onChange={(event) => set('reminder_days', event.target.value)}
          />
        </div>
      </div>
    </Modal>
  )
}

function CompleteMilestoneModal({
  milestone,
  onClose,
  onCompleted,
}: {
  milestone: Milestone
  onClose: () => void
  onCompleted: () => void
}) {
  const toast = useToast()
  const [note, setNote] = useState('')
  const [completedOn, setCompletedOn] = useState('')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      await api.post(`/milestones/${milestone.id}/complete`, {
        completion_note: note.trim() || null,
        completed_on: completedOn || null,
      })
      toast.success('Milestone completed', milestone.title)
      onCompleted()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not complete the milestone', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Complete this milestone"
      description={`${milestone.title} — due ${formatDate(milestone.due_date)}`}
      width={480}
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
        <DateInput
          label="Completed on"
          value={completedOn}
          error={errors.completed_on}
          hint="Leave blank for today."
          onChange={(event) => setCompletedOn(event.target.value)}
        />
        <Textarea
          label="What was delivered"
          rows={3}
          value={note}
          error={errors.completion_note}
          hint="Supporting files belong on the Documents tab; name them here so the record points at them."
          onChange={(event) => setNote(event.target.value)}
        />
      </div>
    </Modal>
  )
}
