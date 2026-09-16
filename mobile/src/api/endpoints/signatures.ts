import { api, apiUpload } from '../client';

export interface SignatureRequestRow {
  id: number;
  contract_id?: number;
  signer_name?: string | null;
  signer_email?: string | null;
  order?: number;
  status: string;
  sent_at?: string | null;
  signed_at?: string | null;
  [key: string]: unknown;
}

export function listSignatureRequests(contractId: number | string): Promise<SignatureRequestRow[]> {
  return api.get<SignatureRequestRow[]>(`/contracts/${contractId}/signatures`);
}

export interface SignerInput {
  name: string;
  email: string;
  order?: number;
}

export function createSignatureRequest(contractId: number | string, signers: SignerInput[]): Promise<SignatureRequestRow[]> {
  return api.post<SignatureRequestRow[]>(`/contracts/${contractId}/signatures`, { signers });
}

export function sendSignatureRequest(requestId: number | string): Promise<void> {
  return api.post<void>(`/signatures/${requestId}/send`);
}

export function cancelSignatureRequest(requestId: number | string): Promise<void> {
  return api.post<void>(`/signatures/${requestId}/cancel`);
}

/** The manual provider's whole job: record execution once the signed copy is uploaded — see docs/IMPLEMENTATION_STATUS.md. */
export function markSigned(requestId: number | string, file: { uri: string; name: string; mimeType: string }): Promise<void> {
  const form = new FormData();
  form.append('file', { uri: file.uri, name: file.name, type: file.mimeType } as unknown as Blob);
  return apiUpload<void>(`/signatures/${requestId}/mark-signed`, form);
}
