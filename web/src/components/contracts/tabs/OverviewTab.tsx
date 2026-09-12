import type { ReactNode } from 'react'
import {
  ArrowRight,
  CalendarClock,
  ClipboardCheck,
  FileText,
  Gavel,
  Landmark,
  ListChecks,
  PenLine,
  RefreshCw,
  Users,
} from 'lucide-react'

import { Card, CardHeader, Chip, ErrorState, ProgressRing, RiskChip, Skeleton } from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { api } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract } from '../../../types/contracts'
import { formatDate, formatMoney, formatRelativeDays, humanise } from '../../../utils/format'

/**
 * The first screen of a contract.
 *
 * Overview answers the questions someone opening a contract actually has —
 * who it is with, when it runs to, what it is worth, what is about to be due —
 * and then hands off. Every block here links to the tab that owns the detail,
 * so this stays a page you read in ten seconds rather than a sixteenth copy of
 * everything.
 */

interface Props {
  contractId: number
  contract: Contract
  onChanged: () => void
  /** Moves the workspace to another tab, so a summary can hand off to its detail. */
  onOpenTab?: (tabId: string) => void
}

/** `GET /contracts/{id}/health`. */
interface HealthPayload {
  overall?: number | null
  categories?: Record<string, number | null> | null
  explanations?: (string | { title?: string | null; detail?: string | null })[] | null
}

/** `GET /contracts/{id}/obligations` — enough of it to name the next one due. */
interface ObligationSummary {
  id: number
  title?: string | null
  description?: string | null
  responsible_party?: string | null
  status?: string | null
  due_date?: string | null
  next_due_date?: string | null
  next_occurrence?: {
    id?: number
    due_date?: string | null
    status?: string | null
  } | null
}

const MS_PER_DAY = 86_400_000

/** Days since the epoch, in UTC — the same basis the API dates are stated in. */
function dayNumber(value?: string | null): number | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value ?? '')
  if (!match) return null
  return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])) / MS_PER_DAY
}

function dueDateOf(obligation: ObligationSummary): string | null {
  return obligation.next_occurrence?.due_date ?? obligation.next_due_date ?? obligation.due_date ?? null
}

function isOpen(obligation: ObligationSummary): boolean {
  const status = (obligation.next_occurrence?.status ?? obligation.status ?? '').toLowerCase()
  return !['completed', 'waived', 'not_applicable', 'cancelled'].includes(status)
}

