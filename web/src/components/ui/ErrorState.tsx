import { AlertCircle, RefreshCw } from 'lucide-react'
import { Button } from './Button'

/**
 * A failure the user can see and, where possible, act on.
 *
 * `detail` is the message the API actually returned. Hiding it behind
 * "something went wrong" makes every support conversation start with a
 * screen-share, so it is shown — the API is careful never to put a stack trace
 * or a credential in that field.
 */
export function ErrorState({
  title = 'That did not load',
  detail,
  onRetry,
  compact = false,
}: {
  title?: string
  detail?: string
  onRetry?: () => void
  compact?: boolean
}) {
  return (
    <div
      role="alert"
      style={{
        textAlign: 'center',
        padding: compact ? '24px 16px' : '48px 24px',
      }}
    >
      <div
        aria-hidden
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 46,
          height: 46,
          borderRadius: '50%',
          background: 'var(--color-danger-bg)',
          color: 'var(--color-danger)',
          marginBottom: 14,
        }}
      >
        <AlertCircle size={22} />
      </div>
      <h3 style={{ fontSize: 15, fontWeight: 700, color: 'var(--color-text)' }}>{title}</h3>
      {detail ? (
        <p
          style={{
            fontSize: 13,
            color: 'var(--color-text-secondary)',
            marginTop: 6,
            maxWidth: 520,
            marginInline: 'auto',
            lineHeight: 1.6,
          }}
        >
          {detail}
        </p>
      ) : null}
      {onRetry ? (
        <div style={{ marginTop: 18 }}>
          <Button variant="secondary" icon={<RefreshCw size={14} />} onClick={onRetry}>
            Try again
          </Button>
        </div>
      ) : null}
    </div>
  )
}
