import { useState } from 'react';
import { Alert, FlatList, Pressable, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { ScreenContainer, Card, StatusBadge, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listPortfolioRisks, reviewRiskFinding } from '../../../../src/api/endpoints/risk';
import { ApiError } from '../../../../src/api/errors';
import { RISK_SEVERITIES } from '../../../../src/types/contracts';
import { humaniseSnakeCase } from '../../../../src/utils/format';

export default function RisksPortfolioScreen() {
  const router = useRouter();
  const [severity, setSeverity] = useState<string | null>(null);
  const { data, loading, error, reload } = useAsync(() => listPortfolioRisks({ severity: severity ?? undefined, perPage: 50 }), [severity]);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function handleAccept(findingId: number) {
    setBusyId(findingId);
    try {
      await reviewRiskFinding(findingId, 'accepted');
      reload();
    } catch (err) {
      Alert.alert('Could not update', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  const items = data?.items ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={{ flexGrow: 0, paddingVertical: 10 }}
        contentContainerStyle={{ paddingHorizontal: 16, gap: 8 }}
      >
        {([null, ...RISK_SEVERITIES] as (string | null)[]).map((s) => {
          const active = severity === s;
          return (
            <Text
              key={s ?? 'all'}
              onPress={() => setSeverity(s)}
              style={{
                paddingHorizontal: 12,
                paddingVertical: 7,
                borderRadius: 999,
                backgroundColor: active ? colors.primary : colors.surface,
                borderWidth: 1,
                borderColor: active ? colors.primary : colors.border,
                color: active ? '#FFFFFF' : colors.textSecondary,
                fontFamily: 'Nunito_600SemiBold',
                fontSize: 12,
              }}
            >
              {s ? humaniseSnakeCase(s) : 'All'}
            </Text>
          );
        })}
      </ScrollView>

      {loading && !data ? (
        <LoadingState label="Loading risk findings…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState icon="shield-checkmark-outline" title="No findings" message="Risk findings across every contract will show up here." />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/contracts/${item.contract_id}?tab=risk`)}>
              <Card style={{ marginBottom: 10 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                    {item.title}
                  </Text>
                  <StatusBadge value={item.severity} kind="risk" />
                </View>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
                  {item.contract_number}
                  {item.contract_title ? ` · ${item.contract_title}` : ''}
                </Text>
                {item.detail ? (
                  <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 6 }} numberOfLines={2}>
                    {item.detail}
                  </Text>
                ) : null}
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
                  <StatusBadge value={item.review_status} kind="workflow" />
                  {item.review_status === 'open' ? (
                    <Text
                      onPress={() => handleAccept(item.id)}
                      style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}
                    >
                      {busyId === item.id ? '…' : 'Accept'}
                    </Text>
                  ) : null}
                </View>
              </Card>
            </Pressable>
          )}
        />
      )}
    </ScreenContainer>
  );
}