export function OverviewTab({ contractId, contract, onOpenTab }: Props) {
  const { can } = useSession()
  const canViewCommercials = can(PERMISSION.COMMERCIALS_VIEW)

  const health = useApiResource<HealthPayload>(
    (signal) => api.get<HealthPayload>(`/contracts/${contractId}/health`, undefined, signal),
    [contractId],
  )

  const obligations = useApiResource<ObligationSummary[]>(
    (signal) => api.get<ObligationSummary[]>(`/contracts/${contractId}/obligations`, undefined, signal),
    [contractId],
  )

  const nextObligation = (obligations.data ?? [])
    .filter((item) => isOpen(item) && dueDateOf(item))
    .sort((a, b) => (dueDateOf(a) ?? '').localeCompare(dueDateOf(b) ?? ''))[0]

  const healthScore = health.data?.overall ?? contract.health_score
  const explanations = (health.data?.explanations ?? []).slice(0, 3)

  const counts = contract.tabs
  const approvalPending =
    contract.approval_status === 'pending' || contract.approval_status === 'in_progress'

  return (
    <div className="ct-ov-grid">
      <style>{`
        .ct-ov-grid { display: grid; gap: 16px; grid-template-columns: minmax(0, 1.8fr) minmax(0, 1fr); align-items: start; }
        .ct-ov-facts { display: grid; gap: 12px 20px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); }
        .ct-ov-counts { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(112px, 1fr)); }
        @media (max-width: 980px) { .ct-ov-grid { grid-template-columns: minmax(0, 1fr); } }
      `}</style>

      <div style={{ display: 'grid', gap: 16, minWidth: 0 }}>
        <Card>
          <CardHeader
            level={3}
            title="Key dates"
            description="Where this contract is in its term."
            action={
              <TabLink label="Amendments" tabId="amendments" onOpenTab={onOpenTab} />
            }
          />
          <TermBar contract={contract} />
          <dl className="ct-ov-facts" style={{ marginTop: 16 }}>
            <Fact label="Effective" value={formatDate(contract.effective_date)} />
            <Fact label="Commencement" value={formatDate(contract.commencement_date)} />
            <Fact
              label="Expiry"
              value={formatDate(contract.expiry_date)}
              note={
                contract.days_to_expiry !== null
                  ? formatRelativeDays(contract.days_to_expiry)
                  : undefined
              }
              tone={
                contract.days_to_expiry !== null && contract.days_to_expiry <= 30 ? 'danger' : undefined
              }
            />
            <Fact
              label="Notice deadline"
              value={formatDate(contract.notice_deadline)}
              note={
                contract.days_to_notice !== null
                  ? formatRelativeDays(contract.days_to_notice)
                  : contract.notice_period_days
                    ? `${contract.notice_period_days} days notice`
                    : undefined
              }
              tone={
                contract.days_to_notice !== null && contract.days_to_notice <= 30 ? 'warning' : undefined
              }
            />
            <Fact label="Executed" value={formatDate(contract.execution_date)} />
            <Fact
              label="Renewal"
              value={contract.auto_renewal ? 'Automatic' : humanise(contract.renewal_type)}
              note={contract.renewal_frequency ? humanise(contract.renewal_frequency) : undefined}
            />
          </dl>
        </Card>

        <Card>
          <CardHeader
            level={3}
            title="Counterparty and ownership"
            action={<TabLink label="Parties" tabId="parties" onOpenTab={onOpenTab} />}
          />
          <dl className="ct-ov-facts">
            <Fact label="Counterparty" value={contract.counterparty_name ?? 'Not recorded'} icon={<Users size={13} />} />
            <Fact label="Owner" value={contract.owner_uuid ?? 'Unassigned'} mono />
            <Fact label="Contract type" value={contract.contract_type_name ?? '—'} />
            <Fact label="Department" value={contract.department_name ?? '—'} />
            <Fact label="Governing law" value={contract.governing_law ?? '—'} icon={<Gavel size={13} />} />
            <Fact label="Jurisdiction" value={contract.jurisdiction ?? '—'} icon={<Landmark size={13} />} />
          </dl>
        </Card>

        {contract.description || contract.notes ? (
          <Card>
            <CardHeader level={3} title="Summary" />
            {contract.description ? (
              <p style={{ fontSize: 13.5, lineHeight: 1.7, color: 'var(--color-text)' }}>
                {contract.description}
              </p>
            ) : null}
            {contract.notes ? (
              <p
                style={{
                  fontSize: 13,
                  lineHeight: 1.7,
                  color: 'var(--color-text-secondary)',
                  marginTop: contract.description ? 12 : 0,
                  paddingTop: contract.description ? 12 : 0,
                  borderTop: contract.description ? '1px solid var(--color-border-light)' : undefined,
                }}
              >
                {contract.notes}
              </p>
            ) : null}
          </Card>
        ) : null}
      </div>

      <div style={{ display: 'grid', gap: 16, minWidth: 0 }}>
        <Card>
          <CardHeader
            level={3}
            title="Health and risk"
            action={<TabLink label="Risk" tabId="risk" onOpenTab={onOpenTab} />}
          />

          {health.loading ? (
            <div style={{ display: 'grid', gap: 8 }} role="status" aria-label="Loading health">
              <Skeleton height={72} width={72} radius={36} />
              <Skeleton height={12} width="70%" />
              <Skeleton height={12} width="55%" />
            </div>
          ) : health.error ? (
            <ErrorState
              compact
              title="Health unavailable"
              detail={health.error.message}
              onRetry={health.reload}
            />
          ) : (
            <>
              <div style={{ display: 'flex', alignItems: 'center', gap: 14, flexWrap: 'wrap' }}>
                {healthScore !== null && healthScore !== undefined ? (
                  <ProgressRing value={healthScore} label="Health" />
                ) : (
                  <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
                    No health score yet.
                  </p>
                )}
                <div style={{ display: 'grid', gap: 6 }}>
                  <RiskChip level={contract.risk_level} score={contract.ai_risk_score} />
                  <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                    {counts.risks} risk {counts.risks === 1 ? 'finding' : 'findings'} recorded
                  </span>
                </div>
              </div>

              {explanations.length > 0 ? (
                <ul style={{ listStyle: 'none', display: 'grid', gap: 6, marginTop: 12 }}>
                  {explanations.map((item, index) => {
                    const text =
                      typeof item === 'string' ? item : (item.title ?? item.detail ?? '')
                    if (!text) return null
                    return (
                      <li
                        key={index}
                        style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}
                      >
                        {text}
                      </li>
                    )
                  })}
                </ul>
              ) : null}
            </>
          )}
        </Card>

        <Card>
          <CardHeader level={3} title="Needs attention" />

          <div style={{ display: 'grid', gap: 12 }}>
            {obligations.loading ? (
              <div style={{ display: 'grid', gap: 6 }} role="status" aria-label="Loading obligations">
                <Skeleton height={12} width="40%" />
                <Skeleton height={12} width="75%" />
              </div>
            ) : obligations.error ? (
              <ErrorState
                compact
                title="Obligations unavailable"
                detail={obligations.error.message}
                onRetry={obligations.reload}
              />
            ) : nextObligation ? (
              <AttentionRow
                icon={<ListChecks size={14} />}
                title={nextObligation.title || 'Next obligation'}
                detail={`Due ${formatDate(dueDateOf(nextObligation))}`}
                chip={
                  nextObligation.next_occurrence?.status || nextObligation.status ? (
                    <Chip
                      size="sm"
                      tone={
                        (nextObligation.next_occurrence?.status ?? nextObligation.status) === 'overdue'
                          ? 'danger'
                          : 'neutral'
                      }
                    >
                      {humanise(nextObligation.next_occurrence?.status ?? nextObligation.status)}
                    </Chip>
                  ) : null
                }
                tabId="obligations"
                onOpenTab={onOpenTab}
              />
            ) : (
              <AttentionRow
                icon={<ListChecks size={14} />}
                title="No obligation is due"
                detail={
                  counts.obligations > 0
                    ? `${counts.obligations} recorded, none outstanding`
                    : 'None have been recorded for this contract'
                }
                tabId="obligations"
                onOpenTab={onOpenTab}
              />
            )}

            <AttentionRow
              icon={<ClipboardCheck size={14} />}
              title={approvalPending ? 'Approval in progress' : 'Approvals'}
              detail={
                contract.approval_status
                  ? humanise(contract.approval_status)
                  : counts.approvals > 0
                    ? `${counts.approvals} on record`
                    : 'Not submitted'
              }
              chip={
                approvalPending ? (
                  <Chip size="sm" tone="warning">
                    Waiting
                  </Chip>
                ) : null
              }
              tabId="approvals"
              onOpenTab={onOpenTab}
            />

            <AttentionRow
              icon={<PenLine size={14} />}
              title="Signature"
              detail={humanise(contract.signing_status ?? 'not_started')}
              tabId="document"
              onOpenTab={onOpenTab}
            />

            {contract.auto_renewal || contract.renewal_type ? (
              <AttentionRow
                icon={<RefreshCw size={14} />}
                title={contract.auto_renewal ? 'Renews automatically' : 'Renewal'}
                detail={
                  contract.notice_deadline
                    ? `Notice by ${formatDate(contract.notice_deadline)}`
                    : humanise(contract.renewal_type)
                }
                tabId="renewal"
                onOpenTab={onOpenTab}
              />
            ) : null}
          </div>
        </Card>

        {canViewCommercials ? (
          <Card>
            <CardHeader
              level={3}
              title="Value"
              action={<TabLink label="Commercials" tabId="commercials" onOpenTab={onOpenTab} />}
            />
            <p
              style={{
                fontSize: 22,
                fontWeight: 800,
                letterSpacing: '-.01em',
                fontVariantNumeric: 'tabular-nums',
              }}
            >
              {formatMoney(contract.total_value, contract.currency || 'INR')}
            </p>
            <dl className="ct-ov-facts" style={{ marginTop: 12 }}>
              {contract.recurring_value ? (
                <Fact
                  label="Recurring"
                  value={formatMoney(contract.recurring_value, contract.currency || 'INR')}
                  note={contract.payment_frequency ? humanise(contract.payment_frequency) : undefined}
                />
              ) : null}
              <Fact label="Billing" value={humanise(contract.billing_frequency)} />
            </dl>
            {contract.commercial_summary ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 12, lineHeight: 1.6 }}>
                {contract.commercial_summary}
              </p>
            ) : null}
          </Card>
        ) : null}

        <Card>
          <CardHeader level={3} title="Where the detail lives" />
          <div className="ct-ov-counts">
            <CountLink label="Documents" count={counts.documents} tabId="document" onOpenTab={onOpenTab} icon={<FileText size={13} />} />
            <CountLink label="Versions" count={counts.versions} tabId="versions" onOpenTab={onOpenTab} />
            <CountLink label="Parties" count={counts.parties} tabId="parties" onOpenTab={onOpenTab} />
            <CountLink label="Clauses" count={counts.clauses} tabId="clauses" onOpenTab={onOpenTab} />
            <CountLink label="Obligations" count={counts.obligations} tabId="obligations" onOpenTab={onOpenTab} />
            <CountLink label="Milestones" count={counts.milestones} tabId="milestones" onOpenTab={onOpenTab} />
            <CountLink label="Amendments" count={counts.amendments} tabId="amendments" onOpenTab={onOpenTab} />
            <CountLink label="Linked" count={counts.links} tabId="links" onOpenTab={onOpenTab} />
          </div>
        </Card>
      </div>
    </div>
  )
}

