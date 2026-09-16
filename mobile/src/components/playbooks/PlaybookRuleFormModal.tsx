import { useState } from 'react';
import { Modal, Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../../theme/colors';
import { PrimaryButton } from '../PrimaryButton';
import { FormField } from '../FormField';
import { SelectField } from '../SelectField';
import { SwitchField } from '../SwitchField';
import { ApiError } from '../../api/errors';
import { createPlaybookRule, updatePlaybookRule, type PlaybookRuleBody } from '../../api/endpoints/playbooks';
import { PLAYBOOK_RULE_TYPES, RISK_CATEGORIES, RISK_SEVERITIES, type PlaybookRule } from '../../types/contracts';
import { humaniseSnakeCase } from '../../utils/format';

interface PlaybookRuleFormModalProps {
  visible: boolean;
  onClose: () => void;
  playbookId: number;
  row: PlaybookRule | null;
  onSaved: () => void;
}

/** Full-screen modal, mirroring ContractFilterSheet's shell — a rule has too many fields for a bottom sheet. */
export function PlaybookRuleFormModal({ visible, onClose, playbookId, row, onSaved }: PlaybookRuleFormModalProps) {
  const insets = useSafeAreaInsets();

  const [ruleKey, setRuleKey] = useState('');
  const [label, setLabel] = useState('');
  const [description, setDescription] = useState('');
  const [ruleType, setRuleType] = useState('mandatory_clause');
  const [expectedValue, setExpectedValue] = useState('');
  const [expectedNumeric, setExpectedNumeric] = useState('');
  const [severity, setSeverity] = useState('medium');
  const [riskCategory, setRiskCategory] = useState('legal');
  const [recommendation, setRecommendation] = useState('');
  const [isActive, setIsActive] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  // Re-seeded each time the modal opens, adjusted during render rather than in
  // a useEffect — the modal stays mounted while closed, so a plain useState
  // initializer only captures `row` from the very first open.
  const [wasVisible, setWasVisible] = useState(false);
  if (visible && !wasVisible) {
    setWasVisible(true);
    setRuleKey(row?.rule_key ?? '');
    setLabel(row?.label ?? '');
    setDescription(row?.description ?? '');
    setRuleType(row?.rule_type ?? 'mandatory_clause');
    setExpectedValue(row?.expected_value ?? '');
    setExpectedNumeric(row?.expected_numeric === null || row?.expected_numeric === undefined ? '' : String(row.expected_numeric));
    setSeverity(row?.severity ?? 'medium');
    setRiskCategory(row?.risk_category ?? 'legal');
    setRecommendation(row?.recommendation ?? '');
    setIsActive(row?.is_active ?? true);
    setErrors({});
  } else if (!visible && wasVisible) {
    setWasVisible(false);
  }

  async function submit() {
    const next: Record<string, string> = {};
    if (label.trim() === '') next.label = 'Give the rule a label.';
    if (ruleKey.trim() === '') next.rule_key = 'A short, unique key.';
    if (Object.keys(next).length > 0) {
      setErrors(next);
      return;
    }

    setBusy(true);
    setErrors({});
    const numeric = expectedNumeric.trim() === '' ? null : Number(expectedNumeric);
    const body: PlaybookRuleBody = {
      rule_key: ruleKey.trim(),
      label: label.trim(),
      description: description.trim() === '' ? null : description.trim(),
      rule_type: ruleType,
      expected_value: expectedValue.trim() === '' ? null : expectedValue.trim(),
      expected_numeric: numeric !== null && Number.isNaN(numeric) ? null : numeric,
      expected_list: row?.expected_list ?? [],
      category_id: row?.category_id ?? null,
      severity,
      risk_category: riskCategory,
      recommendation: recommendation.trim() === '' ? null : recommendation.trim(),
      sort_order: row?.sort_order ?? 100,
      is_active: isActive,
    };

    try {
      if (row) await updatePlaybookRule(row.id, body);
      else await createPlaybookRule(playbookId, body);
      onSaved();
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) setErrors(err.fieldErrors);
      else setErrors({ _general: err instanceof ApiError ? err.message : 'Could not save this rule.' });
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose}>
      <View style={{ flex: 1, backgroundColor: '#FFFFFF', paddingTop: insets.top }}>
        <View
          style={{
            flexDirection: 'row',
            alignItems: 'center',
            justifyContent: 'space-between',
            paddingHorizontal: 16,
            paddingTop: 12,
            paddingBottom: 12,
            borderBottomWidth: 1,
            borderBottomColor: colors.borderSoft,
          }}
        >
          <Pressable onPress={onClose} hitSlop={8}>
            <Ionicons name="close" size={24} color={colors.textPrimary} />
          </Pressable>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary }}>{row ? 'Edit rule' : 'New playbook rule'}</Text>
          <View style={{ width: 24 }} />
        </View>

        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 32 }} keyboardShouldPersistTaps="handled">
          {errors._general ? <Text style={{ color: colors.danger, fontSize: 12.5, marginBottom: 12 }}>{errors._general}</Text> : null}
          <FormField label="Label" value={label} onChangeText={setLabel} error={errors.label} placeholder="Liability cap must be present" />
          <FormField label="Key" value={ruleKey} onChangeText={setRuleKey} error={errors.rule_key} editable={row === null} placeholder="liability_cap_present" />
          <FormField label="Description" value={description} onChangeText={setDescription} error={errors.description} />
          <SelectField
            label="Rule type"
            value={ruleType}
            options={PLAYBOOK_RULE_TYPES.map((t) => ({ value: t, label: humaniseSnakeCase(t) }))}
            onChange={setRuleType}
            error={errors.rule_type}
          />
          <SelectField
            label="Severity"
            value={severity}
            options={RISK_SEVERITIES.map((s) => ({ value: s, label: humaniseSnakeCase(s) }))}
            onChange={setSeverity}
            error={errors.severity}
          />
          <FormField label="Expected value" value={expectedValue} onChangeText={setExpectedValue} error={errors.expected_value} hint="Wording or value the contract should hold." />
          <FormField
            label="Expected number"
            value={expectedNumeric}
            onChangeText={setExpectedNumeric}
            error={errors.expected_numeric}
            keyboardType="numeric"
            hint="For a cap or a minimum."
          />
          <SelectField
            label="Risk category"
            value={riskCategory}
            options={RISK_CATEGORIES.map((c) => ({ value: c, label: humaniseSnakeCase(c) }))}
            onChange={setRiskCategory}
            error={errors.risk_category}
          />
          <FormField
            label="Recommendation"
            value={recommendation}
            onChangeText={setRecommendation}
            error={errors.recommendation}
            multiline
            numberOfLines={3}
            style={{ minHeight: 80, textAlignVertical: 'top' }}
          />
          <SwitchField label="Active" value={isActive} onChange={setIsActive} hint="An inactive rule is never checked." />
        </ScrollView>

        <View style={{ padding: 16, borderTopWidth: 1, borderTopColor: colors.borderSoft }}>
          <PrimaryButton label={row ? 'Save rule' : 'Add rule'} onPress={() => void submit()} loading={busy} />
        </View>
      </View>
    </Modal>
  );
}
