import { useMemo, useState } from 'react';
import { Alert, Pressable, ScrollView, Text, View } from 'react-native';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { File, Paths } from 'expo-file-system';
import * as Sharing from 'expo-sharing';
import { ScreenContainer, Card, StatTile, StatusBadge, FormField, SelectField, DateField, PrimaryButton, LoadingState, ErrorState, EmptyState } from '../../../src/components';
import { colors } from '../../../src/theme/colors';
import { useAsync } from '../../../src/utils/useAsync';
import { listReportDefinitions, runReport, exportReportCsv } from '../../../src/api/endpoints/reports';
import { listContractTypes, listDepartments } from '../../../src/api/endpoints/settings';
import { ApiError } from '../../../src/api/errors';
import { formatDate, formatDateTime, formatMoney, formatNumber, humaniseSnakeCase } from '../../../src/utils/format';
import {
  CONTRACT_STATUSES,
  RISK_LEVELS,
  REPORT_FILTER_KEYS,
  type ContractTypeSummary,
  type DepartmentSummary,
  type ReportDefinition,
  type ReportFilterKey,
  type ReportFilterValues,
  type ReportColumnDefinition,
  type ReportColumnsPayload,
  type ReportRow,
} from '../../../src/types/contracts';

const DEFAULT_FILTERS: ReportFilterKey[] = ['status', 'date_from', 'date_to'];

function reportName(d: ReportDefinition): string {
  return d.name ?? d.title ?? humaniseSnakeCase(d.key);
}

function normaliseColumns(payload?: ReportColumnsPayload | null): ReportColumnDefinition[] {
  if (!payload) return [];
  if (Array.isArray(payload)) return payload.map((e) => (typeof e === 'string' ? { key: e, label: humaniseSnakeCase(e) } : e));
  return Object.entries(payload).map(([key, label]) => ({ key, label }));
}

function columnsFromRows(rows: ReportRow[]): ReportColumnDefinition[] {
  const first = rows[0];
  if (!first) return [];
  return Object.keys(first).map((key) => ({ key, label: humaniseSnakeCase(key) }));
}

function columnLabel(c: ReportColumnDefinition): string {
  return c.label ?? c.header ?? humaniseSnakeCase(c.key);
}

function isNumericType(t?: string | null): boolean {
  return t === 'number' || t === 'money' || t === 'percent';
}

function toComparable(value: unknown): number | string | null {
  if (value === null || value === undefined || value === '') return null;
  if (typeof value === 'number') return value;
  if (typeof value === 'boolean') return value ? 1 : 0;
  const text = String(value);
  if (/^-?\d+(\.\d+)?$/.test(text)) return Number(text);
  return text.toLowerCase();
}

function currencyFor(row: ReportRow, column: ReportColumnDefinition): string {
  const key = column.currency_key ?? 'currency';
  const value = row[key];
  return typeof value === 'string' && value.length === 3 ? value : 'INR';
}

/** Keyed by report key at the route boundary — mirrors web's `<ReportView key={definition.key} />` — so filters/sort/page reset cleanly when navigating between reports. */
export default function ReportRouteScreen() {
  const { key } = useLocalSearchParams<{ key: string }>();
  return <ReportView reportKey={key} />;
}

