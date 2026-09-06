import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  AlarmClock,
  CalendarClock,
  CircleCheck,
  ClipboardList,
  RefreshCw,
  Settings2,
  ShieldAlert,
  Sparkles,
} from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  EmptyState,
  ErrorState,
  PageHeader,
  Skeleton,
} from '../components/ui'
import { AiDisclaimer } from '../components/contracts/AiDisclaimer'
import { useSession } from '../context/SessionProvider'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import type { AiStatus, ContractListItem, Paged } from '../types/contracts'
import { formatDate, formatMoney, formatRelativeDays, humanise } from '../utils/format'

/**
 * What the portfolio needs a person to do about it.
 *
 * The screen is deliberately not "here is what the model said". It reads the
 * findings, the renewal deadlines, the overdue obligations and the expiry
 * window, and states each one as the action it implies — a notice deadline in
 * seventeen days is a diary entry, not an observation. Everything links to the
 * record it is about, because an insight you cannot act on from where you are
 * reading it gets forgotten.
 *
 * Rule-derived items are shown even when no AI provider is configured. They are
 * computed by Contracts itself, and hiding them behind an AI banner would be
 * both wrong and unhelpful.
 */

type Urgency = 'now' | 'soon' | 'watch'
type Category = 'deadline' | 'risk' | 'obligation' | 'expiry'
type Source = 'ai' | 'rules' | 'schedule'

interface Insight {
  id: string
  urgency: Urgency
  category: Category
  source: Source
  title: string
  detail: string | null
  contractId: number | null
  contractLabel: string | null
  counterparty: string | null
  to: string | null
  dueDate: string | null
  days: number | null
  badge: string | null
}

/** Findings across the portfolio, `GET /risks`. */
interface RiskFindingRow {
  id: number
  contract_id: number
  rule_key: string | null
  risk_category: string
  severity: string
  title: string
  detail: string | null
  recommendation: string | null
  detected_by: string
  ai_confidence: number | null
  review_status: string
  created_at: string
  contract_number: string | null
  contract_title: string | null
  counterparty_name: string | null
}

/** A renewal cycle, `GET /renewals`. */
interface RenewalRow {
  id: number
  contract_id: number
  contract_number: string | null
  contract_title: string | null
  counterparty_name: string | null
  status: string
  current_expiry: string | null
  notice_deadline: string | null
  decision_due_date: string | null
  auto_renewal: boolean
  days_to_notice: number | null
  days_to_expiry: number | null
  recommendation: string | null
  recommendation_reason: string | null
  recommendation_source: string | null
}

/** One instance of an obligation falling due, `GET /obligations`. */
interface OccurrenceRow {
  id: number
  obligation_id: number
  contract_id: number
  contract_number: string | null
  contract_title: string | null
  obligation_title: string | null
  obligation_type: string | null
  responsible_party: string | null
  due_date: string
  status: string
  amount: string | null
  currency: string | null
}

interface Sources {
  status: AiStatus | null
  findings: RiskFindingRow[]
  renewals: RenewalRow[]
  obligations: OccurrenceRow[]
  expiring: ContractListItem[]
  failures: { source: string; message: string }[]
}

const URGENCY: Record<Urgency, { label: string; description: string; colour: string; tone: 'danger' | 'warning' | 'neutral' }> = {
  now: {
    label: 'Act now',
    description: 'Past due, or the window closes within a week.',
    colour: 'var(--color-danger)',
    tone: 'danger',
  },
  soon: {
    label: 'This month',
    description: 'Needs a decision before it becomes urgent.',
    colour: 'var(--color-warning)',
    tone: 'warning',
  },
  watch: {
    label: 'Keep an eye on',
    description: 'Nothing to do today; worth knowing about.',
    colour: 'rgb(var(--color-border-strong))',
    tone: 'neutral',
  },
}

const CATEGORY_LABEL: Record<Category, string> = {
  deadline: 'Renewal deadlines',
  risk: 'Risk findings',
  obligation: 'Obligations',
  expiry: 'Expiring contracts',
}

const CATEGORY_ICON: Record<Category, typeof AlarmClock> = {
  deadline: AlarmClock,
  risk: ShieldAlert,
  obligation: ClipboardList,
  expiry: CalendarClock,
}

const SOURCE_LABEL: Record<Source, string> = {
  ai: 'Found by AI',
  rules: 'Playbook rule',
  schedule: 'Scheduled date',
}

