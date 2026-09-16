import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Card, PrimaryButton, SelectField } from '../../index';
import { ContractFormFields } from '../ContractFormFields';
import { colors } from '../../../theme/colors';
import { formatMoney, formatDate, formatDateTime, humaniseSnakeCase } from '../../../utils/format';
import { updateContract, setContractArchived, setContractFavourite, deleteContract, changeContractStatus } from '../../../api/endpoints/contracts';
import { listContractTypes, listDepartments } from '../../../api/endpoints/settings';
import { useAsync } from '../../../utils/useAsync';
import { ApiError } from '../../../api/errors';
import { CONTRACT_STATUSES } from '../../../types/contracts';
import type { Contract, ContractInput } from '../../../types/contracts';

interface OverviewTabProps {
  contract: Contract;
  onReload: () => void;
}

function toInput(c: Contract): ContractInput {
  return {
    title: c.title,
    contract_type_id: c.contract_type_id,
    department_id: c.department_id,
    counterparty_name: c.counterparty_name ?? '',
    description: c.description,
    effective_date: c.effective_date,
    commencement_date: c.commencement_date,
    execution_date: c.execution_date,
    expiry_date: c.expiry_date,
    renewal_type: c.renewal_type,
    renewal_frequency: c.renewal_frequency,
    auto_renewal: c.auto_renewal,
    notice_period_days: c.notice_period_days,
    currency: c.currency,
    total_value: c.total_value,
    recurring_value: c.recurring_value,
    payment_frequency: c.payment_frequency,
    billing_frequency: c.billing_frequency,
    commercial_summary: c.commercial_summary,
    governing_law: c.governing_law,
    jurisdiction: c.jurisdiction,
    risk_level: c.risk_level,
    notes: c.notes,
    custom_fields: c.custom_fields ?? {},
  };
}

