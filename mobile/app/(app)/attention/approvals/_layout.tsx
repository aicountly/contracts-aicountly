import { Stack } from 'expo-router';
import { colors } from '../../../../src/theme/colors';

const headerOptions = {
  headerTintColor: colors.primary,
  headerTitleStyle: { fontFamily: 'Nunito_700Bold' as const },
  headerShadowVisible: false,
  headerStyle: { backgroundColor: '#FFFFFF' },
};

export default function ApprovalsStackLayout() {
  return (
    <Stack screenOptions={headerOptions}>
      <Stack.Screen name="index" options={{ title: 'Approvals' }} />
      <Stack.Screen name="workflows" options={{ title: 'Approval Workflows' }} />
    </Stack>
  );
}
