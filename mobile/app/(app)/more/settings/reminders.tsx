import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { ScreenContainer, Card, FormField, PrimaryButton, LoadingState, ErrorState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { getSettings, updateSettings } from '../../../../src/api/endpoints/settings';
import { ApiError } from '../../../../src/api/errors';
import type { ContractSettings } from '../../../../src/types/contracts';

function daysChips(csv: string): string[] {
  return csv
    .split(',')
    .map((v) => v.trim())
    .filter((v) => v !== '');
}

function DaysPreview({ csv, suffix }: { csv: string; suffix: string }) {
  const days = daysChips(csv);
  if (days.length === 0) return null;
  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: -10, marginBottom: 16 }}>
      {days.map((d, i) => (
        <View key={i} style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 9, paddingVertical: 3 }}>
          <Text style={{ fontSize: 11, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>
            {d} {suffix}
          </Text>
        </View>
      ))}
    </View>
  );
}

export default function RemindersSettingsScreen() {
  const { data, loading, error, reload } = useAsync(getSettings);

  const [expiryDays, setExpiryDays] = useState('');
  const [obligationDays, setObligationDays] = useState('');
  const [escalationDays, setEscalationDays] = useState('3');
  const [saving, setSaving] = useState(false);

  const [seededFrom, setSeededFrom] = useState<typeof data>(null);
  if (data && data !== seededFrom) {
    setSeededFrom(data);
    const s = data.settings;
    setExpiryDays(s.expiry_alert_days ?? '');
    setObligationDays(s.obligation_alert_days ?? '');
    setEscalationDays(String(s.approval_escalation_days ?? 3));
  }

  async function save() {
    setSaving(true);
    const patch: Partial<ContractSettings> = {
      expiry_alert_days: expiryDays.trim(),
      obligation_alert_days: obligationDays.trim(),
      approval_escalation_days: Number(escalationDays) || 0,
    };
    try {
      await updateSettings(patch);
      reload();
    } catch (err) {
      Alert.alert('Could not save', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setSaving(false);
    }
  }

  if (loading && !data) return <LoadingState label="Loading reminders…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <Card style={{ marginBottom: 16, backgroundColor: colors.surface, borderColor: colors.borderSoft }}>
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, lineHeight: 18 }}>
            Comma-separated days before a deadline to raise a reminder — e.g. &ldquo;30,15,7,1&rdquo; warns at each of those points before expiry.
          </Text>
        </Card>

        <FormField label="Expiry alert days" value={expiryDays} onChangeText={setExpiryDays} placeholder="30,15,7,1" />
        <DaysPreview csv={expiryDays} suffix="days before expiry" />

        <FormField label="Obligation alert days" value={obligationDays} onChangeText={setObligationDays} placeholder="14,7,1" />
        <DaysPreview csv={obligationDays} suffix="days before due" />

        <FormField
          label="Approval escalation (days)"
          value={escalationDays}
          onChangeText={setEscalationDays}
          keyboardType="numeric"
          hint="An approval waiting this long escalates to the next step."
        />

        <View style={{ marginTop: 6 }}>
          <PrimaryButton label="Save" onPress={() => void save()} loading={saving} />
        </View>
      </ScrollView>
    </ScreenContainer>
  );
}
