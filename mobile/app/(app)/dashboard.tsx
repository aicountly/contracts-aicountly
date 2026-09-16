import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { useCallback, useState } from 'react';

import { ScreenContainer, LoadingState, ErrorState, Card, StatusBadge } from '../../src/components';
import { StatTile } from '../../src/components/StatTile';
import { BarList } from '../../src/components/charts/BarList';
import { useCompany } from '../../src/state/CompanyContext';
import { useAsync } from '../../src/utils/useAsync';
import { getDashboardKpis, getDashboardCharts, getMyActions, getDashboardActivity } from '../../src/api/endpoints/dashboard';
import type { DashboardCharts, MyActionItem } from '../../src/types/contracts';
import { formatMoney, formatNumber, daysLabel, formatDateTime } from '../../src/utils/format';
import { pointLabel, pointValue } from '../../src/utils/chartPoint';
import { categoricalColor } from '../../src/theme/chartPalette';
import { colors, riskLevelColors } from '../../src/theme/colors';

const CHART_SECTIONS: { key: keyof DashboardCharts; title: string; palette?: 'status' | 'risk' | 'categorical' }[] = [
  { key: 'by_status', title: 'Contracts by status', palette: 'status' },
  { key: 'by_type', title: 'Contracts by type', palette: 'categorical' },
  { key: 'by_department', title: 'Contracts by department', palette: 'categorical' },
  { key: 'value_by_category', title: 'Value by category', palette: 'categorical' },
  { key: 'expiry_timeline', title: 'Expiry timeline' },
  { key: 'renewal_pipeline', title: 'Renewal pipeline' },
  { key: 'risk_distribution', title: 'Risk distribution', palette: 'risk' },
  { key: 'obligations_timeline', title: 'Obligations timeline' },
  { key: 'customer_vs_vendor', title: 'Customer vs vendor', palette: 'categorical' },
  { key: 'monthly_executed', title: 'Monthly executed' },
  { key: 'counterparty_mix', title: 'Counterparty mix', palette: 'categorical' },
  { key: 'approval_throughput', title: 'Approval throughput (avg days)' },
];

