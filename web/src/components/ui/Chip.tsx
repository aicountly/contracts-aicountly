import type { ReactNode } from 'react'

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'primary'

const TONE: Record<Tone, { bg: string; fg: string; border: string }> = {
  neutral: { bg: 'var(--color-neutral-bg)', fg: 'var(--color-neutral)', border: 'var(--color-neutral-border)' },
  success: { bg: 'var(--color-success-bg)', fg: 'var(--color-success)', border: 'var(--color-success-border)' },
  warning: { bg: 'var(--color-warning-bg)', fg: 'var(--color-warning-text)', border: 'var(--color-warning-border)' },
  danger: { bg: 'var(--color-danger-bg)', fg: 'var(--color-danger)', border: 'var(--color-danger-border)' },
  info: { bg: 'var(--color-info-bg)', fg: 'var(--color-info)', border: 'var(--color-info-border)' },
  primary: { bg: 'var(--color-primary-muted)', fg: 'rgb(var(--color-primary-active))', border: 'var(--color-primary-border)' },
}

export function Chip({
  children,
  tone = 'neutral',
  title,
  size = 'md',
}: {
  children: ReactNode
  tone?: Tone
  title?: string
  size?: 'sm' | 'md'
}) {
  const c = TONE[tone]
  return (
    <span
      title={title}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        padding: size === 'sm' ? '1px 7px' : '2px 9px',
        fontSize: size === 'sm' ? 11 : 11.5,
        fontWeight: 700,
        lineHeight: 1.6,
        borderRadius: 999,
        background: c.bg,
        color: c.fg,
        border: `1px solid ${c.border}`,
        whiteSpace: 'nowrap',
      }}
    >
      {children}
    </span>
  )
}

/**
 * Status colour carries meaning, so it is defined once here rather than at
 * every call site — a contract shown as green on one screen and grey on
 * another is worse than no colour at all.
 *
 * The label is spelled out as well as coloured: colour alone fails for anyone
 * who cannot distinguish these hues.
 */
const CONTRACT_STATUS_TONE: Record<string, Tone> = {
  draft: 'neutral',
  under_review: 'info',
  awaiting_approval: 'warning',
  approved: 'primary',
  negotiation: 'info',
  awaiting_signature: 'warning',
  active: 'success',
  renewal_review: 'warning',
  expired: 'danger',
  terminated: 'danger',
  cancelled: 'neutral',
  archived: 'neutral',

  // Requests
  submitted: 'info',
  more_info_required: 'warning',
  approved_for_drafting: 'primary',
  rejected: 'danger',
  converted: 'success',

  // Obligations and occurrences
  upcoming: 'neutral',
  due: 'warning',
  overdue: 'danger',
  completed: 'success',
  waived: 'neutral',
  not_applicable: 'neutral',
  disputed: 'warning',

  // Approvals
  pending: 'warning',
  in_progress: 'info',
  sent_back: 'warning',

  // Renewals
  not_yet_due: 'neutral',
  review_due: 'warning',
  renew: 'success',
  renegotiate: 'warning',
  terminate: 'danger',
  renewal_in_progress: 'info',
  renewed: 'success',
  closed: 'neutral',

  // Signature
  not_started: 'neutral',
  sent: 'info',
  viewed: 'info',
  partially_signed: 'warning',
  signed: 'success',
  declined: 'danger',

  // Milestones
  missed: 'danger',

  // AI / jobs
  queued: 'neutral',
  running: 'info',
  succeeded: 'success',
  failed: 'danger',
}

export function humanise(value: string): string {
  return value
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export function StatusChip({ status, size }: { status: string; size?: 'sm' | 'md' }) {
  return (
    <Chip tone={CONTRACT_STATUS_TONE[status] ?? 'neutral'} size={size}>
      {humanise(status)}
    </Chip>
  )
}

const RISK_TONE: Record<string, Tone> = {
  low: 'success',
  medium: 'warning',
  high: 'danger',
  critical: 'danger',
  informational: 'info',
}

export function RiskChip({ level, score }: { level?: string | null; score?: number | null }) {
  if (!level) return <span style={{ color: 'var(--color-text-subtle)', fontSize: 12.5 }}>—</span>

  return (
    <Chip
      tone={RISK_TONE[level] ?? 'neutral'}
      title={score != null ? `Risk score ${score} of 100` : undefined}
    >
      {humanise(level)}
      {score != null ? <span style={{ opacity: 0.75, fontWeight: 600 }}>{score}</span> : null}
    </Chip>
  )
}
