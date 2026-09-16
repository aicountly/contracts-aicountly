import { Text, View } from 'react-native';
import { colors, cardShadow } from '../theme/colors';

interface StatTileProps {
  label: string;
  value: string | number | null | undefined;
  hint?: string | null;
  tone?: 'default' | 'danger' | 'warning' | 'success';
}

const TONE_COLOR: Record<string, string> = {
  default: colors.textPrimary,
  danger: colors.danger,
  warning: colors.warning,
  success: colors.success,
};

export function StatTile({ label, value, hint, tone = 'default' }: StatTileProps) {
  return (
    <View
      style={{
        flexBasis: '48%',
        flexGrow: 1,
        backgroundColor: '#FFFFFF',
        borderRadius: 14,
        padding: 14,
        borderWidth: 1,
        borderColor: colors.borderSoft,
        ...cardShadow,
      }}
    >
      <Text style={{ fontSize: 12, fontFamily: 'Nunito_600SemiBold', color: colors.textMuted, marginBottom: 6 }} numberOfLines={1}>
        {label}
      </Text>
      <Text style={{ fontSize: 20, fontFamily: 'Nunito_700Bold', color: TONE_COLOR[tone] }}>{value === null || value === undefined ? '—' : value}</Text>
      {hint ? <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 2 }}>{hint}</Text> : null}
    </View>
  );
}
