import { useEffect, useState } from 'react';
import { FlatList, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, StatusBadge, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { formatDateTime, humaniseSnakeCase } from '../../../../src/utils/format';
import { useAsync } from '../../../../src/utils/useAsync';
import { listTemplates } from '../../../../src/api/endpoints/templates';
import { TEMPLATE_STATUSES } from '../../../../src/types/contracts';

export default function TemplatesListScreen() {
  const router = useRouter();
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');
  const [status, setStatus] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(query.trim()), 300);
    return () => clearTimeout(timer);
  }, [query]);

  const { data, loading, error, reload } = useAsync(
    () => listTemplates({ q: debounced, status: status ?? undefined, perPage: 50 }),
    [debounced, status],
  );
  const items = data?.items ?? [];
  const filtered = debounced !== '' || status !== null;

  return (
    <ScreenContainer bottomInset={false}>
      <View
        style={{
          marginHorizontal: 16,
          marginTop: 12,
          borderWidth: 1,
          borderColor: colors.border,
          borderRadius: 10,
          paddingHorizontal: 12,
          flexDirection: 'row',
          alignItems: 'center',
          backgroundColor: '#FFFFFF',
        }}
      >
        <Ionicons name="search" size={16} color={colors.textMuted} />
        <TextInput
          value={query}
          onChangeText={setQuery}
          placeholder="Search templates"
          placeholderTextColor={colors.textMuted}
          style={{ flex: 1, paddingVertical: 10, paddingHorizontal: 8, fontFamily: 'Nunito_400Regular', fontSize: 14, color: colors.textPrimary }}
        />
      </View>

      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={{ flexGrow: 0, paddingVertical: 10 }}
        contentContainerStyle={{ paddingHorizontal: 16, gap: 8 }}
      >
        {([null, ...TEMPLATE_STATUSES] as (string | null)[]).map((s) => {
          const active = status === s;
          return (
            <Text
              key={s ?? 'all'}
              onPress={() => setStatus(s)}
              style={{
                paddingHorizontal: 12,
                paddingVertical: 7,
                borderRadius: 999,
                backgroundColor: active ? colors.primary : colors.surface,
                borderWidth: 1,
                borderColor: active ? colors.primary : colors.border,
                color: active ? '#FFFFFF' : colors.textSecondary,
                fontFamily: 'Nunito_600SemiBold',
                fontSize: 12,
              }}
            >
              {s ? humaniseSnakeCase(s) : 'All'}
            </Text>
          );
        })}
        <Pressable
          onPress={() => router.push('/more/templates/new')}
          style={{ paddingHorizontal: 12, paddingVertical: 7, borderRadius: 999, backgroundColor: colors.primaryLight, flexDirection: 'row', alignItems: 'center', gap: 4 }}
        >
          <Ionicons name="add" size={14} color={colors.primaryDark} />
          <Text style={{ color: colors.primaryDark, fontFamily: 'Nunito_700Bold', fontSize: 12 }}>New template</Text>
        </Pressable>
      </ScrollView>

      {loading && !data ? (
        <LoadingState label="Loading templates…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState
          icon="documents-outline"
          title={filtered ? 'No template matches those filters' : 'No templates yet'}
          message="A template holds the standing wording of an agreement with merge variables where the contract's own values belong."
        />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <Pressable onPress={() => router.push(`/more/templates/${item.id}`)}>
              <Card style={{ marginBottom: 10 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                    {item.name}
                  </Text>
                  <StatusBadge value={item.status} />
                </View>
                {item.description ? (
                  <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 4 }} numberOfLines={2}>
                    {item.description}
                  </Text>
                ) : null}
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 8 }}>
                  <Text style={{ fontSize: 11.5, color: colors.textMuted }}>
                    {item.contract_type_name ?? 'Any type'} · {item.variables?.length ?? 0} variables
                  </Text>
                  <Text style={{ fontSize: 11, color: colors.textMuted }}>Updated {formatDateTime(item.updated_at)}</Text>
                </View>
              </Card>
            </Pressable>
          )}
        />
      )}
    </ScreenContainer>
  );
}
