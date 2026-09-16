import { api, apiUpload } from '../client';
import type { ObligationOccurrenceRow, ObligationOccurrenceStatus, Paged } from '../../types/contracts';

export interface ListObligationsParams {
  status?: string;
  ownerUuid?: string;
  contractId?: number | string;
  q?: string;
  page?: number;
  perPage?: number;
}

/** GET /obligations — the cross-contract occurrence queue (Attention tab). */
export function listObligationOccurrences(params: ListObligationsParams = {}): Promise<Paged<ObligationOccurrenceRow>> {
  return api.get<Paged<ObligationOccurrenceRow>>('/obligations', {
    status: params.status,
    owner_uuid: params.ownerUuid,
    contract_id: params.contractId,
    q: params.q,
    page: params.page,
    per_page: params.perPage,
  });
}

/** GET /contracts/{id}/obligations — same occurrence shape, scoped to one contract. */
export function listContractObligations(contractId: number | string): Promise<ObligationOccurrenceRow[]> {
  return api.get<ObligationOccurrenceRow[]>(`/contracts/${contractId}/obligations`);
}

export interface ObligationInput {
  title: string;
  obligation_type?: string | null;
  responsible_party: 'company' | 'counterparty' | 'both';
  frequency?: string | null;
  first_due_date: string;
  evidence_required?: boolean;
  grace_period_days?: number | null;
  amount?: string | null;
  currency?: string | null;
  owner_uuid?: string | null;
}

export function createObligation(contractId: number | string, input: ObligationInput): Promise<void> {
  return api.post<void>(`/contracts/${contractId}/obligations`, input);
}

export function updateObligation(obligationId: number | string, input: Partial<ObligationInput>): Promise<void> {
  return api.put<void>(`/obligations/${obligationId}`, input);
}

export function deleteObligation(obligationId: number | string): Promise<void> {
  return api.delete<void>(`/obligations/${obligationId}`);
}

export function generateOccurrences(obligationId: number | string): Promise<void> {
  return api.post<void>(`/obligations/${obligationId}/generate`);
}

export function completeOccurrence(occurrenceId: number | string, note?: string): Promise<void> {
  return api.post<void>(`/occurrences/${occurrenceId}/complete`, { completion_note: note });
}

/** Same endpoint, as a multipart request — for the evidence-required case. */
export function completeOccurrenceWithEvidence(
  occurrenceId: number | string,
  note: string | undefined,
  evidence: { uri: string; name: string; mimeType: string },
): Promise<void> {
  const form = new FormData();
  if (note) form.append('completion_note', note);
  form.append('evidence', { uri: evidence.uri, name: evidence.name, type: evidence.mimeType } as unknown as Blob);
  return apiUpload<void>(`/occurrences/${occurrenceId}/complete`, form);
}

export function setOccurrenceStatus(occurrenceId: number | string, status: ObligationOccurrenceStatus, note?: string): Promise<void> {
  return api.post<void>(`/occurrences/${occurrenceId}/status`, { status, note });
}

/** No dedicated Milestone interface exists in types/contracts.ts — shaped defensively, matching the Commercials tab's own approach. */
export interface MilestoneRow {
  id: number;
  contract_id: number;
  title: string;
  description?: string | null;
  due_date?: string | null;
  status?: string | null;
  completed_at?: string | null;
  owner_uuid?: string | null;
  [key: string]: unknown;
}

export interface MilestoneInput {
  title: string;
  description?: string | null;
  due_date?: string | null;
  owner_uuid?: string | null;
}

export function listMilestones(contractId: number | string): Promise<MilestoneRow[]> {
  return api.get<MilestoneRow[]>(`/contracts/${contractId}/milestones`);
}

export function createMilestone(contractId: number | string, input: MilestoneInput): Promise<MilestoneRow> {
  return api.post<MilestoneRow>(`/contracts/${contractId}/milestones`, input);
}

export function updateMilestone(milestoneId: number | string, input: Partial<MilestoneInput>): Promise<MilestoneRow> {
  return api.put<MilestoneRow>(`/milestones/${milestoneId}`, input);
}

export function deleteMilestone(milestoneId: number | string): Promise<void> {
  return api.delete<void>(`/milestones/${milestoneId}`);
}

export function completeMilestone(milestoneId: number | string): Promise<MilestoneRow> {
  return api.post<MilestoneRow>(`/milestones/${milestoneId}/complete`);
}
