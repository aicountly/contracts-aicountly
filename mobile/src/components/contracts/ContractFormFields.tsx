import { Text, View } from 'react-native';
import { FormField } from '../FormField';
import { SelectField } from '../SelectField';
import { DateField } from '../DateField';
import { SwitchField } from '../SwitchField';
import { colors } from '../../theme/colors';
import { RENEWAL_TYPES, RENEWAL_FREQUENCIES, PAYMENT_FREQUENCIES, CURRENCIES, RISK_LEVELS } from '../../types/contracts';
import type { ContractInput, ContractTypeSummary, DepartmentSummary } from '../../types/contracts';
import { humaniseSnakeCase } from '../../utils/format';

interface ContractFormFieldsProps {
  value: ContractInput;
  onChange: (patch: Partial<ContractInput>) => void;
  errors?: Record<string, string>;
  contractTypes: ContractTypeSummary[];
  departments: DepartmentSummary[];
}

function SectionLabel({ children }: { children: string }) {
  return (
    <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 12, color: colors.textMuted, marginTop: 8, marginBottom: 10, letterSpacing: 0.3 }}>
      {children.toUpperCase()}
    </Text>
  );
}

/**
 * The full ContractInput as a form — shared by the create screen
 * (app/(app)/contracts/new.tsx) and the Overview tab's edit mode (contract
 * workspace, task 8), so the two never drift apart.
 */
export function ContractFormFields({ value, onChange, errors = {}, contractTypes, departments }: ContractFormFieldsProps) {
  return (
    <View>
      <SectionLabel>Basic details</SectionLabel>
      <FormField
        label="Title"
        value={value.title}
        onChangeText={(t) => onChange({ title: t })}
        placeholder="e.g. Master Services Agreement — Acme Corp"
        error={errors.title}
      />
      <SelectField
        label="Contract type"
        value={value.contract_type_id ? String(value.contract_type_id) : null}
        options={contractTypes.map((t) => ({ value: String(t.id), label: t.name }))}
        onChange={(v) => onChange({ contract_type_id: v ? Number(v) : null })}
        error={errors.contract_type_id}
      />
      <SelectField
        label="Department"
        value={value.department_id ? String(value.department_id) : null}
        options={departments.map((d) => ({ value: String(d.id), label: d.name }))}
        onChange={(v) => onChange({ department_id: v ? Number(v) : null })}
        error={errors.department_id}
      />
      <FormField
        label="Counterparty"
        value={value.counterparty_name}
        onChangeText={(t) => onChange({ counterparty_name: t })}
        placeholder="Company or individual name"
        error={errors.counterparty_name}
      />
      <FormField
        label="Description"
        value={value.description ?? ''}
        onChangeText={(t) => onChange({ description: t || null })}
        placeholder="What this agreement covers"
        multiline
        numberOfLines={3}
        style={{ minHeight: 80, textAlignVertical: 'top' }}
      />

      <SectionLabel>Dates & renewal</SectionLabel>
      <DateField label="Effective date" value={value.effective_date} onChange={(v) => onChange({ effective_date: v })} />
      <DateField label="Commencement date" value={value.commencement_date} onChange={(v) => onChange({ commencement_date: v })} />
      <DateField label="Expiry date" value={value.expiry_date} onChange={(v) => onChange({ expiry_date: v })} error={errors.expiry_date} />
      <SelectField
        label="Renewal type"
        value={value.renewal_type}
        options={RENEWAL_TYPES.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
        onChange={(v) => onChange({ renewal_type: v })}
      />
      <SelectField
        label="Renewal frequency"
        value={value.renewal_frequency}
        options={RENEWAL_FREQUENCIES.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
        onChange={(v) => onChange({ renewal_frequency: v })}
      />
      <SwitchField label="Auto-renews" value={value.auto_renewal} onChange={(v) => onChange({ auto_renewal: v })} />
      <FormField
        label="Notice period (days)"
        keyboardType="number-pad"
        value={value.notice_period_days !== null ? String(value.notice_period_days) : ''}
        onChangeText={(t) => onChange({ notice_period_days: t ? Number(t) : null })}
        placeholder="e.g. 30"
      />

      <SectionLabel>Commercial</SectionLabel>
      <SelectField
        label="Currency"
        value={value.currency}
        options={CURRENCIES.map((c) => ({ value: c, label: c }))}
        onChange={(v) => onChange({ currency: v })}
        error={errors.currency}
      />
      <FormField
        label="Total value"
        keyboardType="numeric"
        value={value.total_value ?? ''}
        onChangeText={(t) => onChange({ total_value: t || null })}
        placeholder="0.00"
        error={errors.total_value}
      />
      <FormField
        label="Recurring value"
        keyboardType="numeric"
        value={value.recurring_value ?? ''}
        onChangeText={(t) => onChange({ recurring_value: t || null })}
        placeholder="0.00"
      />
      <SelectField
        label="Payment frequency"
        value={value.payment_frequency}
        options={PAYMENT_FREQUENCIES.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
        onChange={(v) => onChange({ payment_frequency: v })}
      />
      <FormField
        label="Commercial summary"
        value={value.commercial_summary ?? ''}
        onChangeText={(t) => onChange({ commercial_summary: t || null })}
        placeholder="Pricing, terms, key commercial points"
        multiline
        numberOfLines={3}
        style={{ minHeight: 80, textAlignVertical: 'top' }}
      />

      <SectionLabel>Legal & risk</SectionLabel>
      <FormField label="Governing law" value={value.governing_law ?? ''} onChangeText={(t) => onChange({ governing_law: t || null })} placeholder="e.g. Laws of India" />
      <FormField label="Jurisdiction" value={value.jurisdiction ?? ''} onChangeText={(t) => onChange({ jurisdiction: t || null })} placeholder="e.g. Courts of Mumbai" />
      <SelectField
        label="Risk level"
        value={value.risk_level}
        options={RISK_LEVELS.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
        onChange={(v) => onChange({ risk_level: v })}
      />

      <SectionLabel>Notes</SectionLabel>
      <FormField
        label="Internal notes"
        value={value.notes ?? ''}
        onChangeText={(t) => onChange({ notes: t || null })}
        placeholder="Not visible to the counterparty"
        multiline
        numberOfLines={4}
        style={{ minHeight: 100, textAlignVertical: 'top' }}
      />
    </View>
  );
}
