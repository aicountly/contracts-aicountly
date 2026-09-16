import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { Card, PrimaryButton, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import { getContractRisk, assessContractRisk, getContractHealth, reviewRiskFinding } from '../../../api/endpoints/risk';
import { ApiError } from '../../../api/errors';
import type { RiskReviewStatus } from '../../../types/contracts';

interface RiskTabProps {
  contractId: number;
}

const REVIEW_OPTIONS: { value: RiskReviewStatus; label: string }[] = [
  { value: 'accepted', label: 'Accept' },
  { value: 'mitigated', label: 'Mitigated' },
  { value: 'false_positive', label: 'False positive' },
  { value: 'resolved', label: 'Resolved' },
];

export function RiskTab({ contractId }: RiskTabProps) {
  const { data: risk, loading, error, reload } = useAsync(() => getContractRisk(contractId), [contractId]);
  const health = useAsync(() => getContractHealth(contractId), [contractId]);
  const [assessing, setAssessing] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function handleAssess() {
    setAssessing(true);
    try {
      await assessContractRisk(contractId);
      reload();
    } catch (err) {
      Alert.alert('Could not run assessment', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setAssessing(false);
    }
  }

  async function handleReview(findingId: number, status: RiskReviewStatus) {
    setBusyId(findingId);
    try {
      await reviewRiskFinding(findingId, status);
      reload();
    } catch (err) {
      Alert.alert('Could not update', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  if (loading && !risk) return <LoadingState label="Loading risk assessment…" />;
  if (error && !risk) return <ErrorState message={error} onRetry={reload} />;

  const findings = risk?.findings ?? [];

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={{ flexDirection: 'row', gap: 16, marginBottom: 16 }}>
        {typeof risk?.score === 'number' ? (
          <Card style={{ flex: 1 }}>
            <Text style={{ fontSize: 11, color: colors.textMuted, fontFamily: 'Nunito_600SemiBold' }}>RISK SCORE</Text>
            <Text style={{ fontSize: 22, fontFamily: 'Nunito_700Bold', color: colors.textPrimary, marginTop: 4 }}>{risk.score}</Text>
          </Card>
        ) : null}
        {typeof health.data?.score === 'number' ? (
          <Card style={{ flex: 1 }}>
            <Text style={{ fontSize: 11, color: colors.textMuted, fontFamily: 'Nunito_600SemiBold' }}>HEALTH SCORE</Text>
            <Text style={{ fontSize: 22, fontFamily: 'Nunito_700Bold', color: colors.success, marginTop: 4 }}>{health.data.score}</Text>
          </Card>
        ) : null}
      </View>

      <PrimaryButton label="Run risk assessment" onPress={handleAssess} loading={assessing} variant="secondary" />

      <View style={{ marginTop: 16 }}>
        {findings.length === 0 ? (
          <EmptyState
            icon="shield-checkmark-outline"
            title="No risk findings"
            message="Run an assessment to check this contract against your playbook and rules."
          />
        ) : (
          findings.map((finding) => (
            <Card key={finding.id} style={{ marginBottom: 10 }}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }}>{finding.title}</Text>
                <StatusBadge value={finding.severity} kind="risk" />
              </View>
              {finding.detail ? (
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 6 }}>{finding.detail}</Text>
              ) : null}
              {finding.recommendation ? (
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 4, fontStyle: 'italic' }}>
                  {finding.recommendation}
                </Text>
              ) : null}
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 8 }}>
                <StatusBadge value={finding.review_status} kind="workflow" />
                {finding.detected_by === 'ai' ? <Text style={{ fontSize: 10, color: colors.violet, fontFamily: 'Nunito_600SemiBold' }}>AI</Text> : null}
              </View>
              {finding.review_status === 'open' ? (
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
                  {REVIEW_OPTIONS.map((opt) => (
                    <Text
                      key={opt.value}
                      onPress={() => handleReview(finding.id, opt.value)}
                      style={{
                        fontSize: 11,
                        fontFamily: 'Nunito_600SemiBold',
                        color: colors.primary,
                        paddingHorizontal: 10,
                        paddingVertical: 5,
                        borderRadius: 999,
                        borderWidth: 1,
                        borderColor: colors.primary,
                      }}
                    >
                      {busyId === finding.id ? '…' : opt.label}
                    </Text>
                  ))}
                </View>
              ) : null}
            </Card>
          ))
        )}
      </View>
    </ScrollView>
  );
}
