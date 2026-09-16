import { ScrollView, Text, View } from 'react-native';
import { Card, LoadingState, ErrorState, EmptyState } from '../../index';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import { getCommercials } from '../../../api/endpoints/commercials';
import { formatMoney, humaniseSnakeCase } from '../../../utils/format';
import type { Contract } from '../../../types/contracts';

interface CommercialsTabProps {
  contract: Contract;
}

/**
 * GET /contracts/{id}/commercials — response shape not yet mirrored as an
 * interface in types/contracts.ts. Rendered generically (humanised key:
 * value) until the real fields are confirmed and this becomes a typed,
 * editable form like the other tabs. The summary fields already on the
 * Contract record itself are shown first regardless, since those are known.
 */
export function CommercialsTab({ contract }: CommercialsTabProps) {
  const { data, loading, error, reload } = useAsync(() => getCommercials(contract.id), [contract.id]);

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      <Card style={{ marginBottom: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Summary</Text>
        <DetailRow label="Total value" value={formatMoney(contract.total_value, contract.currency)} />
        <DetailRow label="Recurring value" value={contract.recurring_value ? formatMoney(contract.recurring_value, contract.currency) : null} />
        <DetailRow label="Payment frequency" value={contract.payment_frequency ? humaniseSnakeCase(contract.payment_frequency) : null} />
        <DetailRow label="Billing frequency" value={contract.billing_frequency ? humaniseSnakeCase(contract.billing_frequency) : null} />
        {contract.commercial_summary ? (
          <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 13, color: colors.textSecondary, marginTop: 8 }}>{contract.commercial_summary}</Text>
        ) : null}
      </Card>

      {loading && !data ? (
        <LoadingState label="Loading commercial details…" />
      ) : error ? (
        <ErrorState message={error} onRetry={reload} />
      ) : data && Object.keys(data).length > 0 ? (
        <Card>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Additional terms</Text>
          {Object.entries(data)
            .filter(([, v]) => v !== null && v !== undefined && v !== '')
            .map(([key, v]) => (
              <DetailRow key={key} label={humaniseSnakeCase(key)} value={typeof v === 'object' ? JSON.stringify(v) : String(v)} />
            ))}
        </Card>
      ) : (
        <EmptyState icon="cash-outline" title="No additional commercial terms" />
      )}
    </ScrollView>
  );
}

function DetailRow({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value) return null;
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 6 }}>
      <Text style={{ fontFamily: 'Nunito_500Medium', fontSize: 12, color: colors.textMuted, flex: 1 }}>{label}</Text>
      <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary, flex: 2, textAlign: 'right' }}>{value}</Text>
    </View>
  );
}
