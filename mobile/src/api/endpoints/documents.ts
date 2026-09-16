import { api, apiUpload } from '../client';
import type { ContractDocument, VersionUrl, CompareResult } from '../../types/contracts';

export function listDocuments(contractId: number | string): Promise<ContractDocument[]> {
  return api.get<ContractDocument[]>(`/contracts/${contractId}/documents`);
}

export function compareVersions(contractId: number | string, base: number | string, target: number | string): Promise<CompareResult> {
  return api.get<CompareResult>(`/contracts/${contractId}/compare`, { base, target });
}

export function getVersionUrl(versionId: number | string): Promise<VersionUrl> {
  return api.get<VersionUrl>(`/versions/${versionId}/url`);
}

export function markVersionExecuted(versionId: number | string): Promise<void> {
  return api.post<void>(`/versions/${versionId}/executed`);
}

export function deleteVersion(versionId: number | string): Promise<void> {
  return api.delete<void>(`/versions/${versionId}`);
}

interface PickedFile {
  uri: string;
  name: string;
  mimeType: string;
}

/**
 * POST /uploads/direct — the single-request fallback path (vs. the
 * signed-URL session flow in Routes.php meant for very large files going
 * straight to storage). Simplest correct path for mobile; revisit for the
 * session flow if large-file uploads prove slow through the API server.
 */
export function uploadDocumentDirect(contractId: number | string, file: PickedFile, title?: string): Promise<ContractDocument> {
  const form = new FormData();
  form.append('contract_id', String(contractId));
  if (title) form.append('title', title);
  // React Native's fetch accepts this {uri, name, type} object as a file part — see expo-document-picker's own docs.
  form.append('file', { uri: file.uri, name: file.name, type: file.mimeType } as unknown as Blob);
  return apiUpload<ContractDocument>('/uploads/direct', form);
}
