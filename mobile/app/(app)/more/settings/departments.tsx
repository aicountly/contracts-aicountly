import { useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, BottomSheet, FormField, SwitchField, PrimaryButton, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listDepartmentRows, createDepartment, updateDepartment, deleteDepartment } from '../../../../src/api/endpoints/settings';
import { ApiError } from '../../../../src/api/errors';
import type { DepartmentRow } from '../../../../src/types/contracts';

export default function DepartmentsScreen() {
  const { data, loading, error, reload } = useAsync(listDepartmentRows);
  const [editing, setEditing] = useState<DepartmentRow | 'new' | null>(null);
  const rows = data ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12, paddingBottom: 8, alignItems: 'flex-end' }}>
        <Pressable
          onPress={() => setEditing('new')}
          style={{ flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: colors.primary, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 10 }}
        >
          <Ionicons name="add" size={16} color="#FFFFFF" />
          <Text style={{ color: '#FFFFFF', fontFamily: 'Nunito_700Bold', fontSize: 13 }}>New department</Text>
        </Pressable>
      </View>

      {loading && !data ? (
        <LoadingState label="Loading departments…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState icon="business-outline" title="No departments yet" message="Departments organise contracts and requests by who owns them." />
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => setEditing(item)}>
              <Card style={{ marginBottom: 10, opacity: item.is_active ? 1 : 0.6 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{item.name}</Text>
                  <Text style={{ fontSize: 11.5, color: colors.textMuted }}>{item.code}</Text>
                </View>
                {!item.is_active ? <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 4 }}>Inactive</Text> : null}
              </Card>
            </Pressable>
          )}
        />
      )}

      <DepartmentFormSheet
        visible={editing !== null}
        row={editing === 'new' ? null : editing}
        onClose={() => setEditing(null)}
        onSaved={() => {
          setEditing(null);
          reload();
        }}
      />
    </ScreenContainer>
  );
}

function DepartmentFormSheet({
  visible,
  row,
  onClose,
  onSaved,
}: {
  visible: boolean;
  row: DepartmentRow | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [headUuid, setHeadUuid] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [wasVisible, setWasVisible] = useState(false);
  if (visible && !wasVisible) {
    setWasVisible(true);
    setName(row?.name ?? '');
    setCode(row?.code ?? '');
    setHeadUuid(row?.head_uuid ?? '');
    setIsActive(row?.is_active ?? true);
    setError(null);
  } else if (!visible && wasVisible) {
    setWasVisible(false);
  }

  async function submit() {
    if (name.trim() === '' || code.trim() === '') {
      setError('Name and code are both required.');
      return;
    }
    setSaving(true);
    setError(null);
    const input = { name: name.trim(), code: code.trim(), head_uuid: headUuid.trim() || null, is_active: isActive };
    try {
      if (row) await updateDepartment(row.id, input);
      else await createDepartment(input);
      onSaved();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not save this department.');
    } finally {
      setSaving(false);
    }
  }

  function remove() {
    if (!row) return;
    Alert.alert('Delete this department?', `${row.name} will no longer be offered when drafting or filtering.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setDeleting(true);
          try {
            await deleteDepartment(row.id);
            onSaved();
          } catch (err) {
            Alert.alert('Could not delete', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setDeleting(false);
          }
        },
      },
    ]);
  }

  return (
    <BottomSheet visible={visible} onClose={onClose}>
      <View style={{ padding: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary, marginBottom: 14 }}>{row ? 'Edit department' : 'New department'}</Text>
        {error ? <Text style={{ color: colors.danger, fontSize: 12.5, marginBottom: 10 }}>{error}</Text> : null}
        <FormField label="Name" value={name} onChangeText={setName} placeholder="Legal" />
        <FormField label="Code" value={code} onChangeText={setCode} placeholder="LEGAL" autoCapitalize="characters" />
        <FormField label="Head (user id)" value={headUuid} onChangeText={setHeadUuid} placeholder="Optional" />
        <SwitchField label="Active" value={isActive} onChange={setIsActive} />
        <View style={{ gap: 10, marginTop: 6 }}>
          <PrimaryButton label={row ? 'Save' : 'Create department'} onPress={() => void submit()} loading={saving} />
          {row ? <PrimaryButton label="Delete" variant="danger" onPress={remove} loading={deleting} /> : null}
        </View>
      </View>
    </BottomSheet>
  );
}
