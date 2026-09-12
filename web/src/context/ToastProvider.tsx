import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { AlertTriangle, CheckCircle2, Info, X, XCircle } from 'lucide-react'

/**
 * Transient feedback for actions the user just took.
 *
 * Toasts are for confirmations and for failures the user can do nothing about
 * right now. A validation failure belongs on the field, not up here — the whole
 * point of a 422 carrying field messages is that the form can say which box is
 * wrong.
 *
 * The region is `aria-live="polite"`, so a screen reader announces a toast
 * without interrupting whatever it is reading.
 */

export type ToastKind = 'success' | 'error' | 'warning' | 'info'

interface Toast {
  id: number
  kind: ToastKind
  title: string
  detail?: string
}

interface ToastValue {
  toast: (kind: ToastKind, title: string, detail?: string) => void
  success: (title: string, detail?: string) => void
  error: (title: string, detail?: string) => void
  warning: (title: string, detail?: string) => void
  info: (title: string, detail?: string) => void
  dismiss: (id: number) => void
}

const ToastContext = createContext<ToastValue | null>(null)

const ICONS = {
  success: CheckCircle2,
  error: XCircle,
  warning: AlertTriangle,
  info: Info,
} as const

const TONE: Record<ToastKind, { bg: string; border: string; fg: string }> = {
  success: { bg: 'var(--color-success-bg)', border: 'var(--color-success-border)', fg: 'var(--color-success)' },
  error: { bg: 'var(--color-danger-bg)', border: 'var(--color-danger-border)', fg: 'var(--color-danger)' },
  warning: { bg: 'var(--color-warning-bg)', border: 'var(--color-warning-border)', fg: 'var(--color-warning)' },
  info: { bg: 'var(--color-info-bg)', border: 'var(--color-info-border)', fg: 'var(--color-info)' },
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([])
  const nextId = useRef(1)

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((t) => t.id !== id))
  }, [])

  const toast = useCallback(
    (kind: ToastKind, title: string, detail?: string) => {
      const id = nextId.current++
      setToasts((current) => [...current.slice(-3), { id, kind, title, detail }])

      // Errors stay long enough to be read and copied; a success confirmation
      // has done its job in four seconds.
      window.setTimeout(() => dismiss(id), kind === 'error' ? 9000 : 4500)
    },
    [dismiss],
  )

  const value = useMemo<ToastValue>(
    () => ({
      toast,
      success: (title, detail) => toast('success', title, detail),
      error: (title, detail) => toast('error', title, detail),
      warning: (title, detail) => toast('warning', title, detail),
      info: (title, detail) => toast('info', title, detail),
      dismiss,
    }),
    [toast, dismiss],
  )

  return (
    <ToastContext.Provider value={value}>
      {children}
      <div
        aria-live="polite"
        aria-atomic="false"
        className="ct-no-print"
        style={{
          position: 'fixed',
          bottom: 20,
          right: 20,
          zIndex: 60,
          display: 'flex',
          flexDirection: 'column',
          gap: 10,
          maxWidth: 'min(420px, calc(100vw - 40px))',
        }}
      >
        {toasts.map((item) => {
          const Icon = ICONS[item.kind]
          const tone = TONE[item.kind]
          return (
            <div
              key={item.id}
              role={item.kind === 'error' ? 'alert' : 'status'}
              className="ct-fade-in"
              style={{
                display: 'flex',
                gap: 10,
                alignItems: 'flex-start',
                padding: '12px 14px',
                background: tone.bg,
                border: `1px solid ${tone.border}`,
                borderRadius: 'var(--radius-md)',
                boxShadow: 'var(--shadow-md)',
              }}
            >
              <Icon size={18} style={{ color: tone.fg, flexShrink: 0, marginTop: 1 }} aria-hidden />
              <div style={{ minWidth: 0, flex: 1 }}>
                <div style={{ fontWeight: 700, fontSize: 13.5, color: 'var(--color-text)' }}>
                  {item.title}
                </div>
                {item.detail ? (
                  <div style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
                    {item.detail}
                  </div>
                ) : null}
              </div>
              <button
                type="button"
                onClick={() => dismiss(item.id)}
                aria-label="Dismiss notification"
                style={{
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer',
                  color: 'var(--color-text-muted)',
                  padding: 2,
                  lineHeight: 0,
                }}
              >
                <X size={14} aria-hidden />
              </button>
            </div>
          )
        })}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastValue {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>')
  return ctx
}
