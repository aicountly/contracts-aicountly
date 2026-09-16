import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { Card, PrimaryButton, FormField, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDateTime } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import { listApprovalInstances, submitForApproval, actOnApproval, cancelApproval, type ApprovalInstanceRow } from '../../../api/endpoints/approvals';
import { ApiError } from '../../../api/errors';

interface ApprovalsTabProps {
  contractId: number;
}

export function ApprovalsTab({ contractId }: ApprovalsTabProps) {
  const { data: instances, loading, error, reload } = useAsync(
    () => listApprovalInstances({ subjectType: 'contract', subjectId: contractId }),
    [contractId],
  );
  const [submitting, setSubmitting] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [comment, setComment] = useState('');

  async function handleSubmit() {
    setSubmitting(true);
    try {
      await submitForApproval('contract', contractId);
      reload();
    } catch (err) {
      Alert.alert('Could not submit', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setSubmitting(false);
    }
  }

  async function handleAct(instance: ApprovalInstanceRow, action: 'approve' | 'reject') {
    setBusyId(instance.id);
    try {
      await actOnApproval(instance.id, action, comment || undefined);
      setComment('');
      reload();
    } catch (err) {
      Alert.alert('Could not record decision', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  function confirmCancel(instance: ApprovalInstanceRow) {
    Alert.alert('Cancel approval', 'Cancel this approval request?', [
      { text: 'No', style: 'cancel' },
      {
        text: 'Cancel request',
        style: 'destructive',
        onPress: async () => {
          setBusyId(instance.id);
          try {
            await cancelApproval(instance.id);
            reload();
          } catch (err) {
            Alert.alert('Could not cancel', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setBusyId(null);
          }
        },
      },
    ]);
  }

  if (loading && !instances) return <LoadingState label="Loading approvals…" />;
  if (error && !instances) return <ErrorState message={error} onRetry={reload} />;

  const active = instances?.find((i) => i.status === 'pending' || i.status === 'in_progress');

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      {!active ? (
        <View style={{ marginBottom: 16 }}>
          <PrimaryButton label="Submit for approval" onPress={handleSubmit} loading={submitting} />
        </View>
      ) : null}

      {!instances || instances.length === 0 ? (
        <EmptyState icon="checkmark-done-outline" title="No approval history" message="Submit this contract when it's ready for review." />
      ) : (
        instances.map((instance) => (
          <Card key={instance.id} style={{ marginBottom: 14 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 8 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{instance.workflow_name ?? 'Approval'}</Text>
              <StatusBadge value={instance.status} kind="workflow" />
            </View>
            {instance.submitted_at ? (
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted }}>Submitted {formatDateTime(instance.submitted_at)}</Text>
            ) : null}

            {instance.steps && instance.steps.length > 0 ? (
              <View style={{ marginTop: 10 }}>
                {instance.steps.map((step, i) => (
                  <View
                    key={step.id ?? i}
                    style={{
                      flexDirection: 'row',
                      justifyContent: 'space-between',
                      paddingVertical: 6,
                      borderTopWidth: i === 0 ? 0 : 1,
                      borderTopColor: colors.borderSoft,
                    }}
                  >
                    <Text style={{ fontSize: 12, color: colors.textSecondary, fontFamily: 'Nunito_500Medium' }}>
                      {step.step_no}. {step.name ?? 'Step'}
                    </Text>
                    <StatusBadge value={step.status} kind="workflow" />
                  </View>
                ))}
              </View>
            ) : null}

            {instance.status === 'pending' || instance.status === 'in_progress' ? (
              <View style={{ marginTop: 12 }}>
                <FormField label="Comment (optional)" value={comment} onChangeText={setComment} placeholder="Add a note with your decision" />
                <View style={{ flexDirection: 'row', gap: 10 }}>
                  <View style={{ flex: 1 }}>
                    <PrimaryButton label="Approve" onPress={() => handleAct(instance, 'approve')} loading={busyId === instance.id} />
                  </View>
                  <View style={{ flex: 1 }}>
                    <PrimaryButton label="Reject" variant="danger" onPress={() => handleAct(instance, 'reject')} loading={busyId === instance.id} />
                  </View>
                </View>
                <Text
                  onPress={() => confirmCancel(instance)}
                  style={{ color: colors.textMuted, fontFamily: 'Nunito_600SemiBold', fontSize: 12, textAlign: 'center', marginTop: 10 }}
                >
                  Cancel this request
                </Text>
              </View>
            ) : null}
          </Card>
        ))
      )}
    </ScrollView>
  );
}
