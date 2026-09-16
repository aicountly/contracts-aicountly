import { Stack } from 'expo-router';
import { colors } from '../../../../src/theme/colors';

const headerOptions = {
  headerTintColor: colors.primary,
  headerTitleStyle: { fontFamily: 'Nunito_700Bold' as const },
  headerShadowVisible: false,
  headerStyle: { backgroundColor: '#FFFFFF' },
};

export default function TemplatesStackLayout() {
  return (
    <Stack screenOptions={headerOptions}>
      <Stack.Screen name="index" options={{ title: 'Templates' }} />
      <Stack.Screen name="new" options={{ title: 'New Template', presentation: 'modal' }} />
      <Stack.Screen name="[id]" options={{ title: 'Template' }} />
    </Stack>
  );
}
