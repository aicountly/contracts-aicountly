import { useState } from 'react';
import { Alert, ScrollView, Text, View } from 'react-native';
import { ScreenContainer, Card, FormField, SelectField, SwitchField, PrimaryButton, LoadingState, ErrorState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { getSettings, updateSettings } from '../../../../src/api/endpoints/settings';
import { ApiError } from '../../../../src/api/errors';
import { CURRENCIES, type ContractSettings } from '../../../../src/types/contracts';

export default function GeneralSettingsScreen() {
  const { data, loading, error, reload } = useAsync(getSettings);

  const [prefix, setPrefix] = useState('');
  const [pad, setPad] = useState('4');
  const [includeYear, setIncludeYear] = useState(true);
  const [resetYearly, setResetYearly] = useState(false);
  const [currency, setCurrency] = useState('INR');
  const [noticeDays, setNoticeDays] = useState('30');
  const [saving, setSaving] = useState(false);

  const [seededFrom, setSeededFrom] = useState<typeof data>(null);
  if (data && data !== seededFrom) {
    setSeededFrom(data);
    const s = data.settings;
    setPrefix(s.number_prefix);
    setPad(String(s.number_pad));
    setIncludeYear(s.number_include_year);
    setResetYearly(s.number_reset_yearly);
    setCurrency(s.default_currency);
    setNoticeDays(String(s.default_notice_days));
  }

  async function save() {
    setSaving(true);
    const patch: Partial<ContractSettings> = {
      number_prefix: prefix.trim(),
      number_pad: Number(pad) || 0,
      number_include_year: includeYear,
      number_reset_yearly: resetYearly,
      default_currency: currency,
      default_notice_days: Number(noticeDays) || 0,
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

  if (loading && !data) return <LoadingState label="Loading settings…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        {data?.numbering_preview ? (
          <Card style={{ marginBottom: 16, backgroundColor: colors.surface, borderColor: colors.borderSoft }}>
            <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 }}>Preview</Text>
            {data.numbering_preview.contract ? (
              <Text style={{ fontSize: 14, fontFamily: 'Nunito_700Bold', color: colors.textPrimary, marginTop: 6 }}>{data.numbering_preview.contract}</Text>
            ) : null}
            {data.numbering_preview.request ? (
              <Text style={{ fontSize: 12, color: colors.textSecondary, marginTop: 2 }}>Request: {data.numbering_preview.request}</Text>
            ) : null}
          </Card>
        ) : null}

        <FormField label="Number prefix" value={prefix} onChangeText={setPrefix} placeholder="CTR" />
        <FormField label="Number padding" value={pad} onChangeText={setPad} keyboardType="numeric" hint="How many digits the running number is padded to." />
        <SwitchField label="Include year" value={includeYear} onChange={setIncludeYear} hint="e.g. CTR-2026-0001 instead of CTR-0001." />
        <SwitchField label="Reset yearly" value={resetYearly} onChange={setResetYearly} hint="Start the running number over at 1 each year." />
        <SelectField label="Default currency" value={currency} options={CURRENCIES.map((c) => ({ value: c, label: c }))} onChange={setCurrency} />
        <FormField label="Default notice period (days)" value={noticeDays} onChangeText={setNoticeDays} keyboardType="numeric" />

        <View style={{ marginTop: 6 }}>
          <PrimaryButton label="Save" onPress={() => void save()} loading={saving} />
        </View>
      </ScrollView>
    </ScreenContainer>
  );
}
