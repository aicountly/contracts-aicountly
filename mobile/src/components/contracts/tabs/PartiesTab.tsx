import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { Card, PrimaryButton, FormField, SelectField, SwitchField, LoadingState, ErrorState, EmptyState } from '../../index';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import { listParties, addParty, updateParty, deleteParty, type PartyInput } from '../../../api/endpoints/parties';
import { ApiError } from '../../../api/errors';
import type { ContractParty } from '../../../types/contracts';

const PARTY_ROLES = [
  { value: 'company', label: 'Company (Us)' },
  { value: 'counterparty', label: 'Counterparty' },
  { value: 'guarantor', label: 'Guarantor' },
  { value: 'witness', label: 'Witness' },
  { value: 'other', label: 'Other' },
];

const EMPTY_PARTY: PartyInput = {
  party_role: 'counterparty',
  name: '',
  legal_name: null,
  email: null,
  phone: null,
  address: null,
  registration_number: null,
  signatory_name: null,
  signatory_email: null,
  signatory_designation: null,
  is_primary: false,
};

interface PartiesTabProps {
  contractId: number;
}

export function PartiesTab({ contractId }: PartiesTabProps) {
  const { data: parties, loading, error, reload } = useAsync(() => listParties(contractId), [contractId]);
  const [editing, setEditing] = useState<ContractParty | 'new' | null>(null);
  const [form, setForm] = useState<PartyInput>(EMPTY_PARTY);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  function startAdd() {
    setForm(EMPTY_PARTY);
    setSaveError(null);
    setEditing('new');
  }

  function startEdit(party: ContractParty) {
    setForm({
      party_role: party.party_role,
      name: party.name,
      legal_name: party.legal_name,
      contact_uuid: party.contact_uuid,
      email: party.email,
      phone: party.phone,
      address: party.address,
      registration_number: party.registration_number,
      signatory_name: party.signatory_name,
      signatory_email: party.signatory_email,
      signatory_designation: party.signatory_designation,
      is_primary: party.is_primary,
    });
    setSaveError(null);
    setEditing(party);
  }

  async function handleSave() {
    setSaving(true);
    setSaveError(null);
    try {
      if (editing === 'new') {
        await addParty(contractId, form);
      } else if (editing) {
        await updateParty(editing.id, form);
      }
      setEditing(null);
      reload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not save this party.');
    } finally {
      setSaving(false);
    }
  }

  function confirmDelete(party: ContractParty) {
    Alert.alert('Remove party', `Remove ${party.name} from this contract?`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Remove',
        style: 'destructive',
        onPress: async () => {
          try {
            await deleteParty(party.id);
            reload();
          } catch (err) {
            Alert.alert('Could not remove', err instanceof ApiError ? err.message : 'Something went wrong.');
          }
        },
      },
    ]);
  }

  if (loading && !parties) return <LoadingState label="Loading parties…" />;
  if (error && !parties) return <ErrorState message={error} onRetry={reload} />;

  if (editing) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 16 }}>
          {editing === 'new' ? 'Add party' : 'Edit party'}
        </Text>
        {saveError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
          </View>
        ) : null}
        <SelectField label="Role" value={form.party_role} options={PARTY_ROLES} onChange={(v) => setForm((f) => ({ ...f, party_role: v }))} />
        <FormField label="Name" value={form.name} onChangeText={(t) => setForm((f) => ({ ...f, name: t }))} placeholder="Full or company name" />
        <FormField label="Legal name" value={form.legal_name ?? ''} onChangeText={(t) => setForm((f) => ({ ...f, legal_name: t || null }))} />
        <FormField
          label="Email"
          value={form.email ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, email: t || null }))}
          keyboardType="email-address"
          autoCapitalize="none"
        />
        <FormField label="Phone" value={form.phone ?? ''} onChangeText={(t) => setForm((f) => ({ ...f, phone: t || null }))} keyboardType="phone-pad" />
        <FormField
          label="Address"
          value={form.address ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, address: t || null }))}
          multiline
          numberOfLines={2}
          style={{ minHeight: 60, textAlignVertical: 'top' }}
        />
        <FormField
          label="Registration number"
          value={form.registration_number ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, registration_number: t || null }))}
        />
        <FormField label="Signatory name" value={form.signatory_name ?? ''} onChangeText={(t) => setForm((f) => ({ ...f, signatory_name: t || null }))} />
        <FormField
          label="Signatory email"
          value={form.signatory_email ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, signatory_email: t || null }))}
          keyboardType="email-address"
          autoCapitalize="none"
        />
        <FormField
          label="Signatory designation"
          value={form.signatory_designation ?? ''}
          onChangeText={(t) => setForm((f) => ({ ...f, signatory_designation: t || null }))}
        />
        <SwitchField label="Primary party" value={!!form.is_primary} onChange={(v) => setForm((f) => ({ ...f, is_primary: v }))} />

        <View style={{ flexDirection: 'row', gap: 10, marginTop: 8 }}>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Cancel" variant="secondary" onPress={() => setEditing(null)} />
          </View>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Save" onPress={handleSave} loading={saving} disabled={!form.name.trim()} />
          </View>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={{ marginBottom: 16 }}>
        <PrimaryButton label="Add party" onPress={startAdd} />
      </View>
      {!parties || parties.length === 0 ? (
        <EmptyState icon="people-outline" title="No parties yet" message="Add the counterparty and any other signatories." />
      ) : (
        parties.map((party) => (
          <Card key={party.id} style={{ marginBottom: 10 }}>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>
              {party.name}
              {party.is_primary ? ' · Primary' : ''}
            </Text>
            <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
              {party.party_role ?? 'Party'}
              {party.email ? ` · ${party.email}` : ''}
            </Text>
            {party.signatory_name ? (
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
                Signatory: {party.signatory_name}
                {party.signatory_designation ? ` (${party.signatory_designation})` : ''}
              </Text>
            ) : null}
            <View style={{ flexDirection: 'row', gap: 16, marginTop: 10 }}>
              <Text onPress={() => startEdit(party)} style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                Edit
              </Text>
              <Text onPress={() => confirmDelete(party)} style={{ color: colors.danger, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                Remove
              </Text>
            </View>
          </Card>
        ))
      )}
    </ScrollView>
  );
}