/** Whole days from today, read in UTC so a date never shifts by a timezone. */
function daysUntil(value?: string | null): number | null {
  if (!value) return null
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  if (!match) return null

  const target = Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  const now = new Date()
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())

  return Math.round((target - today) / 86_400_000)
}

function urgencyForDays(days: number | null): Urgency {
  if (days === null) return 'watch'
  if (days <= 7) return 'now'
  if (days <= 30) return 'soon'
  return 'watch'
}

function urgencyForSeverity(severity: string): Urgency {
  if (severity === 'critical' || severity === 'high') return 'now'
  if (severity === 'medium') return 'soon'
  return 'watch'
}

function contractLabel(number: string | null, title: string | null): string | null {
  const parts = [number, title].filter((part): part is string => !!part && part.trim() !== '')
  return parts.length === 0 ? null : parts.join(' · ')
}

export default function AiInsights() {
  const { can } = useSession()
  const canSeeRisk = can(PERMISSION.AI_RISK_VIEW)
  const canSeeContracts = can(PERMISSION.CONTRACT_VIEW)

  const [category, setCategory] = useState<Category | 'all'>('all')
  const [aiOnly, setAiOnly] = useState(false)

  const resource = useApiResource<Sources>(
    async (signal) => {
      const calls: { key: keyof Sources | 'status'; label: string; run: Promise<unknown> }[] = [
        { key: 'status', label: 'AI status', run: api.get<AiStatus>('/ai/status', undefined, signal) },
      ]

      if (canSeeRisk) {
        calls.push({
          key: 'findings',
          label: 'Risk findings',
          run: api.get<Paged<RiskFindingRow>>('/risks', { per_page: 50, review_status: 'open' }, signal),
        })
      }

      if (canSeeContracts) {
        calls.push(
          {
            key: 'renewals',
            label: 'Renewal deadlines',
            run: api.get<Paged<RenewalRow>>('/renewals', { bucket: 'notice_due', per_page: 50 }, signal),
          },
          {
            key: 'obligations',
            label: 'Obligations',
            run: api.get<Paged<OccurrenceRow>>('/obligations', { overdue_only: 1, per_page: 50 }, signal),
          },
          {
            key: 'expiring',
            label: 'Expiring contracts',
            run: api.get<Paged<ContractListItem>>(
              '/contracts',
              { expiring_within_days: 60, sort: 'expiry_date', dir: 'asc', per_page: 50 },
              signal,
            ),
          },
        )
      }

      const settled = await Promise.allSettled(calls.map((call) => call.run))

      // One source refusing is not the page failing: a reader without the risk
      // permission still needs their renewal deadlines, and saying which part is
      // missing is more use than an error page over the whole screen.
      const result: Sources = {
        status: null,
        findings: [],
        renewals: [],
        obligations: [],
        expiring: [],
        failures: [],
      }

      settled.forEach((outcome, index) => {
        const call = calls[index]
        if (outcome.status === 'rejected') {
          result.failures.push({
            source: call.label,
            message: outcome.reason instanceof Error ? outcome.reason.message : 'Could not be read.',
          })
          return
        }

        if (call.key === 'status') {
          result.status = (outcome.value ?? null) as AiStatus | null
          return
        }

        const paged = outcome.value as Paged<unknown> | null
        const items = paged?.items ?? []
        if (call.key === 'findings') result.findings = items as RiskFindingRow[]
        if (call.key === 'renewals') result.renewals = items as RenewalRow[]
        if (call.key === 'obligations') result.obligations = items as OccurrenceRow[]
        if (call.key === 'expiring') result.expiring = items as ContractListItem[]
      })

      if (result.failures.length === calls.length) {
        throw new Error(result.failures[0]?.message ?? 'Nothing could be read.')
      }

      return result
    },
    [canSeeRisk, canSeeContracts],
  )

  const insights = useMemo<Insight[]>(() => {
    const data = resource.data
    if (!data) return []

    const out: Insight[] = []

    for (const finding of data.findings) {
      out.push({
        id: `risk-${finding.id}`,
        urgency: urgencyForSeverity(finding.severity),
        category: 'risk',
        source: finding.detected_by === 'ai' ? 'ai' : 'rules',
        title: finding.title,
        detail: finding.recommendation ?? finding.detail,
        contractId: finding.contract_id,
        contractLabel: contractLabel(finding.contract_number, finding.contract_title),
        counterparty: finding.counterparty_name,
        to: `/contracts/${finding.contract_id}?tab=risk`,
        dueDate: null,
        days: null,
        badge: `${humanise(finding.severity)} · ${humanise(finding.risk_category)}`,
      })
    }

    for (const renewal of data.renewals) {
      const days = renewal.days_to_notice ?? daysUntil(renewal.notice_deadline)
      const passed = days !== null && days < 0

      out.push({
        id: `renewal-${renewal.id}`,
        urgency: passed ? 'now' : urgencyForDays(days),
        category: 'deadline',
        source: renewal.recommendation_source === 'ai' ? 'ai' : 'schedule',
        title: passed
          ? `Renewal notice deadline passed ${formatRelativeDays(days)}`
          : `Renewal notice deadline is ${formatRelativeDays(days)}`,
        detail:
          renewal.recommendation_reason ??
          (renewal.auto_renewal
            ? 'This contract renews automatically unless notice is given before the deadline.'
            : 'Give notice or agree the next term before the deadline.'),
        contractId: renewal.contract_id,
        contractLabel: contractLabel(renewal.contract_number, renewal.contract_title),
        counterparty: renewal.counterparty_name,
        to: `/contracts/${renewal.contract_id}?tab=renewal`,
        dueDate: renewal.notice_deadline,
        days,
        badge: renewal.auto_renewal ? 'Auto-renewing' : renewal.recommendation ? humanise(renewal.recommendation) : null,
      })
    }

    for (const occurrence of data.obligations) {
      const days = daysUntil(occurrence.due_date)
      out.push({
        id: `obligation-${occurrence.id}`,
        urgency: urgencyForDays(days),
        category: 'obligation',
        source: 'schedule',
        title: `${occurrence.obligation_title ?? 'An obligation'} was due ${formatRelativeDays(days)}`,
        detail:
          occurrence.amount != null
            ? `${humanise(occurrence.responsible_party ?? 'unassigned')} · ${formatMoney(occurrence.amount, occurrence.currency ?? 'INR')}`
            : occurrence.responsible_party
              ? `Responsibility: ${humanise(occurrence.responsible_party)}`
              : null,
        contractId: occurrence.contract_id,
        contractLabel: contractLabel(occurrence.contract_number, occurrence.contract_title),
        counterparty: null,
        to: `/contracts/${occurrence.contract_id}?tab=obligations`,
        dueDate: occurrence.due_date,
        days,
        badge: humanise(occurrence.status),
      })
    }

    for (const contract of data.expiring) {
      const days = contract.days_to_expiry ?? daysUntil(contract.expiry_date)
      out.push({
        id: `expiry-${contract.id}`,
        urgency: urgencyForDays(days),
        category: 'expiry',
        source: 'schedule',
        title: `${contract.title} expires ${formatRelativeDays(days)}`,
        detail: contract.auto_renewal
          ? 'Auto-renewal is on, so it will roll over unless someone stops it.'
          : 'Decide whether to renew, renegotiate or let it lapse.',
        contractId: contract.id,
        contractLabel: contractLabel(contract.contract_number, null),
        counterparty: contract.counterparty_name,
        to: `/contracts/${contract.id}`,
        dueDate: contract.expiry_date,
        days,
        badge: contract.total_value ? formatMoney(contract.total_value, contract.currency, { compact: true }) : null,
      })
    }

    const order: Record<Urgency, number> = { now: 0, soon: 1, watch: 2 }

    return out.sort((a, b) => {
      if (order[a.urgency] !== order[b.urgency]) return order[a.urgency] - order[b.urgency]
      if (a.days !== null && b.days !== null) return a.days - b.days
      if (a.days !== null) return -1
      if (b.days !== null) return 1
      return a.title.localeCompare(b.title)
    })
  }, [resource.data])

  const visible = useMemo(
    () =>
      insights.filter(
        (insight) =>
          (category === 'all' || insight.category === category) && (!aiOnly || insight.source === 'ai'),
      ),
    [insights, category, aiOnly],
  )

  const counts = useMemo(() => {
    const byUrgency: Record<Urgency, number> = { now: 0, soon: 0, watch: 0 }
    for (const insight of visible) byUrgency[insight.urgency] += 1
    return byUrgency
  }, [visible])

  const categoryCounts = useMemo(() => {
    const map: Record<Category, number> = { deadline: 0, risk: 0, obligation: 0, expiry: 0 }
    for (const insight of insights) map[insight.category] += 1
    return map
  }, [insights])

  const status = resource.data?.status ?? null
  const aiConfigured = status?.configured ?? false
  const showsAiFindings = visible.some((insight) => insight.source === 'ai')

  return (
    <div>
      <PageHeader
        title="AI insights"
        description="Everything the portfolio is telling you, stated as the action it implies and grouped by how soon it needs one."
        actions={
          <Button variant="secondary" icon={<RefreshCw size={14} />} onClick={resource.reload}>
            Refresh
          </Button>
        }
      />

      <div style={{ display: 'grid', gap: 16 }}>
        <AiStatusCard status={status} loading={resource.loading} />

        {resource.data && resource.data.failures.length > 0 ? (
          <p
            role="status"
            style={{
              padding: '10px 13px',
              fontSize: 12.5,
              lineHeight: 1.6,
              background: 'var(--color-warning-bg)',
              border: '1px solid var(--color-warning-border)',
              color: 'var(--color-warning-text)',
              borderRadius: 'var(--radius-md)',
            }}
          >
            Part of this page could not be read, so something may be missing:{' '}
            {resource.data.failures.map((failure) => `${failure.source} — ${failure.message}`).join(' ')}
          </p>
        ) : null}

        {resource.loading ? (
          <LoadingInsights />
        ) : resource.error ? (
          <Card>
            <ErrorState
              title="Could not read the portfolio"
              detail={resource.error.message}
              onRetry={resource.reload}
            />
          </Card>
        ) : (
          <>
            <Card>
              <div
                style={{
                  display: 'flex',
                  gap: 18,
                  alignItems: 'center',
                  justifyContent: 'space-between',
                  flexWrap: 'wrap',
                }}
              >
                <div style={{ minWidth: 240, flex: '1 1 320px' }}>
                  <h2 style={{ fontSize: 14, fontWeight: 700 }}>
                    {visible.length} {visible.length === 1 ? 'item' : 'items'} for your attention
                  </h2>
                  <UrgencyBar counts={counts} />
                </div>

                <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }}>
                  <FilterChip
                    label="Everything"
                    count={insights.length}
                    active={category === 'all'}
                    onClick={() => setCategory('all')}
                  />
                  {(Object.keys(CATEGORY_LABEL) as Category[]).map((key) => (
                    <FilterChip
                      key={key}
                      label={CATEGORY_LABEL[key]}
                      count={categoryCounts[key]}
                      active={category === key}
                      onClick={() => setCategory(key)}
                    />
                  ))}
                  <FilterChip
                    label="AI-detected only"
                    count={insights.filter((insight) => insight.source === 'ai').length}
                    active={aiOnly}
                    onClick={() => setAiOnly((current) => !current)}
                  />
                </div>
              </div>
            </Card>

            <div aria-live="polite">
              {visible.length === 0 ? (
                <Card>
                  <EmptyState
                    icon={<CircleCheck size={22} />}
                    title={insights.length === 0 ? 'Nothing needs your attention' : 'Nothing matches that filter'}
                    description={
                      insights.length === 0
                        ? 'No notice deadline is close, no obligation is overdue, and no open risk finding is waiting on a decision. This page fills itself as contracts move.'
                        : 'Clear the filter to see everything the portfolio is reporting.'
                    }
                    action={
                      insights.length > 0 ? (
                        <Button
                          variant="secondary"
                          onClick={() => {
                            setCategory('all')
                            setAiOnly(false)
                          }}
                        >
                          Show everything
                        </Button>
                      ) : undefined
                    }
                  />
                </Card>
              ) : (
                (Object.keys(URGENCY) as Urgency[]).map((urgency) => {
                  const group = visible.filter((insight) => insight.urgency === urgency)
                  if (group.length === 0) return null

                  return (
                    <section key={urgency} style={{ marginBottom: 22 }}>
                      <header style={{ marginBottom: 10 }}>
                        <h2
                          style={{
                            fontSize: 13.5,
                            fontWeight: 700,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                          }}
                        >
                          <span
                            aria-hidden
                            style={{
                              width: 9,
                              height: 9,
                              borderRadius: 3,
                              background: URGENCY[urgency].colour,
                            }}
                          />
                          {URGENCY[urgency].label}
                          <span style={{ fontWeight: 600, color: 'var(--color-text-muted)' }}>{group.length}</span>
                        </h2>
                        <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
                          {URGENCY[urgency].description}
                        </p>
                      </header>

                      <ul style={{ listStyle: 'none', display: 'grid', gap: 10 }}>
                        {group.map((insight) => (
                          <li key={insight.id}>
                            <InsightCard insight={insight} />
                          </li>
                        ))}
                      </ul>
                    </section>
                  )
                })
              )}
            </div>

            {showsAiFindings ? (
              <Card>
                <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
                  Items marked <strong>Found by AI</strong> come from a model reading the contract
                  document. Everything else is a date or a rule Contracts computed itself.
                </p>
                <AiDisclaimer text={status?.disclaimer} compact />
              </Card>
            ) : null}

            {!aiConfigured && insights.length > 0 ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)', lineHeight: 1.6 }}>
                Everything above was computed from your own dates and playbook rules. Connecting an AI
                provider adds findings read out of the contract documents themselves.
              </p>
            ) : null}
          </>
        )}
      </div>
    </div>
  )
}

