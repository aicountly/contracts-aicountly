import type { ReactNode } from 'react'

export function PageHeader({
  title,
  description,
  actions,
  breadcrumb,
}: {
  title: string
  description?: string
  actions?: ReactNode
  breadcrumb?: ReactNode
}) {
  return (
    <header style={{ marginBottom: 18 }}>
      {breadcrumb ? <div style={{ marginBottom: 8 }}>{breadcrumb}</div> : null}
      <div
        style={{
          display: 'flex',
          alignItems: 'flex-start',
          justifyContent: 'space-between',
          gap: 14,
          flexWrap: 'wrap',
        }}
      >
        <div style={{ minWidth: 0 }}>
          <h1 style={{ fontSize: 21, fontWeight: 800, color: 'var(--color-text)', letterSpacing: '-.01em' }}>
            {title}
          </h1>
          {description ? (
            <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 4, maxWidth: 720 }}>
              {description}
            </p>
          ) : null}
        </div>
        {actions ? (
          <div className="ct-no-print" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {actions}
          </div>
        ) : null}
      </div>
    </header>
  )
}
