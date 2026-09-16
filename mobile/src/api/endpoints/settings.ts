import { api } from '../client';
import type { ContractTypeSummary, DepartmentSummary, TagSummary, CustomFieldDefinition } from '../../types/contracts';

/**
 * Read-only lookups shared by the Repository filters and the contract form.
 * Full settings CRUD (contract types, departments, custom fields, tags,
 * roles, risk rules, integrations, saved views) lives in this same module,
 * extended when Settings itself is built.
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
