import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'

import { setCompanyContext } from '../services/apiClient'
import {
  getCompanyDetail,
  listCompanies,
  type BranchSummary,
  type CompanySummary,
  type FinancialYearSummary,
} from '../services/manageApi'

/**
 * Which company, branch and financial year the user is working in.
 *
 * Every API call carries this as `X-AIC-*` headers, and the server refuses a
 * request without it, so nothing else in the app renders until a company is
 * chosen. The choice is remembered per browser — a user who works in one
 * company should not pick it again every morning — but it is re-validated
 * against the list on each load, because access can be revoked between visits.
 */

const STORAGE_KEY = 'aic.contracts.company'

interface StoredSelection {
  cmp_id: string
  bo_id: string
  fy_id: string
}

interface CompanyValue {
  status: 'loading' | 'ready' | 'empty' | 'error'
  error: string | null
  companies: CompanySummary[]
  company: CompanySummary | null
  branches: BranchSummary[]
  financialYears: FinancialYearSummary[]
  cmpId: string | null
  boId: string | null
  fyId: string | null
  selectCompany: (cmpId: string) => Promise<void>
  selectBranch: (boId: string) => void
  selectFinancialYear: (fyId: string) => void
  reload: () => Promise<void>
}

const CompanyContext = createContext<CompanyValue | null>(null)

function readStored(): StoredSelection | null {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as StoredSelection
    return parsed?.cmp_id ? parsed : null
  } catch {
    return null
  }
}

function writeStored(selection: StoredSelection): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(selection))
  } catch {
    /* remembering the selection is a convenience */
  }
}

export function CompanyProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<CompanyValue['status']>('loading')
  const [error, setError] = useState<string | null>(null)
  const [companies, setCompanies] = useState<CompanySummary[]>([])
  const [company, setCompany] = useState<CompanySummary | null>(null)
  const [branches, setBranches] = useState<BranchSummary[]>([])
  const [financialYears, setFinancialYears] = useState<FinancialYearSummary[]>([])
  const [boId, setBoId] = useState<string | null>(null)
  const [fyId, setFyId] = useState<string | null>(null)

  const cmpId = company?.cmp_id ?? null

  // One effect writes the headers the API client sends. Keeping it in a single
  // place means a half-applied switch (new company, old branch) can never reach
  // the server.
  useEffect(() => {
    if (cmpId && boId && fyId) {
      setCompanyContext({ cmp_id: cmpId, fy_id: fyId, bo_id: boId })
      writeStored({ cmp_id: cmpId, bo_id: boId, fy_id: fyId })
    } else {
      setCompanyContext(null)
    }
  }, [cmpId, boId, fyId])

  const loadCompany = useCallback(
    async (nextCmpId: string, preferred?: StoredSelection | null) => {
      const detail = await getCompanyDetail(nextCmpId)

      setCompany(detail.company)
      setBranches(detail.branches)
      setFinancialYears(detail.financial_years)

      // A remembered branch or year that no longer exists must not be sent —
      // the API validates both against Manage and would reject every request.
      const branch =
        detail.branches.find((b) => b.id === preferred?.bo_id) ?? detail.branches[0] ?? null
      const year =
        detail.financial_years.find((f) => f.id === preferred?.fy_id) ??
        detail.financial_years.find((f) => f.is_current) ??
        detail.financial_years[0] ??
        null

      setBoId(branch?.id ?? null)
      setFyId(year?.id ?? null)

      if (!branch || !year) {
        setStatus('error')
        setError(
          'This company has no branch or financial year set up in Manage Account. Add one there, then reload.',
        )
        return
      }

      setStatus('ready')
      setError(null)
    },
    [],
  )

  const boot = useCallback(async () => {
    setStatus('loading')
    setError(null)

    try {
      const list = await listCompanies()
      setCompanies(list)

      if (list.length === 0) {
        setStatus('empty')
        return
      }

      const stored = readStored()
      const target = list.find((c) => c.cmp_id === stored?.cmp_id) ?? list[0]

      await loadCompany(target.cmp_id, stored)
    } catch (err) {
      setStatus('error')
      setError(err instanceof Error ? err.message : 'Could not load your companies.')
    }
  }, [loadCompany])

  useEffect(() => {
    void boot()
  }, [boot])

  const selectCompany = useCallback(
    async (nextCmpId: string) => {
      if (nextCmpId === cmpId) return
      setStatus('loading')
      try {
        await loadCompany(nextCmpId, null)
      } catch (err) {
        setStatus('error')
        setError(err instanceof Error ? err.message : 'Could not switch company.')
      }
    },
    [cmpId, loadCompany],
  )

  const value = useMemo<CompanyValue>(
    () => ({
      status,
      error,
      companies,
      company,
      branches,
      financialYears,
      cmpId,
      boId,
      fyId,
      selectCompany,
      selectBranch: setBoId,
      selectFinancialYear: setFyId,
      reload: boot,
    }),
    [status, error, companies, company, branches, financialYears, cmpId, boId, fyId, selectCompany, boot],
  )

  return <CompanyContext.Provider value={value}>{children}</CompanyContext.Provider>
}

export function useCompany(): CompanyValue {
  const ctx = useContext(CompanyContext)
  if (!ctx) throw new Error('useCompany must be used inside <CompanyProvider>')
  return ctx
}
