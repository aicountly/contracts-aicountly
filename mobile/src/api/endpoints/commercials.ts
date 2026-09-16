import { api } from '../client';

/**
 * GET/PUT /contracts/{id}/commercials — response shape not yet mirrored in
 * types/contracts.ts (no dedicated interface exists there for it). Typed
 * loosely on purpose; see src/components/contracts/tabs/CommercialsTab.tsx
 * for how it's rendered until the real fields are confirmed.
 */
export function getCommercials(contractId: number | string): Promise<Record<string, unknown>> {
  return api.get<Record<string, unknown>>(`/contracts/${contractId}/commercials`);
}

export function updateCommercials(contractId: number | string, input: Record<string, unknown>): Promise<Record<string, unknown>> {
  return api.put<Record<string, unknown>>(`/contracts/${contractId}/commercials`, input);
}
