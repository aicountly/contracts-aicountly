import { useState } from 'react';
import { Modal, Pressable, ScrollView, Text, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../../theme/colors';
import { PrimaryButton } from '../PrimaryButton';
import { FormField } from '../FormField';
import { SelectField } from '../SelectField';
import { DateField } from '../DateField';
import { SwitchField } from '../SwitchField';
import {
  CONTRACT_STATUSES,
  RISK_LEVELS,
  APPROVAL_STATUSES,
  SIGNING_STATUSES,
  CURRENCIES,
  OBLIGATION_STATUSES,
  EMPTY_CONTRACT_FILTERS,
  type ContractFilters,
  type ContractTypeSummary,
  type DepartmentSummary,
  type TagSummary,
} from '../../types/contracts';
import { humaniseSnakeCase } from '../../utils/format';

interface ContractFilterSheetProps {
  visible: boolean;
  onClose: () => void;
  filters: ContractFilters;
  onApply: (filters: ContractFilters) => void;
  contractTypes: ContractTypeSummary[];
  departments: DepartmentSummary[];
  tags: TagSummary[];
}

function toOptions(values: readonly string[]) {
  return values.map((v) => ({ value: v, label: humaniseSnakeCase(v) }));
}

function SectionLabel({ children }: { children: string }) {
  return (
    <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 12, color: colors.textMuted, marginTop: 12, marginBottom: 10, letterSpacing: 0.3 }}>
      {children.toUpperCase()}
    </Text>
  );
}

/** A full-screen modal rather than a short bottom sheet — ~20 filter fields need real space. */
export function ContractFilterSheet({ visible, onClose, filters, onApply, contractTypes, departments, tags }: ContractFilterSheetProps) {
  const insets = useSafeAreaInsets();
  const [draft, setDraft] = useState<ContractFilters>(filters);

  // Re-seeded each time the sheet opens, adjusted during render rather than in
  // a useEffect — the sheet stays mounted (inside a Modal) while closed.
  const [wasVisible, setWasVisible] = useState(false);
  if (visible && !wasVisible) {
    setWasVisible(true);
    setDraft(filters);
  } else if (!visible && wasVisible) {
    setWasVisible(false);
  }

  function set<K extends keyof ContractFilters>(key: K, value: ContractFilters[K]) {
    setDraft((d) => ({ ...d, [key]: value }));
  }

  function toggleStatus(status: string) {
    setDraft((d) => ({ ...d, status: d.status.includes(status) ? d.status.filter((s) => s !== status) : [...d.status, status] }));
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
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary }}>Filters</Text>
        <Pressable onPress={() => setDraft(EMPTY_CONTRACT_FILTERS)}>
          <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 13 }}>Reset</Text>
        </Pressable>
      </View>

      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 32 }} keyboardShouldPersistTaps="handled">
        <SectionLabel>Status</SectionLabel>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 8 }}>
          {CONTRACT_STATUSES.map((status) => {
            const active = draft.status.includes(status);
            return (
              <Pressable
                key={status}
                onPress={() => toggleStatus(status)}
                style={{
                  paddingHorizontal: 12,
                  paddingVertical: 7,
                  borderRadius: 999,
                  backgroundColor: active ? colors.primary : colors.surface,
                  borderWidth: 1,
                  borderColor: active ? colors.primary : colors.border,
                }}
              >
                <Text style={{ fontSize: 12, fontFamily: 'Nunito_600SemiBold', color: active ? '#FFFFFF' : colors.textSecondary }}>
                  {humaniseSnakeCase(status)}
                </Text>
              </Pressable>
            );
          })}
        </View>

        <SectionLabel>Classification</SectionLabel>
        <SelectField label="Risk level" value={draft.risk_level || null} options={toOptions(RISK_LEVELS)} onChange={(v) => set('risk_level', v)} />
        <SelectField
          label="Contract type"
          value={draft.contract_type_id || null}
          options={contractTypes.map((t) => ({ value: String(t.id), label: t.name }))}
          onChange={(v) => set('contract_type_id', v)}
        />
        <SelectField
          label="Department"
          value={draft.department_id || null}
          options={departments.map((d) => ({ value: String(d.id), label: d.name }))}
          onChange={(v) => set('department_id', v)}
        />
        <SelectField label="Currency" value={draft.currency || null} options={toOptions(CURRENCIES)} onChange={(v) => set('currency', v)} />
        <SelectField
          label="Approval status"
          value={draft.approval_status || null}
          options={toOptions(APPROVAL_STATUSES)}
          onChange={(v) => set('approval_status', v)}
        />
        <SelectField
          label="Signing status"
          value={draft.signing_status || null}
          options={toOptions(SIGNING_STATUSES)}
          onChange={(v) => set('signing_status', v)}
        />
        <SelectField
          label="Obligation status"
          value={draft.obligation_status || null}
          options={toOptions(OBLIGATION_STATUSES)}
          onChange={(v) => set('obligation_status', v)}
        />
        <SelectField
          label="Tag"
          value={draft.tag_id || null}
          options={tags.map((t) => ({ value: String(t.id), label: t.name }))}
          onChange={(v) => set('tag_id', v)}
        />

        <SectionLabel>Effective date</SectionLabel>
        <DateField label="From" value={draft.effective_from || null} onChange={(v) => set('effective_from', v ?? '')} />
        <DateField label="To" value={draft.effective_to || null} onChange={(v) => set('effective_to', v ?? '')} />

        <SectionLabel>Expiry date</SectionLabel>
        <DateField label="From" value={draft.expiry_from || null} onChange={(v) => set('expiry_from', v ?? '')} />
        <DateField label="To" value={draft.expiry_to || null} onChange={(v) => set('expiry_to', v ?? '')} />
        <FormField
          label="Expiring within (days)"
          keyboardType="number-pad"
          value={draft.expiring_within_days}
          onChangeText={(v) => set('expiring_within_days', v)}
          placeholder="e.g. 90"
        />

        <SectionLabel>Value</SectionLabel>
        <FormField label="Minimum" keyboardType="numeric" value={draft.value_min} onChangeText={(v) => set('value_min', v)} placeholder="0" />
        <FormField label="Maximum" keyboardType="numeric" value={draft.value_max} onChangeText={(v) => set('value_max', v)} placeholder="No maximum" />

        <SectionLabel>Other</SectionLabel>
        <SwitchField
          label="Auto-renewal only"
          value={draft.auto_renewal === 'true' || draft.auto_renewal === '1'}
          onChange={(v) => set('auto_renewal', v ? 'true' : '')}
        />
        <SwitchField label="Favourites only" value={draft.favourites_only} onChange={(v) => set('favourites_only', v)} />
        <SwitchField label="Include archived" value={draft.archived !== 'no'} onChange={(v) => set('archived', v ? 'all' : 'no')} />
      </ScrollView>

      <View style={{ padding: 16, borderTopWidth: 1, borderTopColor: colors.borderSoft }}>
        <PrimaryButton
          label="Apply filters"
          onPress={() => {
            onApply(draft);
            onClose();
          }}
        />
      </View>
      </View>
    </Modal>
  );
}
