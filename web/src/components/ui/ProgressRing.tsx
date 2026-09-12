/**
 * A 0–100 score as a ring.
 *
 * Used for contract health and risk. The number is always rendered in the
 * middle as well as encoded in the arc — a ring alone is not a value anyone can
 * read off precisely, and health scores get quoted in meetings.
 */
export function ProgressRing({
  value,
  size = 72,
  label,
  tone,
}: {
  value: number
  size?: number
  label?: string
  tone?: 'success' | 'warning' | 'danger' | 'primary'
}) {
  const clamped = Math.max(0, Math.min(100, Math.round(value)))
  const stroke = Math.max(5, Math.round(size / 11))
  const radius = (size - stroke) / 2
  const circumference = 2 * Math.PI * radius
  const dash = (clamped / 100) * circumference

  const resolvedTone =
    tone ?? (clamped >= 75 ? 'success' : clamped >= 50 ? 'warning' : 'danger')

  const colour = {
    success: 'var(--color-success)',
    warning: 'var(--color-warning)',
    danger: 'var(--color-danger)',
    primary: 'rgb(var(--color-primary))',
  }[resolvedTone]

  return (
    <div style={{ position: 'relative', width: size, height: size, flexShrink: 0 }}>
      <svg
        width={size}
        height={size}
        role="img"
        aria-label={`${label ? `${label}: ` : ''}${clamped} out of 100`}
        style={{ transform: 'rotate(-90deg)' }}
      >
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="rgb(var(--color-border))"
          strokeWidth={stroke}
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={colour}
          strokeWidth={stroke}
          strokeDasharray={`${dash} ${circumference - dash}`}
          strokeLinecap="round"
        />
      </svg>
      <div
        aria-hidden
        style={{
          position: 'absolute',
          inset: 0,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
        }}
      >
        <span style={{ fontSize: size / 3.4, fontWeight: 800, lineHeight: 1 }}>{clamped}</span>
        {label ? (
          <span style={{ fontSize: 9.5, color: 'var(--color-text-muted)', fontWeight: 700, marginTop: 2 }}>
            {label}
          </span>
        ) : null}
      </div>
    </div>
  )
}
