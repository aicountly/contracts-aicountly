import { Stack } from 'expo-router';
import { colors } from '../../../../src/theme/colors';

const headerOptions = {
  headerTintColor: colors.primary,
  headerTitleStyle: { fontFamily: 'Nunito_700Bold' as const },
  headerShadowVisible: false,
  headerStyle: { backgroundColor: '#FFFFFF' },
};

export default function RequestsStackLayout() {
  return (
    <Stack screenOptions={headerOptions}>
      <Stack.Screen name="index" options={{ title: 'Requests' }} />
      <Stack.Screen name="new" options={{ title: 'New Request', presentation: 'modal' }} />
      <Stack.Screen name="[id]" options={{ title: 'Request' }} />
    </Stack>
  );
}
