import { Stack } from 'expo-router';
import { colors } from '../../../../src/theme/colors';

export default function RenewalsStackLayout() {
  return (
    <Stack
      screenOptions={{
        headerTintColor: colors.primary,
        headerTitleStyle: { fontFamily: 'Nunito_700Bold' },
        headerShadowVisible: false,
        headerStyle: { backgroundColor: '#FFFFFF' },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Renewals' }} />
    </Stack>
  );
}
