import { Switch, Text, View } from 'react-native';
import { colors } from '../theme/colors';

interface SwitchFieldProps {
  label: string;
  value: boolean;
  onChange: (value: boolean) => void;
  hint?: string | null;
}

export function SwitchField({ label, value, onChange, hint }: SwitchFieldProps) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
      <View style={{ flex: 1, marginRight: 12 }}>
        <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 14, color: colors.textPrimary }}>{label}</Text>
        {hint ? <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>{hint}</Text> : null}
      </View>
      <Switch
        value={value}
        onValueChange={onChange}
        trackColor={{ false: colors.track, true: colors.primaryLight }}
        thumbColor={value ? colors.primary : '#FFFFFF'}
      />
    </View>
  );
}
