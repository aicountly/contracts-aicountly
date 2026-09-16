import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import { Card, PrimaryButton, FormField, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import {
  listSignatureRequests,
  createSignatureRequest,
  sendSignatureRequest,
  cancelSignatureRequest,
  markSigned,
  type SignatureRequestRow,
} from '../../../api/endpoints/signatures';
import { ApiError } from '../../../api/errors';

interface SignaturesTabProps {
  contractId: number;
}

export function SignaturesTab({ contractId }: SignaturesTabProps) {
  const { data: requests, loading, error, reload } = useAsync(() => listSignatureRequests(contractId), [contractId]);
  const [adding, setAdding] = useState(false);
  const [signerName, setSignerName] = useState('');
  const [signerEmail, setSignerEmail] = useState('');
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  async function handleCreate() {
    setSaving(true);
    setSaveError(null);
    try {
      await createSignatureRequest(contractId, [{ name: signerName, email: signerEmail }]);
      setAdding(false);
      setSignerName('');
      setSignerEmail('');
      reload();
    } catch (err) {
      setSaveError(err instanceof ApiError ? err.message : 'Could not create the signature request.');
    } finally {
      setSaving(false);
    }
  }

  async function handleSend(request: SignatureRequestRow) {
    setBusyId(request.id);
    try {
      await sendSignatureRequest(request.id);
      reload();
    } catch (err) {
      Alert.alert('Could not send', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  function confirmCancel(request: SignatureRequestRow) {
    Alert.alert('Cancel request', 'Cancel this signature request?', [
      { text: 'No', style: 'cancel' },
      {
        text: 'Cancel it',
        style: 'destructive',
        onPress: async () => {
          setBusyId(request.id);
          try {
            await cancelSignatureRequest(request.id);
            reload();
          } catch (err) {
            Alert.alert('Could not cancel', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setBusyId(null);
          }
        },
      },
    ]);
  }

  async function handleMarkSigned(request: SignatureRequestRow) {
    const result = await DocumentPicker.getDocumentAsync({ multiple: false, copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;
    const file = result.assets[0];
    setBusyId(request.id);
    try {
      await markSigned(request.id, { uri: file.uri, name: file.name, mimeType: file.mimeType ?? 'application/octet-stream' });
      reload();
    } catch (err) {
      Alert.alert('Could not mark signed', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusyId(null);
    }
  }

  if (loading && !requests) return <LoadingState label="Loading signatures…" />;
  if (error && !requests) return <ErrorState message={error} onRetry={reload} />;

  if (adding) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 16 }}>New signature request</Text>
        {saveError ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{saveError}</Text>
          </View>
        ) : null}
        <FormField label="Signer name" value={signerName} onChangeText={setSignerName} placeholder="Full name" />
        <FormField
          label="Signer email"
          value={signerEmail}
          onChangeText={setSignerEmail}
          placeholder="name@company.com"
          keyboardType="email-address"
          autoCapitalize="none"
        />
        <View style={{ flexDirection: 'row', gap: 10, marginTop: 8 }}>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Cancel" variant="secondary" onPress={() => setAdding(false)} />
          </View>
          <View style={{ flex: 1 }}>
            <PrimaryButton label="Create" onPress={handleCreate} loading={saving} disabled={!signerName.trim() || !signerEmail.trim()} />
          </View>
        </View>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={{ marginBottom: 16 }}>
        <PrimaryButton label="New signature request" onPress={() => setAdding(true)} />
      </View>
      {!requests || requests.length === 0 ? (
        <EmptyState
          icon="create-outline"
          title="No signature requests"
          message="Manual tracking: record who still needs to sign, then mark signed once you have the signed copy."
        />
      ) : (
        requests.map((request) => (
          <Card key={request.id} style={{ marginBottom: 10 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{request.signer_name ?? 'Signer'}</Text>
              <StatusBadge value={request.status} kind="workflow" />
            </View>
            {request.signer_email ? (
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>{request.signer_email}</Text>
            ) : null}
            <View style={{ flexDirection: 'row', gap: 16, marginTop: 10 }}>
              {request.status === 'not_started' || request.status === 'draft' ? (
                <Text onPress={() => handleSend(request)} style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                  {busyId === request.id ? 'Sending…' : 'Send'}
                </Text>
              ) : null}
              {request.status !== 'signed' && request.status !== 'declined' ? (
                <>
                  <Text onPress={() => handleMarkSigned(request)} style={{ color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                    Mark signed
                  </Text>
                  <Text onPress={() => confirmCancel(request)} style={{ color: colors.danger, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                    Cancel
                  </Text>
                </>
              ) : null}
            </View>
          </Card>
        ))
      )}
    </ScrollView>
  );
}
