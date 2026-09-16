import { FlatList, Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, StatusBadge, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { formatDate, formatMoney } from '../../../../src/utils/format';
import { useAsync } from '../../../../src/utils/useAsync';
import { listRequests } from '../../../../src/api/endpoints/requests';

export default function RequestsListScreen() {
  const router = useRouter();
  const { data, loading, error, reload } = useAsync(() => listRequests({ perPage: 50 }));

  if (loading && !data) return <LoadingState label="Loading requests…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  const items = data?.items ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12, paddingBottom: 8, flexDirection: 'row', justifyContent: 'flex-end' }}>
        <Pressable
          onPress={() => router.push('/attention/requests/new')}
          style={{ width: 42, height: 42, borderRadius: 10, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.primary }}
        >
          <Ionicons name="add" size={22} color="#FFFFFF" />
        </Pressable>
      </View>
      {items.length === 0 ? (
        <EmptyState icon="file-tray-full-outline" title="No requests" message="New contract requests will show up here." />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/attention/requests/${item.id}`)}>
              <Card style={{ marginBottom: 10 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                    {item.title}
                  </Text>
                  <StatusBadge value={item.status} kind="workflow" />
                </View>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 4 }}>
                  {item.request_number}
                  {item.counterparty_name ? ` · ${item.counterparty_name}` : ''}
                </Text>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 8 }}>
                  {item.estimated_value ? (
                    <Text style={{ fontSize: 12, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>
                      {formatMoney(item.estimated_value, item.currency)}
                    </Text>
                  ) : (
                    <View />
                  )}
                  {item.required_by_date ? (
                    <Text style={{ fontSize: 11, color: colors.textMuted }}>Needed by {formatDate(item.required_by_date)}</Text>
                  ) : null}
                </View>
              </Card>
            </Pressable>
          )}
        />
      )}
    </ScreenContainer>
  );
}
