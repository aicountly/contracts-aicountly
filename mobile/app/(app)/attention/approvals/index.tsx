import { useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { ScreenContainer, Card, StatusBadge, LoadingState, ErrorState, EmptyState, PrimaryButton } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { formatMoney } from '../../../../src/utils/format';
import { useAsync } from '../../../../src/utils/useAsync';
import { listApprovalQueue, actOnApproval } from '../../../../src/api/endpoints/approvals';
import { ApiError } from '../../../../src/api/errors';
import type { ApprovalQueueItem } from '../../../../src/types/contracts';

export default function ApprovalsQueueScreen() {
  const router = useRouter();
  const { data, loading, error, reload } = useAsync(() => listApprovalQueue({ perPage: 50 }));
  const [busyId, setBusyId] = useState<number | null>(null);

  async function handleAct(item: ApprovalQueueItem, action: 'approve' | 'reject') {
    setBusyId(item.instance_id);
    try {
      await actOnApproval(item.instance_id, action);
      reload();
    } catch (err) {
      Alert.alert('Could not record decision', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  if (loading && !data) return <LoadingState label="Loading your approvals…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  const items = data?.items ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12, paddingBottom: 4, alignItems: 'flex-end' }}>
        <Text onPress={() => router.push('/attention/approvals/workflows')} style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
          View workflows
        </Text>
      </View>
      {items.length === 0 ? (
        <EmptyState icon="checkmark-done-outline" title="Nothing waiting on you" message="Approvals assigned to you will show up here." />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.assignment_id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/contracts/${item.contract_id}?tab=approvals`)}>
              <Card style={{ marginBottom: 10 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                    {item.contract_title ?? item.workflow_name ?? 'Approval'}
                  </Text>
                  {item.is_overdue ? (
                    <Text style={{ color: colors.danger, fontFamily: 'Nunito_700Bold', fontSize: 11 }}>Overdue</Text>
                  ) : null}
                </View>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
                  {item.contract_number}
                  {item.counterparty_name ? ` · ${item.counterparty_name}` : ''}
                </Text>
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8, alignItems: 'center' }}>
                  <Text style={{ fontSize: 12, color: colors.textSecondary, fontFamily: 'Nunito_500Medium' }}>
                    Step {item.step_no}
                    {item.step_name ? `: ${item.step_name}` : ''}
                  </Text>
                  {item.risk_level ? <StatusBadge value={item.risk_level} kind="risk" /> : null}
                  {item.total_value ? (
                    <Text style={{ fontSize: 12, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>
                      {formatMoney(item.total_value, item.currency)}
                    </Text>
                  ) : null}
                </View>
                <View style={{ flexDirection: 'row', gap: 10, marginTop: 12 }}>
                  <View style={{ flex: 1 }}>
                    <PrimaryButton label="Approve" onPress={() => handleAct(item, 'approve')} loading={busyId === item.instance_id} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <PrimaryButton label="Reject" variant="danger" onPress={() => handleAct(item, 'reject')} loading={busyId === item.instance_id} />
                  </View>
                </View>
              </Card>
            </Pressable>
          )}
        />
      )}
    </ScreenContainer>
  );
}
