import { useEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from './Button'

/**
 * A modal dialog that behaves like one.
 *
 * Escape closes it, focus moves into it on open and returns to the trigger on
 * close, and Tab is trapped inside. Without the trap, a keyboard user tabs
 * straight out of the dialog into the page behind it — which is still there,
 * still interactive, and now invisible to them.
 */
export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  width = 560,
  closeOnBackdrop = true,
}: {
  open: boolean
  onClose: () => void
  title: string
  description?: string
  children: ReactNode
  footer?: ReactNode
  width?: number
  closeOnBackdrop?: boolean
}) {
  const panelRef = useRef<HTMLDivElement>(null)
  const restoreFocusTo = useRef<HTMLElement | null>(null)

  useEffect(() => {
    if (!open) return

    restoreFocusTo.current = document.activeElement as HTMLElement | null

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    const focusables = () =>
      Array.from(
        panelRef.current?.querySelectorAll<HTMLElement>(
          'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ) ?? [],
      ).filter((el) => el.offsetParent !== null)

    // Deferred: the panel's children have not mounted on the tick the effect
    // runs, so focusing now would find nothing to focus.
    const timer = window.setTimeout(() => focusables()[0]?.focus(), 0)

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        return
      }
      if (event.key !== 'Tab') return

      const items = focusables()
      if (items.length === 0) return

      const first = items[0]
      const last = items[items.length - 1]

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)

    return () => {
      window.clearTimeout(timer)
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
      restoreFocusTo.current?.focus?.()
    }
  }, [open, onClose])

  if (!open) return null

  const titleId = `modal-title-${title.replace(/\W+/g, '-').toLowerCase()}`

  return (
    <div
      role="presentation"
      onMouseDown={(event) => {
        if (closeOnBackdrop && event.target === event.currentTarget) onClose()
      }}
      style={{
        position: 'fixed',
        inset: 0,
        zIndex: 50,
        background: 'rgba(15, 23, 42, 0.42)',
        display: 'flex',
        alignItems: 'flex-start',
        justifyContent: 'center',
        padding: '6vh 16px 24px',
        overflowY: 'auto',
      }}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="ct-fade-in"
        style={{
          width: '100%',
          maxWidth: width,
          background: 'var(--color-bg-card)',
          border: '1px solid rgb(var(--color-border))',
          borderRadius: 'var(--radius-lg)',
          boxShadow: 'var(--shadow-lg)',
        }}
      >
        <header
          style={{
            display: 'flex',
            alignItems: 'flex-start',
            justifyContent: 'space-between',
            gap: 12,
            padding: '16px 18px',
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <div>
            <h2 id={titleId} style={{ fontSize: 15.5, fontWeight: 700 }}>
              {title}
            </h2>
            {description ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4 }}>
                {description}
              </p>
            ) : null}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close dialog"
            style={{
              background: 'none',
              border: 'none',
              cursor: 'pointer',
              color: 'var(--color-text-muted)',
              padding: 4,
              lineHeight: 0,
            }}
          >
            <X size={17} aria-hidden />
          </button>
        </header>

        <div style={{ padding: 18 }}>{children}</div>

        {footer ? (
          <footer
            style={{
              display: 'flex',
              justifyContent: 'flex-end',
              gap: 8,
              padding: '13px 18px',
              borderTop: '1px solid rgb(var(--color-border))',
              background: 'var(--color-bg-subtle)',
              borderBottomLeftRadius: 'var(--radius-lg)',
              borderBottomRightRadius: 'var(--radius-lg)',
            }}
          >
            {footer}
          </footer>
        ) : null}
      </div>
    </div>
  )
}

/**
 * Confirmation before something that is hard to undo.
 *
 * `tone="danger"` is for the genuinely destructive cases only. A confirm on
 * every action trains people to click through them, which is exactly what you
 * do not want on the one that deletes a draft.
 */
export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  message,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  tone = 'primary',
  busy = false,
}: {
  open: boolean
  onClose: () => void
  onConfirm: () => void
  title: string
  message: ReactNode
  confirmLabel?: string
  cancelLabel?: string
  tone?: 'primary' | 'danger'
  busy?: boolean
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      width={460}
      closeOnBackdrop={!busy}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            {cancelLabel}
          </Button>
          <Button variant={tone} onClick={onConfirm} loading={busy}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div style={{ fontSize: 13.5, color: 'var(--color-text-secondary)', lineHeight: 1.65 }}>
        {message}
      </div>
    </Modal>
  )
}
