import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { PrimaryButton, FormField, SelectField, SwitchField, DateField, LoadingState, ErrorState, EmptyState } from '../../index';
import { ObligationRow } from '../../obligations/ObligationRow';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import { listContractObligations, createObligation, completeOccurrence, type ObligationInput } from '../../../api/endpoints/obligations';
import { ApiError } from '../../../api/errors';

const RESPONSIBLE_OPTIONS = [
  { value: 'company', label: 'Company (Us)' },
  { value: 'counterparty', label: 'Counterparty' },
  { value: 'both', label: 'Both' },
];

const EMPTY_INPUT: ObligationInput = {
  title: '',
  obligation_type: null,
  responsible_party: 'company',
  frequency: null,
  first_due_date: '',
  evidence_required: false,
  grace_period_days: null,
  amount: null,
  currency: null,
};

interface ObligationsTabProps {
  contractId: number;
}

export function ObligationsTab({ contractId }: ObligationsTabProps) {
  const { data: occurrences, loading, error, reload } = useAsync(() => listContractObligations(contractId), [contractId]);
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState<ObligationInput>(EMPTY_INPUT);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  async function handleCreate() {
    setSaving(true);
    setSaveError(null);
    try {
      await createObligation(contractId, form);
      setAdding(false);
      reload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not create this obligation.');
    } finally {
      setSaving(false);
    }
  }

  function confirmComplete(occurrenceId: number) {
    Alert.alert('Mark complete', 'Mark this occurrence as complete?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Complete',
        onPress: async () => {
          try {
            await completeOccurrence(occurrenceId);
            reload();
          } catch (err) {
            Alert.alert('Could not complete', err instanceof ApiError ? err.message : 'Something went wrong.');
          }
        },
      },
    ]);
  }

  if (loading && !occurrences) return <LoadingState label="Loading obligations…" />;
  if (error && !occurrences) return <ErrorState message={error} onRetry={reload} />;

  if (adding) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 16 }}>New obligation</Text>
        {saveError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
          </View>
        ) : null}
        <FormField label="Title" value={form.title} onChangeText={(t) => setForm((f) => ({ ...f, title: t }))} placeholder="e.g. Submit quarterly SLA report" />
        <SelectField
          label="Responsible party"
          value={form.responsible_party}
          options={RESPONSIBLE_OPTIONS}
          onChange={(v) => setForm((f) => ({ ...f, responsible_party: v as ObligationInput['responsible_party'] }))}
        />
        <DateField label="First due date" value={form.first_due_date || null} onChange={(v) => setForm((f) => ({ ...f, first_due_date: v ?? '' }))} clearable={false} />
        <FormField
          label="Frequency"
          value={form.frequency ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, frequency: t || null }))}
          placeholder="e.g. quarterly, monthly, one-time"
        />
        <FormField
          label="Grace period (days)"
          keyboardType="number-pad"
          value={form.grace_period_days ? String(form.grace_period_days) : ''}
          onChangeText={(t) => setForm((f) => ({ ...f, grace_period_days: t ? Number(t) : null }))}
        />
        <FormField label="Amount" keyboardType="numeric" value={form.amount ?? ''} onChangeText={(t) => setForm((f) => ({ ...f, amount: t || null }))} />
        <SwitchField label="Evidence required" value={!!form.evidence_required} onChange={(v) => setForm((f) => ({ ...f, evidence_required: v }))} />

        <View style={{ flexDirection: 'row', gap: 10, marginTop: 8 }}>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Cancel" variant="secondary" onPress={() => setAdding(false)} />
          </View>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Create" onPress={handleCreate} loading={saving} disabled={!form.title.trim() || !form.first_due_date} />
          </View>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={{ marginBottom: 16 }}>
        <PrimaryButton
          label="Add obligation"
          onPress={() => {
            setForm(EMPTY_INPUT);
            setSaveError(null);
            setAdding(true);
          }}
        />
      </View>
      {!occurrences || occurrences.length === 0 ? (
        <EmptyState icon="checkbox-outline" title="No obligations yet" message="Track what this contract requires, and from whom." />
      ) : (
        occurrences.map((occ) => (
          <ObligationRow key={occ.id} occurrence={occ} showContract={false} onPress={occ.status !== 'completed' ? () => confirmComplete(occ.id) : undefined} />
        ))
      )}
    </ScrollView>
  );
}
