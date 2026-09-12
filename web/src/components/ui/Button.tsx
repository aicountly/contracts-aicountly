import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Spinner } from './Spinner'

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'subtle'
type Size = 'sm' | 'md' | 'lg'

interface Props extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant
  size?: Size
  icon?: ReactNode
  loading?: boolean
  block?: boolean
}

const SIZES: Record<Size, { padding: string; font: number; height: number }> = {
  sm: { padding: '0 10px', font: 12.5, height: 30 },
  md: { padding: '0 14px', font: 13.5, height: 36 },
  lg: { padding: '0 18px', font: 14.5, height: 42 },
}

function styleFor(variant: Variant): React.CSSProperties {
  switch (variant) {
    case 'primary':
      return {
        background: 'rgb(var(--color-primary))',
        color: '#fff',
        border: '1px solid rgb(var(--color-primary))',
      }
    case 'danger':
      return {
        background: 'var(--color-danger)',
        color: '#fff',
        border: '1px solid var(--color-danger)',
      }
    case 'secondary':
      return {
        background: 'var(--color-bg-card)',
        color: 'var(--color-text)',
        border: '1px solid rgb(var(--color-border-strong))',
      }
    case 'subtle':
      return {
        background: 'var(--color-bg-subtle)',
        color: 'var(--color-text)',
        border: '1px solid transparent',
      }
    case 'ghost':
    default:
      return {
        background: 'transparent',
        color: 'var(--color-text-secondary)',
        border: '1px solid transparent',
      }
  }
}

/**
 * `loading` disables the button as well as showing a spinner: a submit that is
 * already in flight must not be sendable twice, and on a form that creates a
 * contract the second press would create a second contract.
 */
export function Button({
  variant = 'secondary',
  size = 'md',
  icon,
  loading = false,
  block = false,
  children,
  disabled,
  style,
  ...rest
}: Props) {
  const dims = SIZES[size]
  const isDisabled = disabled || loading

  return (
    <button
      {...rest}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      style={{
        display: block ? 'flex' : 'inline-flex',
        width: block ? '100%' : undefined,
        alignItems: 'center',
        justifyContent: 'center',
        gap: 7,
        height: dims.height,
        padding: dims.padding,
        fontSize: dims.font,
        fontWeight: 600,
        borderRadius: 'var(--radius-md)',
        cursor: isDisabled ? 'not-allowed' : 'pointer',
        opacity: isDisabled ? 0.55 : 1,
        transition: 'background-color .12s ease, border-color .12s ease, opacity .12s ease',
        whiteSpace: 'nowrap',
        ...styleFor(variant),
        ...style,
      }}
    >
      {loading ? <Spinner size={size === 'sm' ? 12 : 14} /> : icon}
      {children}
    </button>
  )
}
