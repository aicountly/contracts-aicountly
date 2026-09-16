import { ScrollView, Text, View } from 'react-native';
import { ScreenContainer, Card, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listApprovalWorkflows } from '../../../../src/api/endpoints/approvals';

export default function ApprovalWorkflowsScreen() {
  const { data: workflows, loading, error, reload } = useAsync(listApprovalWorkflows);

  if (loading && !workflows) return <LoadingState label="Loading workflows…" />;
  if (error && !workflows) return <ErrorState message={error} onRetry={reload} />;

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        {!workflows || workflows.length === 0 ? (
          <EmptyState icon="git-network-outline" title="No approval workflows configured" />
        ) : (
          workflows.map((wf) => (
            <Card key={wf.id} style={{ marginBottom: 10 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{wf.name}</Text>
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
                Applies to {wf.applies_to} · {wf.is_active ? 'Active' : 'Inactive'}
              </Text>
              {wf.steps && wf.steps.length > 0 ? (
                <View style={{ marginTop: 10 }}>
                  {wf.steps.map((step, i) => (
                    <Text key={step.id ?? i} style={{ fontSize: 12, color: colors.textSecondary, marginTop: 4 }}>
                      {step.step_no}. {step.name} · {step.execution} · {step.approver_type}
                    </Text>
                  ))}
                </View>
              ) : null}
            </Card>
          ))
        )}
      </ScrollView>
    </ScreenContainer>
  );
}
