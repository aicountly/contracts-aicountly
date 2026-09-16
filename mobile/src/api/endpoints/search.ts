import { api } from '../client';
import type { SearchResults } from '../../types/contracts';

export function globalSearch(q: string, limit = 20): Promise<SearchResults> {
  return api.get<SearchResults>('/search', { q, limit });
}
