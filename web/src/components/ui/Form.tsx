import { useId } from 'react'
import type { InputHTMLAttributes, ReactNode, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react'

/**
 * Form primitives that stay accessible without the caller having to think.
 *
 * Every control gets a real `<label for>`, an id, and — when there is an error
 * or hint — `aria-describedby` pointing at it. Wiring that by hand at 40 call
 * sites is how half of them end up without it.
 */

const CONTROL_BASE = {
  width: '100%',
  height: 36,
  padding: '0 10px',
  borderRadius: 'var(--radius-md)',
  border: '1px solid rgb(var(--color-border-strong))',
  background: 'var(--color-bg-card)',
  color: 'var(--color-text)',
  fontSize: 13.5,
  transition: 'border-color .12s ease',
} as const

export function Field({
  label,
  htmlFor,
  error,
  hint,
  required,
  children,
  describedById,
}: {
  label: string
  htmlFor: string
  error?: string
  hint?: string
  required?: boolean
  children: ReactNode
  describedById?: string
}) {
  return (
    <div style={{ display: 'grid', gap: 5 }}>
      <label
        htmlFor={htmlFor}
        style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--color-text-secondary)' }}
      >
        {label}
        {required ? (
          <span style={{ color: 'var(--color-danger)', marginLeft: 3 }} aria-hidden>
            *
          </span>
        ) : null}
        {required ? <span className="ct-sr-only"> (required)</span> : null}
      </label>
      {children}
      {error ? (
        <p id={describedById} role="alert" style={{ fontSize: 12, color: 'var(--color-danger)' }}>
          {error}
        </p>
      ) : hint ? (
        <p id={describedById} style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>
          {hint}
        </p>
      ) : null}
    </div>
  )
}

interface BaseProps {
  label: string
  error?: string
  hint?: string
}

export function Input({
  label,
  error,
  hint,
  required,
  id,
  ...rest
}: BaseProps & InputHTMLAttributes<HTMLInputElement>) {
  const generatedId = useId()
  const inputId = id ?? generatedId
  const describedById = error || hint ? `${inputId}-desc` : undefined

  return (
    <Field label={label} htmlFor={inputId} error={error} hint={hint} required={required} describedById={describedById}>
      <input
        {...rest}
        id={inputId}
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedById}
        style={{
          ...CONTROL_BASE,
          borderColor: error ? 'var(--color-danger)' : 'rgb(var(--color-border-strong))',
          ...rest.style,
        }}
      />
    </Field>
  )
}

export function DateInput(props: BaseProps & InputHTMLAttributes<HTMLInputElement>) {
  return <Input {...props} type="date" />
}

/**
 * A money field.
 *
 * `inputMode="decimal"` gets the right keyboard on a phone, and the value stays
 * a string all the way to the API — a contract value that has been through a
 * JavaScript float is a contract value you cannot reconcile.
 */
export function MoneyInput({
  currency,
  ...props
}: BaseProps & InputHTMLAttributes<HTMLInputElement> & { currency?: string }) {
  const generatedId = useId()
  const inputId = props.id ?? generatedId
  const describedById = props.error || props.hint ? `${inputId}-desc` : undefined

  return (
    <Field
      label={props.label}
      htmlFor={inputId}
      error={props.error}
      hint={props.hint}
      required={props.required}
      describedById={describedById}
    >
      <div style={{ position: 'relative' }}>
        {currency ? (
          <span
            aria-hidden
            style={{
              position: 'absolute',
              left: 10,
              top: '50%',
              transform: 'translateY(-50%)',
              fontSize: 12,
              fontWeight: 700,
              color: 'var(--color-text-muted)',
              pointerEvents: 'none',
            }}
          >
            {currency}
          </span>
        ) : null}
        <input
          {...props}
          id={inputId}
          type="text"
          inputMode="decimal"
          aria-invalid={props.error ? true : undefined}
          aria-describedby={describedById}
          style={{
            ...CONTROL_BASE,
            paddingLeft: currency ? 46 : 10,
            textAlign: 'right',
            fontVariantNumeric: 'tabular-nums',
            borderColor: props.error ? 'var(--color-danger)' : 'rgb(var(--color-border-strong))',
          }}
        />
      </div>
    </Field>
  )
}

export function Textarea({
  label,
  error,
  hint,
  required,
  id,
  rows = 4,
  ...rest
}: BaseProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const generatedId = useId()
  const inputId = id ?? generatedId
  const describedById = error || hint ? `${inputId}-desc` : undefined

  return (
    <Field label={label} htmlFor={inputId} error={error} hint={hint} required={required} describedById={describedById}>
      <textarea
        {...rest}
        id={inputId}
        rows={rows}
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedById}
        style={{
          ...CONTROL_BASE,
          height: 'auto',
          padding: '8px 10px',
          lineHeight: 1.6,
          resize: 'vertical',
          borderColor: error ? 'var(--color-danger)' : 'rgb(var(--color-border-strong))',
          ...rest.style,
        }}
      />
    </Field>
  )
}

export function Select({
  label,
  error,
  hint,
  required,
  id,
  options,
  placeholder,
  ...rest
}: BaseProps &
  SelectHTMLAttributes<HTMLSelectElement> & {
    options: { value: string; label: string }[]
    placeholder?: string
  }) {
  const generatedId = useId()
  const inputId = id ?? generatedId
  const describedById = error || hint ? `${inputId}-desc` : undefined

  return (
    <Field label={label} htmlFor={inputId} error={error} hint={hint} required={required} describedById={describedById}>
      <select
        {...rest}
        id={inputId}
        required={required}
        aria-invalid={error ? true : undefined}
        aria-describedby={describedById}
        style={{
          ...CONTROL_BASE,
          borderColor: error ? 'var(--color-danger)' : 'rgb(var(--color-border-strong))',
          ...rest.style,
        }}
      >
        {placeholder ? <option value="">{placeholder}</option> : null}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </Field>
  )
}

export function Checkbox({
  label,
  hint,
  id,
  ...rest
}: { label: string; hint?: string } & InputHTMLAttributes<HTMLInputElement>) {
  const generatedId = useId()
  const inputId = id ?? generatedId

  return (
    <div style={{ display: 'flex', alignItems: 'flex-start', gap: 9 }}>
      <input
        {...rest}
        id={inputId}
        type="checkbox"
        style={{ width: 16, height: 16, marginTop: 2, accentColor: 'rgb(var(--color-primary))', cursor: 'pointer' }}
      />
      <label htmlFor={inputId} style={{ fontSize: 13, cursor: 'pointer' }}>
        {label}
        {hint ? (
          <span style={{ display: 'block', fontSize: 12, color: 'var(--color-text-muted)', marginTop: 2 }}>
            {hint}
          </span>
        ) : null}
      </label>
    </div>
  )
}
