import { api } from '../client';
import type { ApprovalQueueItem, ApprovalActionName, ApprovalWorkflow, Paged } from '../../types/contracts';

export interface ApprovalStepRow {
  id?: number;
  step_no: number;
  name?: string | null;
  status: string;
  assignee?: string | null;
  acted_by?: string | null;
  acted_at?: string | null;
  comment?: string | null;
  [key: string]: unknown;
}

/** No dedicated ApprovalInstance interface exists in types/contracts.ts (only the queue-row shape) — shaped defensively. */
export interface ApprovalInstanceRow {
  id: number;
  uuid?: string | null;
  subject_type: string;
  subject_id: number;
  workflow_id?: number | null;
  workflow_name?: string | null;
  status: string;
  current_step?: number;
  submitted_by?: string | null;
  submitted_at?: string | null;
  completed_at?: string | null;
  steps?: ApprovalStepRow[];
  [key: string]: unknown;
}

export function listApprovalQueue(params: { page?: number; perPage?: number } = {}): Promise<Paged<ApprovalQueueItem>> {
  return api.get<Paged<ApprovalQueueItem>>('/approvals/queue', { page: params.page, per_page: params.perPage });
}

export function listApprovalInstances(params: { subjectType?: string; subjectId?: number | string } = {}): Promise<ApprovalInstanceRow[]> {
  return api.get<ApprovalInstanceRow[]>('/approvals/instances', { subject_type: params.subjectType, subject_id: params.subjectId });
}

export function submitForApproval(subjectType: string, subjectId: number | string, workflowId?: number): Promise<ApprovalInstanceRow> {
  return api.post<ApprovalInstanceRow>('/approvals/submit', { subject_type: subjectType, subject_id: subjectId, workflow_id: workflowId });
}

export function actOnApproval(instanceId: number | string, action: ApprovalActionName, comment?: string): Promise<void> {
  return api.post<void>(`/approvals/${instanceId}/act`, { action, comment });
}

export function cancelApproval(instanceId: number | string): Promise<void> {
  return api.post<void>(`/approvals/${instanceId}/cancel`);
}

export function listApprovalWorkflows(): Promise<ApprovalWorkflow[]> {
  return api.get<ApprovalWorkflow[]>('/approval-workflows');
}
