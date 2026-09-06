import type { ReactNode } from 'react'

export interface TabItem {
  id: string
  label: string
  badge?: number | string
  icon?: ReactNode
}

/**
 * Tabs with real `role="tablist"` semantics and arrow-key navigation.
 *
 * The contract workspace has sixteen of these; without arrow keys a keyboard
 * user tabs through every one of them to reach the panel.
 */
export function Tabs({
  items,
  active,
  onChange,
  ariaLabel,
}: {
  items: TabItem[]
  active: string
  onChange: (id: string) => void
  ariaLabel: string
}) {
  const move = (delta: number) => {
    const index = items.findIndex((item) => item.id === active)
    if (index < 0) return
    const next = items[(index + delta + items.length) % items.length]
    onChange(next.id)
    document.getElementById(`tab-${next.id}`)?.focus()
  }

  return (
    <div
      role="tablist"
      aria-label={ariaLabel}
      className="ct-scroll-x ct-no-print"
      onKeyDown={(event) => {
        if (event.key === 'ArrowRight') {
          event.preventDefault()
          move(1)
        } else if (event.key === 'ArrowLeft') {
          event.preventDefault()
          move(-1)
        }
      }}
      style={{
        display: 'flex',
        gap: 2,
        borderBottom: '1px solid rgb(var(--color-border))',
      }}
    >
      {items.map((item) => {
        const selected = item.id === active
        return (
          <button
            key={item.id}
            id={`tab-${item.id}`}
            role="tab"
            type="button"
            aria-selected={selected}
            aria-controls={`panel-${item.id}`}
            tabIndex={selected ? 0 : -1}
            onClick={() => onChange(item.id)}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 6,
              padding: '9px 13px',
              background: 'none',
              border: 'none',
              borderBottom: `2px solid ${selected ? 'rgb(var(--color-primary))' : 'transparent'}`,
              color: selected ? 'var(--color-text)' : 'var(--color-text-secondary)',
              fontWeight: selected ? 700 : 600,
              fontSize: 13,
              cursor: 'pointer',
              whiteSpace: 'nowrap',
              marginBottom: -1,
            }}
          >
            {item.icon}
            {item.label}
            {item.badge != null && item.badge !== 0 ? (
              <span
                style={{
                  minWidth: 18,
                  padding: '0 5px',
                  borderRadius: 999,
                  background: selected ? 'var(--color-primary-muted)' : 'var(--color-bg-inset)',
                  color: selected ? 'rgb(var(--color-primary-active))' : 'var(--color-text-muted)',
                  fontSize: 10.5,
                  fontWeight: 700,
                  lineHeight: '17px',
                  textAlign: 'center',
                }}
              >
                {item.badge}
              </span>
            ) : null}
          </button>
        )
      })}
    </div>
  )
}
