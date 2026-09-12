import type { ReactNode } from 'react'
import { Inbox } from 'lucide-react'

/**
 * What to show when there is nothing yet.
 *
 * An empty screen should say what belongs here and offer the first step, not
 * just report "no records found" — the two cases (nothing created yet, versus
 * a filter matching nothing) call for different words, which is why `action`
 * and `description` are the caller's to choose.
 */
export function EmptyState({
  title,
  description,
  icon,
  action,
  compact = false,
}: {
  title: string
  description?: string
  icon?: ReactNode
  action?: ReactNode
  compact?: boolean
}) {
  return (
    <div
      style={{
        textAlign: 'center',
        padding: compact ? '28px 16px' : '56px 24px',
        color: 'var(--color-text-secondary)',
      }}
    >
      <div
        aria-hidden
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: compact ? 40 : 52,
          height: compact ? 40 : 52,
          borderRadius: '50%',
          background: 'var(--color-bg-subtle)',
          color: 'var(--color-text-subtle)',
          marginBottom: 14,
        }}
      >
        {icon ?? <Inbox size={compact ? 19 : 24} />}
      </div>
      <h3 style={{ fontSize: compact ? 14 : 15.5, fontWeight: 700, color: 'var(--color-text)' }}>
        {title}
      </h3>
      {description ? (
        <p style={{ fontSize: 13, marginTop: 6, maxWidth: 460, marginInline: 'auto', lineHeight: 1.6 }}>
          {description}
        </p>
      ) : null}
      {action ? <div style={{ marginTop: 18 }}>{action}</div> : null}
    </div>
  )
}