/**
 * The contract's term as a bar.
 *
 * Inline SVG rather than a chart dependency — it is one rectangle, a marker for
 * the notice deadline and a marker for today. The dates either side are printed
 * in text, so the bar is an orientation aid and never the only way to read a
 * date.
 */
function TermBar({ contract }: { contract: Contract }) {
  const start = dayNumber(contract.effective_date ?? contract.commencement_date)
  const end = dayNumber(contract.expiry_date)
  const notice = dayNumber(contract.notice_deadline)
  const today = Math.floor(Date.now() / MS_PER_DAY)

  if (start === null || end === null || end <= start) {
    return (
      <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
        {contract.expiry_date
          ? 'A start date is needed before the term can be drawn.'
          : 'This contract has no end date recorded — it runs until it is terminated.'}
      </p>
    )
  }

  const span = end - start
  const clamp = (fraction: number) => Math.max(0, Math.min(1, fraction))
  const elapsed = clamp((today - start) / span)
  const noticeAt = notice === null ? null : clamp((notice - start) / span)

  return (
    <div>
      <svg
        viewBox="0 0 100 10"
        preserveAspectRatio="none"
        role="img"
        aria-label={`Term from ${formatDate(contract.effective_date)} to ${formatDate(
          contract.expiry_date,
        )}, ${Math.round(elapsed * 100)} per cent elapsed`}
        style={{ width: '100%', height: 10, display: 'block' }}
      >
        <rect x={0} y={3} width={100} height={4} rx={2} fill="rgb(var(--color-border))" />
        <rect x={0} y={3} width={elapsed * 100} height={4} rx={2} fill="rgb(var(--color-primary))" />
        {noticeAt !== null ? (
          <rect x={Math.min(99.4, noticeAt * 100)} y={0} width={0.6} height={10} fill="var(--color-warning)" />
        ) : null}
        <rect x={Math.min(99.4, elapsed * 100)} y={0} width={0.6} height={10} fill="var(--color-text)" />
      </svg>

      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          gap: 10,
          fontSize: 11.5,
          color: 'var(--color-text-muted)',
          marginTop: 6,
        }}
      >
        <span>{formatDate(contract.effective_date ?? contract.commencement_date)}</span>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
          <CalendarClock size={12} aria-hidden />
          {Math.round(elapsed * 100)}% elapsed
        </span>
        <span>{formatDate(contract.expiry_date)}</span>
      </div>
    </div>
  )
}