function ReportView({ reportKey }: { reportKey: string }) {
  const router = useRouter();
  const catalogue = useAsync(listReportDefinitions);
  const definition = (catalogue.data ?? []).find((d) => d.key === reportKey) ?? null;

  const [filters, setFilters] = useState<ReportFilterValues>({});
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | null>(null);
  const [exporting, setExporting] = useState(false);

  const filterKeys = useMemo<ReportFilterKey[]>(() => {
    const declared = definition?.filters;
    if (!declared || declared.length === 0) return DEFAULT_FILTERS;
    return REPORT_FILTER_KEYS.filter((k) => declared.includes(k));
  }, [definition]);

  function setFilter(k: ReportFilterKey, v: string) {
    setFilters((f) => {
      const next = { ...f };
      if (v === '') delete next[k];
      else next[k] = v;
      return next;
    });
    setPage(1);
  }

  const needsTypes = filterKeys.includes('contract_type_id');
  const needsDepartments = filterKeys.includes('department_id');
  const types = useAsync(() => (needsTypes ? listContractTypes() : Promise.resolve([])), [needsTypes]);
  const departments = useAsync(() => (needsDepartments ? listDepartments() : Promise.resolve([])), [needsDepartments]);

  const filterSignature = JSON.stringify(filters);
  const result = useAsync(
    () => (definition ? runReport(definition.key, { filters, page, perPage: 50 }) : Promise.resolve(null)),
    [definition?.key, filterSignature, page],
  );

  const rawRows = useMemo<ReportRow[]>(() => result.data?.rows ?? result.data?.items ?? [], [result.data]);
  const columns = useMemo<ReportColumnDefinition[]>(() => {
    const fromResult = normaliseColumns(result.data?.columns);
    if (fromResult.length > 0) return fromResult;
    const fromDefinition = normaliseColumns(definition?.columns);
    if (fromDefinition.length > 0) return fromDefinition;
    return columnsFromRows(rawRows);
  }, [result.data, definition, rawRows]);

  const rows = useMemo<ReportRow[]>(() => {
    if (!sort) return rawRows;
    const column = columns.find((c) => c.key === sort.key);
    const numeric = isNumericType(column?.type);
    const dir = sort.dir === 'asc' ? 1 : -1;
    return [...rawRows].sort((a, b) => {
      const left = toComparable(a[sort.key]);
      const right = toComparable(b[sort.key]);
      if (left === null && right === null) return 0;
      if (left === null) return 1;
      if (right === null) return -1;
      if (numeric || (typeof left === 'number' && typeof right === 'number')) return (Number(left) - Number(right)) * dir;
      return String(left).localeCompare(String(right)) * dir;
    });
  }, [rawRows, sort, columns]);

  const total = result.data?.total ?? rawRows.length;
  const summary = result.data?.summary ?? null;
  const isFiltered = Object.keys(filters).length > 0;

  async function handleExport() {
    if (!definition) return;
    setExporting(true);
    try {
      const csv = await exportReportCsv(definition.key, filters);
      const filename = `report-${definition.key}-${new Date().toISOString().slice(0, 10)}.csv`;
      const file = new File(Paths.cache, filename);
      file.write(csv);
      if (await Sharing.isAvailableAsync()) {
        await Sharing.shareAsync(file.uri, { mimeType: 'text/csv', dialogTitle: reportName(definition) });
      } else {
        Alert.alert('Export ready', `Saved to ${file.uri}`);
      }
    } catch (err) {
      Alert.alert('Export failed', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setExporting(false);
    }
  }

  if (catalogue.loading && !catalogue.data) return <LoadingState label="Loading report…" />;
  if (catalogue.error && !catalogue.data) return <ErrorState message={catalogue.error} onRetry={catalogue.reload} />;

  if (!definition) {
    return (
      <ScreenContainer bottomInset={false} style={{ padding: 16 }}>
        <EmptyState
          icon="bar-chart-outline"
          title="No such report"
          message="It may have been renamed, or the link may be from an older version of the app."
          action={<PrimaryButton label="Browse all reports" variant="secondary" onPress={() => router.replace('/reports')} />}
        />
      </ScreenContainer>
    );
  }

  const sortOptions = [
    { value: '', label: 'Report order (default)' },
    ...columns.flatMap((c) => [
      { value: `${c.key}:asc`, label: `${columnLabel(c)} (A–Z)` },
      { value: `${c.key}:desc`, label: `${columnLabel(c)} (Z–A)` },
    ]),
  ];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        {definition.description ? (
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginBottom: 14, lineHeight: 18 }}>{definition.description}</Text>
        ) : null}

        {filterKeys.length > 0 ? (
          <Card style={{ marginBottom: 14 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary }}>Filters</Text>
              {isFiltered ? (
                <Pressable
                  onPress={() => {
                    setFilters({});
                    setPage(1);
                  }}
                >
                  <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>Clear</Text>
                </Pressable>
              ) : null}
            </View>
            {filterKeys.map((k) => (
              <ReportFilterControl
                key={k}
                filterKey={k}
                value={filters[k] ?? ''}
                onChange={(v) => setFilter(k, v)}
                types={types.data ?? []}
                departments={departments.data ?? []}
              />
            ))}
          </Card>
        ) : null}

        {summary ? <ReportSummaryStrip summary={summary} /> : null}

        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 10 }}>
          <Text style={{ fontSize: 12.5, color: colors.textMuted }}>{result.loading ? 'Running…' : `${total} ${total === 1 ? 'row' : 'rows'}`}</Text>
          <PrimaryButton label="Export CSV" variant="secondary" onPress={() => void handleExport()} loading={exporting} />
        </View>

        {columns.length > 1 ? (
          <View style={{ marginBottom: 4 }}>
            <SelectField
              label="Sort by"
              value={sort ? `${sort.key}:${sort.dir}` : ''}
              options={sortOptions}
              onChange={(v) => {
                if (!v) {
                  setSort(null);
                  return;
                }
                const [k, dir] = v.split(':');
                setSort({ key: k, dir: dir as 'asc' | 'desc' });
              }}
            />
          </View>
        ) : null}

        {result.error ? (
          <ErrorState message={result.error} onRetry={result.reload} />
        ) : !result.loading && rows.length === 0 ? (
          <EmptyState
            icon="document-text-outline"
            title={isFiltered ? 'Nothing matches these filters' : 'This report has nothing to show'}
            message={isFiltered ? 'Widen the dates, or clear a filter and start again.' : 'It will fill as contracts are added.'}
            action={isFiltered ? <PrimaryButton label="Clear filters" variant="secondary" onPress={() => setFilters({})} /> : undefined}
          />
        ) : (
          <View style={{ gap: 10 }}>
            {rows.map((row, i) => (
              <ReportRowCard key={i} row={row} columns={columns} />
            ))}
          </View>
        )}

        {total > rawRows.length ? (
          <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 10 }}>Sorting orders the rows on this page. Export the CSV to sort the whole report.</Text>
        ) : null}

        {rawRows.length === 50 || page > 1 ? (
          <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginTop: 16 }}>
            <PrimaryButton label="Previous" variant="secondary" disabled={page <= 1} onPress={() => setPage((p) => Math.max(1, p - 1))} />
            <Text style={{ fontSize: 12.5, color: colors.textMuted }}>Page {page}</Text>
            <PrimaryButton label="Next" variant="secondary" disabled={rawRows.length < 50} onPress={() => setPage((p) => p + 1)} />
          </View>
        ) : null}
      </ScrollView>
    </ScreenContainer>
  );
}

