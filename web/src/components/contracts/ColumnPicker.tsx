import { useEffect, useId, useRef, useState } from 'react'
import { Columns3 } from 'lucide-react'

import { Button } from '../ui'

/**
 * Which columns the repository table shows.
 *
 * People use this list for different jobs — a finance user wants value and
 * currency, a legal reviewer wants risk and governing law — and a table wide
 * enough for everyone is readable for no one. The choice is per browser rather
 * than per account: it is a display preference, and round-tripping it through
 * the server would mean a settings write on every checkbox.
 */

export interface ColumnOption {
  key: string
  label: string
  /** Always shown; the row would be unidentifiable without it. */
  locked?: boolean
}

export function readStoredColumns(storageKey: string, fallback: string[]): string[] {
  try {
    const raw = window.localStorage.getItem(storageKey)
    if (!raw) return fallback
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed) || parsed.some((item) => typeof item !== 'string')) return fallback
    return parsed as string[]
  } catch {
    return fallback
  }
}

export function writeStoredColumns(storageKey: string, columns: string[]): void {
  try {
    window.localStorage.setItem(storageKey, JSON.stringify(columns))
  } catch {
    /* a display preference is not worth failing a render over */
  }
}

export function ColumnPicker({
  options,
  visible,
  onChange,
  onReset,
}: {
  options: ColumnOption[]
  visible: string[]
  onChange: (next: string[]) => void
  onReset: () => void
}) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const panelId = useId()

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      // The trigger is the first button inside the container; React 19 would
      // forward a ref to Button, but its props do not declare one.
      containerRef.current?.querySelector('button')?.focus()
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  const toggle = (key: string) => {
    onChange(visible.includes(key) ? visible.filter((item) => item !== key) : [...visible, key])
  }

  const optional = options.filter((option) => !option.locked)

  return (
    <div ref={containerRef} style={{ position: 'relative' }}>
      <Button
        size="sm"
        variant="secondary"
        icon={<Columns3 size={14} />}
        aria-expanded={open}
        aria-controls={panelId}
        aria-haspopup="true"
        onClick={() => setOpen((current) => !current)}
      >
        Columns
      </Button>

      {open ? (
        <div
          id={panelId}
          role="group"
          aria-label="Visible columns"
          style={{
            position: 'absolute',
            zIndex: 25,
            top: 'calc(100% + 6px)',
            right: 0,
            width: 240,
            maxHeight: 340,
            overflowY: 'auto',
            padding: 10,
            background: 'var(--color-bg-card)',
            border: '1px solid rgb(var(--color-border))',
            borderRadius: 'var(--radius-md)',
            boxShadow: 'var(--shadow-lg)',
          }}
        >
          <div style={{ display: 'grid', gap: 2 }}>
            {optional.map((option) => {
              const checked = visible.includes(option.key)
              return (
                <label
                  key={option.key}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 9,
                    padding: '5px 6px',
                    borderRadius: 'var(--radius-sm)',
                    fontSize: 13,
                    cursor: 'pointer',
                  }}
                >
                  <input
                    type="checkbox"
                    checked={checked}
                    onChange={() => toggle(option.key)}
                    style={{
                      width: 15,
                      height: 15,
                      accentColor: 'rgb(var(--color-primary))',
                      cursor: 'pointer',
                    }}
                  />
                  {option.label}
                </label>
              )
            })}
          </div>

          <div
            style={{
              marginTop: 8,
              paddingTop: 8,
              borderTop: '1px solid rgb(var(--color-border))',
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
            }}
          >
            <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
              {visible.length} of {options.length}
            </span>
            <Button size="sm" variant="ghost" onClick={onReset}>
              Reset
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