function Fact({
  label,
  value,
  note,
  icon,
  mono = false,
  tone,
}: {
  label: string
  value: ReactNode
  note?: string
  icon?: ReactNode
  mono?: boolean
  tone?: 'danger' | 'warning'
}) {
  return (
    <div style={{ minWidth: 0 }}>
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
          display: 'flex',
          alignItems: 'center',
          gap: 6,
          fontSize: 13.5,
          fontWeight: 600,
          color: 'var(--color-text)',
          marginTop: 3,
          wordBreak: mono ? 'break-all' : undefined,
        }}
      >
        {icon ? <span style={{ color: 'var(--color-text-subtle)', lineHeight: 0 }}>{icon}</span> : null}
        {value}
      </dd>
      {note ? (
        <p
          style={{
            fontSize: 11.5,
            marginTop: 2,
            color:
              tone === 'danger'
                ? 'var(--color-danger)'
                : tone === 'warning'
                  ? 'var(--color-warning-text)'
                  : 'var(--color-text-muted)',
          }}
        >
          {note}
        </p>
      ) : null}
    </div>
  )
}

function AttentionRow({
  icon,
  title,
  detail,
  chip,
  tabId,
  onOpenTab,
}: {
  icon: ReactNode
  title: string
  detail: string
  chip?: ReactNode
  tabId: string
  onOpenTab?: (tabId: string) => void
}) {
  const body = (
    <>
      <span style={{ color: 'var(--color-text-subtle)', lineHeight: 0, flexShrink: 0 }} aria-hidden>
        {icon}
      </span>
      <span style={{ minWidth: 0, flex: 1 }}>
        <span style={{ display: 'block', fontSize: 13, fontWeight: 600, color: 'var(--color-text)' }}>
          {title}
        </span>
        <span style={{ display: 'block', fontSize: 12, color: 'var(--color-text-secondary)' }}>
          {detail}
        </span>
      </span>
      {chip}
    </>
  )

  const style = {
    display: 'flex',
    alignItems: 'center',
    gap: 10,
    width: '100%',
    textAlign: 'left' as const,
    padding: '8px 10px',
    borderRadius: 'var(--radius-md)',
    border: '1px solid rgb(var(--color-border))',
    background: 'var(--color-bg-card)',
  }

  if (!onOpenTab) {
    return <div style={style}>{body}</div>
  }

  return (
    <button type="button" onClick={() => onOpenTab(tabId)} style={{ ...style, cursor: 'pointer' }}>
      {body}
    </button>
  )
}

