import { ActivityIndicator, Text, View } from 'react-native';
import { colors } from '../theme/colors';

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <ActivityIndicator color={colors.primary} size="large" />
      <Text style={{ marginTop: 12, color: colors.textMuted, fontFamily: 'Nunito_400Regular' }}>{label}</Text>
    </View>
  );
}
