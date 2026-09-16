import { FlatList, Pressable, Text, View } from 'react-native';
import { Redirect } from 'expo-router';
import { useAuth } from '../src/auth/AuthProvider';
import { useCompany } from '../src/state/CompanyContext';
import { ScreenContainer, LoadingState, ErrorState, EmptyState, Card } from '../src/components';
import { colors } from '../src/theme/colors';

export default function CompanyPickerScreen() {
  const { status: authStatus } = useAuth();
  const { status, error, companies, company, selectCompany, reload } = useCompany();

  if (authStatus !== 'signedIn') return <Redirect href="/login" />;
  if (status === 'ready' && company) return <Redirect href="/dashboard" />;
  if (status === 'loading') return <LoadingState label="Loading your companies…" />;
  if (status === 'error') return <ErrorState message={error ?? 'Could not load your companies.'} onRetry={reload} />;
  if (status === 'empty') {
    return (
      <ScreenContainer>
        <EmptyState
          icon="business-outline"
          title="No companies yet"
          message="Your AICOUNTLY account isn't linked to a company. Set one up in Manage, then reload."
        />
      </ScreenContainer>
    );
  }

  return (
    <ScreenContainer>
      <View style={{ paddingHorizontal: 20, paddingTop: 12, paddingBottom: 8 }}>
        <Text style={{ fontSize: 20, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>Choose a company</Text>
      </View>
      <FlatList
        data={companies}
        keyExtractor={(item) => item.cmp_id}
        contentContainerStyle={{ padding: 20, gap: 10 }}
        renderItem={({ item }) => (
          <Pressable onPress={() => selectCompany(item.cmp_id)}>
            <Card>
              <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 15, color: colors.textPrimary }}>{item.name}</Text>
              {item.legal_name && item.legal_name !== item.name ? (
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>{item.legal_name}</Text>
              ) : null}
            </Card>
          </Pressable>
        )}
      />
    </ScreenContainer>
  );
}
