import { api } from '../client';
import type { ContractRequest, ContractRequestListItem, ContractRequestInput, RequestDecision, Paged } from '../../types/contracts';

export interface ListRequestsParams {
  status?: string[];
  q?: string;
  page?: number;
  perPage?: number;
}

export function listRequests(params: ListRequestsParams = {}): Promise<Paged<ContractRequestListItem>> {
  return api.get<Paged<ContractRequestListItem>>('/requests', { status: params.status, q: params.q, page: params.page, per_page: params.perPage });
}

export function getRequest(id: number | string): Promise<ContractRequest> {
  return api.get<ContractRequest>(`/requests/${id}`);
}

export function createRequest(input: ContractRequestInput): Promise<ContractRequest> {
  return api.post<ContractRequest>('/requests', input);
}

export function updateRequest(id: number | string, input: Partial<ContractRequestInput>): Promise<ContractRequest> {
  return api.put<ContractRequest>(`/requests/${id}`, input);
}

export function submitRequest(id: number | string): Promise<ContractRequest> {
  return api.post<ContractRequest>(`/requests/${id}/submit`);
}

export function decideRequest(id: number | string, decision: RequestDecision, notes?: string): Promise<ContractRequest> {
  return api.post<ContractRequest>(`/requests/${id}/decision`, { decision, notes });
}

export function convertRequest(id: number | string): Promise<{ contract_id: number }> {
  return api.post<{ contract_id: number }>(`/requests/${id}/convert`);
}
