import { Redirect } from 'expo-router';
import { useAuth } from '../src/auth/AuthProvider';
import { LoadingState } from '../src/components';

export default function Index() {
  const { status } = useAuth();
  if (status === 'loading') return <LoadingState />;
  if (status === 'signedOut') return <Redirect href="/login" />;
  return <Redirect href="/dashboard" />;
}
