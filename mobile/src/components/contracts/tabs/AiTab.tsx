import { useState } from 'react';
import { ScrollView, Text, TextInput, View } from 'react-native';
import { Card, PrimaryButton, LoadingState } from '../../index';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import { getAiStatus, getContractSummary, generateSummary, askContract } from '../../../api/endpoints/ai';
import { ApiError } from '../../../api/errors';

interface AiTabProps {
  contractId: number;
}

export function AiTab({ contractId }: AiTabProps) {
  const status = useAsync(getAiStatus);
  const summary = useAsync(() => getContractSummary(contractId), [contractId]);
  const [generating, setGenerating] = useState(false);
  const [question, setQuestion] = useState('');
  const [asking, setAsking] = useState(false);
  const [answer, setAnswer] = useState<{ text: string; citations?: unknown[] } | null>(null);
  const [askError, setAskError] = useState<string | null>(null);

  async function handleGenerateSummary() {
    setGenerating(true);
    try {
      await generateSummary(contractId);
      summary.reload();
    } catch (err) {
      setAskError(err instanceof ApiError ? err.message : 'Could not generate a summary.');
    } finally {
      setGenerating(false);
    }
  }

  async function handleAsk() {
    if (!question.trim()) return;
    setAsking(true);
    setAskError(null);
    setAnswer(null);
    try {
      const result = await askContract(contractId, question.trim());
      setAnswer(result);
    } catch (err) {
      setAskError(err instanceof ApiError ? err.message : 'Could not get an answer.');
    } finally {
      setAsking(false);
    }
  }

  if (status.loading) return <LoadingState label="Checking AI status…" />;

  if (status.data && !status.data.configured) {
    return (
      <ScrollView contentContainerStyle={{ padding: 16 }}>
        <Card>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 6 }}>AI is not configured</Text>
          <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 13, color: colors.textSecondary }}>
            {status.data.message ?? 'An administrator needs to connect an AI provider in Console before this works.'}
          </Text>
        </Card>
      </ScrollView>
    );
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      <Card style={{ marginBottom: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 8 }}>Summary</Text>
        {summary.loading ? (
          <Text style={{ color: colors.textMuted, fontSize: 13 }}>Loading…</Text>
        ) : summary.data?.summary ? (
          <Text style={{ fontSize: 13, color: colors.textSecondary, lineHeight: 19 }}>{String(summary.data.summary)}</Text>
        ) : (
          <Text style={{ color: colors.textMuted, fontSize: 13, marginBottom: 10 }}>No summary yet.</Text>
        )}
        <View style={{ marginTop: 10 }}>
          <PrimaryButton
            label={summary.data?.summary ? 'Regenerate' : 'Generate summary'}
            onPress={handleGenerateSummary}
            loading={generating}
            variant="secondary"
          />
        </View>
      </Card>

      <Card>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 4 }}>Ask Your Contract</Text>
        <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginBottom: 10 }}>
          Answers grounded in this contract&apos;s own text, with citations.
        </Text>
        <TextInput
          value={question}
          onChangeText={setQuestion}
          placeholder="e.g. What is the notice period?"
          placeholderTextColor={colors.textMuted}
          style={{
            borderWidth: 1,
            borderColor: colors.border,
            borderRadius: 10,
            paddingHorizontal: 14,
            paddingVertical: 12,
            fontSize: 14,
            marginBottom: 10,
            color: colors.textPrimary,
          }}
        />
        <PrimaryButton label="Ask" onPress={handleAsk} loading={asking} disabled={!question.trim()} />
        {askError ? <Text style={{ color: colors.danger, fontSize: 12, marginTop: 10 }}>{askError}</Text> : null}
        {answer ? (
          <View style={{ backgroundColor: colors.primaryLight, borderRadius: 10, padding: 12, marginTop: 12 }}>
            <Text style={{ color: colors.textPrimary, fontSize: 13 }}>{answer.text}</Text>
          </View>
        ) : null}
      </Card>
    </ScrollView>
  );
}