function CountLink({
  label,
  count,
  tabId,
  onOpenTab,
  icon,
}: {
  label: string
  count: number
  tabId: string
  onOpenTab?: (tabId: string) => void
  icon?: ReactNode
}) {
  const content = (
    <>
      <span style={{ fontSize: 18, fontWeight: 800, lineHeight: 1.2, fontVariantNumeric: 'tabular-nums' }}>
        {count}
      </span>
      <span
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: 4,
          fontSize: 11.5,
          color: 'var(--color-text-secondary)',
        }}
      >
        {icon}
        {label}
      </span>
    </>
  )

  const style = {
    display: 'flex',
    flexDirection: 'column' as const,
    gap: 2,
    padding: '9px 10px',
    borderRadius: 'var(--radius-md)',
    border: '1px solid rgb(var(--color-border))',
    background: 'var(--color-bg-subtle)',
    textAlign: 'left' as const,
    color: 'var(--color-text)',
  }

  if (!onOpenTab) return <div style={style}>{content}</div>

  return (
    <button
      type="button"
      onClick={() => onOpenTab(tabId)}
      aria-label={`${label}: ${count}. Open the ${label} tab`}
      style={{ ...style, cursor: 'pointer' }}
    >
      {content}
    </button>
  )
}

function TabLink({
  label,
  tabId,
  onOpenTab,
}: {
  label: string
  tabId: string
  onOpenTab?: (tabId: string) => void
}) {
  if (!onOpenTab) return null

  return (
    <button
      type="button"
      onClick={() => onOpenTab(tabId)}
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
      {label}
      <ArrowRight size={13} aria-hidden />
    </button>
  )
}
