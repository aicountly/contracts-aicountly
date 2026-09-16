/**
 * Company, branch and financial-year lists — mirrors web/src/services/manageApi.ts
 * exactly, including *why* these are relayed through Contracts' own API rather
 * than called directly: manage.aicountly.com's CORS allow-list does not include
 * contracts.aicountly.com. Mobile has no CORS concern, but reuses the same
 * relay so authorization rules stay identical between web and mobile.
 */
import { api } from '../client';

export interface CompanySummary {
  cmp_id: string;
  name: string;
  legal_name?: string;
  gstin?: string;
  currency?: string;
  is_owner?: boolean;
}

export interface BranchSummary {
  id: string;
  name: string;
}

export interface FinancialYearSummary {
  id: string;
  label: string;
  start_date?: string;
  end_date?: string;
  is_current?: boolean;
}

export interface CompanyDetail {
  company: CompanySummary;
  branches: BranchSummary[];
  financial_years: FinancialYearSummary[];
}

export function listCompanies(): Promise<CompanySummary[]> {
  return api.getWithoutCompany<CompanySummary[]>('/manage/companies');
}

export function getCompanyDetail(cmpId: string): Promise<CompanyDetail> {
  return api.getWithoutCompany<CompanyDetail>('/manage/company', { cmp_id: cmpId });
}
