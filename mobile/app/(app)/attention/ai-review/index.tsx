import { useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { ScreenContainer, Card, LoadingState, ErrorState, EmptyState, PrimaryButton } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listReviewQueue, acceptExtraction, rejectExtraction } from '../../../../src/api/endpoints/ai';
import { ApiError } from '../../../../src/api/errors';
import type { AiExtractionRow } from '../../../../src/types/contracts';

export default function AiReviewQueueScreen() {
  const router = useRouter();
  const { data, loading, error, reload } = useAsync(() => listReviewQueue({ perPage: 50 }));
  const [busyId, setBusyId] = useState<number | null>(null);

  async function handleAccept(row: AiExtractionRow) {
    setBusyId(row.id);
    try {
      await acceptExtraction(row.id);
      reload();
    } catch (err) {
      Alert.alert('Could not accept', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  async function handleReject(row: AiExtractionRow) {
    setBusyId(row.id);
    try {
      await rejectExtraction(row.id);
      reload();
    } catch (err) {
      Alert.alert('Could not reject', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  if (loading && !data) return <LoadingState label="Loading AI review queue…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  const items = data?.items ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      {items.length === 0 ? (
        <EmptyState icon="sparkles-outline" title="Nothing to review" message="AI-extracted fields waiting for confirmation will show up here." />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16 }}
          renderItem={({ item }) => (
            <Card style={{ marginBottom: 10 }}>
              <Pressable onPress={() => router.push(`/contracts/${item.contract_id}`)}>
                <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 12, color: colors.textMuted }}>
                  {item.contract_number}
                  {item.contract_title ? ` · ${item.contract_title}` : ''}
                </Text>
              </Pressable>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginTop: 6 }}>
                {item.field_label ?? item.field_key}
              </Text>
              <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 15, color: colors.primary, marginTop: 4 }}>
                {item.normalised_value ?? item.extracted_value}
              </Text>
              {item.confidence !== null ? (
                <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 2 }}>{Math.round((item.confidence ?? 0) * 100)}% confidence</Text>
              ) : null}
              {item.source_excerpt ? (
                <View style={{ backgroundColor: colors.surface, borderRadius: 8, padding: 10, marginTop: 8 }}>
                  <Text style={{ fontSize: 12, color: colors.textSecondary, fontStyle: 'italic' }} numberOfLines={3}>
                    &ldquo;{item.source_excerpt}&rdquo;
                  </Text>
                </View>
              ) : null}
              <View style={{ flexDirection: 'row', gap: 10, marginTop: 12 }}>
                <View style={{ flex: 1 }}>
                  <PrimaryButton label="Accept" onPress={() => handleAccept(item)} loading={busyId === item.id} />
                </View>
                <View style={{ flex: 1 }}>
                  <PrimaryButton label="Reject" variant="danger" onPress={() => handleReject(item)} loading={busyId === item.id} />
                </View>
              </View>
            </Card>
          )}
        />
      )}
    </ScreenContainer>
  );
}
