import { api } from '../client';
import type { Contract, Paged, TemplateDetail, TemplateInput, TemplatePreview, TemplateSummary, TemplateVariable } from '../../types/contracts';

export interface ListTemplatesParams {
  q?: string;
  status?: string;
  contractTypeId?: string;
  page?: number;
  perPage?: number;
}

export function listTemplates(params: ListTemplatesParams = {}): Promise<Paged<TemplateSummary>> {
  return api.get<Paged<TemplateSummary>>('/templates', {
    q: params.q,
    status: params.status,
    contract_type_id: params.contractTypeId,
    page: params.page,
    per_page: params.perPage,
  });
}

export function getTemplate(id: number): Promise<TemplateDetail> {
  return api.get<TemplateDetail>(`/templates/${id}`);
}

export function listTemplateVariables(): Promise<TemplateVariable[]> {
  return api.get<TemplateVariable[]>('/template-variables');
}

export function createTemplate(input: TemplateInput): Promise<TemplateSummary> {
  return api.post<TemplateSummary>('/templates', input);
}

export function updateTemplate(id: number, input: TemplateInput): Promise<TemplateSummary> {
  return api.put<TemplateSummary>(`/templates/${id}`, input);
}

export function deleteTemplate(id: number): Promise<void> {
  return api.delete<void>(`/templates/${id}`);
}

export function previewTemplate(id: number, contractId: number | null): Promise<TemplatePreview> {
  return api.post<TemplatePreview>(`/templates/${id}/preview`, { contract_id: contractId });
}

export function createContractFromTemplate(
  id: number,
  body: { title: string; counterparty_name: string | null },
): Promise<{ contract: Contract } | Contract> {
  return api.post<{ contract: Contract } | Contract>(`/templates/${id}/create-contract`, body);
}
