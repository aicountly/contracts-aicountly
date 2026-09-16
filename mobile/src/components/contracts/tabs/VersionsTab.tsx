import { useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { Card, PrimaryButton, SelectField, LoadingState, ErrorState, EmptyState } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDateTime } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import { listDocuments, compareVersions } from '../../../api/endpoints/documents';
import { ApiError } from '../../../api/errors';
import type { CompareResult, DocumentVersion } from '../../../types/contracts';

interface VersionsTabProps {
  contractId: number;
}

export function VersionsTab({ contractId }: VersionsTabProps) {
  const { data: documents, loading, error } = useAsync(() => listDocuments(contractId), [contractId]);
  const versions: DocumentVersion[] = (documents ?? []).flatMap((d) => d.versions).sort((a, b) => b.version_number - a.version_number);

  const [baseId, setBaseId] = useState<string | null>(null);
  const [targetId, setTargetId] = useState<string | null>(null);
  const [comparing, setComparing] = useState(false);
  const [compareError, setCompareError] = useState<string | null>(null);
  const [result, setResult] = useState<CompareResult | null>(null);

  async function handleCompare() {
    if (!baseId || !targetId) return;
    setComparing(true);
    setCompareError(null);
    try {
      const r = await compareVersions(contractId, baseId, targetId);
      setResult(r);
    } catch (err) {
      setCompareError(err instanceof ApiError ? err.message : 'Could not compare these versions.');
    } finally {
      setComparing(false);
    }
  }

  if (loading && !documents) return <LoadingState label="Loading versions…" />;
  if (error && !documents) return <ErrorState message={error} />;
  if (versions.length === 0) {
    return <EmptyState icon="git-compare-outline" title="No versions yet" message="Upload a document from the Document tab first." />;
  }

  const options = versions.map((v) => ({ value: String(v.id), label: `v${v.version_number} · ${formatDateTime(v.created_at)}` }));

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Compare versions</Text>
      <SelectField label="From" value={baseId} options={options} onChange={setBaseId} />
      <SelectField label="To" value={targetId} options={options} onChange={setTargetId} />
      <PrimaryButton label="Compare" onPress={handleCompare} loading={comparing} disabled={!baseId || !targetId || baseId === targetId} />

      {compareError ? (
        <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginTop: 16 }}>
          <Text style={{ color: colors.danger, fontSize: 13 }}>{compareError}</Text>
        </View>
      ) : null}

      {result ? (
        <View style={{ marginTop: 20 }}>
          {result.stats ? (
            <Card style={{ marginBottom: 14 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary, marginBottom: 6 }}>Summary</Text>
              <Text style={{ fontSize: 12, color: colors.textSecondary }}>
                {result.stats.added ?? 0} added · {result.stats.removed ?? 0} removed · {result.stats.changed ?? 0} changed
                {result.stats.similarity !== null && result.stats.similarity !== undefined
                  ? ` · ${Math.round((result.stats.similarity ?? 0) * 100)}% similar`
                  : ''}
              </Text>
            </Card>
          ) : null}

          {result.classified && result.classified.length > 0 ? (
            <Card style={{ marginBottom: 14 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary, marginBottom: 8 }}>Material changes</Text>
              {result.classified.map((change, i) => (
                <View key={change.id ?? i} style={{ paddingVertical: 6, borderTopWidth: i === 0 ? 0 : 1, borderTopColor: colors.borderSoft }}>
                  <Text style={{ fontSize: 13, fontFamily: 'Nunito_600SemiBold', color: colors.textPrimary }}>
                    {change.title ?? change.category ?? 'Change'}
                  </Text>
                  {change.summary || change.description ? (
                    <Text style={{ fontSize: 12, color: colors.textSecondary, marginTop: 2 }}>{change.summary ?? change.description}</Text>
                  ) : null}
                  {change.base_value !== undefined || change.target_value !== undefined ? (
                    <Text style={{ fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
                      {String(change.base_value ?? '—')} → {String(change.target_value ?? '—')}
                    </Text>
                  ) : null}
                </View>
              ))}
            </Card>
          ) : null}

          {result.ai_explanation ? (
            <Card style={{ marginBottom: 14 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary, marginBottom: 6 }}>AI summary</Text>
              <Text style={{ fontSize: 12, color: colors.textSecondary }}>{result.ai_explanation}</Text>
            </Card>
          ) : null}

          {result.segments && result.segments.length > 0 ? (
            <Card>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary, marginBottom: 8 }}>Text diff</Text>
              <Text style={{ fontSize: 13, lineHeight: 20 }}>
                {result.segments.map((seg, i) => {
                  const op = seg.type ?? seg.op;
                  const text = seg.text ?? seg.value ?? '';
                  if (op === 'add' || op === 'insert' || op === 'added') {
                    return (
                      <Text key={i} style={{ backgroundColor: colors.successSoft, color: colors.textPrimary }}>
                        {text}
                      </Text>
                    );
                  }
                  if (op === 'remove' || op === 'delete' || op === 'removed') {
                    return (
                      <Text key={i} style={{ backgroundColor: colors.dangerSoft, color: colors.textMuted, textDecorationLine: 'line-through' }}>
                        {text}
                      </Text>
                    );
                  }
                  return (
                    <Text key={i} style={{ color: colors.textSecondary }}>
                      {text}
                    </Text>
                  );
                })}
              </Text>
            </Card>
          ) : null}
        </View>
      ) : null}
    </ScrollView>
  );
}