export function OverviewTab({ contract, onReload }: OverviewTabProps) {
  const router = useRouter();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<ContractInput>(() => toInput(contract));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saveError, setSaveError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [busy, setBusy] = useState(false);

  const contractTypes = useAsync(listContractTypes);
  const departments = useAsync(listDepartments);

  function startEdit() {
    setForm(toInput(contract));
    setErrors({});
    setSaveError(null);
    setEditing(true);
  }

  async function handleSave() {
    setSaving(true);
    setSaveError(null);
    setErrors({});
    try {
      await updateContract(contract.id, form);
      setEditing(false);
      onReload();
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors);
        setSaveError(err.message);
      } else {
        setSaveError(err instanceof ApiError ? err.message : 'Could not save changes.');
      }
    } finally {
      setSaving(false);
    }
  }

  async function handleStatusChange(status: string) {
    if (status === contract.status) return;
    setBusy(true);
    setSaveError(null);
    try {
      await changeContractStatus(contract.id, status);
      onReload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not change status.');
    } finally {
      setBusy(false);
    }
  }

  async function handleArchiveToggle() {
    setBusy(true);
    setSaveError(null);
    try {
      await setContractArchived(contract.id, !contract.archived_at);
      onReload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not update archive status.');
    } finally {
      setBusy(false);
    }
  }

  async function handleFavouriteToggle() {
    try {
      await setContractFavourite(contract.id, !contract.is_favourite);
      onReload();
    } catch {
      /* non-critical — leave the star as-is on failure */
    }
  }

  function confirmDelete() {
    Alert.alert('Delete contract', 'This cannot be undone. Delete this contract permanently?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setBusy(true);
          try {
            await deleteContract(contract.id);
            router.back();
          } catch (err) {
            setSaveError(err instanceof ApiError ? err.message : 'Could not delete this contract.');
            setBusy(false);
          }
        },
      },
    ]);
  }

  if (editing) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        {saveError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
          </View>
        ) : null}
        <ContractFormFields
          value={form}
          onChange={(patch) => setForm((f) => ({ ...f, ...patch }))}
          errors={errors}
          contractTypes={contractTypes.data ?? []}
          departments={departments.data ?? []}
        />
        <View style={{ flexDirection: 'row', gap: 10 }}>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Cancel" variant="secondary" onPress={() => setEditing(false)} />
          </View>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Save changes" onPress={handleSave} loading={saving} />
          </View>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      {saveError ? (
        <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
          <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
        </View>
      ) : null}

      <View style={{ flexDirection: 'row', gap: 10, marginBottom: 16 }}>
        <View style={{ flex: 1 }}>
          <PrimaryButton label="Edit" onPress={startEdit} variant="secondary" />
        </View>
        <View style={{ flex: 1 }}>
          <PrimaryButton label={contract.is_favourite ? 'Unstar' : 'Star'} onPress={handleFavouriteToggle} variant="secondary" />
        </View>
      </View>

      <Card style={{ marginBottom: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Status</Text>
        <SelectField
          label="Change status"
          value={contract.status}
          options={CONTRACT_STATUSES.map((s) => ({ value: s, label: humaniseSnakeCase(s) }))}
          onChange={handleStatusChange}
        />
      </Card>

      <Card style={{ marginBottom: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Details</Text>
        <DetailRow label="Counterparty" value={contract.counterparty_name} />
        <DetailRow label="Description" value={contract.description} />
        <DetailRow label="Contract type" value={contract.contract_type_name} />
        <DetailRow label="Department" value={contract.department_name} />
        <DetailRow label="Effective date" value={formatDate(contract.effective_date)} />
        <DetailRow label="Commencement date" value={formatDate(contract.commencement_date)} />
        <DetailRow label="Expiry date" value={formatDate(contract.expiry_date)} />
        <DetailRow label="Renewal type" value={contract.renewal_type ? humaniseSnakeCase(contract.renewal_type) : null} />
        <DetailRow label="Auto-renewal" value={contract.auto_renewal ? 'Yes' : 'No'} />
        <DetailRow label="Notice period" value={contract.notice_period_days ? `${contract.notice_period_days} days` : null} />
      </Card>

      <Card style={{ marginBottom: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Commercial</Text>
        <DetailRow label="Total value" value={formatMoney(contract.total_value, contract.currency)} />
        <DetailRow label="Recurring value" value={contract.recurring_value ? formatMoney(contract.recurring_value, contract.currency) : null} />
        <DetailRow label="Payment frequency" value={contract.payment_frequency ? humaniseSnakeCase(contract.payment_frequency) : null} />
        <DetailRow label="Summary" value={contract.commercial_summary} />
      </Card>

      <Card style={{ marginBottom: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Legal</Text>
        <DetailRow label="Governing law" value={contract.governing_law} />
        <DetailRow label="Jurisdiction" value={contract.jurisdiction} />
        <DetailRow label="Risk level" value={contract.risk_level ? humaniseSnakeCase(contract.risk_level) : null} />
      </Card>

      {contract.notes ? (
        <Card style={{ marginBottom: 16 }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 6 }}>Notes</Text>
          <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 13, color: colors.textSecondary }}>{contract.notes}</Text>
        </Card>
      ) : null}

      <Card style={{ marginBottom: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Record</Text>
        <DetailRow label="Created" value={formatDateTime(contract.created_at)} />
        <DetailRow label="Last updated" value={formatDateTime(contract.updated_at)} />
      </Card>

      <View style={{ gap: 10 }}>
        <PrimaryButton label={contract.archived_at ? 'Unarchive' : 'Archive'} onPress={handleArchiveToggle} variant="secondary" loading={busy} />
        <PrimaryButton label="Delete contract" onPress={confirmDelete} variant="danger" loading={busy} />
      </View>
    </ScrollView>
  );
}

function DetailRow({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value) return null;
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6 }}>
      <Text style={{ fontFamily: 'Nunito_500Medium', fontSize: 12, color: colors.textMuted, flex: 1 }}>{label}</Text>
      <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary, flex: 2, textAlign: 'right' }}>{value}</Text>
    </View>
  );
}
