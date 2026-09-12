import { useMemo, useState } from 'react'
import { ArrowDownLeft, ArrowUpRight, Lock, Pencil, Plus, Trash2 } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  ConfirmDialog,
  DataTable,
  DateInput,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  MoneyInput,
  Select,
  Skeleton,
  Textarea,
} from '../../ui'
import type { Column } from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import { CURRENCIES, type Contract } from '../../../types/contracts'
import { formatDate, formatMoney, humanise } from '../../../utils/format'

/**
 * The money the contract moves, and in which direction.
 *
 * Totals are grouped by currency rather than added together: a contract with a
 * USD licence fee and an INR support retainer has two totals, and one number
 * combining them would be wrong in a way nobody notices until reconciliation.
 */

type PaymentStatus = 'scheduled' | 'invoiced' | 'part_paid' | 'settled' | 'waived' | 'overdue'
type Direction = 'receivable' | 'payable'

interface PaymentSchedule {
  id: number
  contract_id: number
  milestone_id: number | null
  sequence_no: number
  label: string
  due_date: string | null
  amount: string
  percent_of_total: string | null
  currency: string
  direction: Direction
  status: PaymentStatus
  settled_at: string | null
  external_ref_product: string | null
  external_ref_id: string | null
  notes: string | null
}

interface CommercialTerms {
  currency: string
  total_value: string | null
  recurring_amount: string | null
  billing_frequency: string | null
  payment_terms_days: number | null
  payment_terms_note: string | null
  value_direction: 'receivable' | 'payable' | 'both' | 'none'
  advance_amount: string | null
  advance_percent: string | null
  retention_percent: string | null
  security_deposit: string | null
  late_payment_interest: string | null
  escalation_percent: string | null
  minimum_purchase: string | null
  penalty_note: string | null
}

interface CommercialsPayload {
  terms: CommercialTerms | null
  payment_schedules: PaymentSchedule[]
}

const STATUS_TONE: Record<PaymentStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  scheduled: 'neutral',
  invoiced: 'info',
  part_paid: 'warning',
  settled: 'success',
  waived: 'neutral',
  overdue: 'danger',
}

const STATUS_OPTIONS: { value: PaymentStatus; label: string }[] = [
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'part_paid', label: 'Part paid' },
  { value: 'settled', label: 'Settled' },
  { value: 'waived', label: 'Waived' },
  { value: 'overdue', label: 'Overdue' },
]

const SETTLED_STATUSES: PaymentStatus[] = ['settled', 'waived']

function amountOf(value: string | null): number {
  const numeric = Number(value ?? 0)
  return Number.isFinite(numeric) ? numeric : 0
}

function PaymentStatusChip({ status }: { status: PaymentStatus }) {
  return (
    <Chip tone={STATUS_TONE[status]} size="sm">
      {humanise(status)}
    </Chip>
  )
}

function DirectionChip({ direction }: { direction: Direction }) {
  return (
    <Chip tone={direction === 'receivable' ? 'success' : 'warning'} size="sm">
      {direction === 'receivable' ? <ArrowDownLeft size={11} aria-hidden /> : <ArrowUpRight size={11} aria-hidden />}
      {direction === 'receivable' ? 'Receivable' : 'Payable'}
    </Chip>
  )
}