export default function DashboardScreen() {
  const router = useRouter();
  const { company } = useCompany();
  const [refreshTick, setRefreshTick] = useState(0);

  const kpis = useAsync(getDashboardKpis, [refreshTick]);
  const charts = useAsync(getDashboardCharts, [refreshTick]);
  const myActions = useAsync(getMyActions, [refreshTick]);
  const activity = useAsync(getDashboardActivity, [refreshTick]);

  const refreshing = kpis.loading && kpis.data !== null;

  const onRefresh = useCallback(() => setRefreshTick((n) => n + 1), []);

  if (kpis.loading && !kpis.data) return <LoadingState label="Loading dashboard…" />;
  if (kpis.error && !kpis.data) return <ErrorState message={kpis.error} onRetry={kpis.reload} />;

  const currency = kpis.data?.currency ?? undefined;
  const actionRows: MyActionItem[] = myActions.data
    ? [...myActions.data.approvals, ...myActions.data.obligations, ...myActions.data.renewals, ...myActions.data.ai_reviews]
    : [];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView
        contentContainerStyle={{ padding: 16, paddingBottom: 32 }}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={colors.primary} />}
      >
        <Text style={{ fontSize: 22, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>Dashboard</Text>
        <Text style={{ fontSize: 13, fontFamily: 'Nunito_400Regular', color: colors.textMuted, marginBottom: 16 }}>
          {company?.name ?? 'Across your contracts'}
        </Text>

        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 20 }}>
          <StatTile label="Total Contracts" value={formatNumber(kpis.data?.total_contracts)} />
          <StatTile label="Active" value={formatNumber(kpis.data?.active)} tone="success" />
          <StatTile label="Draft" value={formatNumber(kpis.data?.draft)} />
          <StatTile label="Awaiting Approval" value={formatNumber(kpis.data?.awaiting_approval)} tone="warning" />
          <StatTile label="Awaiting Signature" value={formatNumber(kpis.data?.awaiting_signature)} tone="warning" />
          <StatTile
            label={kpis.data?.expiring_within_days ? `Expiring in ${kpis.data.expiring_within_days}d` : 'Expiring Soon'}
            value={formatNumber(kpis.data?.expiring_soon)}
            tone="warning"
          />
          <StatTile label="Renewals Due" value={formatNumber(kpis.data?.renewals_due)} tone="warning" />
          <StatTile label="Obligations Due" value={formatNumber(kpis.data?.obligations_due)} />
          <StatTile label="Overdue Obligations" value={formatNumber(kpis.data?.overdue_obligations)} tone="danger" />
          <StatTile label="High Risk" value={formatNumber(kpis.data?.high_risk)} tone="danger" />
          <StatTile label="Total Value" value={formatMoney(kpis.data?.total_value, currency)} />
          <StatTile label="Receivable" value={formatMoney(kpis.data?.receivable_commitments, currency)} />
          <StatTile label="Payable" value={formatMoney(kpis.data?.payable_commitments, currency)} />
        </View>

        <Card style={{ marginBottom: 20 }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 4 }}>Needs your attention</Text>
          {myActions.loading && !myActions.data ? (
            <Text style={{ color: colors.textMuted, fontSize: 13 }}>Loading…</Text>
          ) : myActions.error ? (
            <Text style={{ color: colors.danger, fontSize: 13 }}>{myActions.error}</Text>
          ) : actionRows.length === 0 ? (
            <Text style={{ color: colors.textMuted, fontSize: 13, marginTop: 4 }}>Nothing waiting on you right now.</Text>
          ) : (
            <View style={{ marginTop: 8 }}>
              {actionRows.slice(0, 6).map((item, index) => (
                <MyActionRow
                  key={`${item.id}-${index}`}
                  item={item}
                  onPress={() => item.contract_id && router.push(`/contracts/${item.contract_id}`)}
                  isLast={index === Math.min(actionRows.length, 6) - 1}
                />
              ))}
              {actionRows.length > 0 ? (
                <Text
                  onPress={() => router.push('/attention')}
                  style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 13, marginTop: 10 }}
                >
                  See all in Attention
                </Text>
              ) : null}
            </View>
          )}
        </Card>

        {charts.loading && !charts.data ? (
          <Text style={{ color: colors.textMuted, fontSize: 13, marginBottom: 16 }}>Loading charts…</Text>
        ) : charts.error ? (
          <Text style={{ color: colors.danger, fontSize: 13, marginBottom: 16 }}>{charts.error}</Text>
        ) : (
          charts.data &&
          CHART_SECTIONS.map((section) => {
            const points = charts.data![section.key] ?? [];
            if (!points || points.length === 0) return null;
            const items = points.map((point, index) => {
              const value = pointValue(point);
              let color: string | undefined;
              if (section.palette === 'status') color = undefined; // status colours applied via StatusBadge legend below instead of the bar itself
              else if (section.palette === 'risk') color = riskLevelColors[pointLabel(point).toLowerCase()]?.fg ?? colors.primary;
              else if (section.palette === 'categorical') color = categoricalColor(index);
              return {
                label: pointLabel(point),
                value,
                color,
                formattedValue: section.key === 'value_by_category' ? formatMoney(value, currency) : undefined,
              };
            });
            return (
              <Card key={section.key} style={{ marginBottom: 16 }}>
                <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 12 }}>{section.title}</Text>
                <BarList items={items} />
              </Card>
            );
          })
        )}

        {activity.data && activity.data.length > 0 ? (
          <View>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 15, color: colors.textPrimary, marginBottom: 10 }}>Recent activity</Text>
            {activity.data.slice(0, 10).map((entry) => (
              <Card key={entry.id} style={{ marginBottom: 8 }}>
                <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary }}>{entry.description ?? entry.action}</Text>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 11, color: colors.textMuted, marginTop: 3 }}>
                  {[entry.actor_name, entry.contract_number, formatDateTime(entry.created_at)].filter(Boolean).join(' · ')}
                </Text>
              </Card>
            ))}
          </View>
        ) : null}
      </ScrollView>
    </ScreenContainer>
  );
}

function MyActionRow({ item, onPress, isLast }: { item: MyActionItem; onPress: () => void; isLast: boolean }) {
  const due = daysLabel(item.days_remaining);
  return (
    <Pressable onPress={onPress} style={{ paddingVertical: 10, borderBottomWidth: isLast ? 0 : 1, borderBottomColor: colors.borderSoft }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <View style={{ flex: 1, marginRight: 8 }}>
          <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary }} numberOfLines={1}>
            {item.title ?? item.contract_title ?? item.contract_number ?? 'Item'}
          </Text>
          {item.contract_number ? (
            <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 11, color: colors.textMuted, marginTop: 2 }}>{item.contract_number}</Text>
          ) : null}
        </View>
        {item.status ? <StatusBadge value={item.status} kind="workflow" /> : null}
      </View>
      {due ? (
        <Text style={{ fontFamily: 'Nunito_500Medium', fontSize: 11, color: due.includes('overdue') ? colors.danger : colors.textMuted, marginTop: 4 }}>
          {due}
        </Text>
      ) : null}
    </Pressable>
  );
}
