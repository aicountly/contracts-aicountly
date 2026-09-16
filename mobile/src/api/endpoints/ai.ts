import { api, apiUpload } from '../client';
import type { AiStatus, AiJobRow, AiExtractionRow, ApplyVerifiedResult, Paged } from '../../types/contracts';

export function getAiStatus(): Promise<AiStatus> {
  return api.get<AiStatus>('/ai/status');
}

export function listAiJobs(params: { contractId?: number | string; status?: string; page?: number; perPage?: number } = {}): Promise<Paged<AiJobRow>> {
  return api.get<Paged<AiJobRow>>('/ai/jobs', { contract_id: params.contractId, status: params.status, page: params.page, per_page: params.perPage });
}

export function getAiJob(jobId: number | string): Promise<AiJobRow> {
  return api.get<AiJobRow>(`/ai/jobs/${jobId}`);
}

export function retryAiJob(jobId: number | string): Promise<void> {
  return api.post<void>(`/ai/jobs/${jobId}/retry`);
}

export function listReviewQueue(params: { contractId?: number | string; page?: number; perPage?: number } = {}): Promise<Paged<AiExtractionRow>> {
  return api.get<Paged<AiExtractionRow>>('/ai/review-queue', { contract_id: params.contractId, page: params.page, per_page: params.perPage });
}

export function acceptExtraction(extractionId: number | string, value?: string): Promise<void> {
  return api.post<void>(`/ai/extractions/${extractionId}/accept`, { accepted_value: value });
}

export function rejectExtraction(extractionId: number | string, reason?: string): Promise<void> {
  return api.post<void>(`/ai/extractions/${extractionId}/reject`, { reason });
}

export function importDocumentForExtraction(contractId: number | string, file: { uri: string; name: string; mimeType: string }): Promise<AiJobRow> {
  const form = new FormData();
  form.append('contract_id', String(contractId));
  form.append('file', { uri: file.uri, name: file.name, type: file.mimeType } as unknown as Blob);
  return apiUpload<AiJobRow>('/ai/import', form);
}

export function extractContract(contractId: number | string): Promise<AiJobRow> {
  return api.post<AiJobRow>(`/ai/contracts/${contractId}/extract`);
}

export function generateSummary(contractId: number | string): Promise<void> {
  return api.post<void>(`/ai/contracts/${contractId}/summarize`);
}

export interface ContractSummary {
  summary?: string | null;
  [key: string]: unknown;
}

export function getContractSummary(contractId: number | string): Promise<ContractSummary> {
  return api.get<ContractSummary>(`/ai/contracts/${contractId}/summary`);
}

export function editSummary(contractId: number | string, summary: string): Promise<ContractSummary> {
  return api.put<ContractSummary>(`/ai/contracts/${contractId}/summary`, { summary });
}

interface AskResponse {
  text?: string;
  answer?: string;
  citations?: unknown[];
  [key: string]: unknown;
}

export async function askContract(contractId: number | string, question: string): Promise<{ text: string; citations?: unknown[] }> {
  const res = await api.post<AskResponse>(`/ai/contracts/${contractId}/ask`, { question });
  return { text: res.text ?? res.answer ?? '', citations: res.citations };
}

export interface AiConversationMessage {
  id: number | string;
  role: 'user' | 'assistant';
  content: string;
  citations?: unknown[];
  created_at: string;
  [key: string]: unknown;
}

export function listContractConversations(contractId: number | string): Promise<{ id: number | string }[]> {
  return api.get(`/ai/contracts/${contractId}/conversations`);
}

export function listConversationMessages(conversationId: number | string): Promise<AiConversationMessage[]> {
  return api.get<AiConversationMessage[]>(`/ai/conversations/${conversationId}/messages`);
}

export function applyVerifiedExtractions(contractId: number | string): Promise<ApplyVerifiedResult> {
  return api.post<ApplyVerifiedResult>(`/ai/contracts/${contractId}/apply-verified`);
}

export interface AiInsight {
  id?: number | string;
  contract_id?: number;
  contract_number?: string | null;
  contract_title?: string | null;
  title?: string | null;
  summary?: string | null;
  category?: string | null;
  created_at?: string;
  [key: string]: unknown;
}

export function listAiInsights(params: { page?: number; perPage?: number } = {}): Promise<Paged<AiInsight> | AiInsight[]> {
  return api.get('/ai/insights', { page: params.page, per_page: params.perPage });
}
