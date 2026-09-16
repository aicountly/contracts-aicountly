import { FlatList, Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, LoadingState, ErrorState, EmptyState } from '../../../../../src/components';
import { colors } from '../../../../../src/theme/colors';
import { useAsync } from '../../../../../src/utils/useAsync';
import { listContractTypeRows } from '../../../../../src/api/endpoints/settings';

export default function ContractTypesListScreen() {
  const router = useRouter();
  const { data, loading, error, reload } = useAsync(listContractTypeRows);
  const rows = data ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12, paddingBottom: 8, alignItems: 'flex-end' }}>
        <Pressable
          onPress={() => router.push('/more/settings/contract-types/new')}
          style={{ flexDirection: 'row', alignItems: 'center', gap: 6, backgroundColor: colors.primary, borderRadius: 10, paddingHorizontal: 14, paddingVertical: 10 }}
        >
          <Ionicons name="add" size={16} color="#FFFFFF" />
          <Text style={{ color: '#FFFFFF', fontFamily: 'Nunito_700Bold', fontSize: 13 }}>New type</Text>
        </Pressable>
      </View>

      {loading && !data ? (
        <LoadingState label="Loading contract types…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : rows.length === 0 ? (
        <EmptyState icon="shapes-outline" title="No contract types yet" />
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/more/settings/contract-types/${item.id}`)}>
              <Card style={{ marginBottom: 10, opacity: item.is_active ? 1 : 0.6 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={1}>
                    {item.name}
                  </Text>
                  <Text style={{ fontSize: 11.5, color: colors.textMuted }}>{item.code}</Text>
                </View>
                <Text style={{ fontSize: 12, color: colors.textSecondary, marginTop: 4 }}>
                  {item.category} · {item.counterparty_side}
                  {item.is_system ? ' · System' : ''}
                  {!item.is_active ? ' · Inactive' : ''}
                </Text>
              </Card>
            </Pressable>
          )}
        />
      )}
    </ScreenContainer>
  );
}
