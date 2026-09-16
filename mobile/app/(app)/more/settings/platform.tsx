import { ScrollView, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, LoadingState, ErrorState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { getIntegrations, getAiStatus } from '../../../../src/api/endpoints/settings';
import type { IntegrationStatus } from '../../../../src/types/contracts';

function StatusRow({ label, status }: { label: string; status?: IntegrationStatus | null }) {
  const configured = status?.configured ?? false;
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, paddingVertical: 10, borderTopWidth: 1, borderTopColor: colors.borderSoft }}>
      <Ionicons name={configured ? 'checkmark-circle' : 'ellipse-outline'} size={18} color={configured ? colors.success : colors.textMuted} />
      <View style={{ flex: 1 }}>
        <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13.5, color: colors.textPrimary }}>{label}</Text>
        <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 2 }}>
          {configured ? (status?.provider ?? status?.detail ?? 'Configured') : 'Not configured'}
        </Text>
      </View>
    </View>
  );
}

export default function PlatformStatusScreen() {
  const integrations = useAsync(getIntegrations);
  const ai = useAsync(getAiStatus);

  if (integrations.loading && !integrations.data) return <LoadingState label="Loading platform status…" />;
  if (integrations.error && !integrations.data) return <ErrorState message={integrations.error} onRetry={integrations.reload} />;

  const data = integrations.data;

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <Card style={{ marginBottom: 16, backgroundColor: colors.surface, borderColor: colors.borderSoft }}>
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, lineHeight: 18 }}>
            Read-only status — credentials and provider configuration are managed from the web app&apos;s admin console.
          </Text>
        </Card>

        <Card style={{ padding: 0, overflow: 'hidden', marginBottom: 16 }}>
          <View style={{ paddingHorizontal: 14 }}>
            <StatusRow label="Account management" status={data?.manage} />
            <StatusRow label="Contacts" status={data?.contacts} />
            <StatusRow label="Document storage" status={data?.drive} />
            <StatusRow label="Console" status={data?.console} />
            <StatusRow label="E-signature" status={data?.signature} />
            <StatusRow label="Email" status={data?.email} />
          </View>
        </Card>

        <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 }}>
          AI
        </Text>
        <Card>
          <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
            <Ionicons name={ai.data?.configured ? 'checkmark-circle' : 'ellipse-outline'} size={18} color={ai.data?.configured ? colors.success : colors.textMuted} />
            <View style={{ flex: 1 }}>
              <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13.5, color: colors.textPrimary }}>
                {ai.data?.configured ? 'AI is configured' : 'AI is not configured'}
              </Text>
              {ai.data?.provider || ai.data?.model ? (
                <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 2 }}>
                  {[ai.data.provider, ai.data.model].filter(Boolean).join(' · ')}
                </Text>
              ) : null}
              {ai.data?.message ? <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 4 }}>{ai.data.message}</Text> : null}
            </View>
          </View>
        </Card>
      </ScrollView>
    </ScreenContainer>
  );
}
