import { useState } from 'react';
import { FlatList, Pressable, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { ScreenContainer, Card, StatusBadge, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { formatDate, formatMoney } from '../../../../src/utils/format';
import { useAsync } from '../../../../src/utils/useAsync';
import { listRenewalPipeline } from '../../../../src/api/endpoints/lifecycle';
import type { RenewalBucket } from '../../../../src/types/contracts';

const BUCKETS: { value: RenewalBucket; label: string }[] = [
  { value: 'all', label: 'All' },
  { value: 'decision_due', label: 'Decision due' },
  { value: 'notice_due', label: 'Notice due' },
  { value: 'expiring_30', label: 'Expiring 30d' },
  { value: 'expiring_60', label: 'Expiring 60d' },
  { value: 'expiring_90', label: 'Expiring 90d' },
  { value: 'auto_renewal_risk', label: 'Auto-renewal risk' },
];

export default function RenewalsPipelineScreen() {
  const router = useRouter();
  const [bucket, setBucket] = useState<RenewalBucket>('all');
  const { data, loading, error, reload } = useAsync(() => listRenewalPipeline({ bucket, perPage: 50 }), [bucket]);

  const items = data?.items ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ flexGrow: 0, paddingVertical: 10 }} contentContainerStyle={{ paddingHorizontal: 16, gap: 8 }}>
        {BUCKETS.map((b) => {
          const active = bucket === b.value;
          return (
            <Text
              key={b.value}
              onPress={() => setBucket(b.value)}
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
              {b.label}
            </Text>
          );
        })}
      </ScrollView>

      {loading && !data ? (
        <LoadingState label="Loading renewals…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState icon="refresh-outline" title="Nothing in this bucket" />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/contracts/${item.contract_id}?tab=renewal`)}>
              <Card style={{ marginBottom: 10 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                    {item.contract_title}
                  </Text>
                  <StatusBadge value={item.status} kind="workflow" />
                </View>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
                  {item.contract_number}
                  {item.counterparty_name ? ` · ${item.counterparty_name}` : ''}
                </Text>
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 8 }}>
                  {item.current_expiry ? (
                    <Text style={{ fontSize: 12, color: colors.textSecondary }}>Expires {formatDate(item.current_expiry)}</Text>
                  ) : null}
                  {item.notice_deadline ? (
                    <Text style={{ fontSize: 12, color: colors.warning, fontFamily: 'Nunito_600SemiBold' }}>
                      Notice by {formatDate(item.notice_deadline)}
                    </Text>
                  ) : null}
                  {item.total_value ? (
                    <Text style={{ fontSize: 12, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>
                      {formatMoney(item.total_value, item.currency)}
                    </Text>
                  ) : null}
                </View>
                {item.recommendation ? (
                  <Text style={{ fontSize: 11, color: colors.primary, fontFamily: 'Nunito_600SemiBold', marginTop: 6 }}>
                    Recommended: {item.recommendation}
                  </Text>
                ) : null}
              </Card>
            </Pressable>
          )}
        />
      )}
    </ScreenContainer>
  );
}
