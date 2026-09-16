import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { Card, PrimaryButton, FormField, DateField, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDate } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import { listContractAmendments, createAmendment, applyAmendment, type AmendmentInput } from '../../../api/endpoints/lifecycle';
import { ApiError } from '../../../api/errors';
import type { AmendmentRegisterItem } from '../../../types/contracts';

interface AmendmentsTabProps {
  contractId: number;
}

const EMPTY_INPUT: AmendmentInput = { title: '', description: null, effective_date: null };

export function AmendmentsTab({ contractId }: AmendmentsTabProps) {
  const { data: amendments, loading, error, reload } = useAsync(() => listContractAmendments(contractId), [contractId]);
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState<AmendmentInput>(EMPTY_INPUT);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function handleCreate() {
    setSaving(true);
    setSaveError(null);
    try {
      await createAmendment(contractId, form);
      setAdding(false);
      reload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not create this amendment.');
    } finally {
      setSaving(false);
    }
  }

  function confirmApply(amendment: AmendmentRegisterItem) {
    Alert.alert('Apply amendment', `Apply "${amendment.title}" to this contract? This cannot be undone.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Apply',
        onPress: async () => {
          setBusyId(amendment.id);
          try {
            await applyAmendment(amendment.id);
            reload();
          } catch (err) {
            Alert.alert('Could not apply', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setBusyId(null);
          }
        },
      },
    ]);
  }

  if (loading && !amendments) return <LoadingState label="Loading amendments…" />;
  if (error && !amendments) return <ErrorState message={error} onRetry={reload} />;

  if (adding) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 16 }}>New amendment</Text>
        {saveError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
          </View>
        ) : null}
        <FormField
          label="Title"
          value={form.title}
          onChangeText={(t) => setForm((f) => ({ ...f, title: t }))}
          placeholder="e.g. Amendment 2 — Pricing update"
        />
        <FormField
          label="Description"
          value={form.description ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, description: t || null }))}
          multiline
          numberOfLines={4}
          style={{ minHeight: 100, textAlignVertical: 'top' }}
        />
        <DateField label="Effective date" value={form.effective_date ?? null} onChange={(v) => setForm((f) => ({ ...f, effective_date: v }))} />
        <View style={{ flexDirection: 'row', gap: 10, marginTop: 8 }}>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Cancel" variant="secondary" onPress={() => setAdding(false)} />
          </View>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Create" onPress={handleCreate} loading={saving} disabled={!form.title.trim()} />
          </View>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={{ marginBottom: 16 }}>
        <PrimaryButton
          label="New amendment"
          onPress={() => {
            setForm(EMPTY_INPUT);
            setSaveError(null);
            setAdding(true);
          }}
        />
      </View>
      {!amendments || amendments.length === 0 ? (
        <EmptyState icon="git-branch-outline" title="No amendments yet" />
      ) : (
        amendments.map((amendment) => (
          <Card key={amendment.id} style={{ marginBottom: 10 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }}>
                #{amendment.amendment_no} · {amendment.title}
              </Text>
              <StatusBadge value={amendment.status} kind="workflow" />
            </View>
            {amendment.effective_date ? (
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 4 }}>
                Effective {formatDate(amendment.effective_date)}
              </Text>
            ) : null}
            {amendment.description ? (
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 4 }}>{amendment.description}</Text>
            ) : null}
            {amendment.status !== 'executed' && amendment.status !== 'cancelled' ? (
              <Text
                onPress={() => confirmApply(amendment)}
                style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12, marginTop: 10 }}
              >
                {busyId === amendment.id ? 'Applying…' : 'Apply amendment'}
              </Text>
            ) : null}
          </Card>
        ))
      )}
    </ScrollView>
  );
}
