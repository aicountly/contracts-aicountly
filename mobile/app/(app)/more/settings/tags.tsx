import { useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, FormField, SelectField, PrimaryButton, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listTagRows, createTag, deleteTag } from '../../../../src/api/endpoints/settings';
import { ApiError } from '../../../../src/api/errors';
import { TAG_COLOURS } from '../../../../src/types/contracts';
import { humaniseSnakeCase } from '../../../../src/utils/format';

type TagColour = (typeof TAG_COLOURS)[number];

const SWATCH: Record<string, string> = {
  slate: '#64748B',
  green: '#25B003',
  blue: '#2563EB',
  amber: '#D97706',
  red: '#DC2626',
  violet: '#7A42F4',
  teal: '#0D9488',
  pink: '#DB2777',
};

export default function TagsScreen() {
  const { data, loading, error, reload } = useAsync(listTagRows);
  const [name, setName] = useState('');
  const [colour, setColour] = useState<TagColour>('slate');
  const [adding, setAdding] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const rows = data ?? [];

  async function handleAdd() {
    if (name.trim() === '') {
      setFormError('Give the tag a name.');
      return;
    }
    setAdding(true);
    setFormError(null);
    try {
      await createTag({ name: name.trim(), colour });
      setName('');
      reload();
    } catch (err) {
      setFormError(err instanceof ApiError ? err.message : 'Could not add this tag.');
    } finally {
      setAdding(false);
    }
  }

  function handleDelete(id: number, tagName: string) {
    Alert.alert('Delete this tag?', `${tagName} will be removed from every contract it's on.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setDeletingId(id);
          try {
            await deleteTag(id);
            reload();
          } catch (err) {
            Alert.alert('Could not delete', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setDeletingId(null);
          }
        },
      },
    ]);
  }

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ padding: 16, paddingBottom: 8 }}>
        <Card>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary, marginBottom: 10 }}>Add a tag</Text>
          {formError ? <Text style={{ color: colors.danger, fontSize: 12.5, marginBottom: 8 }}>{formError}</Text> : null}
          <FormField label="Name" value={name} onChangeText={setName} placeholder="High priority" />
          <SelectField label="Colour" value={colour} options={TAG_COLOURS.map((c) => ({ value: c, label: humaniseSnakeCase(c) }))} onChange={(v) => setColour(v as TagColour)} />
          <PrimaryButton label="Add tag" onPress={() => void handleAdd()} loading={adding} />
        </Card>
      </View>

      {loading && !data ? (
        <LoadingState label="Loading tags…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState icon="pricetags-outline" title="No tags yet" message="Tags help filter and group contracts freely, without a fixed taxonomy." />
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 0 }}
          renderItem={({ item }) => (
            <Card style={{ marginBottom: 8, flexDirection: 'row', alignItems: 'center', gap: 10 }}>
              <View style={{ width: 12, height: 12, borderRadius: 6, backgroundColor: SWATCH[item.colour ?? 'slate'] ?? colors.textMuted }} />
              <Text style={{ flex: 1, fontFamily: 'Nunito_600SemiBold', fontSize: 14, color: colors.textPrimary }}>{item.name}</Text>
              {item.usage_count !== undefined ? (
                <Text style={{ fontSize: 11.5, color: colors.textMuted }}>{item.usage_count} contracts</Text>
              ) : null}
              <Pressable onPress={() => handleDelete(item.id, item.name)} disabled={deletingId === item.id} hitSlop={8}>
                <Ionicons name="trash-outline" size={17} color={colors.danger} />
              </Pressable>
            </Card>
          )}
        />
      )}
    </ScreenContainer>
  );
}
