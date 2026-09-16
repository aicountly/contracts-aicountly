import { Stack } from 'expo-router';
import { colors } from '../../../../src/theme/colors';

const headerOptions = {
  headerTintColor: colors.primary,
  headerTitleStyle: { fontFamily: 'Nunito_700Bold' as const },
  headerShadowVisible: false,
  headerStyle: { backgroundColor: '#FFFFFF' },
};

export default function ClauseLibraryStackLayout() {
  return (
    <Stack screenOptions={headerOptions}>
      <Stack.Screen name="index" options={{ title: 'Clause Library' }} />
      <Stack.Screen name="new" options={{ title: 'New Clause', presentation: 'modal' }} />
      <Stack.Screen name="[id]" options={{ title: 'Clause' }} />
    </Stack>
  );
}