export function PaymentsTab({
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
  const canView = can(PERMISSION.COMMERCIALS_VIEW)
  const canEdit = can(PERMISSION.COMMERCIALS_EDIT)

  const resource = useApiResource<CommercialsPayload>(
    (signal) => api.get<CommercialsPayload>(`/contracts/${contractId}/commercials`, undefined, signal),
    [contractId],
    { enabled: canView },
  )

  const [editing, setEditing] = useState<PaymentSchedule | null | 'new'>(null)
  const [deleting, setDeleting] = useState<PaymentSchedule | null>(null)
  const [removing, setRemoving] = useState(false)

  const schedules = resource.data?.payment_schedules ?? []
  const terms = resource.data?.terms ?? null

  const totals = useMemo(() => {
    const byCurrency = new Map<
      string,
      { currency: string; receivable: number; payable: number; settled: number; outstanding: number }
    >()

    for (const schedule of schedules) {
      const currency = schedule.currency || contract.currency
      const bucket =
        byCurrency.get(currency) ?? { currency, receivable: 0, payable: 0, settled: 0, outstanding: 0 }
      const value = amountOf(schedule.amount)

      if (schedule.direction === 'receivable') bucket.receivable += value
      else bucket.payable += value

      if (SETTLED_STATUSES.includes(schedule.status)) bucket.settled += value
      else bucket.outstanding += value

      byCurrency.set(currency, bucket)
    }

    return [...byCurrency.values()]
  }, [schedules, contract.currency])

  const refresh = () => {
    resource.reload()
    onChanged()
  }

  const remove = async () => {
    if (!deleting) return
    setRemoving(true)
    try {
      await api.delete(`/payment-schedules/${deleting.id}`)
      toast.success('Payment removed', deleting.label)
      setDeleting(null)
      refresh()
    } catch (err) {
      toast.error('Could not remove that payment', err instanceof Error ? err.message : undefined)
    } finally {
      setRemoving(false)
    }
  }

  if (!canView) {
    return (
      <EmptyState
        icon={<Lock size={22} />}
        title="Commercial terms are restricted"
        description="Your role does not include access to contract values and payment schedules. Ask an administrator for the commercials permission if you need it."
      />
    )
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 16 }}>
        <Card>
          <Skeleton width="25%" height={13} />
          <div style={{ marginTop: 14, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 14 }}>
            {[0, 1, 2, 3].map((tile) => (
              <Skeleton key={tile} height={54} radius={10} />
            ))}
          </div>
        </Card>
        <Card padded={false}>
          <div style={{ padding: 16 }}>
            <Skeleton height={13} width="30%" />
            <div style={{ marginTop: 14, display: 'grid', gap: 10 }}>
              {[0, 1, 2, 3].map((row) => (
                <Skeleton key={row} height={13} />
              ))}
            </div>
          </div>
        </Card>
      </div>
    )
  }

  if (resource.error) {
    return (
      <ErrorState title="Could not load the payment schedule" detail={resource.error.message} onRetry={resource.reload} />
    )
  }

  const columns: Column<PaymentSchedule>[] = [
    {
      key: 'label',
      header: 'Payment',
      render: (row) => (
        <div>
          <span style={{ fontWeight: 600 }}>
            <span style={{ color: 'var(--color-text-muted)', marginRight: 6 }}>{row.sequence_no}.</span>
            {row.label}
          </span>
          {row.notes ? (
            <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>{row.notes}</p>
          ) : null}
          {row.external_ref_id ? (
            <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.external_ref_product ? `${humanise(row.external_ref_product)} · ` : ''}
              {row.external_ref_id}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'due_date',
      header: 'Due',
      render: (row) => formatDate(row.due_date),
      hideBelow: 'sm',
    },
    {
      key: 'direction',
      header: 'Direction',
      render: (row) => <DirectionChip direction={row.direction} />,
      hideBelow: 'md',
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      render: (row) => (
        <div style={{ fontVariantNumeric: 'tabular-nums' }}>
          <span style={{ fontWeight: 600 }}>{formatMoney(row.amount, row.currency)}</span>
          {row.percent_of_total ? (
            <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
              {Number(row.percent_of_total)}% of total
            </p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => (
        <div style={{ display: 'grid', gap: 3 }}>
          <PaymentStatusChip status={row.status} />
          {row.settled_at ? (
            <span style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>{formatDate(row.settled_at)}</span>
          ) : null}
        </div>
      ),
    },
  ]

  if (canEdit) {
    columns.push({
      key: 'actions',
      header: '',
      srLabel: 'Actions',
      align: 'right',
      width: 96,
      render: (row) => (
        <div style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
          <Button
            size="sm"
            variant="ghost"
            icon={<Pencil size={13} />}
            aria-label={`Edit ${row.label}`}
            onClick={() => setEditing(row)}
          />
          <Button
            size="sm"
            variant="ghost"
            icon={<Trash2 size={13} />}
            aria-label={`Remove ${row.label}`}
            onClick={() => setDeleting(row)}
          />
        </div>
      ),
    })
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      {terms ? (
        <Card>
          <CardHeader
            level={3}
            title="Commercial terms"
            description="Set on the contract record; the schedule below is how the value is actually collected or paid."
          />
          <dl
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
              gap: '12px 20px',
              fontSize: 12.5,
            }}
          >
            <Term label="Contract value" value={formatMoney(terms.total_value, terms.currency)} />
            <Term
              label="Direction"
              value={
                terms.value_direction === 'both'
                  ? 'Both ways'
                  : terms.value_direction === 'none'
                    ? 'No money moves'
                    : humanise(terms.value_direction)
              }
            />
            <Term
              label="Recurring"
              value={
                terms.recurring_amount
                  ? `${formatMoney(terms.recurring_amount, terms.currency)}${
                      terms.billing_frequency ? ` · ${humanise(terms.billing_frequency).toLowerCase()}` : ''
                    }`
                  : 'One-off value'
              }
            />
            <Term
              label="Payment terms"
              value={
                terms.payment_terms_days !== null
                  ? `${terms.payment_terms_days} days${terms.payment_terms_note ? ` · ${terms.payment_terms_note}` : ''}`
                  : (terms.payment_terms_note ?? 'Not stated')
              }
            />
            {terms.advance_amount || terms.advance_percent ? (
              <Term
                label="Advance"
                value={
                  terms.advance_amount
                    ? formatMoney(terms.advance_amount, terms.currency)
                    : `${Number(terms.advance_percent)}%`
                }
              />
            ) : null}
            {terms.retention_percent ? (
              <Term label="Retention" value={`${Number(terms.retention_percent)}%`} />
            ) : null}
            {terms.late_payment_interest ? (
              <Term label="Late payment interest" value={`${Number(terms.late_payment_interest)}%`} />
            ) : null}
          </dl>
          {terms.penalty_note ? (
            <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 12, lineHeight: 1.6 }}>
              {terms.penalty_note}
            </p>
          ) : null}
        </Card>
      ) : null}

      {totals.length > 0 ? (
        <div style={{ display: 'grid', gap: 12 }}>
          {totals.map((total) => (
            <Card key={total.currency}>
              <h3 style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.03em', color: 'var(--color-text-muted)' }}>
                Totals in {total.currency}
              </h3>
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
                  gap: 14,
                  marginTop: 12,
                }}
              >
                <Total label="Receivable" value={formatMoney(total.receivable, total.currency)} tone="success" />
                <Total label="Payable" value={formatMoney(total.payable, total.currency)} tone="warning" />
                <Total label="Settled" value={formatMoney(total.settled, total.currency)} />
                <Total label="Outstanding" value={formatMoney(total.outstanding, total.currency)} />
              </div>
            </Card>
          ))}
        </div>
      ) : null}

      <Card padded={false}>
        <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            gap: 12,
            flexWrap: 'wrap',
            padding: '14px 16px',
            borderBottom: schedules.length > 0 ? '1px solid rgb(var(--color-border))' : undefined,
          }}
        >
          <div>
            <h3 style={{ fontSize: 14.5, fontWeight: 700 }}>Payment schedule</h3>
            <p aria-live="polite" style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
              {schedules.length === 0
                ? 'Nothing scheduled'
                : `${schedules.length} payment${schedules.length === 1 ? '' : 's'} scheduled`}
            </p>
          </div>
          {canEdit ? (
            <Button size="sm" variant="primary" icon={<Plus size={14} />} onClick={() => setEditing('new')}>
              Add payment
            </Button>
          ) : null}
        </div>

        <DataTable
          columns={columns}
          rows={schedules}
          rowKey={(row) => row.id}
          caption="Payments scheduled under this contract"
          emptyTitle="No payments scheduled"
          emptyDescription="Break the contract value into the instalments it is actually invoiced in — advance, milestones, retention — and the dashboard can answer what is due this quarter."
          emptyAction={
            canEdit ? (
              <Button variant="primary" icon={<Plus size={15} />} onClick={() => setEditing('new')}>
                Add the first payment
              </Button>
            ) : undefined
          }
        />
      </Card>

      {editing !== null ? (
        <ScheduleFormModal
          contractId={contractId}
          defaultCurrency={terms?.currency ?? contract.currency}
          defaultDirection={terms?.value_direction === 'payable' ? 'payable' : 'receivable'}
          nextSequence={schedules.length + 1}
          schedule={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null)
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
        title="Remove this payment?"
        confirmLabel="Remove"
        message={
          <>
            <strong>{deleting?.label}</strong> will be removed from the schedule. Totals and the
            commitments on the dashboard change with it.
          </>
        }
      />
    </div>
  )
}

