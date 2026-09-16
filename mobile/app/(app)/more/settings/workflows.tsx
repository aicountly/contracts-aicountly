import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listApprovalWorkflows } from '../../../../src/api/endpoints/approvals';
import { humaniseSnakeCase } from '../../../../src/utils/format';
import type { ApprovalWorkflow } from '../../../../src/types/contracts';

function WorkflowCard({ workflow }: { workflow: ApprovalWorkflow }) {
  const [open, setOpen] = useState(false);
  const steps = workflow.steps ?? [];

  return (
    <Card style={{ marginBottom: 10, opacity: workflow.is_active ? 1 : 0.6 }}>
      <Pressable onPress={() => setOpen((o) => !o)}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
          <View style={{ flex: 1 }}>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{workflow.name}</Text>
            {workflow.description ? <Text style={{ fontSize: 12, color: colors.textSecondary, marginTop: 3 }}>{workflow.description}</Text> : null}
            <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 4 }}>
              {humaniseSnakeCase(workflow.applies_to)} · {steps.length} step{steps.length === 1 ? '' : 's'}
              {!workflow.is_active ? ' · Inactive' : ''}
            </Text>
          </View>
          <Ionicons name={open ? 'chevron-up' : 'chevron-down'} size={16} color={colors.textMuted} />
        </View>

        {open ? (
          <View style={{ marginTop: 12, gap: 8 }}>
            {steps.length === 0 ? (
              <Text style={{ fontSize: 12, color: colors.textMuted }}>No steps configured.</Text>
            ) : (
              [...steps]
                .sort((a, b) => a.step_no - b.step_no)
                .map((step, i) => (
                  <View key={step.id ?? i} style={{ padding: 10, backgroundColor: colors.surface, borderRadius: 8 }}>
                    <Text style={{ fontSize: 12.5, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>
                      {step.step_no}. {step.name}
                    </Text>
                    <Text style={{ fontSize: 11.5, color: colors.textSecondary, marginTop: 3 }}>
                      {humaniseSnakeCase(step.approver_type)}
                      {step.approver_value ? `: ${step.approver_value}` : ''} · {humaniseSnakeCase(step.execution)} · min {step.min_approvals} approval
                      {step.min_approvals === 1 ? '' : 's'}
                    </Text>
                  </View>
                ))
            )}
          </View>
        ) : null}
      </Pressable>
    </Card>
  );
}

export default function WorkflowsScreen() {
  const { data, loading, error, reload } = useAsync(listApprovalWorkflows);
  const workflows = data ?? [];

  if (loading && !data) return <LoadingState label="Loading workflows…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <Card style={{ marginBottom: 16, backgroundColor: colors.surface, borderColor: colors.borderSoft }}>
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, lineHeight: 18 }}>
            Read-only on mobile — building or changing a workflow&apos;s steps needs the web app.
          </Text>
        </Card>

        {workflows.length === 0 ? (
          <EmptyState icon="git-branch-outline" title="No approval workflows yet" />
        ) : (
          workflows.map((wf) => <WorkflowCard key={wf.id} workflow={wf} />)
        )}
      </ScrollView>
    </ScreenContainer>
  );
}
