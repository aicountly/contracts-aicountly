import { useMemo } from 'react';
import { useLocalSearchParams } from 'expo-router';
import { ClauseEditorScreen } from '../../../../src/components/clauses/ClauseEditorScreen';
import { ErrorState } from '../../../../src/components';
import type { LibraryClauseItem } from '../../../../src/types/contracts';

/**
 * The API has no `GET /clauses/{id}` — only the list and its version history —
 * so the row is carried from the list screen as a serialized param, the same
 * way web keeps it in local state after a row click rather than re-fetching.
 */
export default function ClauseDetailScreen() {
  const { clause: clauseParam } = useLocalSearchParams<{ id: string; clause?: string }>();

  const clause = useMemo<LibraryClauseItem | null>(() => {
    if (!clauseParam) return null;
    try {
      return JSON.parse(clauseParam) as LibraryClauseItem;
    } catch {
      return null;
    }
  }, [clauseParam]);

  if (!clause) {
    return <ErrorState message="Open this clause from the library list — it can't be loaded directly." />;
  }

  return <ClauseEditorScreen clause={clause} />;
}
