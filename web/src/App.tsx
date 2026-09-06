import { lazy, Suspense, useEffect } from 'react'
import { BrowserRouter, Route, Routes, useLocation } from 'react-router-dom'

import { useAuth } from './auth/AuthProvider'
import { AppShell } from './components/layout/AppShell'
import { Spinner } from './components/ui'
import { CompanyProvider } from './context/CompanyProvider'
import { SessionProvider } from './context/SessionProvider'
import { ToastProvider } from './context/ToastProvider'
import SignIn from './pages/SignIn'
import { ThemeProvider } from './theme/ThemeProvider'
import { initAnalytics, trackPageView } from './utils/analytics'

initAnalytics()

/**
 * Routes are lazy so the first paint ships the shell and the dashboard, not the
 * whole product. The contract workspace alone pulls in a diff viewer and a
 * chart set that most sessions never open.
 */
const Dashboard = lazy(() => import('./pages/Dashboard'))
const Repository = lazy(() => import('./pages/Repository'))
const ContractWorkspace = lazy(() => import('./pages/ContractWorkspace'))
const ContractForm = lazy(() => import('./pages/ContractForm'))
const Requests = lazy(() => import('./pages/Requests'))
const RequestDetail = lazy(() => import('./pages/RequestDetail'))
const Approvals = lazy(() => import('./pages/Approvals'))
const Obligations = lazy(() => import('./pages/Obligations'))
const Renewals = lazy(() => import('./pages/Renewals'))
const Amendments = lazy(() => import('./pages/Amendments'))
const Risks = lazy(() => import('./pages/Risks'))
const Templates = lazy(() => import('./pages/Templates'))
const ClauseLibrary = lazy(() => import('./pages/ClauseLibrary'))
const AiInsights = lazy(() => import('./pages/AiInsights'))
const AiReviewQueue = lazy(() => import('./pages/AiReviewQueue'))
const Reports = lazy(() => import('./pages/Reports'))
const Settings = lazy(() => import('./pages/Settings'))
const Notifications = lazy(() => import('./pages/Notifications'))
const GlobalSearch = lazy(() => import('./pages/GlobalSearch'))
const NotFound = lazy(() => import('./pages/NotFound'))

export default function App() {
  const { status } = useAuth()

  if (status === 'signed-out') return <SignIn />

  if (status === 'loading') {
    return (
      <main
        style={{
          minHeight: '100vh',
          display: 'grid',
          placeItems: 'center',
          color: 'var(--color-text-secondary)',
        }}
      >
        <div style={{ textAlign: 'center' }}>
          <div style={{ display: 'inline-flex', color: 'rgb(var(--color-primary))' }}>
            <Spinner size={26} label="Signing in" />
          </div>
          <p style={{ marginTop: 12, fontSize: 13.5 }}>Signing you in…</p>
        </div>
      </main>
    )
  }

  return (
    <ThemeProvider>
      <ToastProvider>
        <CompanyProvider>
          <SessionProvider>
            <BrowserRouter>
              <RouteAnalytics />
              <Suspense fallback={<RouteFallback />}>
                <Routes>
                  <Route element={<AppShell />}>
                    <Route index element={<Dashboard />} />
                    <Route path="contracts" element={<Repository />} />
                    <Route path="contracts/new" element={<ContractForm />} />
                    <Route path="contracts/:id" element={<ContractWorkspace />} />
                    <Route path="contracts/:id/edit" element={<ContractForm />} />
                    <Route path="requests" element={<Requests />} />
                    <Route path="requests/:id" element={<RequestDetail />} />
                    <Route path="approvals" element={<Approvals />} />
                    <Route path="obligations" element={<Obligations />} />
                    <Route path="renewals" element={<Renewals />} />
                    <Route path="amendments" element={<Amendments />} />
                    <Route path="risks" element={<Risks />} />
                    <Route path="templates" element={<Templates />} />
                    <Route path="clauses" element={<ClauseLibrary />} />
                    <Route path="insights" element={<AiInsights />} />
                    <Route path="ai/review" element={<AiReviewQueue />} />
                    <Route path="reports" element={<Reports />} />
                    <Route path="reports/:reportKey" element={<Reports />} />
                    <Route path="settings" element={<Settings />} />
                    <Route path="settings/:section" element={<Settings />} />
                    <Route path="notifications" element={<Notifications />} />
                    <Route path="search" element={<GlobalSearch />} />
                    {/* The portal lands here after sign-in; AuthProvider has
                        already consumed the token, so this is just the home
                        page under a different path. */}
                    <Route path="auth/callback" element={<Dashboard />} />
                    <Route path="*" element={<NotFound />} />
                  </Route>
                </Routes>
              </Suspense>
            </BrowserRouter>
          </SessionProvider>
        </CompanyProvider>
      </ToastProvider>
    </ThemeProvider>
  )
}

function RouteFallback() {
  return (
    <div style={{ display: 'grid', placeItems: 'center', minHeight: 320 }}>
      <div style={{ color: 'rgb(var(--color-primary))' }}>
        <Spinner size={22} label="Loading page" />
      </div>
    </div>
  )
}

/**
 * One page-view per navigation.
 *
 * Inside the router so it sees every route change, including a browser back
 * button, which a call in App's own effect would miss.
 */
function RouteAnalytics() {
  const location = useLocation()

  useEffect(() => {
    trackPageView(location.pathname, document.title)
  }, [location.pathname])

  return null
}