/**
 * What AI is, or is not, doing for this company.
 *
 * Stated plainly either way. A screen that quietly shows fewer findings because
 * no provider is configured turns a five-minute configuration job into a
 * mystery about why the product "does not find anything".
 */
function AiStatusCard({ status, loading }: { status: AiStatus | null; loading: boolean }) {
  if (loading) {
    return (
      <Card>
        <Skeleton width="30%" height={14} />
        <div style={{ marginTop: 10 }}>
          <Skeleton width="70%" height={11} />
        </div>
      </Card>
    )
  }

  if (status?.configured) {
    return (
      <Card>
        <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start', flexWrap: 'wrap' }}>
          <Sparkles size={18} aria-hidden style={{ color: 'rgb(var(--color-primary))', marginTop: 2 }} />
          <div style={{ minWidth: 0, flex: 1 }}>
            <h2 style={{ fontSize: 14, fontWeight: 700, display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
              AI analysis is connected
              <Chip tone="success" size="sm">
                Active
              </Chip>
            </h2>
            <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4 }}>
              {[status.provider, status.model].filter(Boolean).join(' · ') ||
                'A provider is configured for this company.'}
            </p>
          </div>
        </div>
      </Card>
    )
  }

  return (
    <Card style={{ borderColor: 'var(--color-warning-border)', background: 'var(--color-warning-bg)' }}>
      <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <Settings2 size={18} aria-hidden style={{ color: 'var(--color-warning)', marginTop: 2 }} />
        <div style={{ minWidth: 0, flex: 1 }}>
          <h2 style={{ fontSize: 14, fontWeight: 700, display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            AI analysis is not configured
            <Chip tone="warning" size="sm">
              Not connected
            </Chip>
          </h2>
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4, lineHeight: 1.6 }}>
            {status?.message?.trim()
              ? status.message
              : 'Contracts does not hold AI credentials. The provider and model are configured in Console by an administrator; once one is set, findings read out of the contract documents appear here alongside the dates and rules below.'}
          </p>
        </div>
      </div>
    </Card>
  )
}

