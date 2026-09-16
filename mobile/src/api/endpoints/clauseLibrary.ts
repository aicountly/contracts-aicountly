import { api } from '../client';
import type { ClauseCategory, LibraryClauseInput, LibraryClauseItem, LibraryClauseVersion, Paged } from '../../types/contracts';

export interface ListClausesParams {
  q?: string;
  categoryId?: number | null;
  page?: number;
  perPage?: number;
}

export function listClauseCategories(): Promise<ClauseCategory[]> {
  return api.get<ClauseCategory[]>('/clause-categories');
}

export function listClauses(params: ListClausesParams = {}): Promise<Paged<LibraryClauseItem>> {
  return api.get<Paged<LibraryClauseItem>>('/clauses', {
    q: params.q,
    category_id: params.categoryId ?? undefined,
    page: params.page,
    per_page: params.perPage,
  });
}

export function listClauseVersions(clauseId: number): Promise<LibraryClauseVersion[]> {
  return api.get<LibraryClauseVersion[]>(`/clauses/${clauseId}/versions`);
}

export function createClause(input: LibraryClauseInput): Promise<LibraryClauseItem> {
  return api.post<LibraryClauseItem>('/clauses', input);
}

export function updateClause(id: number, input: LibraryClauseInput): Promise<LibraryClauseItem> {
  return api.put<LibraryClauseItem>(`/clauses/${id}`, input);
}

export function deleteClause(id: number): Promise<void> {
  return api.delete<void>(`/clauses/${id}`);
}
