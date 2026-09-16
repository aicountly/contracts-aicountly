import { useMemo, useState } from 'react';
import { Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, LoadingState, ErrorState, EmptyState } from '../../../src/components';
import { colors } from '../../../src/theme/colors';
import { useAsync } from '../../../src/utils/useAsync';
import { listReportDefinitions } from '../../../src/api/endpoints/reports';
import { humaniseSnakeCase } from '../../../src/utils/format';
import type { ReportDefinition } from '../../../src/types/contracts';

function reportName(d: ReportDefinition): string {
  return d.name ?? d.title ?? humaniseSnakeCase(d.key);
}

function reportGroup(d: ReportDefinition): string {
  return d.group ?? d.category ?? 'Reports';
}

export default function ReportsCatalogScreen() {
  const router = useRouter();
  const { data, loading, error, reload } = useAsync(listReportDefinitions);
  const [query, setQuery] = useState('');

  const groups = useMemo(() => {
    const definitions = data ?? [];
    const needle = query.trim().toLowerCase();
    const matched = needle
      ? definitions.filter((d) => [reportName(d), d.description ?? '', d.key].join(' ').toLowerCase().includes(needle))
      : definitions;

    const byGroup = new Map<string, ReportDefinition[]>();
    for (const d of matched) byGroup.set(reportGroup(d), [...(byGroup.get(reportGroup(d)) ?? []), d]);
    return [...byGroup.entries()];
  }, [data, query]);

  const matchCount = groups.reduce((sum, [, entries]) => sum + entries.length, 0);

  if (loading && !data) return <LoadingState label="Loading report catalogue…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  if ((data ?? []).length === 0) {
    return (
      <ScreenContainer bottomInset={false} style={{ padding: 16 }}>
        <EmptyState
          icon="bar-chart-outline"
          title="No reports are available"
          message="The report catalogue came back empty. Reports appear once there are contracts to report on."
        />
      </ScreenContainer>
    );
  }

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
          placeholder={`Search ${(data ?? []).length} reports…`}
          placeholderTextColor={colors.textMuted}
          style={{ flex: 1, paddingVertical: 10, paddingHorizontal: 8, fontFamily: 'Nunito_400Regular', fontSize: 14, color: colors.textPrimary }}
        />
      </View>

      {matchCount === 0 ? (
        <View style={{ padding: 16 }}>
          <EmptyState icon="search-outline" title="No report matches that" message="Try a shorter word — reports are named for what they answer." />
        </View>
      ) : (
        <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
          {groups.map(([group, entries]) => (
            <View key={group} style={{ marginBottom: 20 }}>
              <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 10 }}>
                {group}
              </Text>
              <View style={{ gap: 10 }}>
                {entries.map((d) => (
                  <Pressable key={d.key} onPress={() => router.push(`/reports/${d.key}`)}>
                    <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
                      <View style={{ width: 36, height: 36, borderRadius: 9, backgroundColor: colors.primaryLight, alignItems: 'center', justifyContent: 'center' }}>
                        <Ionicons name="bar-chart-outline" size={18} color={colors.primaryDark} />
                      </View>
                      <View style={{ flex: 1 }}>
                        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{reportName(d)}</Text>
                        {d.description ? (
                          <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 2 }} numberOfLines={2}>
                            {d.description}
                          </Text>
                        ) : null}
                      </View>
                      <Ionicons name="chevron-forward" size={18} color={colors.textMuted} />
                    </Card>
                  </Pressable>
                ))}
              </View>
            </View>
          ))}
        </ScrollView>
      )}
    </ScreenContainer>
  );
}
