import { api } from '../client';
import type { AmendmentRegisterItem, RenewalPipelineItem, RenewalDecisionName, Paged } from '../../types/contracts';

// --- Renewals ---

export interface RenewalListParams {
  bucket?: string;
  page?: number;
  perPage?: number;
}

export function listRenewalPipeline(params: RenewalListParams = {}): Promise<Paged<RenewalPipelineItem>> {
  return api.get<Paged<RenewalPipelineItem>>('/renewals', { bucket: params.bucket, page: params.page, per_page: params.perPage });
}

export function listContractRenewals(contractId: number | string): Promise<RenewalPipelineItem[]> {
  return api.get<RenewalPipelineItem[]>(`/contracts/${contractId}/renewals`);
}

export function ensureRenewalCycle(contractId: number | string): Promise<RenewalPipelineItem> {
  return api.post<RenewalPipelineItem>(`/contracts/${contractId}/renewals/ensure`);
}

export function recordRenewalDecision(renewalId: number | string, decision: RenewalDecisionName, notes?: string): Promise<void> {
  return api.post<void>(`/renewals/${renewalId}/decision`, { decision, notes });
}

export interface RenewalAdvice {
  recommendation?: string | null;
  recommendation_reason?: string | null;
  [key: string]: unknown;
}

export function getRenewalAdvice(renewalId: number | string): Promise<RenewalAdvice> {
  return api.post<RenewalAdvice>(`/renewals/${renewalId}/recommend`);
}

// --- Amendments ---

export interface AmendmentListParams {
  status?: string;
  page?: number;
  perPage?: number;
}

export function listAmendmentRegister(params: AmendmentListParams = {}): Promise<Paged<AmendmentRegisterItem>> {
  return api.get<Paged<AmendmentRegisterItem>>('/amendments', { status: params.status, page: params.page, per_page: params.perPage });
}

export function listContractAmendments(contractId: number | string): Promise<AmendmentRegisterItem[]> {
  return api.get<AmendmentRegisterItem[]>(`/contracts/${contractId}/amendments`);
}

export interface AmendmentInput {
  title: string;
  description?: string | null;
  effective_date?: string | null;
}

export function createAmendment(contractId: number | string, input: AmendmentInput): Promise<AmendmentRegisterItem> {
  return api.post<AmendmentRegisterItem>(`/contracts/${contractId}/amendments`, input);
}

export function updateAmendment(amendmentId: number | string, input: Partial<AmendmentInput>): Promise<AmendmentRegisterItem> {
  return api.put<AmendmentRegisterItem>(`/amendments/${amendmentId}`, input);
}

export function deleteAmendment(amendmentId: number | string): Promise<void> {
  return api.delete<void>(`/amendments/${amendmentId}`);
}

export function applyAmendment(amendmentId: number | string): Promise<void> {
  return api.post<void>(`/amendments/${amendmentId}/apply`);
}

export function getEffectivePosition(contractId: number | string): Promise<Record<string, unknown>> {
  return api.get<Record<string, unknown>>(`/contracts/${contractId}/effective-position`);
}

// --- Terminations (reached from the Renewal tab's "terminate" decision, not a tab of its own) ---

export interface TerminationRow {
  id: number;
  contract_id: number;
  reason?: string | null;
  status?: string | null;
  notice_date?: string | null;
  effective_date?: string | null;
  [key: string]: unknown;
}

export interface TerminationInput {
  reason: string;
  effective_date?: string | null;
  notes?: string | null;
}

export function listContractTerminations(contractId: number | string): Promise<TerminationRow[]> {
  return api.get<TerminationRow[]>(`/contracts/${contractId}/terminations`);
}

export function createTermination(contractId: number | string, input: TerminationInput): Promise<TerminationRow> {
  return api.post<TerminationRow>(`/contracts/${contractId}/terminations`, input);
}

export function approveTermination(terminationId: number | string): Promise<void> {
  return api.post<void>(`/terminations/${terminationId}/approve`);
}

export function issueTerminationNotice(terminationId: number | string): Promise<void> {
  return api.post<void>(`/terminations/${terminationId}/notice`);
}

export function completeTermination(terminationId: number | string): Promise<void> {
  return api.post<void>(`/terminations/${terminationId}/complete`);
}
