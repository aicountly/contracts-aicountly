import { useState } from 'react';
import { Alert, FlatList, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import * as ImagePicker from 'expo-image-picker';
import * as DocumentPicker from 'expo-document-picker';
import { ScreenContainer, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { ObligationRow } from '../../../../src/components/obligations/ObligationRow';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listObligationOccurrences, completeOccurrence, completeOccurrenceWithEvidence } from '../../../../src/api/endpoints/obligations';
import { ApiError } from '../../../../src/api/errors';
import { humaniseSnakeCase } from '../../../../src/utils/format';
import type { ObligationOccurrenceRow } from '../../../../src/types/contracts';

const QUICK_FILTERS = ['due', 'overdue', 'upcoming'] as const;

export default function ObligationsQueueScreen() {
  const router = useRouter();
  const [statusFilter, setStatusFilter] = useState<string | null>(null);
  const { data, loading, error, reload } = useAsync(
    () => listObligationOccurrences({ status: statusFilter ?? undefined, perPage: 50 }),
    [statusFilter],
  );
  const [busyId, setBusyId] = useState<number | null>(null);

  async function completeWithoutEvidence(occurrence: ObligationOccurrenceRow) {
    setBusyId(occurrence.id);
    try {
      await completeOccurrence(occurrence.id);
      reload();
    } catch (err) {
      Alert.alert('Could not complete', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  async function completeWithPhoto(occurrence: ObligationOccurrenceRow) {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) {
      Alert.alert('Camera access needed', 'Allow camera access to attach evidence, or choose a file instead.');
      return;
    }
    const result = await ImagePicker.launchCameraAsync({ quality: 0.7 });
    if (result.canceled || !result.assets?.[0]) return;
    const asset = result.assets[0];
    setBusyId(occurrence.id);
    try {
      await completeOccurrenceWithEvidence(occurrence.id, undefined, {
        uri: asset.uri,
        name: asset.fileName ?? `evidence-${occurrence.id}.jpg`,
        mimeType: asset.mimeType ?? 'image/jpeg',
      });
      reload();
    } catch (err) {
      Alert.alert('Could not complete', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  async function completeWithFile(occurrence: ObligationOccurrenceRow) {
    const result = await DocumentPicker.getDocumentAsync({ multiple: false, copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;
    const file = result.assets[0];
    setBusyId(occurrence.id);
    try {
      await completeOccurrenceWithEvidence(occurrence.id, undefined, {
        uri: file.uri,
        name: file.name,
        mimeType: file.mimeType ?? 'application/octet-stream',
      });
      reload();
    } catch (err) {
      Alert.alert('Could not complete', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  function handleComplete(occurrence: ObligationOccurrenceRow) {
    if (!occurrence.evidence_required) {
      Alert.alert('Mark complete', 'Mark this occurrence as complete?', [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Complete', onPress: () => completeWithoutEvidence(occurrence) },
      ]);
      return;
    }
    Alert.alert('Evidence required', 'Attach evidence to complete this occurrence.', [
      { text: 'Cancel', style: 'cancel' },
      { text: 'Take Photo', onPress: () => completeWithPhoto(occurrence) },
      { text: 'Choose File', onPress: () => completeWithFile(occurrence) },
    ]);
  }

  if (loading && !data) return <LoadingState label="Loading obligations…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  const items = data?.items ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, paddingHorizontal: 16, paddingTop: 12, paddingBottom: 8 }}>
        {([null, ...QUICK_FILTERS] as (string | null)[]).map((status) => {
          const active = statusFilter === status;
          return (
            <Text
              key={status ?? 'all'}
              onPress={() => setStatusFilter(status)}
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
                overflow: 'hidden',
              }}
            >
              {status ? humaniseSnakeCase(status) : 'All'}
            </Text>
          );
        })}
      </View>

      {items.length === 0 ? (
        <EmptyState icon="checkbox-outline" title="Nothing due" message="Obligations across your contracts will show up here." />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <ObligationRow
              occurrence={item}
              onPress={
                item.status !== 'completed' ? () => handleComplete(item) : () => router.push(`/contracts/${item.contract_id}?tab=obligations`)
              }
            />
          )}
        />
      )}
    </ScreenContainer>
  );
}
