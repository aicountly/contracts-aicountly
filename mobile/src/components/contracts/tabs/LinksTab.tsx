import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Card, PrimaryButton, FormField, SelectField, LoadingState, ErrorState, EmptyState } from '../../index';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import { listLinks, addLink, deleteLink } from '../../../api/endpoints/links';
import { ApiError } from '../../../api/errors';
import type { ContractLink } from '../../../types/contracts';

interface LinksTabProps {
  contractId: number;
}

const LINK_TYPES = [
  { value: 'related', label: 'Related' },
  { value: 'supersedes', label: 'Supersedes' },
  { value: 'superseded_by', label: 'Superseded by' },
  { value: 'reference', label: 'Reference' },
];

export function LinksTab({ contractId }: LinksTabProps) {
  const router = useRouter();
  const { data: links, loading, error, reload } = useAsync(() => listLinks(contractId), [contractId]);
  const [adding, setAdding] = useState(false);
  const [linkType, setLinkType] = useState('related');
  const [relatedNumber, setRelatedNumber] = useState('');
  const [note, setNote] = useState('');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  async function handleAdd() {
    setSaving(true);
    setSaveError(null);
    try {
      await addLink(contractId, { link_type: linkType, label: relatedNumber, note: note || null });
      setAdding(false);
      setRelatedNumber('');
      setNote('');
      reload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not add this link.');
    } finally {
      setSaving(false);
    }
  }

  function confirmDelete(link: ContractLink) {
    Alert.alert('Remove link', 'Remove this linked record?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Remove',
        style: 'destructive',
        onPress: async () => {
          try {
            await deleteLink(link.id);
            reload();
          } catch (err) {
            Alert.alert('Could not remove', err instanceof ApiError ? err.message : 'Something went wrong.');
          }
        },
      },
    ]);
  }

  if (loading && !links) return <LoadingState label="Loading linked records…" />;
  if (error && !links) return <ErrorState message={error} onRetry={reload} />;

  if (adding) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 16 }}>Link a record</Text>
        {saveError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
          </View>
        ) : null}
        <SelectField label="Relationship" value={linkType} options={LINK_TYPES} onChange={setLinkType} />
        <FormField label="Label / reference" value={relatedNumber} onChangeText={setRelatedNumber} placeholder="e.g. PO-2024-0142, or a contract number" />
        <FormField label="Note" value={note} onChangeText={setNote} placeholder="Optional context" />
        <View style={{ flexDirection: 'row', gap: 10, marginTop: 8 }}>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Cancel" variant="secondary" onPress={() => setAdding(false)} />
          </View>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Add link" onPress={handleAdd} loading={saving} disabled={!relatedNumber.trim()} />
          </View>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={{ marginBottom: 16 }}>
        <PrimaryButton label="Link a record" onPress={() => setAdding(true)} />
      </View>
      {!links || links.length === 0 ? (
        <EmptyState icon="link-outline" title="No linked records" />
      ) : (
        links.map((link) => (
          <Card key={link.id} style={{ marginBottom: 10 }}>
            <Text
              onPress={link.related_contract_id ? () => router.push(`/contracts/${link.related_contract_id}`) : undefined}
              style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: link.related_contract_id ? colors.primary : colors.textPrimary }}
            >
              {link.related_contract_number ?? link.label ?? 'Linked record'}
            </Text>
            <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
              {link.link_type ?? 'related'}
              {link.related_contract_title ? ` · ${link.related_contract_title}` : ''}
            </Text>
            {link.note ? <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 4 }}>{link.note}</Text> : null}
            <Text onPress={() => confirmDelete(link)} style={{ color: colors.danger, fontFamily: 'Nunito_600SemiBold', fontSize: 12, marginTop: 10 }}>
              Remove
            </Text>
          </Card>
        ))
      )}
    </ScrollView>
  );
}
