import { useState } from 'react';
import { Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer } from '../ScreenContainer';
import { FormField } from '../FormField';
import { SelectField } from '../SelectField';
import { SwitchField } from '../SwitchField';
import { PrimaryButton } from '../PrimaryButton';
import { colors } from '../../theme/colors';
import { ApiError } from '../../api/errors';
import { createRiskRule, updateRiskRule, deleteRiskRule, listContractTypes } from '../../api/endpoints/settings';
import { useAsync } from '../../utils/useAsync';
import { RISK_RULE_SUBJECTS, RISK_RULE_OPERATORS, UNARY_RISK_OPERATORS, RISK_CATEGORIES, RISK_SEVERITIES, type RiskRuleRow } from '../../types/contracts';
import { humaniseSnakeCase } from '../../utils/format';

const LIST_OPERATORS = new Set(['in_list', 'not_in_list']);
const NUMERIC_OPERATORS = new Set(['greater_than', 'less_than']);

function toNumberIds(values: number[] | null | undefined): number[] {
  return Array.isArray(values) ? values : [];
}

/** Shared by app/(app)/more/settings/risk-rules/new.tsx and [id].tsx. */
export function RiskRuleEditorScreen({ row }: { row: RiskRuleRow | null }) {
  const router = useRouter();
  const contractTypes = useAsync(listContractTypes);

  const [ruleKey, setRuleKey] = useState(row?.rule_key ?? '');
  const [name, setName] = useState(row?.name ?? '');
  const [description, setDescription] = useState(row?.description ?? '');
  const [subject, setSubject] = useState(row?.subject ?? RISK_RULE_SUBJECTS[0]);
  const [operator, setOperator] = useState(row?.operator ?? RISK_RULE_OPERATORS[0]);
  const [valueText, setValueText] = useState(row?.value_text ?? '');
  const [valueNumeric, setValueNumeric] = useState(row?.value_numeric != null ? String(row.value_numeric) : '');
  const [valueList, setValueList] = useState((row?.value_list ?? []).join(', '));
  const [appliesTo, setAppliesTo] = useState<number[]>(toNumberIds(row?.applies_to_types));
  const [riskCategory, setRiskCategory] = useState(row?.risk_category ?? RISK_CATEGORIES[0]);
  const [severity, setSeverity] = useState(row?.severity ?? 'medium');
  const [scoreWeight, setScoreWeight] = useState(String(row?.score_weight ?? 10));
  const [recommendation, setRecommendation] = useState(row?.recommendation ?? '');
  const [isActive, setIsActive] = useState(row?.is_active ?? true);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const isUnary = (UNARY_RISK_OPERATORS as readonly string[]).includes(operator);
  const isList = LIST_OPERATORS.has(operator);
  const isNumeric = NUMERIC_OPERATORS.has(operator);

  async function save() {
    if (name.trim() === '' || ruleKey.trim() === '') {
      setError('Name and key are both required.');
      return;
    }
    setSaving(true);
    setError(null);
    const input: Partial<RiskRuleRow> = {
      rule_key: ruleKey.trim(),
      name: name.trim(),
      description: description.trim() || null,
      subject,
      operator,
      value_text: !isUnary && !isList && !isNumeric ? valueText.trim() || null : null,
      value_numeric: isNumeric ? (valueNumeric.trim() === '' ? null : Number(valueNumeric)) : null,
      value_list: isList
        ? valueList
            .split(',')
            .map((v) => v.trim())
            .filter((v) => v !== '')
        : null,
      applies_to_types: appliesTo,
      risk_category: riskCategory,
      severity,
      score_weight: Number(scoreWeight) || 0,
      recommendation: recommendation.trim() || null,
      is_active: isActive,
    };

    try {
      const saved = row ? await updateRiskRule(row.id, input) : await createRiskRule(input);
      router.replace(`/more/settings/risk-rules/${saved.id}`);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not save this risk rule.');
    } finally {
      setSaving(false);
    }
  }

  function remove() {
    if (!row) return;
    Alert.alert('Delete this risk rule?', `${row.name} will stop being checked. Findings already raised are not affected.`, [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          setDeleting(true);
          try {
            await deleteRiskRule(row.id);
            router.back();
          } catch (err) {
            Alert.alert('Could not delete', err instanceof ApiError ? err.message : 'Something went wrong.');
          } finally {
            setDeleting(false);
          }
        },
      },
    ]);
  }

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        {error ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{error}</Text>
          </View>
        ) : null}
        <FormField label="Name" value={name} onChangeText={setName} placeholder="Liability cap must be present" />
        <FormField label="Key" value={ruleKey} onChangeText={setRuleKey} placeholder="liability_cap_present" editable={row === null} />
        <FormField label="Description" value={description} onChangeText={setDescription} />
        <SelectField label="Subject" value={subject} options={RISK_RULE_SUBJECTS.map((s) => ({ value: s, label: humaniseSnakeCase(s) }))} onChange={setSubject} />
        <SelectField label="Operator" value={operator} options={RISK_RULE_OPERATORS.map((o) => ({ value: o, label: humaniseSnakeCase(o) }))} onChange={setOperator} />

        {isNumeric ? (
          <FormField label="Comparison number" value={valueNumeric} onChangeText={setValueNumeric} keyboardType="numeric" />
        ) : isList ? (
          <FormField label="List of values" value={valueList} onChangeText={setValueList} placeholder="Comma-separated" />
        ) : !isUnary ? (
          <FormField label="Comparison value" value={valueText} onChangeText={setValueText} hint="Wording or value the contract should hold." />
        ) : null}

        <View style={{ marginBottom: 8 }}>
          <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textSecondary, marginBottom: 8 }}>Applies to contract types</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            {(contractTypes.data ?? []).map((type) => {
              const active = appliesTo.includes(type.id);
              return (
                <Pressable
                  key={type.id}
                  onPress={() => setAppliesTo((current) => (active ? current.filter((id) => id !== type.id) : [...current, type.id]))}
                  style={{
                    flexDirection: 'row',
                    alignItems: 'center',
                    gap: 6,
                    paddingHorizontal: 12,
                    paddingVertical: 7,
                    borderRadius: 999,
                    backgroundColor: active ? colors.primaryLight : colors.surface,
                    borderWidth: 1,
                    borderColor: active ? colors.primary : colors.border,
                  }}
                >
                  <Ionicons name={active ? 'checkmark-circle' : 'ellipse-outline'} size={14} color={active ? colors.primaryDark : colors.textMuted} />
                  <Text style={{ fontSize: 12.5, fontFamily: 'Nunito_600SemiBold', color: active ? colors.primaryDark : colors.textSecondary }}>{type.name}</Text>
                </Pressable>
              );
            })}
          </View>
          <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 6 }}>
            {appliesTo.length === 0 ? 'None selected — checked on every contract type.' : `Checked on ${appliesTo.length} type(s).`}
          </Text>
        </View>

        <SelectField label="Risk category" value={riskCategory} options={RISK_CATEGORIES.map((c) => ({ value: c, label: humaniseSnakeCase(c) }))} onChange={setRiskCategory} />
        <SelectField label="Severity" value={severity} options={RISK_SEVERITIES.map((s) => ({ value: s, label: humaniseSnakeCase(s) }))} onChange={setSeverity} />
        <FormField label="Score weight" value={scoreWeight} onChangeText={setScoreWeight} keyboardType="numeric" />
        <FormField
          label="Recommendation"
          value={recommendation}
          onChangeText={setRecommendation}
          multiline
          numberOfLines={3}
          style={{ minHeight: 80, textAlignVertical: 'top' }}
        />
        <SwitchField label="Active" value={isActive} onChange={setIsActive} />

        <View style={{ gap: 10, marginTop: 6 }}>
          <PrimaryButton label={row ? 'Save' : 'Create rule'} onPress={() => void save()} loading={saving} />
          {row && !row.is_system ? <PrimaryButton label="Delete" variant="danger" onPress={remove} loading={deleting} /> : null}
        </View>
      </ScrollView>
    </ScreenContainer>
  );
}
