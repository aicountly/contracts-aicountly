import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { Card, PrimaryButton, FormField, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDate } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import { listContractRenewals, ensureRenewalCycle, recordRenewalDecision, getRenewalAdvice } from '../../../api/endpoints/lifecycle';
import { ApiError } from '../../../api/errors';
import type { RenewalDecisionName } from '../../../types/contracts';

interface RenewalTabProps {
  contractId: number;
}

const DECISIONS: { value: RenewalDecisionName; label: string }[] = [
  { value: 'renew', label: 'Renew' },
  { value: 'renegotiate', label: 'Renegotiate' },
  { value: 'terminate', label: 'Terminate' },
  { value: 'defer', label: 'Defer' },
];

export function RenewalTab({ contractId }: RenewalTabProps) {
  const { data: renewals, loading, error, reload } = useAsync(() => listContractRenewals(contractId), [contractId]);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [advice, setAdvice] = useState<string | null>(null);
  const [adviceLoading, setAdviceLoading] = useState(false);

  async function handleEnsureCycle() {
    setBusy(true);
    try {
      await ensureRenewalCycle(contractId);
      reload();
    } catch (err) {
      Alert.alert('Could not start renewal cycle', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  }

  async function handleDecision(renewalId: number, decision: RenewalDecisionName) {
    setBusy(true);
    try {
      await recordRenewalDecision(renewalId, decision, notes || undefined);
      setNotes('');
      reload();
    } catch (err) {
      Alert.alert('Could not record decision', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  }

  async function handleAdvice(renewalId: number) {
    setAdviceLoading(true);
    setAdvice(null);
    try {
      const result = await getRenewalAdvice(renewalId);
      setAdvice(result.recommendation_reason ?? 'No recommendation available.');
    } catch (err) {
      Alert.alert('Could not get AI advice', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setAdviceLoading(false);
    }
  }

  if (loading && !renewals) return <LoadingState label="Loading renewal…" />;
  if (error && !renewals) return <ErrorState message={error} onRetry={reload} />;

  if (!renewals || renewals.length === 0) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <EmptyState icon="refresh-outline" title="No renewal cycle yet" message="Start tracking this contract's renewal." />
        <PrimaryButton label="Start renewal cycle" onPress={handleEnsureCycle} loading={busy} />
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      {renewals.map((renewal) => (
        <Card key={renewal.id} style={{ marginBottom: 14 }}>
          <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>Cycle {renewal.cycle_no}</Text>
            <StatusBadge value={renewal.status} kind="workflow" />
          </View>
          <View style={{ marginTop: 8, gap: 4 }}>
            {renewal.current_expiry ? <Text style={{ fontSize: 12, color: colors.textMuted }}>Current expiry: {formatDate(renewal.current_expiry)}</Text> : null}
            {renewal.notice_deadline ? (
              <Text style={{ fontSize: 12, color: colors.textMuted }}>Notice deadline: {formatDate(renewal.notice_deadline)}</Text>
            ) : null}
            {renewal.decision_due_date ? (
              <Text style={{ fontSize: 12, color: colors.textMuted }}>Decision due: {formatDate(renewal.decision_due_date)}</Text>
            ) : null}
          </View>
          {renewal.recommendation ? (
            <Text style={{ fontSize: 12, color: colors.primary, fontFamily: 'Nunito_600SemiBold', marginTop: 8 }}>
              Recommended: {renewal.recommendation} {renewal.recommendation_source ? `(${renewal.recommendation_source})` : ''}
            </Text>
          ) : null}

          {renewal.decision ? (
            <Text style={{ fontSize: 12, color: colors.textSecondary, marginTop: 8 }}>
              Decision: {renewal.decision}
              {renewal.decision_notes ? ` — ${renewal.decision_notes}` : ''}
            </Text>
          ) : (
            <View style={{ marginTop: 12 }}>
              <FormField label="Notes (optional)" value={notes} onChangeText={setNotes} placeholder="Reasoning for the record" />
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
                {DECISIONS.map((d) => (
                  <View key={d.value} style={{ flexBasis: '48%', flexGrow: 1 }}>
                    <PrimaryButton
                      label={d.label}
                      variant={d.value === 'terminate' ? 'danger' : 'secondary'}
                      onPress={() => handleDecision(renewal.id, d.value)}
                      loading={busy}
                    />
                  </View>
                ))}
              </View>
              <View style={{ marginTop: 10 }}>
                <PrimaryButton label="Get AI advice" variant="secondary" onPress={() => handleAdvice(renewal.id)} loading={adviceLoading} />
              </View>
              {advice ? (
                <View style={{ backgroundColor: colors.primaryLight, borderRadius: 10, padding: 12, marginTop: 10 }}>
                  <Text style={{ color: colors.textPrimary, fontSize: 12 }}>{advice}</Text>
                </View>
              ) : null}
            </View>
          )}
        </Card>
      ))}
    </ScrollView>
  );
}
