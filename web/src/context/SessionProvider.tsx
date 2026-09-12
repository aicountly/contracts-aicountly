import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'

import { api } from '../services/apiClient'
import { useCompany } from './CompanyProvider'
import type { PermissionSlug } from '../types/permissions'

/**
 * Who the signed-in user is *inside Contracts* for the selected company.
 *
 * The portal says who they are; Manage says which company they may act for;
 * this says what they may do here. It is fetched per company, because a person
 * can be Legal in one company and read-only in another.
 */

export interface SessionState {
  uuid: string
  roles: string[]
  permissions: string[]
  counts: {
    approvals: number
    obligations: number
    renewals: number
    review_queue: number
    notifications: number
  }
  ai: { configured: boolean; provider?: string | null; model?: string | null }
  integrations: Record<string, { configured: boolean; detail?: string }>
}

interface SessionValue {
  status: 'loading' | 'ready' | 'error'
  session: SessionState | null
  error: string | null
  can: (permission: PermissionSlug | string) => boolean
  canAny: (permissions: (PermissionSlug | string)[]) => boolean
  refresh: () => Promise<void>
}

const SessionContext = createContext<SessionValue | null>(null)

const EMPTY_COUNTS: SessionState['counts'] = {
  approvals: 0,
  obligations: 0,
  renewals: 0,
  review_queue: 0,
  notifications: 0,
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const { cmpId, boId, fyId, status: companyStatus } = useCompany()
  const [status, setStatus] = useState<SessionValue['status']>('loading')
  const [session, setSession] = useState<SessionState | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (companyStatus !== 'ready' || !cmpId || !boId || !fyId) return

    setStatus('loading')
    try {
      const payload = await api.get<SessionState>('/me')
      setSession({ ...payload, counts: { ...EMPTY_COUNTS, ...payload.counts } })
      setStatus('ready')
      setError(null)
    } catch (err) {
      setStatus('error')
      setError(err instanceof Error ? err.message : 'Could not load your Contracts profile.')
    }
  }, [companyStatus, cmpId, boId, fyId])

  useEffect(() => {
    void load()
  }, [load])

  const can = useCallback(
    (permission: string) => session?.permissions.includes(permission) ?? false,
    [session],
  )

  const value = useMemo<SessionValue>(
    () => ({
      status,
      session,
      error,
      can,
      canAny: (permissions) => permissions.some(can),
      refresh: load,
    }),
    [status, session, error, can, load],
  )

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

export function useSession(): SessionValue {
  const ctx = useContext(SessionContext)
  if (!ctx) throw new Error('useSession must be used inside <SessionProvider>')
  return ctx
}