/**
 * Where the attention is, at a glance.
 *
 * A stacked bar rather than three numbers: the useful question is what share of
 * the list is urgent, and a proportion is read faster than it is calculated.
 * Every segment is also written out in the legend, so the colour is a scanning
 * aid rather than the message.
 */
function UrgencyBar({ counts }: { counts: Record<Urgency, number> }) {
  const total = counts.now + counts.soon + counts.watch
  const keys: Urgency[] = ['now', 'soon', 'watch']

  if (total === 0) {
    return (
      <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 6 }}>
        Nothing in this view.
      </p>
    )
  }

  let offset = 0

  return (
    <div style={{ marginTop: 10 }}>
      <svg
        width="100%"
        height="12"
        viewBox="0 0 100 12"
        preserveAspectRatio="none"
        role="img"
        aria-label={keys.map((key) => `${counts[key]} ${URGENCY[key].label}`).join(', ')}
        style={{ display: 'block', borderRadius: 999, overflow: 'hidden', background: 'var(--color-bg-inset)' }}
      >
        {keys.map((key) => {
          const width = (counts[key] / total) * 100
          const x = offset
          offset += width
          if (width === 0) return null
          return <rect key={key} x={x} y="0" width={width} height="12" fill={URGENCY[key].colour} />
        })}
      </svg>

      <ul
        style={{
          listStyle: 'none',
          display: 'flex',
          gap: 14,
          flexWrap: 'wrap',
          marginTop: 8,
          fontSize: 12,
          color: 'var(--color-text-secondary)',
        }}
      >
        {keys.map((key) => (
          <li key={key} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span
              aria-hidden
              style={{ width: 9, height: 9, borderRadius: 3, background: URGENCY[key].colour, flexShrink: 0 }}
            />
            <strong style={{ color: 'var(--color-text)', fontVariantNumeric: 'tabular-nums' }}>{counts[key]}</strong>
            {URGENCY[key].label}
          </li>
        ))}
      </ul>
    </div>
  )
}

