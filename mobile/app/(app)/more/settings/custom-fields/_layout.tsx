import { Stack } from 'expo-router';
import { colors } from '../../../../../src/theme/colors';

const headerOptions = {
  headerTintColor: colors.primary,
  headerTitleStyle: { fontFamily: 'Nunito_700Bold' as const },
  headerShadowVisible: false,
  headerStyle: { backgroundColor: '#FFFFFF' },
};

export default function CustomFieldsStackLayout() {
  return (
    <Stack screenOptions={headerOptions}>
      <Stack.Screen name="index" options={{ title: 'Custom Fields' }} />
      <Stack.Screen name="new" options={{ title: 'New Custom Field', presentation: 'modal' }} />
      <Stack.Screen name="[id]" options={{ title: 'Custom Field' }} />
    </Stack>
  );
}
