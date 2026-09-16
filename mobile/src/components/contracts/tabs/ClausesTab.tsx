import { ScrollView, Text, View } from 'react-native';
import { Card, LoadingState, ErrorState, EmptyState, StatusBadge } from '../../index';
import { colors } from '../../../theme/colors';
import { useAsync } from '../../../utils/useAsync';
import { api } from '../../../api/client';

interface ClausesTabProps {
  contractId: number;
}

interface ContractClauseRow {
  id: number;
  name?: string | null;
  heading?: string | null;
  category_name?: string | null;
  text?: string | null;
  standard_text?: string | null;
  risk_classification?: string | null;
  [key: string]: unknown;
}

interface DeviationRow {
  id: number;
  title?: string | null;
  detail?: string | null;
  description?: string | null;
  severity?: string | null;
  review_status?: string | null;
  [key: string]: unknown;
}

/** No ContractClause/Deviation interface exists in types/contracts.ts yet — fetched and rendered defensively, same approach as CommercialsTab. */
export function ClausesTab({ contractId }: ClausesTabProps) {
  const clauses = useAsync(() => api.get<ContractClauseRow[]>(`/contracts/${contractId}/clauses`), [contractId]);
  const deviations = useAsync(() => api.get<DeviationRow[]>(`/contracts/${contractId}/deviations`), [contractId]);

  if (clauses.loading && !clauses.data) return <LoadingState label="Loading clauses…" />;
  if (clauses.error && !clauses.data) return <ErrorState message={clauses.error} onRetry={clauses.reload} />;

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
      {deviations.data && deviations.data.length > 0 ? (
        <View style={{ marginBottom: 20 }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>
            Playbook deviations ({deviations.data.length})
          </Text>
          {deviations.data.map((dev) => (
            <Card key={dev.id} style={{ marginBottom: 8 }}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary, flex: 1 }}>{dev.title ?? 'Deviation'}</Text>
                {dev.severity ? <StatusBadge value={dev.severity} kind="risk" /> : null}
              </View>
              {dev.detail || dev.description ? (
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 4 }}>
                  {dev.detail ?? dev.description}
                </Text>
              ) : null}
            </Card>
          ))}
        </View>
      ) : null}

      <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Clauses</Text>
      {!clauses.data || clauses.data.length === 0 ? (
        <EmptyState icon="book-outline" title="No clauses attached" />
      ) : (
        clauses.data.map((clause) => (
          <Card key={clause.id} style={{ marginBottom: 10 }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }}>
                {clause.name ?? clause.heading ?? 'Clause'}
              </Text>
              {clause.risk_classification ? <StatusBadge value={clause.risk_classification} kind="risk" /> : null}
            </View>
            {clause.category_name ? (
              <Text style={{ fontFamily: 'Nunito_500Medium', fontSize: 11, color: colors.textMuted, marginTop: 2 }}>{clause.category_name}</Text>
            ) : null}
            {clause.text || clause.standard_text ? (
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textSecondary, marginTop: 6 }} numberOfLines={4}>
                {clause.text ?? clause.standard_text}
              </Text>
            ) : null}
          </Card>
        ))
      )}
    </ScrollView>
  );
}
