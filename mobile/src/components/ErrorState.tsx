import { Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../theme/colors';
import { PrimaryButton } from './PrimaryButton';

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <Ionicons name="alert-circle-outline" size={40} color={colors.danger} style={{ marginBottom: 12 }} />
      <Text style={{ textAlign: 'center', color: colors.textPrimary, fontFamily: 'Nunito_600SemiBold', marginBottom: 4, fontSize: 15 }}>
        Something went wrong
      </Text>
      <Text style={{ textAlign: 'center', color: colors.textMuted, fontFamily: 'Nunito_400Regular', marginBottom: 16, fontSize: 13 }}>{message}</Text>
      {onRetry ? (
        <View style={{ width: '100%', maxWidth: 220 }}>
          <PrimaryButton label="Try again" onPress={onRetry} variant="secondary" />
        </View>
      ) : null}
    </View>
  );
}
