import { useState } from 'react'
import { AlarmClock, CalendarSync, RefreshCw, Sparkles } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  DateInput,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  Skeleton,
  StatusChip,
  Textarea,
  Tooltip,
} from '../../ui'
import { AiDisclaimer } from '../AiDisclaimer'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract } from '../../../types/contracts'
import { formatDate, formatDateTime, formatRelativeDays, humanise } from '../../../utils/format'

/**
 * The renewal cycle, its deadline and the decision.
 *
 * The notice deadline is the number that matters: an auto-renewing contract
 * nobody served notice on renews itself, and the date it became too late to
 * stop that is the one people miss. It is shown as a countdown, above the
 * recommendation and above the decision.
 */

type RenewalDecision = 'renew' | 'renegotiate' | 'terminate' | 'defer'

interface Renewal {
  id: number
  contract_id: number
  cycle_no: number
  current_expiry: string | null
  notice_deadline: string | null
  decision_due_date: string | null
  proposed_start: string | null
  proposed_expiry: string | null
  renewal_term_months: number | null
  status: string
  owner_uuid: string | null
  recommendation: 'renew' | 'renegotiate' | 'terminate' | 'review_manually' | null
  recommendation_reason: string | null
  recommendation_source: 'rules' | 'ai' | 'manual' | null
  decision: RenewalDecision | null
  decision_by: string | null
  decision_at: string | null
  decision_notes: string | null
  renegotiation_required: boolean
  renewed_contract_id: number | null
  notes: string | null
  days_to_decision: number | null
  days_to_notice: number | null
  days_to_expiry: number | null
  created_at: string
  updated_at: string
}

const CLOSED_STATUSES = ['renewed', 'closed']

const RECOMMENDATION_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
  renew: 'success',
  renegotiate: 'warning',
  terminate: 'danger',
  review_manually: 'info',
}

const SOURCE_LABEL: Record<string, string> = {
  rules: 'Recommended by the renewal rules',
  ai: 'Recommended by AI',
  manual: 'Set by a person',
}

function deadlineTone(days: number | null): 'danger' | 'warning' | 'neutral' {
  if (days === null) return 'neutral'
  if (days < 0 || days <= 14) return 'danger'
  if (days <= 45) return 'warning'
  return 'neutral'
}

