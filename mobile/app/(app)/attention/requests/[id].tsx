import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { Card, PrimaryButton, FormField, LoadingState, ErrorState, StatusBadge } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { formatDate, formatMoney, formatDateTime } from '../../../../src/utils/format';
import { useAsync } from '../../../../src/utils/useAsync';
import { getRequest, submitRequest, decideRequest, convertRequest } from '../../../../src/api/endpoints/requests';
import { ApiError } from '../../../../src/api/errors';
import type { RequestDecision } from '../../../../src/types/contracts';

const DECISIONS: { value: RequestDecision; label: string }[] = [
  { value: 'review', label: 'Move to review' },
  { value: 'approve', label: 'Approve for drafting' },
  { value: 'more_info', label: 'Request more info' },
  { value: 'reject', label: 'Reject' },
];

export default function RequestDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const router = useRouter();
  const { data: request, loading, error, reload } = useAsync(() => getRequest(id), [id]);
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);

  async function handleSubmit() {
    setBusy(true);
    try {
      await submitRequest(id);
      reload();
    } catch (err) {
      Alert.alert('Could not submit', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  }

  async function handleDecision(decision: RequestDecision) {
    setBusy(true);
    try {
      await decideRequest(id, decision, notes || undefined);
      setNotes('');
      reload();
    } catch (err) {
      Alert.alert('Could not record decision', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  }

  async function handleConvert() {
    setBusy(true);
    try {
      const result = await convertRequest(id);
      router.replace(`/contracts/${result.contract_id}`);
    } catch (err) {
      Alert.alert('Could not convert', err instanceof ApiError ? err.message : 'Something went wrong.');
      setBusy(false);
    }
  }

  if (loading && !request) return <LoadingState label="Loading request…" />;
  if (error && !request) return <ErrorState message={error} onRetry={reload} />;
  if (!request) return null;

  const inDecision = ['submitted', 'under_review', 'more_info_required'].includes(request.status);

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 18, color: colors.textPrimary, flex: 1 }}>{request.title}</Text>
        <StatusBadge value={request.status} kind="workflow" />
      </View>
      <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginBottom: 16 }}>{request.request_number}</Text>

      <Card style={{ marginBottom: 16 }}>
        <DetailRow label="Counterparty" value={request.counterparty_name} />
        <DetailRow label="Contract type" value={request.contract_type_name} />
        <DetailRow label="Department" value={request.department_name} />
        <DetailRow label="Estimated value" value={request.estimated_value ? formatMoney(request.estimated_value, request.currency) : null} />
        <DetailRow label="Required by" value={request.required_by_date ? formatDate(request.required_by_date) : null} />
        <DetailRow label="Purpose" value={request.purpose} />
        <DetailRow label="Business justification" value={request.business_justification} />
        {request.notes ? <DetailRow label="Notes" value={request.notes} /> : null}
      </Card>

      {request.status === 'draft' ? (
        <PrimaryButton label="Submit request" onPress={handleSubmit} loading={busy} />
      ) : inDecision ? (
        <Card>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Decision</Text>
          <FormField label="Notes" value={notes} onChangeText={setNotes} placeholder="Reasoning for the record" />
          <View style={{ gap: 8 }}>
            {DECISIONS.map((d) => (
              <PrimaryButton
                key={d.value}
                label={d.label}
                variant={d.value === 'reject' ? 'danger' : 'secondary'}
                onPress={() => handleDecision(d.value)}
                loading={busy}
              />
            ))}
          </View>
        </Card>
      ) : request.status === 'approved_for_drafting' && !request.converted_contract_id ? (
        <PrimaryButton label="Convert to contract" onPress={handleConvert} loading={busy} />
      ) : request.converted_contract_id ? (
        <PrimaryButton label="View contract" onPress={() => router.push(`/contracts/${request.converted_contract_id}`)} variant="secondary" />
      ) : null}

      {request.activity && request.activity.length > 0 ? (
        <View style={{ marginTop: 20 }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Timeline</Text>
          {request.activity.map((entry) => (
            <View key={entry.id} style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.borderSoft }}>
              <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary }}>{entry.summary ?? entry.event_type}</Text>
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 11, color: colors.textMuted, marginTop: 2 }}>
                {[entry.actor_label, formatDateTime(entry.created_at)].filter(Boolean).join(' · ')}
              </Text>
            </View>
          ))}
        </View>
      ) : null}
    </ScrollView>
  );
}

function DetailRow({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value) return null;
  return (
    <View style={{ paddingVertical: 6 }}>
      <Text style={{ fontFamily: 'Nunito_500Medium', fontSize: 11, color: colors.textMuted }}>{label}</Text>
      <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary, marginTop: 2 }}>{value}</Text>
    </View>
  );
}
