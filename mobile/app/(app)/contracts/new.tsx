import { useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';

import { ScreenContainer, PrimaryButton } from '../../../src/components';
import { ContractFormFields } from '../../../src/components/contracts/ContractFormFields';
import { createContract } from '../../../src/api/endpoints/contracts';
import { listContractTypes, listDepartments } from '../../../src/api/endpoints/settings';
import { useAsync } from '../../../src/utils/useAsync';
import { ApiError } from '../../../src/api/errors';
import type { ContractInput } from '../../../src/types/contracts';
import { colors } from '../../../src/theme/colors';

const EMPTY_INPUT: ContractInput = {
  title: '',
  contract_type_id: null,
  department_id: null,
  counterparty_name: '',
  description: null,
  effective_date: null,
  commencement_date: null,
  execution_date: null,
  expiry_date: null,
  renewal_type: null,
  renewal_frequency: null,
  auto_renewal: false,
  notice_period_days: null,
  currency: 'INR',
  total_value: null,
  recurring_value: null,
  payment_frequency: null,
  billing_frequency: null,
  commercial_summary: null,
  governing_law: null,
  jurisdiction: null,
  risk_level: null,
  notes: null,
  custom_fields: {},
};

export default function NewContractScreen() {
  const router = useRouter();
  const contractTypes = useAsync(listContractTypes);
  const departments = useAsync(listDepartments);

  const [form, setForm] = useState<ContractInput>(EMPTY_INPUT);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function patch(update: Partial<ContractInput>) {
    setForm((f) => ({ ...f, ...update }));
  }

  async function handleSave() {
    setSubmitError(null);
    setErrors({});
    if (!form.title.trim()) {
      setErrors({ title: 'Title is required.' });
      return;
    }
    setSaving(true);
    try {
      const created = await createContract(form);
      router.replace(`/contracts/${created.id}`);
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors);
        setSubmitError(err.message);
      } else {
        setSubmitError(err instanceof ApiError ? err.message : 'Could not create the contract.');
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 20, color: colors.textPrimary, marginBottom: 4 }}>New contract</Text>
        <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 13, color: colors.textMuted, marginBottom: 20 }}>
          Starts as a draft — you can fill in the rest from the contract&apos;s Overview tab.
        </Text>

        {submitError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{submitError}</Text>
          </View>
        ) : null}

        <ContractFormFields value={form} onChange={patch} errors={errors} contractTypes={contractTypes.data ?? []} departments={departments.data ?? []} />

        <PrimaryButton label="Create contract" onPress={handleSave} loading={saving} disabled={!form.title.trim()} />
      </ScrollView>
    </ScreenContainer>
  );
}