function FilterChip({
  label,
  count,
  active,
  onClick,
}: {
  label: string
  count: number
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 30,
        padding: '0 11px',
        borderRadius: 999,
        fontSize: 12.5,
        fontWeight: 600,
        cursor: 'pointer',
        background: active ? 'var(--color-primary-muted)' : 'var(--color-bg-card)',
        color: active ? 'rgb(var(--color-primary-active))' : 'var(--color-text-secondary)',
        border: `1px solid ${active ? 'var(--color-primary-border)' : 'rgb(var(--color-border-strong))'}`,
      }}
    >
      {label}
      <span style={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{count}</span>
    </button>
  )
}

function InsightCard({ insight }: { insight: Insight }) {
  const Icon = CATEGORY_ICON[insight.category]
  const tone = URGENCY[insight.urgency]

  return (
    <article
      className="ct-card"
      style={{
        padding: 14,
        borderLeft: `3px solid ${tone.colour}`,
        display: 'flex',
        gap: 12,
        alignItems: 'flex-start',
      }}
    >
      <span
        aria-hidden
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 30,
          height: 30,
          flexShrink: 0,
          borderRadius: 'var(--radius-md)',
          background: 'var(--color-bg-subtle)',
          color: 'var(--color-text-muted)',
        }}
      >
        <Icon size={15} />
      </span>

      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{ display: 'flex', gap: 10, justifyContent: 'space-between', flexWrap: 'wrap' }}>
          <h3 style={{ fontSize: 13.5, fontWeight: 700, minWidth: 0 }}>{insight.title}</h3>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            <Chip tone={insight.source === 'ai' ? 'info' : 'neutral'} size="sm">
              {insight.source === 'ai' ? <Sparkles size={11} aria-hidden /> : null}
              {SOURCE_LABEL[insight.source]}
            </Chip>
            {insight.badge ? (
              <Chip tone={tone.tone} size="sm">
                {insight.badge}
              </Chip>
            ) : null}
          </div>
        </div>

        {insight.detail ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 6, lineHeight: 1.6 }}>
            {insight.detail}
          </p>
        ) : null}

        <div
          style={{
            display: 'flex',
            gap: 12,
            alignItems: 'center',
            flexWrap: 'wrap',
            marginTop: 9,
            fontSize: 12,
            color: 'var(--color-text-muted)',
          }}
        >
          {insight.contractLabel ? <span>{insight.contractLabel}</span> : null}
          {insight.counterparty ? <span>{insight.counterparty}</span> : null}
          {insight.dueDate ? <span>{formatDate(insight.dueDate)}</span> : null}
          {insight.to ? (
            <Link to={insight.to} style={{ fontWeight: 700, fontSize: 12.5 }}>
              Open the contract
            </Link>
          ) : null}
        </div>
      </div>
    </article>
  )
}

function LoadingInsights() {
  return (
    <div style={{ display: 'grid', gap: 12 }} aria-hidden>
      <Card>
        <Skeleton width="26%" height={14} />
        <div style={{ marginTop: 12 }}>
          <Skeleton height={12} radius={999} />
        </div>
      </Card>
      {[0, 1, 2, 3].map((row) => (
        <Card key={row}>
          <Skeleton width="52%" height={13} />
          <div style={{ marginTop: 10, display: 'grid', gap: 8 }}>
            <Skeleton height={11} width="86%" />
            <Skeleton height={11} width="40%" />
          </div>
        </Card>
      ))}
    </div>
  )
}
