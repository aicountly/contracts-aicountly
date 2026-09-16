import { api } from '../client';

export interface CommentRow {
  id: number;
  contract_id?: number;
  body?: string | null;
  text?: string | null;
  author_uuid?: string | null;
  author_name?: string | null;
  resolved_at?: string | null;
  created_at: string;
  [key: string]: unknown;
}

export function listComments(contractId: number | string): Promise<CommentRow[]> {
  return api.get<CommentRow[]>(`/contracts/${contractId}/comments`);
}

export function addComment(contractId: number | string, body: string): Promise<CommentRow> {
  return api.post<CommentRow>(`/contracts/${contractId}/comments`, { body });
}

export function updateComment(commentId: number | string, body: string): Promise<CommentRow> {
  return api.put<CommentRow>(`/comments/${commentId}`, { body });
}

export function deleteComment(commentId: number | string): Promise<void> {
  return api.delete<void>(`/comments/${commentId}`);
}

export function resolveComment(commentId: number | string): Promise<void> {
  return api.post<void>(`/comments/${commentId}/resolve`);
}