function Term({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.03em', color: 'var(--color-text-muted)' }}>
        {label}
      </dt>
      <dd style={{ marginTop: 3 }}>{value}</dd>
    </div>
  )
}

function Total({ label, value, tone }: { label: string; value: string; tone?: 'success' | 'warning' }) {
  return (
    <div>
      <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', fontWeight: 600 }}>{label}</p>
      <p
        style={{
          fontSize: 17,
          fontWeight: 800,
          marginTop: 2,
          fontVariantNumeric: 'tabular-nums',
          color:
            tone === 'success'
              ? 'var(--color-success)'
              : tone === 'warning'
                ? 'var(--color-warning)'
                : 'var(--color-text)',
        }}
      >
        {value}
      </p>
    </div>
  )
}

function ScheduleFormModal({
  contractId,
  defaultCurrency,
  defaultDirection,
  nextSequence,
  schedule,
  onClose,
  onSaved,
}: {
  contractId: number
  defaultCurrency: string
  defaultDirection: Direction
  nextSequence: number
  schedule: PaymentSchedule | null
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const [form, setForm] = useState({
    label: schedule?.label ?? '',
    sequence_no: (schedule?.sequence_no ?? nextSequence).toString(),
    due_date: schedule?.due_date ?? '',
    amount: schedule?.amount ?? '',
    percent_of_total: schedule?.percent_of_total ?? '',
    currency: schedule?.currency ?? defaultCurrency,
    direction: schedule?.direction ?? defaultDirection,
    status: (schedule?.status ?? 'scheduled') as PaymentStatus,
    notes: schedule?.notes ?? '',
  })

  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) =>
    setForm((current) => ({ ...current, [key]: value }))

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      const body = {
        label: form.label.trim(),
        sequence_no: form.sequence_no ? Number(form.sequence_no) : nextSequence,
        due_date: form.due_date || null,
        amount: form.amount.trim(),
        percent_of_total: form.percent_of_total.trim() || null,
        currency: form.currency,
        direction: form.direction,
        status: form.status,
        notes: form.notes.trim() || null,
      }

      if (schedule) {
        await api.put(`/payment-schedules/${schedule.id}`, body)
        toast.success('Payment updated', body.label)
      } else {
        await api.post(`/contracts/${contractId}/payment-schedules`, body)
        toast.success('Payment added', body.label)
      }
      onSaved()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the payment', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={schedule ? 'Edit payment' : 'Add payment'}
      width={560}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            {schedule ? 'Save changes' : 'Add payment'}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <Input
          label="What this payment is"
          required
          value={form.label}
          error={errors.label}
          placeholder="Advance on signature"
          onChange={(event) => set('label', event.target.value)}
        />

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 14 }}>
          <Input
            label="Order"
            type="number"
            min={1}
            value={form.sequence_no}
            error={errors.sequence_no}
            onChange={(event) => set('sequence_no', event.target.value)}
          />
          <DateInput
            label="Due"
            value={form.due_date}
            error={errors.due_date}
            onChange={(event) => set('due_date', event.target.value)}
          />
          <MoneyInput
            label="Amount"
            required
            currency={form.currency}
            value={form.amount}
            error={errors.amount}
            onChange={(event) => set('amount', event.target.value)}
          />
          <Select
            label="Currency"
            value={form.currency}
            options={CURRENCIES.map((code) => ({ value: code, label: code }))}
            error={errors.currency}
            onChange={(event) => set('currency', event.target.value)}
          />
          <Select
            label="Direction"
            value={form.direction}
            options={[
              { value: 'receivable', label: 'Receivable — they pay us' },
              { value: 'payable', label: 'Payable — we pay them' },
            ]}
            error={errors.direction}
            onChange={(event) => set('direction', event.target.value as Direction)}
          />
          <Select
            label="Status"
            value={form.status}
            options={STATUS_OPTIONS}
            error={errors.status}
            onChange={(event) => set('status', event.target.value as PaymentStatus)}
          />
          <Input
            label="Percent of total"
            type="number"
            min={0}
            max={100}
            step="0.001"
            value={form.percent_of_total}
            error={errors.percent_of_total}
            onChange={(event) => set('percent_of_total', event.target.value)}
          />
        </div>

        <Textarea
          label="Notes"
          rows={2}
          value={form.notes}
          error={errors.notes}
          onChange={(event) => set('notes', event.target.value)}
        />
      </div>
    </Modal>
  )
}