function ReportFilterControl({
  filterKey,
  value,
  onChange,
  types,
  departments,
}: {
  filterKey: ReportFilterKey;
  value: string;
  onChange: (v: string) => void;
  types: ContractTypeSummary[];
  departments: DepartmentSummary[];
}) {
  switch (filterKey) {
    case 'status':
      return (
        <SelectField
          label="Status"
          value={value}
          options={[{ value: '', label: 'Any status' }, ...CONTRACT_STATUSES.map((s) => ({ value: s, label: humaniseSnakeCase(s) }))]}
          onChange={onChange}
        />
      );
    case 'risk_level':
      return (
        <SelectField
          label="Risk level"
          value={value}
          options={[{ value: '', label: 'Any risk level' }, ...RISK_LEVELS.map((l) => ({ value: l, label: humaniseSnakeCase(l) }))]}
          onChange={onChange}
        />
      );
    case 'contract_type_id':
      return (
        <SelectField
          label="Contract type"
          value={value}
          options={[{ value: '', label: 'Any type' }, ...types.map((t) => ({ value: String(t.id), label: t.name }))]}
          onChange={onChange}
        />
      );
    case 'department_id':
      return (
        <SelectField
          label="Department"
          value={value}
          options={[{ value: '', label: 'Any department' }, ...departments.map((d) => ({ value: String(d.id), label: d.name }))]}
          onChange={onChange}
        />
      );
    case 'counterparty':
      return <FormField label="Counterparty" value={value} onChangeText={onChange} placeholder="Name contains…" />;
    case 'owner_uuid':
      return <FormField label="Owner" value={value} onChangeText={onChange} placeholder="User id" hint="The owner's AICOUNTLY user id" />;
    case 'date_from':
      return <DateField label="From" value={value || null} onChange={(v) => onChange(v ?? '')} />;
    case 'date_to':
      return <DateField label="To" value={value || null} onChange={(v) => onChange(v ?? '')} />;
    default:
      return null;
  }
}

