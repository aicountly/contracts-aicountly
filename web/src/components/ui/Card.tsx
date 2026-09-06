import type { CSSProperties, ReactNode } from 'react'

export function Card({
  children,
  padded = true,
  style,
  className,
}: {
  children: ReactNode
  padded?: boolean
  style?: CSSProperties
  className?: string
}) {
  return (
    <section
      className={`ct-card ${className ?? ''}`}
      style={{ padding: padded ? 18 : 0, ...style }}
    >
      {children}
    </section>
  )
}

export function CardHeader({
  title,
  description,
  action,
  level = 2,
}: {
  title: ReactNode
  description?: ReactNode
  action?: ReactNode
  /** Heading level, so a card nested in a section does not break the outline. */
  level?: 2 | 3 | 4
}) {
  const Heading = `h${level}` as 'h2' | 'h3' | 'h4'

  return (
    <header
      style={{
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'space-between',
        gap: 12,
        marginBottom: description ? 14 : 12,
      }}
    >
      <div style={{ minWidth: 0 }}>
        <Heading style={{ fontSize: 14.5, fontWeight: 700, color: 'var(--color-text)' }}>
          {title}
        </Heading>
        {description ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3 }}>
            {description}
          </p>
        ) : null}
      </div>
      {action ? <div style={{ flexShrink: 0 }}>{action}</div> : null}
    </header>
  )
}

export function CardBody({ children }: { children: ReactNode }) {
  return <div>{children}</div>
}
