import { ScrollView, Text, View } from 'react-native';
import { Card, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDate, formatMoney } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import { getCommercials } from '../../../api/endpoints/commercials';

interface PaymentsTabProps {
  contractId: number;
}

interface PaymentScheduleRow {
  id?: number | string;
  due_date?: string | null;
  amount?: string | number | null;
  currency?: string | null;
  description?: string | null;
  status?: string | null;
  paid_at?: string | null;
}

/**
 * No GET .../payment-schedules route exists in Routes.php — only POST/PUT/DELETE
 * for individual schedule rows. The read path is presumed nested inside
 * GET /contracts/{id}/commercials' response (the same fetch CommercialsTab
 * makes) under a `payment_schedules` key, rendered defensively until confirmed.
 */
export function PaymentsTab({ contractId }: PaymentsTabProps) {
  const { data, loading, error, reload } = useAsync(() => getCommercials(contractId), [contractId]);
  const schedules = (data?.payment_schedules as PaymentScheduleRow[] | undefined) ?? [];

  if (loading && !data) return <LoadingState label="Loading payment schedule…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      {schedules.length === 0 ? (
        <EmptyState icon="calendar-outline" title="No payment schedule" message="No scheduled payments recorded for this contract yet." />
      ) : (
        schedules.map((row, index) => (
          <Card key={row.id ?? index} style={{ marginBottom: 10 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'flex-start' }}>
              <View style={{ flex: 1, marginRight: 8 }}>
                <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{formatMoney(row.amount, row.currency)}</Text>
                {row.due_date ? (
                  <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>Due {formatDate(row.due_date)}</Text>
                ) : null}
                {row.description ? (
                  <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 4 }}>{row.description}</Text>
                ) : null}
              </View>
              {row.status ? <StatusBadge value={row.status} kind="workflow" /> : null}
            </View>
          </Card>
        ))
      )}
    </ScrollView>
  );
}
