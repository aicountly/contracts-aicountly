import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from './Button'

/**
 * Server-side pagination controls.
 *
 * The row range is spelled out ("Showing 26–50 of 312") rather than only page
 * numbers, because in a contract repository the useful question is usually
 * "how many are there", not "which page am I on".
 */
export function Pagination({
  page,
  perPage,
  total,
  onPageChange,
  onPerPageChange,
  perPageOptions = [25, 50, 100],
}: {
  page: number
  perPage: number
  total: number
  onPageChange: (page: number) => void
  onPerPageChange?: (perPage: number) => void
  perPageOptions?: number[]
}) {
  const totalPages = Math.max(1, Math.ceil(total / perPage))
  const from = total === 0 ? 0 : (page - 1) * perPage + 1
  const to = Math.min(total, page * perPage)

  return (
    <nav
      aria-label="Pagination"
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 12,
        flexWrap: 'wrap',
        padding: '10px 14px',
        borderTop: '1px solid rgb(var(--color-border))',
        fontSize: 12.5,
        color: 'var(--color-text-secondary)',
      }}
    >
      <div>
        {total === 0 ? 'No results' : `Showing ${from}–${to} of ${total.toLocaleString()}`}
      </div>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
        {onPerPageChange ? (
          <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span className="ct-sr-only">Rows per page</span>
            <select
              value={perPage}
              onChange={(event) => onPerPageChange(Number(event.target.value))}
              style={{
                height: 30,
                padding: '0 8px',
                borderRadius: 'var(--radius-sm)',
                border: '1px solid rgb(var(--color-border-strong))',
                background: 'var(--color-bg-card)',
                fontSize: 12.5,
              }}
            >
              {perPageOptions.map((option) => (
                <option key={option} value={option}>
                  {option} / page
                </option>
              ))}
            </select>
          </label>
        ) : null}

        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <Button
            size="sm"
            variant="secondary"
            disabled={page <= 1}
            onClick={() => onPageChange(page - 1)}
            icon={<ChevronLeft size={14} />}
            aria-label="Previous page"
          >
            Prev
          </Button>
          <span style={{ minWidth: 76, textAlign: 'center' }}>
            Page {page} of {totalPages}
          </span>
          <Button
            size="sm"
            variant="secondary"
            disabled={page >= totalPages}
            onClick={() => onPageChange(page + 1)}
            aria-label="Next page"
          >
            Next
            <ChevronRight size={14} />
          </Button>
        </div>
      </div>
    </nav>
  )
}
