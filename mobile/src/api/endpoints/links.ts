import { api } from '../client';
import type { ContractLink } from '../../types/contracts';

export function listLinks(contractId: number | string): Promise<ContractLink[]> {
  return api.get<ContractLink[]>(`/contracts/${contractId}/links`);
}

export interface LinkInput {
  link_type: string;
  label?: string | null;
  note?: string | null;
  related_contract_id?: number | null;
  related_type?: string | null;
  related_id?: number | string | null;
}

export function addLink(contractId: number | string, input: LinkInput): Promise<ContractLink> {
  return api.post<ContractLink>(`/contracts/${contractId}/links`, input);
}

export function deleteLink(linkId: number | string): Promise<void> {
  return api.delete<void>(`/links/${linkId}`);
}
