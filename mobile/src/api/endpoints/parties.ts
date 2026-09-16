import { api } from '../client';
import type { ContractParty, PartySnapshot, CounterpartyContact } from '../../types/contracts';

export function listParties(contractId: number | string): Promise<ContractParty[]> {
  return api.get<ContractParty[]>(`/contracts/${contractId}/parties`);
}

export function searchCounterpartyContacts(query: string): Promise<CounterpartyContact[]> {
  return api.get<CounterpartyContact[]>('/counterparties/search', { q: query });
}

export interface PartyInput {
  party_role: string | null;
  name: string;
  legal_name?: string | null;
  contact_uuid?: string | null;
  contact_id?: number | string | null;
  email?: string | null;
  phone?: string | null;
  address?: string | null;
  registration_number?: string | null;
  signatory_name?: string | null;
  signatory_email?: string | null;
  signatory_designation?: string | null;
  is_primary?: boolean;
}

export function addParty(contractId: number | string, input: PartyInput): Promise<ContractParty> {
  return api.post<ContractParty>(`/contracts/${contractId}/parties`, input);
}

export function updateParty(partyId: number | string, input: Partial<PartyInput>): Promise<ContractParty> {
  return api.put<ContractParty>(`/parties/${partyId}`, input);
}

export function deleteParty(partyId: number | string): Promise<void> {
  return api.delete<void>(`/parties/${partyId}`);
}

export function snapshotParty(partyId: number | string): Promise<PartySnapshot> {
  return api.post<PartySnapshot>(`/parties/${partyId}/snapshot`);
}

export function snapshotAllParties(contractId: number | string): Promise<PartySnapshot[]> {
  return api.post<PartySnapshot[]>(`/contracts/${contractId}/parties/snapshot-all`);
}

export function listPartySnapshots(partyId: number | string): Promise<PartySnapshot[]> {
  return api.get<PartySnapshot[]>(`/parties/${partyId}/snapshots`);
}
