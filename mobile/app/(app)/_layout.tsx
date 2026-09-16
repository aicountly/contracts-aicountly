import { Redirect, Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useAuth } from '../../src/auth/AuthProvider';
import { useCompany } from '../../src/state/CompanyContext';
import { LoadingState } from '../../src/components';
import { colors } from '../../src/theme/colors';

/**
 * Tab shell for the signed-in, company-selected app. Guards both
 * preconditions, same pattern as books-react-app/mobile's own
 * app/(app)/_layout.tsx.
 *
 * Five tabs collapse Contracts' 14-item, 6-group web sidebar
 * (web/src/config/navigation.ts): Dashboard stays its own tab; Repository +
 * Requests + Amendments become the Contracts tab; the web's "Needs
 * attention" group (Approvals/Obligations/Renewals/Risks/AI review queue)
 * becomes the Attention tab; Reports keeps its own tab; Templates/Clause
 * library/AI insights/Notifications/Settings live under More.
 */
export default function AppTabsLayout() {
  const { status } = useAuth();
  const { status: companyStatus, company } = useCompany();
  const insets = useSafeAreaInsets();

  if (status === 'loading') return <LoadingState />;
  if (status === 'signedOut') return <Redirect href="/login" />;
  if (companyStatus === 'loading') return <LoadingState label="Loading your workspace…" />;
  if (!company) return <Redirect href="/company-picker" />;

  // A fixed pixel height ignores the device's bottom safe-area inset (the
  // iOS home-indicator strip, Android gesture-nav bar).
  const tabBarBottomInset = Math.max(insets.bottom, 8);

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.textMuted,
        tabBarLabelStyle: { fontFamily: 'Nunito_600SemiBold', fontSize: 10 },
        tabBarAllowFontScaling: false,
        tabBarItemStyle: { flex: 1 },
        tabBarStyle: {
          height: 56 + tabBarBottomInset,
          paddingBottom: tabBarBottomInset,
          paddingTop: 6,
          backgroundColor: '#FFFFFF',
          borderTopColor: colors.border,
        },
      }}
    >
      <Tabs.Screen
        name="dashboard"
        options={{ title: 'Dashboard', tabBarIcon: ({ color }) => <Ionicons name="home-outline" size={22} color={color} /> }}
      />
      <Tabs.Screen
        name="contracts"
        options={{ title: 'Contracts', tabBarIcon: ({ color }) => <Ionicons name="document-text-outline" size={22} color={color} /> }}
      />
      <Tabs.Screen
        name="attention"
        options={{ title: 'Attention', tabBarIcon: ({ color }) => <Ionicons name="alert-circle-outline" size={22} color={color} /> }}
      />
      <Tabs.Screen
        name="reports"
        options={{ title: 'Reports', tabBarIcon: ({ color }) => <Ionicons name="bar-chart-outline" size={22} color={color} /> }}
      />
      <Tabs.Screen name="more" options={{ title: 'More', tabBarIcon: ({ color }) => <Ionicons name="grid-outline" size={22} color={color} /> }} />
    </Tabs>
  );
}
