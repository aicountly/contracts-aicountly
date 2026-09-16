import { Stack } from 'expo-router';
import { colors } from '../../../../../src/theme/colors';

const headerOptions = {
  headerTintColor: colors.primary,
  headerTitleStyle: { fontFamily: 'Nunito_700Bold' as const },
  headerShadowVisible: false,
  headerStyle: { backgroundColor: '#FFFFFF' },
};

export default function RiskRulesStackLayout() {
  return (
    <Stack screenOptions={headerOptions}>
      <Stack.Screen name="index" options={{ title: 'Risk Rules' }} />
      <Stack.Screen name="new" options={{ title: 'New Risk Rule', presentation: 'modal' }} />
      <Stack.Screen name="[id]" options={{ title: 'Risk Rule' }} />
    </Stack>
  );
}
