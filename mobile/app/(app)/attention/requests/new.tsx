import { useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { PrimaryButton, FormField, SelectField, DateField } from '../../../../src/components';
import { createRequest } from '../../../../src/api/endpoints/requests';
import { listContractTypes, listDepartments } from '../../../../src/api/endpoints/settings';
import { useAsync } from '../../../../src/utils/useAsync';
import { ApiError } from '../../../../src/api/errors';
import type { ContractRequestInput } from '../../../../src/types/contracts';
import { colors } from '../../../../src/theme/colors';

const EMPTY: ContractRequestInput = {
  title: '',
  contract_type_id: null,
  department_id: null,
  required_by_date: null,
  counterparty_name: null,
  contact_ref_id: null,
  purpose: null,
  business_justification: null,
  estimated_value: null,
  currency: 'INR',
  preferred_template_id: null,
  notes: null,
};

export default function NewRequestScreen() {
  const router = useRouter();
  const contractTypes = useAsync(listContractTypes);
  const departments = useAsync(listDepartments);
  const [form, setForm] = useState<ContractRequestInput>(EMPTY);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function patch(update: Partial<ContractRequestInput>) {
    setForm((f) => ({ ...f, ...update }));
  }

  async function handleSave() {
    setSaving(true);
    setError(null);
    try {
      const created = await createRequest(form);
      router.replace(`/attention/requests/${created.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not create this request.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      {error ? (
        <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
          <Text style={{ color: colors.danger, fontSize: 13 }}>{error}</Text>
        </View>
      ) : null}
      <FormField label="Title" value={form.title} onChangeText={(t) => patch({ title: t })} placeholder="What is this agreement for?" />
      <SelectField
        label="Contract type"
        value={form.contract_type_id ? String(form.contract_type_id) : null}
        options={(contractTypes.data ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
        onChange={(v) => patch({ contract_type_id: v ? Number(v) : null })}
      />
      <SelectField
        label="Department"
        value={form.department_id ? String(form.department_id) : null}
        options={(departments.data ?? []).map((d) => ({ value: String(d.id), label: d.name }))}
        onChange={(v) => patch({ department_id: v ? Number(v) : null })}
      />
      <FormField label="Counterparty" value={form.counterparty_name ?? ''} onChangeText={(t) => patch({ counterparty_name: t || null })} />
      <FormField
        label="Purpose"
        value={form.purpose ?? ''}
        onChangeText={(t) => patch({ purpose: t || null })}
        multiline
        numberOfLines={3}
        style={{ minHeight: 80, textAlignVertical: 'top' }}
      />
      <FormField
        label="Business justification"
        value={form.business_justification ?? ''}
        onChangeText={(t) => patch({ business_justification: t || null })}
        multiline
        numberOfLines={3}
        style={{ minHeight: 80, textAlignVertical: 'top' }}
      />
      <FormField
        label="Estimated value"
        keyboardType="numeric"
        value={form.estimated_value ?? ''}
        onChangeText={(t) => patch({ estimated_value: t || null })}
      />
      <DateField label="Required by" value={form.required_by_date} onChange={(v) => patch({ required_by_date: v })} />
      <FormField
        label="Notes"
        value={form.notes ?? ''}
        onChangeText={(t) => patch({ notes: t || null })}
        multiline
        numberOfLines={3}
        style={{ minHeight: 80, textAlignVertical: 'top' }}
      />

      <PrimaryButton label="Create request" onPress={handleSave} loading={saving} disabled={!form.title.trim()} />
    </ScrollView>
  );
}
