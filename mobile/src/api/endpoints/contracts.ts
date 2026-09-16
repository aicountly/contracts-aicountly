import { api } from '../client';
import type { Contract, ContractListItem, ContractInput, ContractFilters, ContractSort, ActivityEntry, Paged } from '../../types/contracts';

export interface ListContractsParams {
  filters?: Partial<ContractFilters>;
  sort?: ContractSort;
  page?: number;
  perPage?: number;
}

/** Mirrors web/src/pages/Repository.tsx's own GET /contracts call — same filter keys, same sort/page/per_page shape. */
export function listContracts(params: ListContractsParams = {}): Promise<Paged<ContractListItem>> {
  const f = params.filters ?? {};
  return api.get<Paged<ContractListItem>>('/contracts', {
    q: f.q,
    status: f.status,
    contract_type_id: f.contract_type_id,
    department_id: f.department_id,
    owner_uuid: f.owner_uuid,
    counterparty: f.counterparty,
    risk_level: f.risk_level,
    currency: f.currency,
    auto_renewal: f.auto_renewal,
    approval_status: f.approval_status,
    signing_status: f.signing_status,
    effective_from: f.effective_from,
    effective_to: f.effective_to,
    expiry_from: f.expiry_from,
    expiry_to: f.expiry_to,
    value_min: f.value_min,
    value_max: f.value_max,
    tag_id: f.tag_id,
    favourites_only: f.favourites_only,
    expiring_within_days: f.expiring_within_days,
    obligation_status: f.obligation_status,
    archived: f.archived,
    sort: params.sort?.key,
    dir: params.sort?.dir,
    page: params.page,
    per_page: params.perPage,
  });
}

export function getContract(id: number | string): Promise<Contract> {
  return api.get<Contract>(`/contracts/${id}`);
}

export function createContract(input: ContractInput): Promise<Contract> {
  return api.post<Contract>('/contracts', input);
}

export function updateContract(id: number | string, input: Partial<ContractInput>): Promise<Contract> {
  return api.put<Contract>(`/contracts/${id}`, input);
}

export function deleteContract(id: number | string): Promise<void> {
  return api.delete<void>(`/contracts/${id}`);
}

export function changeContractStatus(id: number | string, status: string, note?: string): Promise<Contract> {
  return api.post<Contract>(`/contracts/${id}/status`, { status, note });
}

export function setContractArchived(id: number | string, archived: boolean): Promise<Contract> {
  return api.post<Contract>(`/contracts/${id}/archive`, { archived });
}

/** POST /contracts/{id}/favourite — returns just { favourite }, not the full contract (see web/src/pages/Repository.tsx). */
export function setContractFavourite(id: number | string, favourite: boolean): Promise<{ favourite: boolean }> {
  return api.post<{ favourite: boolean }>(`/contracts/${id}/favourite`, { favourite });
}

export function getContractActivity(id: number | string): Promise<ActivityEntry[]> {
  return api.get<ActivityEntry[]>(`/contracts/${id}/activity`);
}

export function getContractAudit(id: number | string): Promise<ActivityEntry[]> {
  return api.get<ActivityEntry[]>(`/contracts/${id}/audit`);
}
