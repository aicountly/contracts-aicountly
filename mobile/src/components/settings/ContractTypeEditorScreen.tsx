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
import { createContractType, updateContractType, deleteContractType } from '../../api/endpoints/settings';
import { COUNTERPARTY_SIDES, RENEWAL_TYPES, type ContractTypeRow } from '../../types/contracts';
import { humaniseSnakeCase } from '../../utils/format';

/** Shared by app/(app)/more/settings/contract-types/new.tsx and [id].tsx. */
export function ContractTypeEditorScreen({ row }: { row: ContractTypeRow | null }) {
  const router = useRouter();

  const [code, setCode] = useState(row?.code ?? '');
  const [name, setName] = useState(row?.name ?? '');
  const [description, setDescription] = useState(row?.description ?? '');
  const [category, setCategory] = useState(row?.category ?? '');
  const [counterpartySide, setCounterpartySide] = useState(row?.counterparty_side ?? 'either');
  const [renewalType, setRenewalType] = useState(row?.default_renewal_type ?? 'none');
  const [noticeDays, setNoticeDays] = useState(row?.default_notice_days != null ? String(row.default_notice_days) : '');
  const [termMonths, setTermMonths] = useState(row?.default_term_months != null ? String(row.default_term_months) : '');
  const [sortOrder, setSortOrder] = useState(String(row?.sort_order ?? 100));
  const [isActive, setIsActive] = useState(row?.is_active ?? true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  async function save() {
    if (name.trim() === '' || code.trim() === '') {
      setError('Name and code are both required.');
      return;
    }
    setSaving(true);
    setError(null);
    const input: Partial<ContractTypeRow> = {
      code: code.trim(),
      name: name.trim(),
      description: description.trim() || null,
      category: category.trim(),
      counterparty_side: counterpartySide,
      default_renewal_type: renewalType,
      default_notice_days: noticeDays.trim() === '' ? null : Number(noticeDays),
      default_term_months: termMonths.trim() === '' ? null : Number(termMonths),
      sort_order: Number(sortOrder) || 0,
      is_active: isActive,
      // Not exposed on this form — resent unchanged so a save never silently
      // clears configuration set elsewhere (matches the web app's own PUT).
      required_fields: row?.required_fields ?? [],
      mandatory_clauses: row?.mandatory_clauses ?? [],
      default_template_id: row?.default_template_id ?? null,
      approval_workflow_id: row?.approval_workflow_id ?? null,
    };

    try {
      const saved = row ? await updateContractType(row.id, input) : await createContractType(input);
      router.replace(`/more/settings/contract-types/${saved.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not save this contract type.');
    } finally {
      setSaving(false);
    }
  }

  function remove() {
    if (!row) return;
    Alert.alert('Delete this contract type?', `${row.name} will no longer be offered when creating a contract.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setDeleting(true);
          try {
            await deleteContractType(row.id);
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
        <FormField label="Name" value={name} onChangeText={setName} placeholder="Master Services Agreement" />
        <FormField label="Code" value={code} onChangeText={setCode} placeholder="MSA" autoCapitalize="characters" editable={row === null || !row.is_system} />
        <FormField label="Description" value={description} onChangeText={setDescription} multiline numberOfLines={2} style={{ minHeight: 60, textAlignVertical: 'top' }} />
        <FormField label="Category" value={category} onChangeText={setCategory} placeholder="Commercial, HR, Vendor…" />
        <SelectField
          label="Counterparty side"
          value={counterpartySide}
          options={COUNTERPARTY_SIDES.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
          onChange={setCounterpartySide}
        />
        <SelectField label="Default renewal type" value={renewalType} options={RENEWAL_TYPES.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))} onChange={setRenewalType} />
        <FormField label="Default notice period (days)" value={noticeDays} onChangeText={setNoticeDays} keyboardType="numeric" />
        <FormField label="Default term (months)" value={termMonths} onChangeText={setTermMonths} keyboardType="numeric" />
        <FormField label="Sort order" value={sortOrder} onChangeText={setSortOrder} keyboardType="numeric" />
        <SwitchField label="Active" value={isActive} onChange={setIsActive} hint="Only an active type is offered when drafting." />

        <View style={{ gap: 10, marginTop: 6 }}>
          <PrimaryButton label={row ? 'Save' : 'Create contract type'} onPress={() => void save()} loading={saving} />
          {row && !row.is_system ? <PrimaryButton label="Delete" variant="danger" onPress={remove} loading={deleting} /> : null}
        </View>
      </ScrollView>
    </ScreenContainer>
  );
}
