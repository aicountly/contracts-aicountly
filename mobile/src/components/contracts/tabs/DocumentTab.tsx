import { useState } from 'react';
import { Alert, Linking, ScrollView, Text, View } from 'react-native';
import * as DocumentPicker from 'expo-document-picker';
import { Card, PrimaryButton, LoadingState, ErrorState, EmptyState } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDateTime } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import { listDocuments, getVersionUrl, markVersionExecuted, deleteVersion, uploadDocumentDirect } from '../../../api/endpoints/documents';
import { ApiError } from '../../../api/errors';
import type { ContractDocument, DocumentVersion } from '../../../types/contracts';

interface DocumentTabProps {
  contractId: number;
}

export function DocumentTab({ contractId }: DocumentTabProps) {
  const { data: documents, loading, error, reload } = useAsync(() => listDocuments(contractId), [contractId]);
  const [busy, setBusy] = useState<string | null>(null);

  async function handleOpen(version: DocumentVersion) {
    setBusy(`open-${version.id}`);
    try {
      const { url } = await getVersionUrl(version.id);
      await Linking.openURL(url);
    } catch (err) {
      Alert.alert('Could not open file', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusy(null);
    }
  }

  async function handleMarkExecuted(version: DocumentVersion) {
    setBusy(`exec-${version.id}`);
    try {
      await markVersionExecuted(version.id);
      reload();
    } catch (err) {
      Alert.alert('Could not mark executed', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusy(null);
    }
  }

  function confirmDeleteVersion(version: DocumentVersion) {
    Alert.alert('Delete version', `Delete version ${version.version_number}? This cannot be undone.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setBusy(`delete-${version.id}`);
          try {
            await deleteVersion(version.id);
            reload();
          } catch (err) {
            Alert.alert('Could not delete', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setBusy(null);
          }
        },
      },
    ]);
  }

  async function handleUpload() {
    const result = await DocumentPicker.getDocumentAsync({ multiple: false, copyToCacheDirectory: true });
    if (result.canceled || !result.assets?.[0]) return;
    const file = result.assets[0];
    setBusy('upload');
    try {
      await uploadDocumentDirect(contractId, { uri: file.uri, name: file.name, mimeType: file.mimeType ?? 'application/octet-stream' });
      reload();
    } catch (err) {
      Alert.alert('Upload failed', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setBusy(null);
    }
  }

  if (loading && !documents) return <LoadingState label="Loading documents…" />;
  if (error && !documents) return <ErrorState message={error} onRetry={reload} />;

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <View style={{ marginBottom: 16 }}>
        <PrimaryButton label="Upload a new version" onPress={handleUpload} loading={busy === 'upload'} />
      </View>

      {!documents || documents.length === 0 ? (
        <EmptyState icon="document-outline" title="No documents yet" message="Upload the contract's file to get started." />
      ) : (
        documents.map((doc: ContractDocument) => (
          <Card key={doc.id} style={{ marginBottom: 14 }}>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 4 }}>{doc.title ?? 'Document'}</Text>
            {doc.versions.map((version) => (
              <View key={version.id} style={{ paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.borderSoft }}>
                <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary }}>
                  v{version.version_number}
                  {version.is_current ? ' · Current' : ''}
                  {version.executed_at ? ' · Executed' : ''}
                </Text>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }} numberOfLines={1}>
                  {version.filename ?? 'Untitled file'}
                  {version.uploaded_by_name ? ` · ${version.uploaded_by_name}` : ''} · {formatDateTime(version.created_at)}
                </Text>
                <View style={{ flexDirection: 'row', gap: 16, marginTop: 8 }}>
                  <Text onPress={() => handleOpen(version)} style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                    {busy === `open-${version.id}` ? 'Opening…' : 'View'}
                  </Text>
                  {!version.executed_at ? (
                    <Text
                      onPress={() => handleMarkExecuted(version)}
                      style={{ color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}
                    >
                      Mark executed
                    </Text>
                  ) : null}
                  <Text onPress={() => confirmDeleteVersion(version)} style={{ color: colors.danger, fontFamily: 'Nunito_600SemiBold', fontSize: 12 }}>
                    Delete
                  </Text>
                </View>
              </View>
            ))}
          </Card>
        ))
      )}
    </ScrollView>
  );
}
