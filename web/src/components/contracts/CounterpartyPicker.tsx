import { useEffect, useId, useMemo, useRef, useState } from 'react'
import { Building2, Loader2, Mail, Search } from 'lucide-react'

import { Field } from '../ui'
import { useApiResource } from '../../hooks/useApiResource'
import { api } from '../../services/apiClient'
import type { CounterpartyContact } from '../../types/contracts'

/**
 * Pick the other side of the contract.
 *
 * Contacts is a separate AICOUNTLY product reached through a proxy endpoint, so
 * it can be unconfigured, down, or simply not hold the party yet. None of those
 * may stop someone recording a contract: whatever is typed is a valid value,
 * and the lookup only ever *offers* a better one. That is why this is a
 * combobox over a free-text input rather than a select.
 *
 * Organisation and email are shown against every suggestion because "Rajesh
 * Kumar" appears four times in a real Contacts book and the name alone does not
 * tell you which one signs this agreement.
 */

const DEBOUNCE_MS = 300
const MIN_QUERY = 2
const RESULT_LIMIT = 8

function organisationOf(contact: CounterpartyContact): string | null {
  return contact.organisation ?? contact.organization ?? contact.company_name ?? null
}

export function CounterpartyPicker({
  value,
  onChange,
  label = 'Counterparty',
  hint,
  error,
  required,
  disabled,
  id,
}: {
  value: string
  onChange: (name: string, contact?: CounterpartyContact) => void
  label?: string
  hint?: string
  error?: string
  required?: boolean
  disabled?: boolean
  id?: string
}) {
  const generatedId = useId()
  const inputId = id ?? generatedId
  const listId = `${inputId}-listbox`
  const statusId = `${inputId}-status`
  const describedById = error || hint ? `${inputId}-desc` : undefined

  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState(value)
  const [debounced, setDebounced] = useState(value)
  const [activeIndex, setActiveIndex] = useState(-1)
  const containerRef = useRef<HTMLDivElement>(null)

  // The field is controlled from outside on edit (the contract loads after
  // first paint), but must not fight the user while they are typing.
  useEffect(() => {
    setQuery((current) => (current === value ? current : value))
  }, [value])

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query), DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [query])

  const searchable = debounced.trim().length >= MIN_QUERY

  const search = useApiResource<CounterpartyContact[]>(
    (signal) =>
      api.get<CounterpartyContact[]>(
        '/counterparties/search',
        { q: debounced.trim(), limit: RESULT_LIMIT },
        signal,
      ),
    [debounced],
    { enabled: open && searchable && !disabled },
  )

  const results = useMemo(
    () => (open && searchable ? (search.data ?? []) : []),
    [open, searchable, search.data],
  )

  useEffect(() => {
    setActiveIndex(-1)
  }, [results])

  useEffect(() => {
    if (!open) return
    const onPointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onPointerDown)
    return () => document.removeEventListener('mousedown', onPointerDown)
  }, [open])

  const choose = (contact: CounterpartyContact) => {
    setQuery(contact.name)
    onChange(contact.name, contact)
    setOpen(false)
  }

  const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Escape') {
      setOpen(false)
      return
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (!open) {
        setOpen(true)
        return
      }
      if (results.length === 0) return
      event.preventDefault()
      const delta = event.key === 'ArrowDown' ? 1 : -1
      setActiveIndex((current) => (current + delta + results.length) % results.length)
      return
    }
    if (event.key === 'Enter' && open && activeIndex >= 0 && results[activeIndex]) {
      // Only swallow Enter when a suggestion is highlighted, so the form still
      // submits for someone typing a name Contacts does not know.
      event.preventDefault()
      choose(results[activeIndex])
    }
  }

  const unavailable = search.error ? search.error.message : null
  const showEmptyNote = open && searchable && !search.loading && !unavailable && results.length === 0

  return (
    <Field
      label={label}
      htmlFor={inputId}
      error={error}
      hint={hint}
      required={required}
      describedById={describedById}
    >
      <div ref={containerRef} style={{ position: 'relative' }}>
        <Search
          size={14}
          aria-hidden
          style={{
            position: 'absolute',
            left: 10,
            top: '50%',
            transform: 'translateY(-50%)',
            color: 'var(--color-text-subtle)',
            pointerEvents: 'none',
          }}
        />
        <input
          id={inputId}
          role="combobox"
          type="text"
          autoComplete="off"
          value={query}
          disabled={disabled}
          required={required}
          aria-expanded={open}
          aria-controls={listId}
          aria-autocomplete="list"
          aria-invalid={error ? true : undefined}
          aria-describedby={[describedById, statusId].filter(Boolean).join(' ') || undefined}
          aria-activedescendant={
            activeIndex >= 0 && results[activeIndex] ? `${listId}-option-${activeIndex}` : undefined
          }
          placeholder="Search Contacts, or type a name"
          onChange={(event) => {
            setQuery(event.target.value)
            onChange(event.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          onKeyDown={onKeyDown}
          style={{
            width: '100%',
            height: 36,
            padding: '0 32px 0 30px',
            borderRadius: 'var(--radius-md)',
            border: `1px solid ${error ? 'var(--color-danger)' : 'rgb(var(--color-border-strong))'}`,
            background: 'var(--color-bg-card)',
            color: 'var(--color-text)',
            fontSize: 13.5,
          }}
        />
        {search.loading && open && searchable ? (
          <Loader2
            size={14}
            aria-hidden
            style={{
              position: 'absolute',
              right: 10,
              top: '50%',
              transform: 'translateY(-50%)',
              color: 'var(--color-text-subtle)',
              animation: 'ct-spin .8s linear infinite',
            }}
          />
        ) : null}

        <ul
          id={listId}
          role="listbox"
          aria-label="Counterparty suggestions"
          hidden={results.length === 0}
          style={{
            position: 'absolute',
            zIndex: 20,
            top: 'calc(100% + 4px)',
            left: 0,
            right: 0,
            maxHeight: 260,
            overflowY: 'auto',
            listStyle: 'none',
            background: 'var(--color-bg-card)',
            border: '1px solid rgb(var(--color-border))',
            borderRadius: 'var(--radius-md)',
            boxShadow: 'var(--shadow-lg)',
          }}
        >
          {results.map((contact, index) => {
            const organisation = organisationOf(contact)
            return (
              <li
                key={contact.uuid ?? contact.id ?? `${contact.name}-${index}`}
                id={`${listId}-option-${index}`}
                role="option"
                aria-selected={index === activeIndex}
                onMouseEnter={() => setActiveIndex(index)}
                // mousedown, not click: the input blurs first on click and the
                // list would be gone before the handler ran.
                onMouseDown={(event) => {
                  event.preventDefault()
                  choose(contact)
                }}
                style={{
                  padding: '8px 11px',
                  cursor: 'pointer',
                  background: index === activeIndex ? 'var(--color-bg-subtle)' : 'transparent',
                  borderBottom: '1px solid var(--color-border-light)',
                }}
              >
                <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text)' }}>
                  {contact.name}
                </div>
                <div
                  style={{
                    display: 'flex',
                    gap: 12,
                    flexWrap: 'wrap',
                    fontSize: 11.5,
                    color: 'var(--color-text-muted)',
                    marginTop: 2,
                  }}
                >
                  {organisation ? (
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                      <Building2 size={11} aria-hidden />
                      {organisation}
                    </span>
                  ) : null}
                  {contact.email ? (
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
                      <Mail size={11} aria-hidden />
                      {contact.email}
                    </span>
                  ) : null}
                </div>
              </li>
            )
          })}
        </ul>
      </div>

      <p
        id={statusId}
        aria-live="polite"
        style={{ fontSize: 11.5, color: 'var(--color-text-muted)', minHeight: 16 }}
      >
        {unavailable
          ? `Contacts lookup is unavailable (${unavailable}) — the name you type will be used.`
          : showEmptyNote
            ? 'No match in Contacts. The name you type will be used as it is.'
            : ''}
      </p>
    </Field>
  )
}
