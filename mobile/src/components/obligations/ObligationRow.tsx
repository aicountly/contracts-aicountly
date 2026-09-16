import { Pressable, Text, View } from 'react-native';
import { Card } from '../Card';
import { StatusBadge } from '../StatusBadge';
import { colors } from '../../theme/colors';
import { formatDate, formatMoney, daysLabel, humaniseSnakeCase } from '../../utils/format';
import type { ObligationOccurrenceRow } from '../../types/contracts';

interface ObligationRowProps {
  occurrence: ObligationOccurrenceRow;
  onPress?: () => void;
  showContract?: boolean;
}

/** Shared by the in-contract Obligations tab and the cross-contract Attention queue. */
export function ObligationRow({ occurrence, onPress, showContract = true }: ObligationRowProps) {
  const due = daysLabel(occurrence.days_to_due);
  const content = (
    <Card style={{ marginBottom: 10 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <View style={{ flex: 1, marginRight: 8 }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }} numberOfLines={2}>
            {occurrence.obligation_title}
          </Text>
          {showContract && occurrence.contract_number ? (
            <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
              {occurrence.contract_number}
              {occurrence.contract_title ? ` · ${occurrence.contract_title}` : ''}
            </Text>
          ) : null}
        </View>
        <StatusBadge value={occurrence.status} kind="workflow" />
      </View>

      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginTop: 8 }}>
        <Text style={{ fontSize: 12, color: colors.textSecondary, fontFamily: 'Nunito_500Medium' }}>Due {formatDate(occurrence.due_date)}</Text>
        {occurrence.responsible_party ? (
          <Text style={{ fontSize: 12, color: colors.textMuted, fontFamily: 'Nunito_500Medium' }}>{humaniseSnakeCase(occurrence.responsible_party)}</Text>
        ) : null}
        {occurrence.amount ? (
          <Text style={{ fontSize: 12, color: colors.textMuted, fontFamily: 'Nunito_500Medium' }}>{formatMoney(occurrence.amount, occurrence.currency)}</Text>
        ) : null}
      </View>

      {due ? (
        <Text style={{ fontSize: 11, fontFamily: 'Nunito_600SemiBold', color: due.includes('overdue') ? colors.danger : colors.warning, marginTop: 6 }}>
          {due}
        </Text>
      ) : null}
      {occurrence.evidence_required ? <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 4 }}>Evidence required</Text> : null}
    </Card>
  );

  return onPress ? <Pressable onPress={onPress}>{content}</Pressable> : content;
}
