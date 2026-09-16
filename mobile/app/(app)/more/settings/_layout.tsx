import { Stack } from 'expo-router';
import { colors } from '../../../../src/theme/colors';

const headerOptions = {
  headerTintColor: colors.primary,
  headerTitleStyle: { fontFamily: 'Nunito_700Bold' as const },
  headerShadowVisible: false,
  headerStyle: { backgroundColor: '#FFFFFF' },
};

export default function SettingsStackLayout() {
  return (
    <Stack screenOptions={headerOptions}>
      <Stack.Screen name="index" options={{ title: 'Settings' }} />
      <Stack.Screen name="general" options={{ title: 'General & Numbering' }} />
      <Stack.Screen name="reminders" options={{ title: 'Reminders' }} />
      <Stack.Screen name="departments" options={{ title: 'Departments' }} />
      <Stack.Screen name="tags" options={{ title: 'Tags' }} />
      <Stack.Screen name="roles" options={{ title: 'Roles & Permissions' }} />
      <Stack.Screen name="workflows" options={{ title: 'Approval Workflows' }} />
      <Stack.Screen name="platform" options={{ title: 'Platform Status' }} />
      <Stack.Screen name="account" options={{ title: 'Account' }} />
    </Stack>
  );
}
