/** A determinate-looking indicator for an indeterminate wait. */
export function Spinner({ size = 16, label }: { size?: number; label?: string }) {
  return (
    <span
      role={label ? 'status' : undefined}
      aria-label={label}
      style={{
        display: 'inline-block',
        width: size,
        height: size,
        borderRadius: '50%',
        border: `${Math.max(2, Math.round(size / 8))}px solid currentColor`,
        borderTopColor: 'transparent',
        opacity: 0.7,
        animation: 'ct-spin .7s linear infinite',
        flexShrink: 0,
      }}
    />
  )
}
