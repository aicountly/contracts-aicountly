import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Bell, Building2, ChevronDown, LogOut, Menu, Moon, Search, Sun } from 'lucide-react'

import { useAuth } from '../../auth/AuthProvider'
import { useCompany } from '../../context/CompanyProvider'
import { useSession } from '../../context/SessionProvider'
import { useTheme } from '../../theme/ThemeProvider'
import { AppLauncher } from '../AppLauncher'

/**
 * The persistent top bar: company context, global search, notifications,
 * appearance, and the way out.
 *
 * Company switching lives here rather than in Settings because it changes what
 * every screen is showing — a control that changes the whole page belongs where
 * the page can always see it.
 */
export function Topbar({ onOpenMobileNav }: { onOpenMobileNav: () => void }) {
  const navigate = useNavigate()
  const { signOut } = useAuth()
  const { session } = useSession()
  const { mode, setMode, resolvedMode } = useTheme()
  const [query, setQuery] = useState('')

  return (
    <header
      className="ct-no-print"
      style={{
        height: 'var(--topbar-height)',
        display: 'flex',
        alignItems: 'center',
        gap: 10,
        padding: '0 14px',
        borderBottom: '1px solid rgb(var(--color-border))',
        background: 'var(--color-bg-card)',
        position: 'sticky',
        top: 0,
        zIndex: 20,
      }}
    >
      <button
        type="button"
        className="ct-mobile-only"
        onClick={onOpenMobileNav}
        aria-label="Open navigation"
        style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--color-text)', padding: 6, lineHeight: 0 }}
      >
        <Menu size={19} aria-hidden />
      </button>

      <CompanySwitcher />

      <form
        role="search"
        onSubmit={(event) => {
          event.preventDefault()
          const trimmed = query.trim()
          if (trimmed) navigate(`/search?q=${encodeURIComponent(trimmed)}`)
        }}
        style={{ flex: 1, maxWidth: 460, position: 'relative' }}
      >
        <label htmlFor="global-search" className="ct-sr-only">
          Search contracts
        </label>
        <Search
          size={15}
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
          id="global-search"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          placeholder="Search contracts, counterparties, clauses…"
          style={{
            width: '100%',
            height: 34,
            padding: '0 10px 0 32px',
            borderRadius: 999,
            border: '1px solid rgb(var(--color-border))',
            background: 'var(--color-bg-subtle)',
            fontSize: 13,
          }}
        />
      </form>

      <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 4 }}>
        <button
          type="button"
          onClick={() => setMode(mode === 'dark' ? 'light' : 'dark')}
          aria-label={resolvedMode === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'}
          style={iconButtonStyle}
        >
          {resolvedMode === 'dark' ? <Sun size={17} aria-hidden /> : <Moon size={17} aria-hidden />}
        </button>

        <button
          type="button"
          onClick={() => navigate('/notifications')}
          aria-label={
            session?.counts.notifications
              ? `Notifications, ${session.counts.notifications} unread`
              : 'Notifications'
          }
          style={{ ...iconButtonStyle, position: 'relative' }}
        >
          <Bell size={17} aria-hidden />
          {session?.counts.notifications ? (
            <span
              aria-hidden
              style={{
                position: 'absolute',
                top: 5,
                right: 5,
                width: 8,
                height: 8,
                borderRadius: '50%',
                background: 'var(--color-danger)',
              }}
            />
          ) : null}
        </button>

        <AppLauncher />

        <button
          type="button"
          onClick={signOut}
          aria-label="Sign out"
          style={iconButtonStyle}
        >
          <LogOut size={17} aria-hidden />
        </button>
      </div>
    </header>
  )
}

const iconButtonStyle: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  justifyContent: 'center',
  width: 34,
  height: 34,
  borderRadius: 'var(--radius-md)',
  background: 'none',
  border: 'none',
  cursor: 'pointer',
  color: 'var(--color-text-secondary)',
}

function CompanySwitcher() {
  const { companies, company, branches, financialYears, boId, fyId, selectCompany, selectBranch, selectFinancialYear } =
    useCompany()
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return
    const onDocumentClick = (event: MouseEvent) => {
      if (!ref.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDocumentClick)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onDocumentClick)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  const branchName = branches.find((b) => b.id === boId)?.name
  const yearLabel = financialYears.find((f) => f.id === fyId)?.label

  return (
    <div ref={ref} style={{ position: 'relative' }}>
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-haspopup="true"
        aria-expanded={open}
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: 8,
          height: 34,
          padding: '0 10px',
          borderRadius: 'var(--radius-md)',
          border: '1px solid rgb(var(--color-border))',
          background: 'var(--color-bg-subtle)',
          cursor: 'pointer',
          fontSize: 13,
          fontWeight: 600,
          maxWidth: 260,
        }}
      >
        <Building2 size={15} aria-hidden style={{ color: 'var(--color-text-muted)', flexShrink: 0 }} />
        <span
          style={{
            overflow: 'hidden',
            textOverflow: 'ellipsis',
            whiteSpace: 'nowrap',
          }}
        >
          {company?.name ?? 'Select company'}
        </span>
        <ChevronDown size={14} aria-hidden style={{ color: 'var(--color-text-muted)', flexShrink: 0 }} />
      </button>

      {open ? (
        <div
          className="ct-fade-in"
          style={{
            position: 'absolute',
            top: 'calc(100% + 6px)',
            left: 0,
            width: 300,
            background: 'var(--color-bg-card)',
            border: '1px solid rgb(var(--color-border))',
            borderRadius: 'var(--radius-lg)',
            boxShadow: 'var(--shadow-lg)',
            padding: 10,
            zIndex: 30,
            display: 'grid',
            gap: 10,
          }}
        >
          <SwitcherSelect
            label="Company"
            value={company?.cmp_id ?? ''}
            options={companies.map((c) => ({ value: c.cmp_id, label: c.name }))}
            onChange={(value) => {
              void selectCompany(value)
              setOpen(false)
            }}
          />
          <SwitcherSelect
            label="Branch"
            value={boId ?? ''}
            options={branches.map((b) => ({ value: b.id, label: b.name }))}
            onChange={selectBranch}
            currentLabel={branchName}
          />
          <SwitcherSelect
            label="Financial year"
            value={fyId ?? ''}
            options={financialYears.map((f) => ({ value: f.id, label: f.label }))}
            onChange={selectFinancialYear}
            currentLabel={yearLabel}
          />
        </div>
      ) : null}
    </div>
  )
}

function SwitcherSelect({
  label,
  value,
  options,
  onChange,
}: {
  label: string
  value: string
  options: { value: string; label: string }[]
  onChange: (value: string) => void
  currentLabel?: string
}) {
  const id = `switch-${label.replace(/\W+/g, '-').toLowerCase()}`

  return (
    <div style={{ display: 'grid', gap: 4 }}>
      <label htmlFor={id} style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--color-text-muted)' }}>
        {label}
      </label>
      <select
        id={id}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        disabled={options.length === 0}
        style={{
          height: 34,
          padding: '0 8px',
          borderRadius: 'var(--radius-md)',
          border: '1px solid rgb(var(--color-border-strong))',
          background: 'var(--color-bg-card)',
          fontSize: 13,
        }}
      >
        {options.length === 0 ? <option value="">None available</option> : null}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </div>
  )
}
