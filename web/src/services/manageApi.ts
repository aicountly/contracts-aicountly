/**
 * Company, branch and financial-year lists, read through this product's own API.
 *
 * The browser cannot call manage.aicountly.com directly — its CORS allow-list
 * does not include contracts.aicountly.com, and adding every product host to
 * every product's allow-list is not a thing anyone wants to maintain. The
 * Contracts API relays these three reads instead, which also means the ses_key
 * never has to be presented to a second origin.
 */

import { apiRequest } from './apiClient'

export interface CompanySummary {
  cmp_id: string
  name: string
  legal_name?: string
  gstin?: string
  currency?: string
  is_owner?: boolean
}

export interface BranchSummary {
  id: string
  name: string
}

export interface FinancialYearSummary {
  id: string
  label: string
  start_date?: string
  end_date?: string
  is_current?: boolean
}

export interface CompanyDetail {
  company: CompanySummary
  branches: BranchSummary[]
  financial_years: FinancialYearSummary[]
}

export function listCompanies(): Promise<CompanySummary[]> {
  return apiRequest<CompanySummary[]>('/manage/companies', { skipCompanyContext: true })
}

export function getCompanyDetail(cmpId: string): Promise<CompanyDetail> {
  return apiRequest<CompanyDetail>('/manage/company', {
    query: { cmp_id: cmpId },
    skipCompanyContext: true,
  })
}
