import { useLocalSearchParams } from 'expo-router';
import { RiskRuleEditorScreen } from '../../../../../src/components/settings/RiskRuleEditorScreen';
import { LoadingState, ErrorState } from '../../../../../src/components';
import { useAsync } from '../../../../../src/utils/useAsync';
import { getRiskRule } from '../../../../../src/api/endpoints/settings';

export default function RiskRuleDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { data, loading, error, reload } = useAsync(() => getRiskRule(Number(id)), [id]);

  if (loading && !data) return <LoadingState label="Loading risk rule…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;
  if (!data) return null;

  return <RiskRuleEditorScreen row={data} />;
}
