import { api } from '../client';
import type {
  ContractTypeSummary,
  DepartmentSummary,
  TagSummary,
  CustomFieldDefinition,
  ContractTypeRow,
  DepartmentRow,
  CustomFieldRow,
  TagRow,
  RiskRuleRow,
  RolesPayload,
  IntegrationsPayload,
  AiStatus,
  SettingsPayload,
  ContractSettings,
} from '../../types/contracts';

/**
 * Read-only lookups shared by the Repository filters and the contract form,
 * plus the full settings CRUD built for the mobile Settings hub. The list+
 * create endpoints live under `/settings/{resource}`; individual read/update/
 * delete drop that prefix — `PUT /contract-types/{id}`, not
 * `/settings/contract-types/{id}` — mirroring the web app's own API calls.
 */
export function listContractTypes(): Promise<ContractTypeSummary[]> {
  return api.get<ContractTypeSummary[]>('/settings/contract-types');
}

export function listDepartments(): Promise<DepartmentSummary[]> {
  return api.get<DepartmentSummary[]>('/settings/departments');
}

export function listTags(): Promise<TagSummary[]> {
  return api.get<TagSummary[]>('/settings/tags');
}

export function listCustomFields(): Promise<CustomFieldDefinition[]> {
  return api.get<CustomFieldDefinition[]>('/settings/custom-fields');
}

/* --- General & numbering, reminders — both live on the one settings row --- */

export function getSettings(): Promise<SettingsPayload> {
  return api.get<SettingsPayload>('/settings');
}

export function updateSettings(patch: Partial<ContractSettings>): Promise<SettingsPayload> {
  return api.put<SettingsPayload>('/settings', patch);
}

/* --- Contract types --------------------------------------------------------- */

export function listContractTypeRows(): Promise<ContractTypeRow[]> {
  return api.get<ContractTypeRow[]>('/settings/contract-types');
}

export function getContractType(id: number): Promise<ContractTypeRow> {
  return api.get<ContractTypeRow>(`/contract-types/${id}`);
}

export function createContractType(input: Partial<ContractTypeRow>): Promise<ContractTypeRow> {
  return api.post<ContractTypeRow>('/settings/contract-types', input);
}

export function updateContractType(id: number, input: Partial<ContractTypeRow>): Promise<ContractTypeRow> {
  return api.put<ContractTypeRow>(`/contract-types/${id}`, input);
}

export function deleteContractType(id: number): Promise<void> {
  return api.delete<void>(`/contract-types/${id}`);
}

/* --- Departments ------------------------------------------------------------- */

export function listDepartmentRows(): Promise<DepartmentRow[]> {
  return api.get<DepartmentRow[]>('/settings/departments');
}

export function createDepartment(input: Partial<DepartmentRow>): Promise<DepartmentRow> {
  return api.post<DepartmentRow>('/settings/departments', input);
}

export function updateDepartment(id: number, input: Partial<DepartmentRow>): Promise<DepartmentRow> {
  return api.put<DepartmentRow>(`/departments/${id}`, input);
}

export function deleteDepartment(id: number): Promise<void> {
  return api.delete<void>(`/departments/${id}`);
}

/* --- Tags — add + delete only, no edit (matches web) ------------------------ */

export function listTagRows(): Promise<TagRow[]> {
  return api.get<TagRow[]>('/settings/tags');
}

export function createTag(input: { name: string; colour: string }): Promise<TagRow> {
  return api.post<TagRow>('/settings/tags', input);
}

export function deleteTag(id: number): Promise<void> {
  return api.delete<void>(`/tags/${id}`);
}

/* --- Custom fields ------------------------------------------------------------ */

export function listCustomFieldRows(): Promise<CustomFieldRow[]> {
  return api.get<CustomFieldRow[]>('/settings/custom-fields');
}

export function getCustomField(id: number): Promise<CustomFieldRow> {
  return api.get<CustomFieldRow>(`/custom-fields/${id}`);
}

export function createCustomField(input: Partial<CustomFieldRow>): Promise<CustomFieldRow> {
  return api.post<CustomFieldRow>('/settings/custom-fields', input);
}

export function updateCustomField(id: number, input: Partial<CustomFieldRow>): Promise<CustomFieldRow> {
  return api.put<CustomFieldRow>(`/custom-fields/${id}`, input);
}

export function deleteCustomField(id: number): Promise<void> {
  return api.delete<void>(`/custom-fields/${id}`);
}

/* --- Risk rules ---------------------------------------------------------------- */

export function listRiskRules(): Promise<RiskRuleRow[]> {
  return api.get<RiskRuleRow[]>('/settings/risk-rules');
}

export function getRiskRule(id: number): Promise<RiskRuleRow> {
  return api.get<RiskRuleRow>(`/risk-rules/${id}`);
}

export function createRiskRule(input: Partial<RiskRuleRow>): Promise<RiskRuleRow> {
  return api.post<RiskRuleRow>('/settings/risk-rules', input);
}

export function updateRiskRule(id: number, input: Partial<RiskRuleRow>): Promise<RiskRuleRow> {
  return api.put<RiskRuleRow>(`/risk-rules/${id}`, input);
}

export function deleteRiskRule(id: number): Promise<void> {
  return api.delete<void>(`/risk-rules/${id}`);
}

/* --- Roles, integrations, AI status — view-only on mobile ---------------------- */

export function getRoles(): Promise<RolesPayload> {
  return api.get<RolesPayload>('/settings/roles');
}

export function getIntegrations(): Promise<IntegrationsPayload> {
  return api.get<IntegrationsPayload>('/settings/integrations');
}

export function getAiStatus(): Promise<AiStatus> {
  return api.get<AiStatus>('/ai/status');
}
