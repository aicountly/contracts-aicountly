import { useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { ScreenContainer, Card, SelectField, StatusBadge, PrimaryButton, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listPlaybooks, listPlaybookRules, deletePlaybookRule } from '../../../../src/api/endpoints/playbooks';
import { ApiError } from '../../../../src/api/errors';
import { PlaybookRuleFormModal } from '../../../../src/components/playbooks/PlaybookRuleFormModal';
import type { PlaybookRule } from '../../../../src/types/contracts';
import { humaniseSnakeCase } from '../../../../src/utils/format';

export default function PlaybooksScreen() {
  const router = useRouter();
  const playbooks = useAsync(listPlaybooks);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [editing, setEditing] = useState<PlaybookRule | 'new' | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const list = playbooks.data ?? [];
  const activeId = selectedId ?? list[0]?.id ?? null;
  const activePlaybook = list.find((p) => p.id === activeId) ?? null;

  const rules = useAsync(() => (activeId === null ? Promise.resolve([]) : listPlaybookRules(activeId)), [activeId]);
  const ruleRows = rules.data ?? [];

  function handleDelete(rule: PlaybookRule) {
    Alert.alert('Delete this playbook rule?', `${rule.label} will stop being checked. Deviations already raised are not affected.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setDeletingId(rule.id);
          try {
            await deletePlaybookRule(rule.id);
            rules.reload();
          } catch (err) {
            Alert.alert('Could not delete this rule', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setDeletingId(null);
          }
        },
      },
    ]);
  }

  if (playbooks.loading && !playbooks.data) return <LoadingState label="Loading playbooks…" />;
  if (playbooks.error && !playbooks.data) return <ErrorState message={playbooks.error} onRetry={playbooks.reload} />;

  if (list.length === 0) {
    return (
      <ScreenContainer bottomInset={false} style={{ padding: 16 }}>
        <EmptyState
          icon="book-outline"
          title="No playbooks yet"
          message="A playbook is created against a contract type, from the clause library. Once one exists, its rules are maintained here."
          action={<PrimaryButton label="Open the clause library" variant="secondary" onPress={() => router.push('/more/clause-library')} />}
        />
      </ScreenContainer>
    );
  }

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12 }}>
        <SelectField
          label="Playbook"
          value={activeId !== null ? String(activeId) : null}
          options={list.map((p) => ({ value: String(p.id), label: p.is_default ? `${p.name} (default)` : p.name }))}
          onChange={(v) => setSelectedId(Number(v))}
        />
        {activePlaybook?.description ? (
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginTop: -8, marginBottom: 12, lineHeight: 18 }}>{activePlaybook.description}</Text>
        ) : null}
        <PrimaryButton label="Add rule" variant="secondary" onPress={() => setEditing('new')} />
      </View>

      {rules.loading && !rules.data ? (
        <LoadingState label="Loading rules…" />
      ) : rules.error && !rules.data ? (
        <ErrorState message={rules.error} onRetry={rules.reload} />
      ) : ruleRows.length === 0 ? (
        <EmptyState
          icon="checkmark-done-outline"
          title="This playbook has no rules"
          message="A playbook with no rules never raises a deviation. Add the positions that matter — a mandatory clause, a cap you will not exceed, a governing law you will not accept."
          action={<PrimaryButton label="Add the first rule" onPress={() => setEditing('new')} />}
        />
      ) : (
        <FlatList
          data={ruleRows}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 12 }}
          renderItem={({ item }) => (
            <Card style={{ marginBottom: 10, opacity: item.is_active ? 1 : 0.6 }}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                  {item.label}
                </Text>
                <StatusBadge value={item.severity} kind="risk" />
              </View>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 7 }}>
                <View style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 }}>
                  <Text style={{ fontSize: 11, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>{humaniseSnakeCase(item.rule_type)}</Text>
                </View>
                <View style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 }}>
                  <Text style={{ fontSize: 11, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>{humaniseSnakeCase(item.risk_category)}</Text>
                </View>
                {!item.is_active ? (
                  <View style={{ backgroundColor: colors.track, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 }}>
                    <Text style={{ fontSize: 11, color: colors.textMuted, fontFamily: 'Nunito_600SemiBold' }}>Inactive</Text>
                  </View>
                ) : null}
              </View>
              {item.description ? (
                <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginTop: 8 }}>{item.description}</Text>
              ) : null}
              {item.expected_value || item.expected_numeric !== null ? (
                <Text style={{ fontSize: 12, color: colors.textPrimary, marginTop: 6, fontFamily: 'Nunito_600SemiBold' }}>
                  Expects: {item.expected_value ?? item.expected_numeric}
                </Text>
              ) : null}
              {item.recommendation ? (
                <Text style={{ fontSize: 12, color: colors.textMuted, marginTop: 6, fontStyle: 'italic' }}>{item.recommendation}</Text>
              ) : null}
              <View style={{ flexDirection: 'row', gap: 16, marginTop: 10 }}>
                <Pressable onPress={() => setEditing(item)}>
                  <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>Edit</Text>
                </Pressable>
                <Pressable onPress={() => handleDelete(item)} disabled={deletingId === item.id}>
                  <Text style={{ color: colors.danger, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>{deletingId === item.id ? 'Deleting…' : 'Delete'}</Text>
                </Pressable>
              </View>
            </Card>
          )}
        />
      )}

      {activeId !== null ? (
        <PlaybookRuleFormModal
          visible={editing !== null}
          onClose={() => setEditing(null)}
          playbookId={activeId}
          row={editing === 'new' ? null : editing}
          onSaved={() => {
            setEditing(null);
            rules.reload();
          }}
        />
      ) : null}
    </ScreenContainer>
  );
}