export function RenewalTab({
  contractId,
  contract,
  onChanged,
}: {
  contractId: number
  contract: Contract
  onChanged: () => void
}) {
  const { can, session } = useSession()
  const toast = useToast()
  const canManage = can(PERMISSION.RENEWAL_MANAGE)
  const canTerminate = can(PERMISSION.CONTRACT_TERMINATE)
  const canAskAi = can(PERMISSION.AI_USE) && (session?.ai.configured ?? false)

  const resource = useApiResource<Renewal[]>(
    (signal) => api.get<Renewal[]>(`/contracts/${contractId}/renewals`, undefined, signal),
    [contractId],
  )

  const [deciding, setDeciding] = useState<{ renewal: Renewal; decision: RenewalDecision } | null>(null)
  const [busy, setBusy] = useState(false)

  const cycles = resource.data ?? []
  const current = cycles.find((cycle) => !CLOSED_STATUSES.includes(cycle.status)) ?? cycles[0] ?? null
  const history = cycles.filter((cycle) => cycle.id !== current?.id)

  const refresh = () => {
    resource.reload()
    onChanged()
  }

  const ensureCycle = async () => {
    setBusy(true)
    try {
      const renewal = await api.post<Renewal | null>(`/contracts/${contractId}/renewals/ensure`)
      toast.success(
        renewal ? `Renewal cycle ${renewal.cycle_no} opened` : 'Nothing to open',
        renewal ? undefined : 'This contract has no expiry date to renew against.',
      )
      refresh()
    } catch (err) {
      toast.error('Could not open a renewal cycle', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(false)
    }
  }

  const askAi = async () => {
    if (!current) return
    setBusy(true)
    try {
      await api.post(`/renewals/${current.id}/recommend`)
      toast.success('AI recommendation ready')
      refresh()
    } catch (err) {
      toast.error('Could not get a recommendation', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(false)
    }
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 16 }}>
        <Card>
          <Skeleton width="30%" height={14} />
          <div style={{ marginTop: 16, display: 'grid', gap: 12 }}>
            <Skeleton height={64} radius={10} />
            <Skeleton height={40} radius={10} />
          </div>
        </Card>
      </div>
    )
  }

  if (resource.error) {
    return <ErrorState title="Could not load renewals" detail={resource.error.message} onRetry={resource.reload} />
  }

  if (!current) {
    return (
      <EmptyState
        icon={<CalendarSync size={22} />}
        title="No renewal cycle yet"
        description="A cycle is opened automatically as the expiry date approaches, and holds the notice deadline, the recommendation and the decision. You can open one now if you want to decide early."
        action={
          canManage ? (
            <Button variant="primary" icon={<CalendarSync size={15} />} loading={busy} onClick={() => void ensureCycle()}>
              Open a renewal cycle
            </Button>
          ) : undefined
        }
      />
    )
  }

  const tone = deadlineTone(current.days_to_notice)
  const decided = current.decision !== null

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Card>
        <CardHeader
          level={3}
          title={`Renewal cycle ${current.cycle_no}`}
          description={
            contract.auto_renewal
              ? 'This contract renews itself unless notice is served before the deadline.'
              : 'This contract ends on its expiry date unless it is renewed.'
          }
          action={<StatusChip status={current.status} />}
        />

        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))',
            gap: 14,
            marginBottom: 16,
          }}
        >
          <Countdown
            label="Notice deadline"
            date={current.notice_deadline}
            days={current.days_to_notice}
            tone={tone}
            emphasis
          />
          <Countdown label="Current expiry" date={current.current_expiry} days={current.days_to_expiry} tone="neutral" />
          <Countdown
            label="Decision due"
            date={current.decision_due_date}
            days={current.days_to_decision}
            tone={deadlineTone(current.days_to_decision)}
          />
        </div>

        {current.notice_deadline && (current.days_to_notice ?? 0) < 0 && !decided ? (
          <p
            role="alert"
            style={{
              fontSize: 13,
              padding: '10px 13px',
              borderRadius: 'var(--radius-md)',
              background: 'var(--color-danger-bg)',
              border: '1px solid var(--color-danger-border)',
              marginBottom: 16,
            }}
          >
            The notice window closed on {formatDate(current.notice_deadline)}.
            {contract.auto_renewal
              ? ' Because this contract auto-renews, it will roll forward unless the counterparty agrees otherwise.'
              : ' Serving notice now may be outside the contractual window.'}
          </p>
        ) : null}

        <section
          style={{
            padding: 14,
            borderRadius: 'var(--radius-md)',
            border: '1px solid rgb(var(--color-border))',
            background: 'var(--color-bg-subtle)',
          }}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
            <h4 style={{ fontSize: 13, fontWeight: 700 }}>Recommendation</h4>
            {canManage && canAskAi ? (
              <Button size="sm" variant="secondary" icon={<Sparkles size={13} />} loading={busy} onClick={() => void askAi()}>
                {current.recommendation ? 'Ask AI again' : 'Ask AI'}
              </Button>
            ) : null}
          </div>

          {current.recommendation ? (
            <>
              <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginTop: 10 }}>
                <Chip tone={RECOMMENDATION_TONE[current.recommendation] ?? 'neutral'}>
                  {humanise(current.recommendation)}
                </Chip>
                <span style={{ fontSize: 12.5, color: 'var(--color-text-secondary)' }}>
                  {SOURCE_LABEL[current.recommendation_source ?? 'manual'] ?? 'Source not recorded'}
                </span>
              </div>
              {current.recommendation_reason ? (
                <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 9, lineHeight: 1.65 }}>
                  {current.recommendation_reason}
                </p>
              ) : null}
              {current.recommendation_source === 'ai' ? <AiDisclaimer compact /> : null}
            </>
          ) : (
            <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 9 }}>
              No recommendation yet. The renewal rules produce one as the deadline approaches
              {canAskAi ? ', or you can ask AI to read the contract and suggest a position now.' : '.'}
            </p>
          )}
        </section>

        {decided ? (
          <section style={{ marginTop: 16 }}>
            <h4 style={{ fontSize: 13, fontWeight: 700, marginBottom: 8 }}>Decision</h4>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              <Chip tone={RECOMMENDATION_TONE[current.decision ?? ''] ?? 'neutral'}>
                {humanise(current.decision ?? '')}
              </Chip>
              <span style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
                {current.decision_at ? formatDateTime(current.decision_at) : ''}
              </span>
              {current.renewal_term_months ? (
                <Chip tone="neutral" size="sm">
                  {current.renewal_term_months} month term
                </Chip>
              ) : null}
              {current.proposed_expiry ? (
                <Chip tone="neutral" size="sm">
                  New expiry {formatDate(current.proposed_expiry)}
                </Chip>
              ) : null}
            </div>
            {current.decision_notes ? (
              <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 9, lineHeight: 1.65 }}>
                {current.decision_notes}
              </p>
            ) : null}
          </section>
        ) : null}

        {canManage && !CLOSED_STATUSES.includes(current.status) ? (
          <div style={{ marginTop: 16 }}>
            <h4 style={{ fontSize: 13, fontWeight: 700, marginBottom: 9 }}>
              {decided ? 'Change the decision' : 'Decide'}
            </h4>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <Button variant="primary" size="sm" onClick={() => setDeciding({ renewal: current, decision: 'renew' })}>
                Renew
              </Button>
              <Button variant="secondary" size="sm" onClick={() => setDeciding({ renewal: current, decision: 'renegotiate' })}>
                Renegotiate
              </Button>
              {canTerminate ? (
                <Button variant="danger" size="sm" onClick={() => setDeciding({ renewal: current, decision: 'terminate' })}>
                  Terminate
                </Button>
              ) : (
                <Tooltip content="Terminating needs the contract termination permission">
                  <Button variant="danger" size="sm" disabled>
                    Terminate
                  </Button>
                </Tooltip>
              )}
              <Button variant="ghost" size="sm" onClick={() => setDeciding({ renewal: current, decision: 'defer' })}>
                Defer
              </Button>
            </div>
          </div>
        ) : null}
      </Card>

      {history.length > 0 ? (
        <Card>
          <CardHeader level={3} title="Earlier cycles" description="Every renewal this contract has been through." />
          <ul style={{ listStyle: 'none', display: 'grid', gap: 10 }}>
            {history.map((cycle) => (
              <li
                key={cycle.id}
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  gap: 12,
                  flexWrap: 'wrap',
                  padding: '10px 12px',
                  border: '1px solid rgb(var(--color-border))',
                  borderRadius: 'var(--radius-md)',
                }}
              >
                <div>
                  <p style={{ fontSize: 13, fontWeight: 600 }}>
                    <RefreshCw size={12} aria-hidden style={{ marginRight: 6, verticalAlign: -1 }} />
                    Cycle {cycle.cycle_no}
                    {cycle.decision ? ` · ${humanise(cycle.decision)}` : ''}
                  </p>
                  <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                    Expiry {formatDate(cycle.current_expiry)} · notice by {formatDate(cycle.notice_deadline)}
                    {cycle.decision_at ? ` · decided ${formatDateTime(cycle.decision_at)}` : ''}
                  </p>
                </div>
                <StatusChip status={cycle.status} size="sm" />
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {deciding ? (
        <DecisionModal
          renewal={deciding.renewal}
          decision={deciding.decision}
          onClose={() => setDeciding(null)}
          onSaved={() => {
            setDeciding(null)
            refresh()
          }}
        />
      ) : null}
    </div>
  )
}

function Countdown({
  label,
  date,
  days,
  tone,
  emphasis = false,
}: {
  label: string
  date: string | null
  days: number | null
  tone: 'danger' | 'warning' | 'neutral'
  emphasis?: boolean
}) {
  const colour =
    tone === 'danger' ? 'var(--color-danger)' : tone === 'warning' ? 'var(--color-warning)' : 'var(--color-text)'

  return (
    <div
      style={{
        padding: 13,
        borderRadius: 'var(--radius-md)',
        border: `1px solid ${
          tone === 'danger'
            ? 'var(--color-danger-border)'
            : tone === 'warning'
              ? 'var(--color-warning-border)'
              : 'rgb(var(--color-border))'
        }`,
        background:
          tone === 'danger' ? 'var(--color-danger-bg)' : tone === 'warning' ? 'var(--color-warning-bg)' : 'var(--color-bg-card)',
      }}
    >
      <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.03em', color: 'var(--color-text-muted)' }}>
        {emphasis ? <AlarmClock size={11} aria-hidden style={{ marginRight: 5, verticalAlign: -1 }} /> : null}
        {label}
      </p>
      <p style={{ fontSize: emphasis ? 17 : 15, fontWeight: 800, marginTop: 4 }}>{formatDate(date)}</p>
      <p style={{ fontSize: 12.5, fontWeight: 600, color: colour, marginTop: 2 }}>
        {date === null ? 'Not set' : formatRelativeDays(days)}
      </p>
    </div>
  )
}

const DECISION_COPY: Record<RenewalDecision, { title: string; description: string; confirm: string }> = {
  renew: {
    title: 'Renew this contract',
    description: 'Records the decision and opens the next term. Say how long it runs for.',
    confirm: 'Record renewal',
  },
  renegotiate: {
    title: 'Renegotiate before renewing',
    description: 'The cycle stays open and is flagged for renegotiation. Say what has to change.',
    confirm: 'Record decision',
  },
  terminate: {
    title: 'Let this contract end',
    description: 'Records the decision not to renew. Serving notice and closing the contract are separate steps on the termination process.',
    confirm: 'Record decision',
  },
  defer: {
    title: 'Defer the decision',
    description: 'The cycle stays open and comes back on the date you choose.',
    confirm: 'Defer',
  },
}

function DecisionModal({
  renewal,
  decision,
  onClose,
  onSaved,
}: {
  renewal: Renewal
  decision: RenewalDecision
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const copy = DECISION_COPY[decision]
  const [notes, setNotes] = useState('')
  const [termMonths, setTermMonths] = useState(renewal.renewal_term_months?.toString() ?? '12')
  const [proposedStart, setProposedStart] = useState(renewal.proposed_start ?? '')
  const [proposedExpiry, setProposedExpiry] = useState(renewal.proposed_expiry ?? '')
  const [deferUntil, setDeferUntil] = useState('')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      await api.post(`/renewals/${renewal.id}/decision`, {
        decision,
        notes: notes.trim() || null,
        renewal_term_months: decision === 'renew' && termMonths ? Number(termMonths) : null,
        proposed_start: decision === 'renew' ? proposedStart || null : null,
        proposed_expiry: decision === 'renew' ? proposedExpiry || null : null,
        defer_until: decision === 'defer' ? deferUntil || null : null,
      })
      toast.success(`Decision recorded: ${humanise(decision)}`)
      onSaved()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not record the decision', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={copy.title}
      description={copy.description}
      width={520}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button
            variant={decision === 'terminate' ? 'danger' : 'primary'}
            loading={saving}
            onClick={() => void submit()}
          >
            {copy.confirm}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        {decision === 'renew' ? (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 14 }}>
            <Input
              label="Term (months)"
              type="number"
              min={1}
              max={600}
              value={termMonths}
              error={errors.renewal_term_months}
              onChange={(event) => setTermMonths(event.target.value)}
            />
            <DateInput
              label="New term starts"
              value={proposedStart}
              error={errors.proposed_start}
              onChange={(event) => setProposedStart(event.target.value)}
            />
            <DateInput
              label="New expiry"
              value={proposedExpiry}
              error={errors.proposed_expiry}
              hint="Leave blank to derive it from the term."
              onChange={(event) => setProposedExpiry(event.target.value)}
            />
          </div>
        ) : null}

        {decision === 'defer' ? (
          <DateInput
            label="Come back on"
            value={deferUntil}
            error={errors.defer_until}
            hint="Leave blank to revisit in 30 days."
            onChange={(event) => setDeferUntil(event.target.value)}
          />
        ) : null}

        <Textarea
          label="Notes"
          rows={4}
          value={notes}
          error={errors.notes}
          hint="Why this is the right call — the next person to open this cycle reads it."
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>
    </Modal>
  )
}
