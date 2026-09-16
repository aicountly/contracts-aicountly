import { api } from '../client';
import type { PortfolioRiskFinding, RiskReviewStatus, Paged } from '../../types/contracts';

/** Per-contract finding — same shape as the portfolio row minus the contract-identifying fields that would be redundant here. */
export type ContractRiskFinding = Omit<PortfolioRiskFinding, 'contract_number' | 'contract_title' | 'counterparty_name' | 'contract_status'>;

export interface ContractRiskSummary {
  findings: ContractRiskFinding[];
  score?: number | null;
  assessed_at?: string | null;
  [key: string]: unknown;
}

export function getContractRisk(contractId: number | string): Promise<ContractRiskSummary> {
  return api.get<ContractRiskSummary>(`/contracts/${contractId}/risk`);
}

export function assessContractRisk(contractId: number | string): Promise<ContractRiskSummary> {
  return api.post<ContractRiskSummary>(`/contracts/${contractId}/risk/assess`);
}

export interface ContractHealth {
  score?: number | null;
  [key: string]: unknown;
}

export function getContractHealth(contractId: number | string): Promise<ContractHealth> {
  return api.get<ContractHealth>(`/contracts/${contractId}/health`);
}

export function reviewRiskFinding(findingId: number | string, status: RiskReviewStatus, note?: string): Promise<void> {
  return api.post<void>(`/risk-findings/${findingId}/review`, { review_status: status, note });
}

export interface ListRisksParams {
  severity?: string;
  category?: string;
  reviewStatus?: string;
  page?: number;
  perPage?: number;
}

/** GET /risks — the portfolio-wide findings list (Attention tab). */
export function listPortfolioRisks(params: ListRisksParams = {}): Promise<Paged<PortfolioRiskFinding>> {
  return api.get<Paged<PortfolioRiskFinding>>('/risks', {
    severity: params.severity,
    category: params.category,
    review_status: params.reviewStatus,
    page: params.page,
    per_page: params.perPage,
  });
}
