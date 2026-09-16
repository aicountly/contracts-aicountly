import { useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, PrimaryButton } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAuth } from '../../../../src/auth/AuthProvider';
import { useCompany } from '../../../../src/state/CompanyContext';
import { getAccountDeletionUrl } from '../../../../src/config/env';

export default function AccountScreen() {
  const router = useRouter();
  const { user, signOut } = useAuth();
  const { company, branches, financialYears, boId, fyId } = useCompany();
  const [signingOut, setSigningOut] = useState(false);

  const branchName = branches.find((b) => b.id === boId)?.name;
  const fyLabel = financialYears.find((f) => f.id === fyId)?.label;

  function handleSignOut() {
    Alert.alert('Sign out?', "You'll need to sign in again to use the app.", [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Sign out',
        style: 'destructive',
        onPress: async () => {
          setSigningOut(true);
          try {
            await signOut();
          } finally {
            setSigningOut(false);
          }
        },
      },
    ]);
  }

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <Card style={{ marginBottom: 16, alignItems: 'center', paddingVertical: 24 }}>
          <View style={{ width: 64, height: 64, borderRadius: 32, backgroundColor: colors.primaryLight, alignItems: 'center', justifyContent: 'center', marginBottom: 10 }}>
            <Ionicons name="person" size={30} color={colors.primaryDark} />
          </View>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary }}>{user?.name ?? 'Signed in'}</Text>
          {user?.email ? <Text style={{ fontSize: 13, color: colors.textMuted, marginTop: 2 }}>{user.email}</Text> : null}
        </Card>

        <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 }}>
          Working in
        </Text>
        <Pressable onPress={() => router.push('/company-picker')}>
          <Card style={{ marginBottom: 16, flexDirection: 'row', alignItems: 'center', gap: 12 }}>
            <Ionicons name="business" size={20} color={colors.primaryDark} />
            <View style={{ flex: 1 }}>
              <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 14, color: colors.textPrimary }}>{company?.name ?? 'No company selected'}</Text>
              <Text style={{ fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
                {[branchName, fyLabel].filter(Boolean).join(' · ') || 'Tap to choose a branch and financial year'}
              </Text>
            </View>
            <Ionicons name="chevron-forward" size={16} color={colors.textMuted} />
          </Card>
        </Pressable>

        <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 }}>
          Session
        </Text>
        <View style={{ marginBottom: 16 }}>
          <PrimaryButton label="Sign out" variant="danger" onPress={handleSignOut} loading={signingOut} />
        </View>

        <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 }}>
          Delete your account
        </Text>
        <Card>
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, lineHeight: 19 }}>
            Your AICOUNTLY account is shared across every AICOUNTLY product, not just Contracts, so it&apos;s deleted from the account portal rather than from inside this app.
          </Text>
          <View style={{ marginTop: 12 }}>
            <PrimaryButton label="Delete account" variant="danger" onPress={() => void Linking.openURL(getAccountDeletionUrl())} />
          </View>
        </Card>
      </ScrollView>
    </ScreenContainer>
  );
}
