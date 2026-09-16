import { useEffect, useState } from 'react';
import { FlatList, Pressable, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, StatusBadge, BottomSheet, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { listClauseCategories, listClauses } from '../../../../src/api/endpoints/clauseLibrary';

export default function ClauseLibraryListScreen() {
  const router = useRouter();
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');
  const [categoryId, setCategoryId] = useState<number | null>(null);
  const [categoriesOpen, setCategoriesOpen] = useState(false);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(query.trim()), 300);
    return () => clearTimeout(timer);
  }, [query]);

  const categories = useAsync(listClauseCategories);
  const { data, loading, error, reload } = useAsync(
    () => listClauses({ q: debounced, categoryId, perPage: 50 }),
    [debounced, categoryId],
  );

  const items = data?.items ?? [];
  const activeCategory = (categories.data ?? []).find((c) => c.id === categoryId) ?? null;

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ flexDirection: 'row', gap: 10, marginHorizontal: 16, marginTop: 12 }}>
        <View
          style={{
            flex: 1,
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
            placeholder="Liability, confidentiality, termination…"
            placeholderTextColor={colors.textMuted}
            style={{ flex: 1, paddingVertical: 10, paddingHorizontal: 8, fontFamily: 'Nunito_400Regular', fontSize: 14, color: colors.textPrimary }}
          />
        </View>
        <Pressable
          onPress={() => router.push('/more/clause-library/new')}
          style={{ width: 42, height: 42, borderRadius: 10, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.primary }}
        >
          <Ionicons name="add" size={22} color="#FFFFFF" />
        </Pressable>
      </View>

      <Pressable
        onPress={() => setCategoriesOpen(true)}
        style={{
          flexDirection: 'row',
          alignItems: 'center',
          gap: 6,
          alignSelf: 'flex-start',
          marginHorizontal: 16,
          marginTop: 10,
          paddingHorizontal: 12,
          paddingVertical: 7,
          borderRadius: 999,
          backgroundColor: activeCategory ? colors.primaryLight : colors.surface,
          borderWidth: 1,
          borderColor: activeCategory ? colors.primary : colors.border,
        }}
      >
        <Ionicons name="folder-outline" size={14} color={activeCategory ? colors.primaryDark : colors.textSecondary} />
        <Text style={{ fontSize: 12.5, fontFamily: 'Nunito_600SemiBold', color: activeCategory ? colors.primaryDark : colors.textSecondary }}>
          {activeCategory ? activeCategory.name : 'All categories'}
        </Text>
        <Ionicons name="chevron-down" size={13} color={activeCategory ? colors.primaryDark : colors.textMuted} />
      </Pressable>

      {loading && !data ? (
        <LoadingState label="Searching the library…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState
          icon="library-outline"
          title={debounced || categoryId ? 'Nothing here matches' : 'The library is empty'}
          message={
            debounced || categoryId
              ? 'Try a shorter search, or look in another category.'
              : 'A library clause is wording your company has agreed to stand behind.'
          }
        />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 12 }}
          renderItem={({ item }) => (
            <Pressable
              onPress={() =>
                router.push({ pathname: '/more/clause-library/[id]', params: { id: String(item.id), clause: JSON.stringify(item) } })
              }
            >
              <Card style={{ marginBottom: 10 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                    {item.name}
                  </Text>
                </View>
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 7 }}>
                  {item.category_name ? (
                    <View style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 }}>
                      <Text style={{ fontSize: 11, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>{item.category_name}</Text>
                    </View>
                  ) : null}
                  <StatusBadge value={item.approval_status} />
                  <StatusBadge value={item.risk_classification} kind="risk" />
                  {item.jurisdiction ? (
                    <View style={{ backgroundColor: colors.infoSoft, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 }}>
                      <Text style={{ fontSize: 11, color: colors.info, fontFamily: 'Nunito_600SemiBold' }}>{item.jurisdiction}</Text>
                    </View>
                  ) : null}
                </View>
                {item.description ? (
                  <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 8 }} numberOfLines={2}>
                    {item.description}
                  </Text>
                ) : null}
                <View style={{ backgroundColor: colors.surface, borderRadius: 8, padding: 10, marginTop: 8 }}>
                  <Text style={{ fontSize: 12, color: colors.textPrimary, lineHeight: 18 }} numberOfLines={3}>
                    {item.standard_text}
                  </Text>
                </View>
                <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 8 }}>
                  Version {item.version}
                  {item.fallback_text ? ' · Fallback wording on file' : ''}
                </Text>
              </Card>
            </Pressable>
          )}
        />
      )}

      <BottomSheet visible={categoriesOpen} onClose={() => setCategoriesOpen(false)}>
        <View style={{ padding: 16, borderBottomWidth: 1, borderBottomColor: colors.borderSoft }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary }}>Categories</Text>
        </View>
        <FlatList
          data={[{ id: null as number | null, name: 'All clauses', clause_count: null as number | null }, ...(categories.data ?? [])]}
          keyExtractor={(item) => String(item.id ?? 'all')}
          renderItem={({ item }) => (
            <Pressable
              onPress={() => {
                setCategoryId(item.id);
                setCategoriesOpen(false);
              }}
              style={{
                paddingHorizontal: 16,
                paddingVertical: 14,
                flexDirection: 'row',
                justifyContent: 'space-between',
                alignItems: 'center',
                borderBottomWidth: 1,
                borderBottomColor: colors.borderSoft,
              }}
            >
              <Text style={{ fontSize: 15, color: colors.textPrimary, fontFamily: item.id === categoryId ? 'Nunito_700Bold' : 'Nunito_400Regular' }}>{item.name}</Text>
              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
                {item.clause_count != null ? <Text style={{ fontSize: 12, color: colors.textMuted }}>{item.clause_count}</Text> : null}
                {item.id === categoryId ? <Ionicons name="checkmark" size={18} color={colors.primary} /> : null}
              </View>
            </Pressable>
          )}
        />
      </BottomSheet>
    </ScreenContainer>
  );
}
