import { FlatList, Text } from 'react-native';
import { ScreenContainer, Card, LoadingState, ErrorState, EmptyState } from '../../../src/components';
import { colors } from '../../../src/theme/colors';
import { formatDateTime } from '../../../src/utils/format';
import { useAsync } from '../../../src/utils/useAsync';
import { listAiInsights, type AiInsight } from '../../../src/api/endpoints/ai';
import type { Paged } from '../../../src/types/contracts';

function isPaged(data: unknown): data is Paged<AiInsight> {
  return !!data && typeof data === 'object' && 'items' in (data as Record<string, unknown>);
}

export default function AiInsightsScreen() {
  const { data, loading, error, reload } = useAsync(() => listAiInsights({ perPage: 50 }));
  const items: AiInsight[] = isPaged(data) ? data.items : Array.isArray(data) ? data : [];

  if (loading && !data) return <LoadingState label="Loading AI insights…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  return (
    <ScreenContainer bottomInset={false}>
      {items.length === 0 ? (
        <EmptyState icon="sparkles-outline" title="No AI insights yet" />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item, i) => String(item.id ?? i)}
          contentContainerStyle={{ padding: 16 }}
          renderItem={({ item }) => (
            <Card style={{ marginBottom: 10 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{item.title ?? 'Insight'}</Text>
              {item.contract_number ? (
                <Text style={{ fontFamily: 'Nunito_500Medium', fontSize: 11, color: colors.textMuted, marginTop: 2 }}>
                  {item.contract_number}
                  {item.contract_title ? ` · ${item.contract_title}` : ''}
                </Text>
              ) : null}
              {item.summary ? (
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 6 }}>{item.summary}</Text>
              ) : null}
              {item.created_at ? <Text style={{ fontSize: 10, color: colors.textMuted, marginTop: 6 }}>{formatDateTime(item.created_at)}</Text> : null}
            </Card>
          )}
        />
      )}
    </ScreenContainer>
  );
}
