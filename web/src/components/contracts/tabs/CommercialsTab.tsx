import { useEffect, useState } from 'react'
import { ArrowRight, Lock, Pencil } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  DataTable,
  EmptyState,
  ErrorState,
  Input,
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
import { PAYMENT_FREQUENCIES, type Contract } from '../../../types/contracts'
import { formatDate, formatMoney, humanise } from '../../../utils/format'

/**
 * What the contract is worth and how it is paid.
 *
 * Commercial terms are the fields a finance reviewer needs and a general reader
 * has no business seeing, which is why the whole tab is gated on
 * `contract.commercials.view` — the server withholds the payload as well, so a
 * user without it gets an honest "not available to you" rather than an empty
 * form that looks like missing data.
 *
 * The payment schedule is summarised here and edited in Payments: two screens
 * that both create schedule rows would disagree about which one is authoritative
 * the first time someone edited in both.
 */

interface Props {
  contractId: number
  contract: Contract
  onChanged: () => void
  onOpenTab?: (tabId: string) => void
}

interface CommercialTerms {
  currency?: string | null
  total_value?: string | number | null
  recurring_value?: string | number | null
  payment_frequency?: string | null
  billing_frequency?: string | null
  payment_terms_days?: number | string | null
  late_fee_percent?: string | number | null
  price_escalation_percent?: string | number | null
  security_deposit?: string | number | null
  tax_treatment?: string | null
  commercial_summary?: string | null
}

interface PaymentSchedule {
  id: number
  due_date?: string | null
  description?: string | null
  amount?: string | number | null
  currency?: string | null
  status?: string | null
  invoice_number?: string | null
  paid_at?: string | null
}

interface CommercialsPayload {
  terms: CommercialTerms | null
  payment_schedules: PaymentSchedule[] | null
}

const TAX_TREATMENTS = [
  { value: 'exclusive', label: 'Taxes exclusive' },
  { value: 'inclusive', label: 'Taxes inclusive' },
  { value: 'exempt', label: 'Exempt' },
  { value: 'reverse_charge', label: 'Reverse charge' },
]

const SCHEDULE_PREVIEW_ROWS = 5

function frequencyOptions() {
  return PAYMENT_FREQUENCIES.map((value) => ({ value, label: humanise(value) }))
}

function asText(value: string | number | null | undefined): string {
  return value === null || value === undefined ? '' : String(value)
}

