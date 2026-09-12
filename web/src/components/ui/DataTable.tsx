import type { ReactNode } from 'react'
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react'
import { SkeletonTable } from './Skeleton'
import { EmptyState } from './EmptyState'

export interface Column<T> {
  key: string
  header: ReactNode
  /** Sort key sent to the API. Omit for a column the server cannot sort by. */
  sortKey?: string
  render: (row: T) => ReactNode
  width?: number | string
  align?: 'left' | 'right' | 'center'
  /** Hidden below this viewport width, so a phone shows the columns that matter. */
  hideBelow?: 'sm' | 'md' | 'lg'
  /** Label announced when the column header is an icon. */
  srLabel?: string
}

interface Props<T> {
  columns: Column<T>[]
  rows: T[]
  rowKey: (row: T) => string | number
  loading?: boolean
  emptyTitle?: string
  emptyDescription?: string
  emptyAction?: ReactNode
  sort?: { key: string; dir: 'asc' | 'desc' }
  onSortChange?: (key: string, dir: 'asc' | 'desc') => void
  onRowClick?: (row: T) => void
  /** Highlights the row without changing its meaning, e.g. an overdue item. */
  rowTone?: (row: T) => 'danger' | 'warning' | undefined
  caption?: string
}

const HIDE_BELOW_PX = { sm: 640, md: 900, lg: 1180 } as const

/**
 * The table every list screen uses.
 *
 * It is a real `<table>` with a `<caption>` and `scope="col"` headers, because
 * a grid of divs is unreadable to a screen reader and this is the primary way
 * people work through a contract portfolio. Sorting is server-side — the client
 * only ever holds one page, so sorting what it has would reorder a slice and
 * look like data loss.
 */
export function DataTable<T>({
  columns,
  rows,
  rowKey,
  loading = false,
  emptyTitle = 'Nothing here yet',
  emptyDescription,
  emptyAction,
  sort,
  onSortChange,
  onRowClick,
  rowTone,
  caption,
}: Props<T>) {
  if (loading) {
    return (
      <div style={{ padding: 16 }}>
        <SkeletonTable rows={6} columns={Math.min(columns.length, 6)} />
      </div>
    )
  }

  if (rows.length === 0) {
    return <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />
  }

  const toggle = (column: Column<T>) => {
    if (!column.sortKey || !onSortChange) return
    const nextDir = sort?.key === column.sortKey && sort.dir === 'desc' ? 'asc' : 'desc'
    onSortChange(column.sortKey, nextDir)
  }

  return (
    <div className="ct-scroll-x">
      <style>{`
        .ct-table-hide-sm { }
        @media (max-width: ${HIDE_BELOW_PX.sm}px) { .ct-table-hide-sm { display: none; } }
        @media (max-width: ${HIDE_BELOW_PX.md}px) { .ct-table-hide-md { display: none; } }
        @media (max-width: ${HIDE_BELOW_PX.lg}px) { .ct-table-hide-lg { display: none; } }
      `}</style>
      <table
        style={{
          width: '100%',
          borderCollapse: 'collapse',
          fontSize: 13,
          minWidth: 620,
        }}
      >
        {caption ? <caption className="ct-sr-only">{caption}</caption> : null}
        <thead>
          <tr>
            {columns.map((column) => {
              // Narrowed to a value rather than a boolean, so the two uses of
              // `.dir` below are provably safe. `sorted` alone left TypeScript
              // unable to see that `sort` is defined inside the branch.
              const activeSort =
                column.sortKey !== undefined && sort?.key === column.sortKey ? sort : null
              const hideClass = column.hideBelow ? `ct-table-hide-${column.hideBelow}` : ''
              return (
                <th
                  key={column.key}
                  scope="col"
                  className={hideClass}
                  aria-sort={activeSort ? (activeSort.dir === 'asc' ? 'ascending' : 'descending') : undefined}
                  style={{
                    textAlign: column.align ?? 'left',
                    padding: '9px 12px',
                    fontSize: 11.5,
                    fontWeight: 700,
                    letterSpacing: '.02em',
                    textTransform: 'uppercase',
                    color: 'var(--color-text-muted)',
                    borderBottom: '1px solid rgb(var(--color-border))',
                    width: column.width,
                    whiteSpace: 'nowrap',
                    background: 'var(--color-bg-card)',
                    position: 'sticky',
                    top: 0,
                    zIndex: 1,
                  }}
                >
                  {column.sortKey && onSortChange ? (
                    <button
                      type="button"
                      onClick={() => toggle(column)}
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 4,
                        background: 'none',
                        border: 'none',
                        padding: 0,
                        cursor: 'pointer',
                        font: 'inherit',
                        color: activeSort ? 'var(--color-text)' : 'inherit',
                        textTransform: 'inherit',
                        letterSpacing: 'inherit',
                      }}
                    >
                      {column.header}
                      {activeSort ? (
                        activeSort.dir === 'asc' ? (
                          <ArrowUp size={12} aria-hidden />
                        ) : (
                          <ArrowDown size={12} aria-hidden />
                        )
                      ) : (
                        <ChevronsUpDown size={12} aria-hidden style={{ opacity: 0.45 }} />
                      )}
                    </button>
                  ) : (
                    <>
                      {column.header}
                      {column.srLabel ? <span className="ct-sr-only">{column.srLabel}</span> : null}
                    </>
                  )}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => {
            const tone = rowTone?.(row)
            return (
              <tr
                key={rowKey(row)}
                onClick={onRowClick ? () => onRowClick(row) : undefined}
                style={{
                  cursor: onRowClick ? 'pointer' : undefined,
                  borderBottom: '1px solid var(--color-border-light)',
                  background:
                    tone === 'danger'
                      ? 'var(--color-danger-bg)'
                      : tone === 'warning'
                        ? 'var(--color-warning-bg)'
                        : undefined,
                }}
              >
                {columns.map((column) => (
                  <td
                    key={column.key}
                    className={column.hideBelow ? `ct-table-hide-${column.hideBelow}` : ''}
                    style={{
                      padding: '11px 12px',
                      textAlign: column.align ?? 'left',
                      color: 'var(--color-text)',
                      verticalAlign: 'middle',
                    }}
                  >
                    {column.render(row)}
                  </td>
                ))}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
