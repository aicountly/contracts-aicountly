import { Text, View } from 'react-native';
import { colors } from '../../theme/colors';

export interface BarListItem {
  label: string;
  value: number;
  color?: string;
  formattedValue?: string;
}

interface BarListProps {
  items: BarListItem[];
  maxValue?: number;
}

/**
 * A minimal, dependency-free horizontal bar chart. No charting library: thin
 * marks (8px, rounded), direct labels on every row (a dashboard-scale list
 * rather than a dense scatter, so per-mark labels are the right call — see
 * the dataviz skill's mark-spec guidance), one series per row so there is no
 * axis to mislabel.
 */
export function BarList({ items, maxValue }: BarListProps) {
  if (items.length === 0) return null;
  const max = maxValue ?? Math.max(1, ...items.map((i) => i.value));
  return (
    <View style={{ gap: 12 }}>
      {items.map((item, index) => {
        const width = Math.max(4, (item.value / max) * 100);
        return (
          <View key={`${item.label}-${index}`}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 4 }}>
              <Text style={{ fontSize: 12, fontFamily: 'Nunito_600SemiBold', color: colors.textSecondary, flex: 1, marginRight: 8 }} numberOfLines={1}>
                {item.label}
              </Text>
              <Text style={{ fontSize: 12, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>{item.formattedValue ?? item.value}</Text>
            </View>
            <View style={{ height: 8, borderRadius: 4, backgroundColor: colors.track, overflow: 'hidden' }}>
              <View style={{ width: `${width}%`, height: '100%', borderRadius: 4, backgroundColor: item.color ?? colors.primary }} />
            </View>
          </View>
        );
      })}
    </View>
  );
}
