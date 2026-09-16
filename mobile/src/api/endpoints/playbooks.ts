import { api } from '../client';
import type { PlaybookRule, PlaybookSummary } from '../../types/contracts';

export function listPlaybooks(): Promise<PlaybookSummary[]> {
  return api.get<PlaybookSummary[]>('/playbooks');
}

export function listPlaybookRules(playbookId: number): Promise<PlaybookRule[]> {
  return api.get<PlaybookRule[]>(`/playbooks/${playbookId}/rules`);
}

export interface PlaybookRuleBody {
  rule_key: string;
  label: string;
  description: string | null;
  rule_type: string;
  expected_value: string | null;
  expected_numeric: number | null;
  expected_list: string[];
  category_id: number | null;
  severity: string;
  risk_category: string;
  recommendation: string | null;
  sort_order: number;
  is_active: boolean;
}

export function createPlaybookRule(playbookId: number, body: PlaybookRuleBody): Promise<PlaybookRule> {
  return api.post<PlaybookRule>(`/playbooks/${playbookId}/rules`, body);
}

export function updatePlaybookRule(ruleId: number, body: PlaybookRuleBody): Promise<PlaybookRule> {
  return api.put<PlaybookRule>(`/playbook-rules/${ruleId}`, body);
}

export function deletePlaybookRule(ruleId: number): Promise<void> {
  return api.delete<void>(`/playbook-rules/${ruleId}`);
}
