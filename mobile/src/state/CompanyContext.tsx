import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import * as SecureStore from 'expo-secure-store';

import { setCompanyContext } from '../api/client';
import { getCompanyDetail, listCompanies, type BranchSummary, type CompanySummary, type FinancialYearSummary } from '../api/endpoints/manage';

/**
 * Which company, branch and financial year the user is working in — ported
 * near-verbatim from web/src/context/CompanyProvider.tsx. Every API call
 * carries this as X-AIC-* headers, and the server refuses a request without
 * it, so nothing else in the app renders until a company is chosen. The
 * choice is remembered on-device but re-validated against the list on each
 * load, since access can be revoked between visits.
 *
 * The stored value isn't a secret, but this reuses SecureStore (already a
 * dependency for auth_token) rather than adding a second storage library for
 * one small value — see sessionStore.ts for the same trade-off on auth_token.
 */

const STORAGE_KEY = 'aic.contracts.company';

interface StoredSelection {
  cmp_id: string;
  bo_id: string;
  fy_id: string;
}

interface CompanyValue {
  status: 'loading' | 'ready' | 'empty' | 'error';
  error: string | null;
  companies: CompanySummary[];
  company: CompanySummary | null;
  branches: BranchSummary[];
  financialYears: FinancialYearSummary[];
  cmpId: string | null;
  boId: string | null;
  fyId: string | null;
  selectCompany: (cmpId: string) => Promise<void>;
  selectBranch: (boId: string) => void;
  selectFinancialYear: (fyId: string) => void;
  reload: () => Promise<void>;
}

const CompanyContext = createContext<CompanyValue | null>(null);

async function readStored(): Promise<StoredSelection | null> {
  try {
    const raw = await SecureStore.getItemAsync(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as StoredSelection;
    return parsed?.cmp_id ? parsed : null;
  } catch {
    return null;
  }
}

async function writeStored(selection: StoredSelection): Promise<void> {
  try {
    await SecureStore.setItemAsync(STORAGE_KEY, JSON.stringify(selection));
  } catch {
    /* remembering the selection is a convenience */
  }
}

export function CompanyProvider({ children }: { children: ReactNode }) {
  const [status, setStatus] = useState<CompanyValue['status']>('loading');
  const [error, setError] = useState<string | null>(null);
  const [companies, setCompanies] = useState<CompanySummary[]>([]);
  const [company, setCompany] = useState<CompanySummary | null>(null);
  const [branches, setBranches] = useState<BranchSummary[]>([]);
  const [financialYears, setFinancialYears] = useState<FinancialYearSummary[]>([]);
  const [boId, setBoId] = useState<string | null>(null);
  const [fyId, setFyId] = useState<string | null>(null);

  const cmpId = company?.cmp_id ?? null;

  // One effect writes the headers the API client sends. Keeping it in a
  // single place means a half-applied switch (new company, old branch) can
  // never reach the server.
  useEffect(() => {
    if (cmpId && boId && fyId) {
      setCompanyContext({ cmp_id: cmpId, fy_id: fyId, bo_id: boId });
      void writeStored({ cmp_id: cmpId, bo_id: boId, fy_id: fyId });
    } else {
      setCompanyContext(null);
    }
  }, [cmpId, boId, fyId]);

  const loadCompany = useCallback(async (nextCmpId: string, preferred?: StoredSelection | null) => {
    const detail = await getCompanyDetail(nextCmpId);

    setCompany(detail.company);
    setBranches(detail.branches);
    setFinancialYears(detail.financial_years);

    // A remembered branch or year that no longer exists must not be sent —
    // the API validates both against Manage and would reject every request.
    const branch = detail.branches.find((b) => b.id === preferred?.bo_id) ?? detail.branches[0] ?? null;
    const year =
      detail.financial_years.find((f) => f.id === preferred?.fy_id) ??
      detail.financial_years.find((f) => f.is_current) ??
      detail.financial_years[0] ??
      null;

    setBoId(branch?.id ?? null);
    setFyId(year?.id ?? null);

    if (!branch || !year) {
      setStatus('error');
      setError('This company has no branch or financial year set up in Manage Account. Add one there, then reload.');
      return;
    }

    setStatus('ready');
    setError(null);
  }, []);

  const boot = useCallback(async () => {
    setStatus('loading');
    setError(null);
    try {
      const list = await listCompanies();
      setCompanies(list);
      if (list.length === 0) {
        setStatus('empty');
        return;
      }
      const stored = await readStored();
      const target = list.find((c) => c.cmp_id === stored?.cmp_id) ?? list[0];
      await loadCompany(target.cmp_id, stored);
    } catch (err) {
      setStatus('error');
      setError(err instanceof Error ? err.message : 'Could not load your companies.');
    }
  }, [loadCompany]);

  useEffect(() => {
    void boot();
  }, [boot]);

  const selectCompany = useCallback(
    async (nextCmpId: string) => {
      if (nextCmpId === cmpId) return;
      setStatus('loading');
      try {
        await loadCompany(nextCmpId, null);
      } catch (err) {
        setStatus('error');
        setError(err instanceof Error ? err.message : 'Could not switch company.');
      }
    },
    [cmpId, loadCompany],
  );

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
  );

  return <CompanyContext.Provider value={value}>{children}</CompanyContext.Provider>;
}

export function useCompany(): CompanyValue {
  const ctx = useContext(CompanyContext);
  if (!ctx) throw new Error('useCompany must be used inside <CompanyProvider>');
  return ctx;
}
