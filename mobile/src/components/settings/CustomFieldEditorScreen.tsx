import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { ScreenContainer } from '../ScreenContainer';
import { FormField } from '../FormField';
import { SelectField } from '../SelectField';
import { SwitchField } from '../SwitchField';
import { PrimaryButton } from '../PrimaryButton';
import { colors } from '../../theme/colors';
import { ApiError } from '../../api/errors';
import { createCustomField, updateCustomField, deleteCustomField, listContractTypes } from '../../api/endpoints/settings';
import { useAsync } from '../../utils/useAsync';
import { CUSTOM_FIELD_TYPES, type CustomFieldRow } from '../../types/contracts';
import { humaniseSnakeCase } from '../../utils/format';

const OPTIONS_TYPES = new Set(['select', 'multi_select']);

/** Shared by app/(app)/more/settings/custom-fields/new.tsx and [id].tsx. */
export function CustomFieldEditorScreen({ row }: { row: CustomFieldRow | null }) {
  const router = useRouter();
  const contractTypes = useAsync(listContractTypes);

  const [fieldKey, setFieldKey] = useState(row?.field_key ?? '');
  const [label, setLabel] = useState(row?.label ?? '');
  const [fieldType, setFieldType] = useState(row?.field_type ?? 'text');
  const [contractTypeId, setContractTypeId] = useState(row?.contract_type_id != null ? String(row.contract_type_id) : '');
  const [options, setOptions] = useState((row?.options ?? []).join(', '));
  const [helpText, setHelpText] = useState(row?.help_text ?? '');
  const [sortOrder, setSortOrder] = useState(String(row?.sort_order ?? 100));
  const [isRequired, setIsRequired] = useState(row?.is_required ?? false);
  const [isFilterable, setIsFilterable] = useState(row?.is_filterable ?? false);
  const [isActive, setIsActive] = useState(row?.is_active ?? true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const needsOptions = OPTIONS_TYPES.has(fieldType);

  async function save() {
    if (label.trim() === '' || fieldKey.trim() === '') {
      setError('Label and field key are both required.');
      return;
    }
    setSaving(true);
    setError(null);
    const input: Partial<CustomFieldRow> = {
      field_key: fieldKey.trim(),
      label: label.trim(),
      field_type: fieldType,
      contract_type_id: contractTypeId === '' ? null : Number(contractTypeId),
      options: needsOptions
        ? options
            .split(',')
            .map((o) => o.trim())
            .filter((o) => o !== '')
        : null,
      help_text: helpText.trim() || null,
      sort_order: Number(sortOrder) || 0,
      is_required: isRequired,
      is_filterable: isFilterable,
      is_active: isActive,
    };

    try {
      const saved = row ? await updateCustomField(row.id, input) : await createCustomField(input);
      router.replace(`/more/settings/custom-fields/${saved.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not save this custom field.');
    } finally {
      setSaving(false);
    }
  }

  function remove() {
    if (!row) return;
    Alert.alert('Delete this custom field?', `${row.label} and its values on every contract will be removed.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setDeleting(true);
          try {
            await deleteCustomField(row.id);
            router.back();
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
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        {error ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{error}</Text>
          </View>
        ) : null}
        <FormField label="Label" value={label} onChangeText={setLabel} placeholder="Renewal owner" />
        <FormField label="Field key" value={fieldKey} onChangeText={setFieldKey} placeholder="renewal_owner" editable={row === null} />
        <SelectField label="Field type" value={fieldType} options={CUSTOM_FIELD_TYPES.map((t) => ({ value: t, label: humaniseSnakeCase(t) }))} onChange={setFieldType} />
        <SelectField
          label="Scope"
          value={contractTypeId || null}
          placeholder="Every contract type"
          options={(contractTypes.data ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
          onChange={setContractTypeId}
        />
        {needsOptions ? (
          <FormField label="Options" value={options} onChangeText={setOptions} placeholder="Comma-separated: North, South, East" hint="One value per comma." />
        ) : null}
        <FormField label="Help text" value={helpText} onChangeText={setHelpText} />
        <FormField label="Sort order" value={sortOrder} onChangeText={setSortOrder} keyboardType="numeric" />
        <SwitchField label="Required" value={isRequired} onChange={setIsRequired} />
        <SwitchField label="Filterable" value={isFilterable} onChange={setIsFilterable} hint="Offered as a filter in the contract repository." />
        <SwitchField label="Active" value={isActive} onChange={setIsActive} />

        <View style={{ gap: 10, marginTop: 6 }}>
          <PrimaryButton label={row ? 'Save' : 'Create field'} onPress={() => void save()} loading={saving} />
          {row ? <PrimaryButton label="Delete" variant="danger" onPress={remove} loading={deleting} /> : null}
        </View>
      </ScrollView>
    </ScreenContainer>
  );
}
