import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import type { LucideIcon } from 'lucide-react'

import { Skeleton } from '../ui'

/**
 * One headline figure, and the way to the rows behind it.
 *
 * Every tile is a link: a number nobody can drill into is decoration, and the
 * first question anyone asks of "14 expiring soon" is "which fourteen".
 */

export type KpiTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info'

const TONE: Record<KpiTone, { fg: string; bg: string }> = {
  neutral: { fg: 'var(--color-neutral)', bg: 'var(--color-neutral-bg)' },
  primary: { fg: 'rgb(var(--color-primary-active))', bg: 'var(--color-primary-muted)' },
  success: { fg: 'var(--color-success)', bg: 'var(--color-success-bg)' },
  warning: { fg: 'var(--color-warning)', bg: 'var(--color-warning-bg)' },
  danger: { fg: 'var(--color-danger)', bg: 'var(--color-danger-bg)' },
  info: { fg: 'var(--color-info)', bg: 'var(--color-info-bg)' },
}

/**
 * The grid the tiles sit in, and the one place their hover state is defined —
 * inline styles cannot express `:hover`, and thirteen identical `<style>` tags
 * is not an improvement on one.
 */
export function KpiGrid({ children }: { children: ReactNode }) {
  return (
    <div>
      <style>{`
        .ct-kpi {
          display: block;
          padding: 14px 15px;
          color: inherit;
          transition: border-color .12s ease, box-shadow .12s ease, transform .12s ease;
        }
        .ct-kpi:hover {
          border-color: rgb(var(--color-primary) / .5);
          box-shadow: var(--shadow-md);
          transform: translateY(-1px);
        }
        .ct-kpi:hover .ct-kpi-label { color: rgb(var(--color-primary-active)); }
      `}</style>
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fill, minmax(192px, 1fr))',
          gap: 12,
        }}
      >
        {children}
      </div>
    </div>
  )
}

export function KpiTile({
  label,
  value,
  to,
  icon: Icon,
  tone = 'neutral',
  note,
  emphasis,
  fullValue,
}: {
  label: string
  /** Already formatted. `null` means the API did not return this figure. */
  value: string | null
  to: string
  icon: LucideIcon
  tone?: KpiTone
  /** What the number counts — the window, the unit, the qualifier. */
  note?: string
  /** A word next to the colour, so the tone is never carried by hue alone. */
  emphasis?: string
  /** The uncompacted figure, for the tooltip on a money tile. */
  fullValue?: string
}) {
  const colours = TONE[tone]
  const unavailable = value === null

  return (
    <Link to={to} className="ct-card ct-kpi">
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
        <span
          aria-hidden
          style={{
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            width: 28,
            height: 28,
            borderRadius: 8,
            background: colours.bg,
            color: colours.fg,
            flexShrink: 0,
          }}
        >
          <Icon size={15} />
        </span>

        <div style={{ minWidth: 0, flex: 1 }}>
          <span
            className="ct-kpi-label"
            style={{
              display: 'block',
              fontSize: 11.5,
              fontWeight: 700,
              color: 'var(--color-text-secondary)',
              letterSpacing: '.01em',
            }}
          >
            {label}
          </span>

          <span
            title={fullValue}
            style={{
              display: 'block',
              marginTop: 4,
              fontSize: 22,
              lineHeight: 1.15,
              fontWeight: 800,
              color: unavailable ? 'var(--color-text-subtle)' : 'var(--color-text)',
              fontVariantNumeric: 'tabular-nums',
              overflowWrap: 'anywhere',
            }}
          >
            {value ?? '—'}
          </span>

          {unavailable ? (
            <span style={{ display: 'block', fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 3 }}>
              Not available
            </span>
          ) : null}

          {emphasis && !unavailable ? (
            <span
              style={{
                display: 'inline-block',
                marginTop: 6,
                padding: '1px 7px',
                borderRadius: 999,
                fontSize: 10.5,
                fontWeight: 700,
                background: colours.bg,
                color: colours.fg,
              }}
            >
              {emphasis}
            </span>
          ) : null}

          {note ? (
            <span
              style={{
                display: 'block',
                fontSize: 11.5,
                color: 'var(--color-text-muted)',
                marginTop: emphasis ? 5 : 4,
              }}
            >
              {note}
            </span>
          ) : null}
        </div>
      </div>
    </Link>
  )
}

export function KpiGridSkeleton({ count = 13 }: { count?: number }) {
  return (
    <div
      role="status"
      aria-label="Loading key figures"
      style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fill, minmax(192px, 1fr))',
        gap: 12,
      }}
    >
      <span className="ct-sr-only">Loading key figures…</span>
      {Array.from({ length: count }).map((_, index) => (
        <div key={index} className="ct-card" style={{ padding: 14 }}>
          <div style={{ display: 'flex', gap: 10 }}>
            <Skeleton width={28} height={28} radius={8} />
            <div style={{ flex: 1, display: 'grid', gap: 8 }}>
              <Skeleton width="70%" height={10} />
              <Skeleton width="45%" height={20} />
            </div>
          </div>
        </div>
      ))}
    </div>
  )
}
