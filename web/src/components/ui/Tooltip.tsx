import { useId, useState } from 'react'
import type { ReactNode } from 'react'

/**
 * A hint attached to a control.
 *
 * Shown on hover AND on focus, and wired with `aria-describedby`, so it is
 * reachable without a mouse. A tooltip that only appears on hover is invisible
 * to keyboard and touch users, which is most of the point of having one.
 */
export function Tooltip({
  content,
  children,
  placement = 'top',
}: {
  content: string
  children: ReactNode
  placement?: 'top' | 'bottom'
}) {
  const [visible, setVisible] = useState(false)
  const id = useId()

  return (
    <span
      style={{ position: 'relative', display: 'inline-flex' }}
      onMouseEnter={() => setVisible(true)}
      onMouseLeave={() => setVisible(false)}
      onFocusCapture={() => setVisible(true)}
      onBlurCapture={() => setVisible(false)}
    >
      <span aria-describedby={id}>{children}</span>
      {visible ? (
        <span
          id={id}
          role="tooltip"
          style={{
            position: 'absolute',
            [placement === 'top' ? 'bottom' : 'top']: 'calc(100% + 6px)',
            left: '50%',
            transform: 'translateX(-50%)',
            padding: '5px 9px',
            background: 'rgb(var(--color-fg-strong))',
            color: 'rgb(var(--color-surface))',
            fontSize: 11.5,
            fontWeight: 600,
            borderRadius: 'var(--radius-sm)',
            whiteSpace: 'nowrap',
            zIndex: 30,
            pointerEvents: 'none',
            boxShadow: 'var(--shadow-md)',
          }}
        >
          {content}
        </span>
      ) : null}
    </span>
  )
}
