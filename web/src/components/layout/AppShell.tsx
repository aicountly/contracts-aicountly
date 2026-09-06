import { useState } from 'react'
import { Outlet } from 'react-router-dom'

import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'
import { useCompany } from '../../context/CompanyProvider'
import { useSession } from '../../context/SessionProvider'
import { EmptyState, ErrorState, Spinner } from '../ui'
import { Building2 } from 'lucide-react'

/**
 * The frame every screen renders inside.
 *
 * Nothing below this point runs until a company is selected and the user's
 * Contracts permissions are known — every API call carries the company context
 * and every screen branches on permissions, so rendering first and correcting
 * afterwards would mean a flash of the wrong thing on every load.
 */
export function AppShell() {
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const company = useCompany()
  const session = useSession()

  if (company.status === 'loading' || (company.status === 'ready' && session.status === 'loading')) {
    return <FullPageState>{<LoadingBlock />}</FullPageState>
  }

  if (company.status === 'empty') {
    return (
      <FullPageState>
        <EmptyState
          icon={<Building2 size={24} />}
          title="No company yet"
          description="Contracts works inside a company. Create one in Manage Account, then come back here."
          action={
            <a
              href="https://manage.aicountly.com"
              style={{
                display: 'inline-flex',
                height: 36,
                alignItems: 'center',
                padding: '0 16px',
                borderRadius: 'var(--radius-md)',
                background: 'rgb(var(--color-primary))',
                color: '#fff',
                fontWeight: 700,
                fontSize: 13.5,
              }}
            >
              Open Manage Account
            </a>
          }
        />
      </FullPageState>
    )
  }

  if (company.status === 'error') {
    return (
      <FullPageState>
        <ErrorState
          title="Could not load your company"
          detail={company.error ?? undefined}
          onRetry={() => void company.reload()}
        />
      </FullPageState>
    )
  }

  if (session.status === 'error') {
    return (
      <FullPageState>
        <ErrorState
          title="Could not load your Contracts profile"
          detail={session.error ?? undefined}
          onRetry={() => void session.refresh()}
        />
      </FullPageState>
    )
  }

  return (
    <div style={{ display: 'flex', minHeight: '100vh' }}>
      <style>{`
        .ct-mobile-only { display: none; }
        @media (max-width: 900px) {
          .ct-sidebar-desktop { display: none; }
          .ct-mobile-only { display: inline-flex; }
        }
        @media (min-width: 901px) {
          .ct-sidebar-mobile { display: none; }
        }
      `}</style>

      <a href="#main" className="ct-skip-link">
        Skip to content
      </a>

      <Sidebar mobileOpen={mobileNavOpen} onCloseMobile={() => setMobileNavOpen(false)} />

      <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <Topbar onOpenMobileNav={() => setMobileNavOpen(true)} />
        <main
          id="main"
          tabIndex={-1}
          style={{
            flex: 1,
            padding: '20px clamp(14px, 3vw, 28px) 48px',
            maxWidth: 'var(--shell-max-width)',
            width: '100%',
            margin: '0 auto',
          }}
        >
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function FullPageState({ children }: { children: React.ReactNode }) {
  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 24,
      }}
    >
      <div style={{ maxWidth: 520, width: '100%' }}>{children}</div>
    </div>
  )
}

function LoadingBlock() {
  return (
    <div style={{ textAlign: 'center', color: 'var(--color-text-secondary)' }}>
      <div style={{ display: 'inline-flex', color: 'rgb(var(--color-primary))' }}>
        <Spinner size={26} label="Loading Contracts" />
      </div>
      <p style={{ marginTop: 12, fontSize: 13.5 }}>Loading your workspace…</p>
    </div>
  )
}
