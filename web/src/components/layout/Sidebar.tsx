import { NavLink, useLocation } from 'react-router-dom'
import { FileSignature, X } from 'lucide-react'

import { NAVIGATION } from '../../config/navigation'
import { useSession } from '../../context/SessionProvider'

/**
 * The primary navigation.
 *
 * Rendered as a `<nav>` of real links so middle-click, copy-link and browser
 * history all behave; a click handler on a div gets none of that for free.
 * On a narrow viewport the same component becomes an overlay drawer rather than
 * a second, differently-behaved mobile menu.
 */
export function Sidebar({
  mobileOpen,
  onCloseMobile,
}: {
  mobileOpen: boolean
  onCloseMobile: () => void
}) {
  const { session, can } = useSession()
  const location = useLocation()

  const counts = session?.counts

  const content = (
    <nav aria-label="Contracts sections" style={{ padding: '12px 10px', display: 'grid', gap: 16 }}>
      {NAVIGATION.map((section, sectionIndex) => {
        const visible = section.items.filter((item) => !item.permission || can(item.permission))
        if (visible.length === 0) return null

        return (
          <div key={section.label ?? `section-${sectionIndex}`}>
            {section.label ? (
              <h2
                style={{
                  fontSize: 10.5,
                  fontWeight: 800,
                  letterSpacing: '.07em',
                  textTransform: 'uppercase',
                  color: 'var(--color-text-subtle)',
                  padding: '0 10px 6px',
                }}
              >
                {section.label}
              </h2>
            ) : null}

            <ul style={{ listStyle: 'none', display: 'grid', gap: 2 }}>
              {visible.map((item) => {
                const Icon = item.icon
                const badgeValue = item.badge ? (counts?.[item.badge] ?? 0) : 0
                const isActive = item.matchPrefix
                  ? location.pathname === item.matchPrefix ||
                    location.pathname.startsWith(`${item.matchPrefix}/`)
                  : location.pathname === item.to

                return (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      end={!item.matchPrefix}
                      onClick={onCloseMobile}
                      aria-current={isActive ? 'page' : undefined}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '8px 10px',
                        borderRadius: 'var(--radius-md)',
                        fontSize: 13.2,
                        fontWeight: isActive ? 700 : 600,
                        color: isActive ? 'rgb(var(--color-primary-active))' : 'var(--color-text-secondary)',
                        background: isActive ? 'var(--color-primary-muted)' : 'transparent',
                      }}
                    >
                      <Icon size={16.5} aria-hidden style={{ flexShrink: 0 }} />
                      <span style={{ flex: 1, minWidth: 0 }}>{item.label}</span>
                      {badgeValue > 0 ? (
                        <span
                          aria-label={`${badgeValue} needing attention`}
                          style={{
                            minWidth: 19,
                            height: 18,
                            padding: '0 5px',
                            borderRadius: 999,
                            background: 'var(--color-warning)',
                            color: '#fff',
                            fontSize: 10.5,
                            fontWeight: 800,
                            lineHeight: '18px',
                            textAlign: 'center',
                          }}
                        >
                          {badgeValue > 99 ? '99+' : badgeValue}
                        </span>
                      ) : null}
                    </NavLink>
                  </li>
                )
              })}
            </ul>
          </div>
        )
      })}
    </nav>
  )

  return (
    <>
      {/* Desktop rail */}
      <aside
        className="ct-sidebar-desktop ct-no-print"
        style={{
          width: 'var(--sidebar-width)',
          flexShrink: 0,
          borderRight: '1px solid rgb(var(--color-border))',
          background: 'var(--color-bg-card)',
          height: '100vh',
          position: 'sticky',
          top: 0,
          overflowY: 'auto',
        }}
      >
        <BrandMark />
        {content}
      </aside>

      {/* Mobile overlay */}
      {mobileOpen ? (
        <div
          className="ct-sidebar-mobile ct-no-print"
          role="presentation"
          onMouseDown={(event) => {
            if (event.target === event.currentTarget) onCloseMobile()
          }}
          style={{
            position: 'fixed',
            inset: 0,
            zIndex: 40,
            background: 'rgba(15, 23, 42, 0.4)',
          }}
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Navigation"
            style={{
              width: 'min(280px, 82vw)',
              height: '100%',
              background: 'var(--color-bg-card)',
              overflowY: 'auto',
            }}
          >
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
              <BrandMark />
              <button
                type="button"
                onClick={onCloseMobile}
                aria-label="Close navigation"
                style={{ background: 'none', border: 'none', padding: 14, cursor: 'pointer', color: 'var(--color-text-muted)' }}
              >
                <X size={18} aria-hidden />
              </button>
            </div>
            {content}
          </div>
        </div>
      ) : null}
    </>
  )
}

function BrandMark() {
  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 9,
        padding: '14px 16px',
        borderBottom: '1px solid rgb(var(--color-border))',
        height: 'var(--topbar-height)',
      }}
    >
      <span
        aria-hidden
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 26,
          height: 26,
          borderRadius: 7,
          background: 'rgb(var(--color-primary))',
          color: '#fff',
        }}
      >
        <FileSignature size={15} />
      </span>
      <span style={{ fontSize: 14.5, fontWeight: 800, letterSpacing: '-.01em' }}>
        Contracts
      </span>
    </div>
  )
}
