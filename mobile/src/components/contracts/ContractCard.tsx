import { Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Card } from '../Card';
import { StatusBadge } from '../StatusBadge';
import { colors } from '../../theme/colors';
import { formatMoney, formatDate, daysLabel } from '../../utils/format';
import type { ContractListItem } from '../../types/contracts';

interface ContractCardProps {
  contract: ContractListItem;
  onPress: () => void;
  onToggleFavourite?: () => void;
}

export function ContractCard({ contract, onPress, onToggleFavourite }: ContractCardProps) {
  const expiry = daysLabel(contract.days_to_expiry);
  return (
    <Pressable onPress={onPress}>
      <Card style={{ marginBottom: 10 }}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <View style={{ flex: 1, marginRight: 8 }}>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }} numberOfLines={2}>
              {contract.title}
            </Text>
            <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
              {contract.contract_number}
              {contract.counterparty_name ? ` · ${contract.counterparty_name}` : ''}
            </Text>
          </View>
          {onToggleFavourite ? (
            <Pressable onPress={onToggleFavourite} hitSlop={8}>
              <Ionicons name={contract.is_favourite ? 'star' : 'star-outline'} size={20} color={contract.is_favourite ? '#EDA100' : colors.textMuted} />
            </Pressable>
          ) : null}
        </View>

        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
          <StatusBadge value={contract.status} kind="contract" />
          {contract.risk_level ? <StatusBadge value={contract.risk_level} kind="risk" /> : null}
        </View>

        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-end', marginTop: 10 }}>
          <View>
            {contract.total_value ? (
              <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary }}>
                {formatMoney(contract.total_value, contract.currency)}
              </Text>
            ) : null}
            {contract.expiry_date ? (
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 11, color: colors.textMuted, marginTop: 2 }}>
                Expires {formatDate(contract.expiry_date)}
              </Text>
            ) : null}
          </View>
          {expiry ? (
            <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 11, color: expiry.includes('overdue') ? colors.danger : colors.warning }}>
              {expiry}
            </Text>
          ) : null}
        </View>
      </Card>
    </Pressable>
  );
}