export function CommercialsTab({ contractId, contract, onChanged, onOpenTab }: Props) {
  const toast = useToast()
  const { can } = useSession()

  const canView = can(PERMISSION.COMMERCIALS_VIEW)
  const canEdit = can(PERMISSION.COMMERCIALS_EDIT)

  const [editing, setEditing] = useState(false)
  const [form, setForm] = useState<CommercialTerms>({})
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const commercials = useApiResource<CommercialsPayload>(
    (signal) => api.get<CommercialsPayload>(`/contracts/${contractId}/commercials`, undefined, signal),
    [contractId],
    { enabled: canView },
  )

  const terms = commercials.data?.terms ?? null
  const schedules = commercials.data?.payment_schedules ?? []
  const currency = terms?.currency || contract.currency || 'INR'

  // The form mirrors the server's copy until the user starts editing; without
  // this an edit opened before the fetch resolved would post empty fields over
  // real terms.
  useEffect(() => {
    if (terms && !editing) setForm(terms)
  }, [terms, editing])

  const save = async () => {
    setSaving(true)
    setErrors({})
    try {
      const payload = await api.put<{ terms: CommercialTerms }>(
        `/contracts/${contractId}/commercials`,
        form,
      )
      commercials.setData({
        terms: payload?.terms ?? form,
        payment_schedules: commercials.data?.payment_schedules ?? [],
      })
      setEditing(false)
      toast.success('Commercial terms saved')
      onChanged()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the terms', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  if (!canView) {
    return (
      <Card>
        <EmptyState
          icon={<Lock size={21} />}
          title="Commercial terms are restricted"
          description="Your role does not include commercial visibility for contracts. Ask an administrator for the contract.commercials.view permission if you need value and payment detail."
        />
      </Card>
    )
  }

  if (commercials.loading) {
    return (
      <Card>
        <div style={{ display: 'grid', gap: 14 }} role="status" aria-label="Loading commercial terms">
          <Skeleton height={18} width="30%" />
          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))' }}>
            <Skeleton height={44} />
            <Skeleton height={44} />
            <Skeleton height={44} />
            <Skeleton height={44} />
          </div>
          <Skeleton height={120} />
        </div>
      </Card>
    )
  }

  if (commercials.error) {
    return (
      <Card>
        <ErrorState
          title="Could not load the commercial terms"
          detail={commercials.error.message}
          onRetry={commercials.reload}
        />
      </Card>
    )
  }

  const scheduleColumns: Column<PaymentSchedule>[] = [
    { key: 'due', header: 'Due', render: (row) => formatDate(row.due_date) },
    {
      key: 'description',
      header: 'Description',
      render: (row) => row.description || row.invoice_number || '—',
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      render: (row) => (
        <span style={{ fontVariantNumeric: 'tabular-nums' }}>
          {formatMoney(row.amount, row.currency || currency)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      hideBelow: 'sm',
      render: (row) => (row.status ? <Chip size="sm">{humanise(row.status)}</Chip> : '—'),
    },
  ]

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Card>
        <CardHeader
          level={3}
          title="Commercial terms"
          description="The money side of this agreement, as recorded on the contract."
          action={
            canEdit && !editing ? (
              <Button size="sm" variant="secondary" icon={<Pencil size={13} />} onClick={() => setEditing(true)}>
                Edit terms
              </Button>
            ) : null
          }
        />

        {editing ? (
          <form
            onSubmit={(event) => {
              event.preventDefault()
              void save()
            }}
            style={{ display: 'grid', gap: 14 }}
          >
            <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
              <MoneyInput
                label="Total value"
                currency={currency}
                value={asText(form.total_value)}
                error={errors.total_value}
                onChange={(event) => setForm({ ...form, total_value: event.target.value })}
              />
              <MoneyInput
                label="Recurring value"
                currency={currency}
                value={asText(form.recurring_value)}
                error={errors.recurring_value}
                hint="Per billing period, where the contract recurs."
                onChange={(event) => setForm({ ...form, recurring_value: event.target.value })}
              />
              <Select
                label="Payment frequency"
                value={asText(form.payment_frequency)}
                error={errors.payment_frequency}
                placeholder="Not set"
                onChange={(event) => setForm({ ...form, payment_frequency: event.target.value })}
                options={frequencyOptions()}
              />
              <Select
                label="Billing frequency"
                value={asText(form.billing_frequency)}
                error={errors.billing_frequency}
                placeholder="Not set"
                onChange={(event) => setForm({ ...form, billing_frequency: event.target.value })}
                options={frequencyOptions()}
              />
              <Input
                label="Payment terms (days)"
                inputMode="numeric"
                value={asText(form.payment_terms_days)}
                error={errors.payment_terms_days}
                hint="Net days from invoice."
                onChange={(event) => setForm({ ...form, payment_terms_days: event.target.value })}
              />
              <Input
                label="Late fee (%)"
                inputMode="decimal"
                value={asText(form.late_fee_percent)}
                error={errors.late_fee_percent}
                onChange={(event) => setForm({ ...form, late_fee_percent: event.target.value })}
              />
              <Input
                label="Escalation (%)"
                inputMode="decimal"
                value={asText(form.price_escalation_percent)}
                error={errors.price_escalation_percent}
                hint="Annual uplift, if the contract provides for one."
                onChange={(event) => setForm({ ...form, price_escalation_percent: event.target.value })}
              />
              <MoneyInput
                label="Security deposit"
                currency={currency}
                value={asText(form.security_deposit)}
                error={errors.security_deposit}
                onChange={(event) => setForm({ ...form, security_deposit: event.target.value })}
              />
              <Select
                label="Tax treatment"
                value={asText(form.tax_treatment)}
                error={errors.tax_treatment}
                placeholder="Not set"
                onChange={(event) => setForm({ ...form, tax_treatment: event.target.value })}
                options={TAX_TREATMENTS}
              />
            </div>

            <Textarea
              label="Commercial summary"
              rows={3}
              value={asText(form.commercial_summary)}
              error={errors.commercial_summary}
              hint="The pricing in a sentence — what a reviewer needs before reading the schedule."
              onChange={(event) => setForm({ ...form, commercial_summary: event.target.value })}
            />

            <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end' }}>
              <Button
                variant="secondary"
                type="button"
                disabled={saving}
                onClick={() => {
                  setEditing(false)
                  setErrors({})
                  setForm(terms ?? {})
                }}
              >
                Cancel
              </Button>
              <Button variant="primary" type="submit" loading={saving}>
                Save terms
              </Button>
            </div>
          </form>
        ) : (
          <dl
            style={{
              display: 'grid',
              gap: '14px 20px',
              gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
            }}
          >
            <Term label="Total value" value={formatMoney(terms?.total_value ?? contract.total_value, currency)} strong />
            <Term
              label="Recurring value"
              value={terms?.recurring_value ? formatMoney(terms.recurring_value, currency) : '—'}
            />
            <Term label="Payment frequency" value={humanise(terms?.payment_frequency ?? contract.payment_frequency)} />
            <Term label="Billing frequency" value={humanise(terms?.billing_frequency ?? contract.billing_frequency)} />
            <Term
              label="Payment terms"
              value={terms?.payment_terms_days ? `Net ${terms.payment_terms_days} days` : '—'}
            />
            <Term label="Late fee" value={terms?.late_fee_percent ? `${terms.late_fee_percent}%` : '—'} />
            <Term
              label="Escalation"
              value={terms?.price_escalation_percent ? `${terms.price_escalation_percent}% a year` : '—'}
            />
            <Term
              label="Security deposit"
              value={terms?.security_deposit ? formatMoney(terms.security_deposit, currency) : '—'}
            />
            <Term label="Tax treatment" value={humanise(terms?.tax_treatment)} />
          </dl>
        )}

        {!editing && (terms?.commercial_summary || contract.commercial_summary) ? (
          <p
            style={{
              fontSize: 13,
              lineHeight: 1.7,
              color: 'var(--color-text-secondary)',
              marginTop: 14,
              paddingTop: 14,
              borderTop: '1px solid var(--color-border-light)',
            }}
          >
            {terms?.commercial_summary ?? contract.commercial_summary}
          </p>
        ) : null}
      </Card>

      <Card padded={false}>
        <div style={{ padding: '13px 16px 0' }}>
          <CardHeader
            level={3}
            title="Payment schedule"
            description={
              schedules.length > SCHEDULE_PREVIEW_ROWS
                ? `The next ${SCHEDULE_PREVIEW_ROWS} of ${schedules.length} instalments.`
                : 'Instalments recorded against this contract.'
            }
            action={
              onOpenTab ? (
                <button
                  type="button"
                  onClick={() => onOpenTab('payments')}
                  style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 4,
                    background: 'none',
                    border: 'none',
                    padding: 0,
                    cursor: 'pointer',
                    fontSize: 12.5,
                    fontWeight: 700,
                    color: 'rgb(var(--color-primary))',
                  }}
                >
                  Manage in Payments
                  <ArrowRight size={13} aria-hidden />
                </button>
              ) : null
            }
          />
        </div>

        <DataTable
          columns={scheduleColumns}
          rows={schedules.slice(0, SCHEDULE_PREVIEW_ROWS)}
          rowKey={(row) => row.id}
          caption="Payment schedule for this contract"
          emptyTitle="No payment schedule"
          emptyDescription="Instalments, milestones and invoicing dates are recorded on the Payments tab."
        />
      </Card>
    </div>
  )
}

function Term({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
  return (
    <div>
      <dt
        style={{
          fontSize: 11,
          fontWeight: 700,
          textTransform: 'uppercase',
          letterSpacing: '.03em',
          color: 'var(--color-text-muted)',
        }}
      >
        {label}
      </dt>
      <dd
        style={{
          fontSize: strong ? 18 : 13.5,
          fontWeight: strong ? 800 : 600,
          color: 'var(--color-text)',
          marginTop: 3,
          fontVariantNumeric: 'tabular-nums',
        }}
      >
        {value}
      </dd>
    </div>
  )
}
