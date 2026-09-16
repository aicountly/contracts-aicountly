import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';

import { ScreenContainer, LoadingState, ErrorState, EmptyState } from '../../../src/components';
import { ContractCard } from '../../../src/components/contracts/ContractCard';
import { ContractFilterSheet } from '../../../src/components/contracts/ContractFilterSheet';
import { listContracts, setContractFavourite } from '../../../src/api/endpoints/contracts';
import { listContractTypes, listDepartments, listTags } from '../../../src/api/endpoints/settings';
import { EMPTY_CONTRACT_FILTERS } from '../../../src/types/contracts';
import type { ContractFilters, ContractListItem, ContractTypeSummary, DepartmentSummary, TagSummary } from '../../../src/types/contracts';
import { colors } from '../../../src/theme/colors';
import { ApiError } from '../../../src/api/errors';

const PER_PAGE = 20;

function countActiveFilters(filters: ContractFilters): number {
  let count = 0;
  for (const [key, value] of Object.entries(filters)) {
    if (key === 'archived') {
      if (value !== 'no') count += 1;
      continue;
    }
    if (Array.isArray(value)) {
      if (value.length > 0) count += 1;
    } else if (typeof value === 'boolean') {
      if (value) count += 1;
    } else if (value) {
      count += 1;
    }
  }
  return count;
}

export default function ContractsScreen() {
  const router = useRouter();
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<ContractFilters>(EMPTY_CONTRACT_FILTERS);
  const [filterSheetOpen, setFilterSheetOpen] = useState(false);
  const [items, setItems] = useState<ContractListItem[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [contractTypes, setContractTypes] = useState<ContractTypeSummary[]>([]);
  const [departments, setDepartments] = useState<DepartmentSummary[]>([]);
  const [tags, setTags] = useState<TagSummary[]>([]);

  useEffect(() => {
    listContractTypes().then(setContractTypes).catch(() => {});
    listDepartments().then(setDepartments).catch(() => {});
    listTags().then(setTags).catch(() => {});
  }, []);

  const load = useCallback(
    async (targetPage: number, mode: 'replace' | 'append' | 'refresh') => {
      if (mode === 'replace') setLoading(true);
      if (mode === 'append') setLoadingMore(true);
      if (mode === 'refresh') setRefreshing(true);
      setError(null);
      try {
        const result = await listContracts({
          filters: { ...filters, q: search },
          sort: { key: 'updated_at', dir: 'desc' },
          page: targetPage,
          perPage: PER_PAGE,
        });
        setItems((prev) => (mode === 'append' ? [...prev, ...result.items] : result.items));
        setPage(result.page);
        setTotalPages(result.total_pages);
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'Could not load contracts.');
      } finally {
        setLoading(false);
        setLoadingMore(false);
        setRefreshing(false);
      }
    },
    [filters, search],
  );

  useEffect(() => {
    const timer = setTimeout(() => void load(1, 'replace'), 300);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters, search]);

  const activeFilterCount = countActiveFilters(filters);

  async function handleToggleFavourite(item: ContractListItem) {
    const next = !item.is_favourite;
    setItems((prev) => prev.map((c) => (c.id === item.id ? { ...c, is_favourite: next } : c)));
    try {
      await setContractFavourite(item.id, next);
    } catch {
      setItems((prev) => prev.map((c) => (c.id === item.id ? { ...c, is_favourite: !next } : c)));
    }
  }

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12, paddingBottom: 8 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10 }}>
          <View
            style={{
              flex: 1,
              flexDirection: 'row',
              alignItems: 'center',
              backgroundColor: colors.surface,
              borderRadius: 10,
              paddingHorizontal: 12,
              borderWidth: 1,
              borderColor: colors.border,
            }}
          >
            <Ionicons name="search" size={16} color={colors.textMuted} />
            <TextInput
              value={search}
              onChangeText={setSearch}
              placeholder="Search contracts…"
              placeholderTextColor={colors.textMuted}
              style={{ flex: 1, paddingVertical: 10, paddingHorizontal: 8, fontSize: 14, fontFamily: 'Nunito_400Regular', color: colors.textPrimary }}
            />
          </View>
          <Pressable
            onPress={() => setFilterSheetOpen(true)}
            style={{
              width: 42,
              height: 42,
              borderRadius: 10,
              alignItems: 'center',
              justifyContent: 'center',
              backgroundColor: activeFilterCount > 0 ? colors.primaryLight : colors.surface,
              borderWidth: 1,
              borderColor: activeFilterCount > 0 ? colors.primary : colors.border,
            }}
          >
            <Ionicons name="options-outline" size={20} color={activeFilterCount > 0 ? colors.primary : colors.textSecondary} />
          </Pressable>
          <Pressable
            onPress={() => router.push('/contracts/new')}
            style={{ width: 42, height: 42, borderRadius: 10, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.primary }}
          >
            <Ionicons name="add" size={22} color="#FFFFFF" />
          </Pressable>
        </View>
      </View>

      {loading && items.length === 0 ? (
        <LoadingState label="Loading contracts…" />
      ) : error && items.length === 0 ? (
        <ErrorState message={error} onRetry={() => load(1, 'replace')} />
      ) : items.length === 0 ? (
        <EmptyState icon="document-text-outline" title="No contracts found" message="Try adjusting your search or filters." />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => (
            <ContractCard
              contract={item}
              onPress={() => router.push(`/contracts/${item.id}`)}
              onToggleFavourite={() => handleToggleFavourite(item)}
            />
          )}
          refreshing={refreshing}
          onRefresh={() => load(1, 'refresh')}
          onEndReachedThreshold={0.4}
          onEndReached={() => {
            if (!loadingMore && page < totalPages) void load(page + 1, 'append');
          }}
          ListFooterComponent={loadingMore ? <ActivityIndicator color={colors.primary} style={{ marginVertical: 16 }} /> : null}
        />
      )}

      <ContractFilterSheet
        visible={filterSheetOpen}
        onClose={() => setFilterSheetOpen(false)}
        filters={filters}
        onApply={setFilters}
        contractTypes={contractTypes}
        departments={departments}
        tags={tags}
      />
    </ScreenContainer>
  );
}