function ReportSummaryStrip({ summary }: { summary: Record<string, unknown> }) {
  const tiles = Object.entries(summary)
    .map(([key, raw]) => {
      if (raw !== null && typeof raw === 'object' && !Array.isArray(raw)) {
        const entry = raw as { label?: string; value?: unknown; format?: string; currency?: string };
        return { key, label: entry.label ?? humaniseSnakeCase(key), value: formatSummaryValue(entry.value, entry.format, entry.currency) };
      }
      if (raw === null || raw === undefined || Array.isArray(raw)) return null;
      return { key, label: humaniseSnakeCase(key), value: formatSummaryValue(raw) };
    })
    .filter((t): t is { key: string; label: string; value: string } => t !== null);

  if (tiles.length === 0) return null;

  return (
    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 14 }}>
      {tiles.map((t) => (
        <StatTile key={t.key} label={t.label} value={t.value} />
      ))}
    </View>
  );
}

function formatSummaryValue(value: unknown, format?: string, currency?: string): string {
  if (value === null || value === undefined || value === '') return '—';
  if (format === 'money') return formatMoney(value as string | number, currency ?? 'INR');
  if (format === 'percent') return `${formatNumber(value as string | number)}%`;
  if (format === 'date') return formatDate(String(value));
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  if (typeof value === 'number') return formatNumber(value);
  return String(value);
}

function ReportRowCard({ row, columns }: { row: ReportRow; columns: ReportColumnDefinition[] }) {
  return (
    <Card>
      {columns.map((c) => (
        <View key={c.key} style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 10, paddingVertical: 5 }}>
          <Text style={{ fontSize: 11.5, color: colors.textMuted, flexShrink: 0 }}>{columnLabel(c)}</Text>
          <View style={{ flex: 1, alignItems: 'flex-end' }}>
            <ReportCellValue column={c} row={row} />
          </View>
        </View>
      ))}
    </Card>
  );
}

function ReportCellValue({ column, row }: { column: ReportColumnDefinition; row: ReportRow }) {
  const router = useRouter();
  const value = row[column.key];

  if (value === null || value === undefined || value === '') {
    return <Text style={{ fontSize: 13, color: colors.textMuted }}>—</Text>;
  }

  switch (column.type) {
    case 'money':
      return <Text style={{ fontSize: 13, color: colors.textPrimary, fontFamily: 'Nunito_600SemiBold' }}>{formatMoney(value as string | number, currencyFor(row, column))}</Text>;
    case 'number':
      return <Text style={{ fontSize: 13, color: colors.textPrimary }}>{formatNumber(value as string | number)}</Text>;
    case 'percent':
      return <Text style={{ fontSize: 13, color: colors.textPrimary }}>{formatNumber(value as string | number)}%</Text>;
    case 'date':
      return <Text style={{ fontSize: 13, color: colors.textPrimary }}>{formatDate(String(value))}</Text>;
    case 'datetime':
      return <Text style={{ fontSize: 13, color: colors.textPrimary }}>{formatDateTime(String(value))}</Text>;
    case 'boolean':
      return <Text style={{ fontSize: 13, fontFamily: 'Nunito_600SemiBold', color: value ? colors.success : colors.textMuted }}>{value ? 'Yes' : 'No'}</Text>;
    case 'status':
      return <StatusBadge value={String(value)} />;
    case 'risk':
      return <StatusBadge value={String(value)} kind="risk" />;
    default:
      break;
  }

  const text = String(value);
  const linkId = column.link_key ? row[column.link_key] : undefined;

  if (linkId !== null && linkId !== undefined && linkId !== '') {
    return (
      <Pressable onPress={() => router.push(`/contracts/${String(linkId)}`)}>
        <Text style={{ fontSize: 13, color: colors.primary, fontFamily: 'Nunito_600SemiBold' }}>{text}</Text>
      </Pressable>
    );
  }

  return (
    <Text style={{ fontSize: 13, color: colors.textPrimary }} numberOfLines={2}>
      {text}
    </Text>
  );
}
