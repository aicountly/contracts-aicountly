/**
 * Loading placeholders shaped like the content they replace.
 *
 * A spinner in the middle of an empty page tells the user nothing about what is
 * coming; a skeleton that matches the table about to appear stops the layout
 * jumping when it does.
 */

export function Skeleton({
  width = '100%',
  height = 14,
  radius = 6,
}: {
  width?: number | string
  height?: number | string
  radius?: number
}) {
  return (
    <div
      className="ct-skeleton"
      aria-hidden
      style={{ width, height, borderRadius: radius }}
    />
  )
}

export function SkeletonTable({ rows = 6, columns = 5 }: { rows?: number; columns?: number }) {
  return (
    <div role="status" aria-label="Loading" style={{ display: 'grid', gap: 10 }}>
      <span className="ct-sr-only">Loading…</span>
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div
          key={rowIndex}
          style={{
            display: 'grid',
            gridTemplateColumns: `2fr ${'1fr '.repeat(Math.max(1, columns - 1))}`,
            gap: 14,
            alignItems: 'center',
          }}
        >
          {Array.from({ length: columns }).map((__, colIndex) => (
            <Skeleton key={colIndex} height={13} width={colIndex === 0 ? '80%' : '55%'} />
          ))}
        </div>
      ))}
    </div>
  )
}

export function SkeletonCards({ count = 4, height = 92 }: { count?: number; height?: number }) {
  return (
    <div
      role="status"
      aria-label="Loading"
      style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
        gap: 14,
      }}
    >
      <span className="ct-sr-only">Loading…</span>
      {Array.from({ length: count }).map((_, index) => (
        <Skeleton key={index} height={height} radius={14} />
      ))}
    </div>
  )
}
