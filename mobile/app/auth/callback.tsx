import { useEffect } from 'react';
import { router } from 'expo-router';
import { LoadingState } from '../../src/components';

export default function AuthCallbackScreen() {
  useEffect(() => {
    // startSsoFlow's openAuthSessionAsync normally intercepts this redirect
    // before Expo Router ever sees it. Reaching this screen means the OS
    // routed the deep link straight to the app instead — bounce back to the
    // root so AuthProvider's current state decides where to go next.
    const timer = setTimeout(() => router.replace('/'), 50);
    return () => clearTimeout(timer);
  }, []);

  return <LoadingState label="Finishing sign-in…" />;
}
