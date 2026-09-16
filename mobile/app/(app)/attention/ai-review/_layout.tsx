import { Stack } from 'expo-router';
import { colors } from '../../../../src/theme/colors';

export default function AiReviewStackLayout() {
  return (
    <Stack
      screenOptions={{
        headerTintColor: colors.primary,
        headerTitleStyle: { fontFamily: 'Nunito_700Bold' },
        headerShadowVisible: false,
        headerStyle: { backgroundColor: '#FFFFFF' },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'AI Review Queue' }} />
    </Stack>
  );
}
