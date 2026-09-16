import { useEffect, type ReactNode } from 'react';
import { Stack } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { useFonts, Nunito_400Regular, Nunito_500Medium, Nunito_600SemiBold, Nunito_700Bold } from '@expo-google-fonts/nunito';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { StatusBar } from 'expo-status-bar';

import { AuthProvider, useAuth } from '../src/auth/AuthProvider';
import { CompanyProvider } from '../src/state/CompanyContext';
import '../src/theme/global.css';

SplashScreen.preventAutoHideAsync().catch(() => {});

/**
 * Mounts CompanyContext only once signed in. CompanyContext's first fetch
 * needs a valid ses_key, which needs a stored auth_token — mounting it
 * unconditionally at the root would guarantee a failed request (and a stale
 * "error" state with nothing to make it retry) on every cold start before
 * the user has signed in. Scoping it here means it (re)mounts fresh exactly
 * when `status` flips to 'signedIn', so its boot effect always runs with a
 * token already in place.
 */
function CompanyGate({ children }: { children: ReactNode }) {
  const { status } = useAuth();
  if (status !== 'signedIn') return <>{children}</>;
  return <CompanyProvider>{children}</CompanyProvider>;
}

export default function RootLayout() {
  const [fontsLoaded] = useFonts({ Nunito_400Regular, Nunito_500Medium, Nunito_600SemiBold, Nunito_700Bold });

  useEffect(() => {
    if (fontsLoaded) SplashScreen.hideAsync().catch(() => {});
  }, [fontsLoaded]);

  if (!fontsLoaded) return null;

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <AuthProvider>
          <CompanyGate>
            <StatusBar style="dark" />
            <Stack screenOptions={{ headerShown: false }} />
          </CompanyGate>
        </AuthProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
