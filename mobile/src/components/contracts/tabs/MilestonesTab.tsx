import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { Card, PrimaryButton, FormField, DateField, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDate } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import {
  listMilestones,
  createMilestone,
  completeMilestone,
  deleteMilestone,
  type MilestoneInput,
  type MilestoneRow,
} from '../../../api/endpoints/obligations';
import { ApiError } from '../../../api/errors';

const EMPTY_INPUT: MilestoneInput = { title: '', description: null, due_date: null };

interface MilestonesTabProps {
  contractId: number;
}

export function MilestonesTab({ contractId }: MilestonesTabProps) {
  const { data: milestones, loading, error, reload } = useAsync(() => listMilestones(contractId), [contractId]);
  const [adding, setAdding] = useState(false);
  const [form, setForm] = useState<MilestoneInput>(EMPTY_INPUT);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  async function handleCreate() {
    setSaving(true);
    setSaveError(null);
    try {
      await createMilestone(contractId, form);
      setAdding(false);
      reload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not create this milestone.');
    } finally {
      setSaving(false);
    }
  }

  async function handleComplete(milestone: MilestoneRow) {
    try {
      await completeMilestone(milestone.id);
      reload();
    } catch (err) {
      Alert.alert('Could not complete', err instanceof ApiError ? err.message : 'Something went wrong.');
    }
  }

  function confirmDelete(milestone: MilestoneRow) {
    Alert.alert('Delete milestone', `Delete "${milestone.title}"?`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          try {
            await deleteMilestone(milestone.id);
            reload();
          } catch (err) {
            Alert.alert('Could not delete', err instanceof ApiError ? err.message : 'Something went wrong.');
          }
        },
      },
    ]);
  }

  if (loading && !milestones) return <LoadingState label="Loading milestones…" />;
  if (error && !milestones) return <ErrorState message={error} onRetry={reload} />;

  if (adding) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 16 }}>New milestone</Text>
        {saveError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
          </View>
        ) : null}
        <FormField label="Title" value={form.title} onChangeText={(t) => setForm((f) => ({ ...f, title: t }))} placeholder="e.g. Go-live" />
        <FormField
          label="Description"
          value={form.description ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, description: t || null }))}
          multiline
          numberOfLines={3}
          style={{ minHeight: 80, textAlignVertical: 'top' }}
        />
        <DateField label="Due date" value={form.due_date ?? null} onChange={(v) => setForm((f) => ({ ...f, due_date: v }))} />
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
          label="Add milestone"
          onPress={() => {
            setForm(EMPTY_INPUT);
            setSaveError(null);
            setAdding(true);
          }}
        />
      </View>
      {!milestones || milestones.length === 0 ? (
        <EmptyState icon="flag-outline" title="No milestones yet" />
      ) : (
        milestones.map((m) => (
          <Card key={m.id} style={{ marginBottom: 10 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <View style={{ flex: 1, marginRight: 8 }}>
                <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{m.title}</Text>
                {m.due_date ? (
                  <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>Due {formatDate(m.due_date)}</Text>
                ) : null}
                {m.description ? (
                  <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 4 }}>{m.description}</Text>
                ) : null}
              </View>
              {m.status ? <StatusBadge value={m.status} kind="workflow" /> : null}
            </View>
            <View style={{ flexDirection: 'row', gap: 16, marginTop: 10 }}>
              {!m.completed_at ? (
                <Text onPress={() => handleComplete(m)} style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                  Mark complete
                </Text>
              ) : null}
              <Text onPress={() => confirmDelete(m)} style={{ color: colors.danger, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                Delete
              </Text>
            </View>
          </Card>
        ))
      )}
    </ScrollView>
  );
}
