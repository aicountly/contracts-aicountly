import { Pressable, FlatList, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { ScreenContainer, Card, StatusBadge, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { formatDate } from '../../../../src/utils/format';
import { useAsync } from '../../../../src/utils/useAsync';
import { listAmendmentRegister } from '../../../../src/api/endpoints/lifecycle';

export default function AmendmentsRegisterScreen() {
  const router = useRouter();
  const { data, loading, error, reload } = useAsync(() => listAmendmentRegister({ perPage: 50 }));

  if (loading && !data) return <LoadingState label="Loading amendments…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  const items = data?.items ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      {items.length === 0 ? (
        <EmptyState icon="git-branch-outline" title="No amendments" message="Amendments across every contract will show up here." />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/contracts/${item.contract_id}?tab=amendments`)}>
              <Card style={{ marginBottom: 10 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                    #{item.amendment_no} · {item.title}
                  </Text>
                  <StatusBadge value={item.status} kind="workflow" />
                </View>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 4 }}>
                  {item.contract_number}
                  {item.contract_title ? ` · ${item.contract_title}` : ''}
                </Text>
                {item.effective_date ? (
                  <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 4 }}>Effective {formatDate(item.effective_date)}</Text>
                ) : null}
              </Card>
            </Pressable>
          )}
        />
      )}
    </ScreenContainer>
  );
}
