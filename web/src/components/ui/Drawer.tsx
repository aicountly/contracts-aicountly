import { useEffect } from 'react'
import type { ReactNode } from 'react'
import { X } from 'lucide-react'

/**
 * A side panel for filters and secondary detail.
 *
 * On a phone the repository's filter bar has nowhere to go, so it moves in
 * here; on a desktop the same component is used for a contract preview
 * alongside the list.
 */
export function Drawer({
  open,
  onClose,
  title,
  children,
  footer,
  side = 'right',
  width = 380,
}: {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  footer?: ReactNode
  side?: 'left' | 'right'
  width?: number
}) {
  useEffect(() => {
    if (!open) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [open, onClose])

  if (!open) return null

  return (
    <div
      role="presentation"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 45,
        background: 'rgba(15, 23, 42, 0.35)',
        display: 'flex',
        justifyContent: side === 'right' ? 'flex-end' : 'flex-start',
      }}
    >
      <aside
        role="dialog"
        aria-modal="true"
        aria-label={title}
        style={{
          width: '100%',
          maxWidth: width,
          height: '100%',
          background: 'var(--color-bg-card)',
          borderLeft: side === 'right' ? '1px solid rgb(var(--color-border))' : undefined,
          borderRight: side === 'left' ? '1px solid rgb(var(--color-border))' : undefined,
          display: 'flex',
          flexDirection: 'column',
          boxShadow: 'var(--shadow-lg)',
        }}
      >
        <header
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '14px 16px',
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <h2 style={{ fontSize: 14.5, fontWeight: 700 }}>{title}</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close panel"
            style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--color-text-muted)', lineHeight: 0 }}
          >
            <X size={17} aria-hidden />
          </button>
        </header>

        <div style={{ flex: 1, overflowY: 'auto', padding: 16 }}>{children}</div>

        {footer ? (
          <footer
            style={{
              display: 'flex',
              gap: 8,
              justifyContent: 'flex-end',
              padding: '12px 16px',
              borderTop: '1px solid rgb(var(--color-border))',
              background: 'var(--color-bg-subtle)',
            }}
          >
            {footer}
          </footer>
        ) : null}
      </aside>
    </div>
  )
}
