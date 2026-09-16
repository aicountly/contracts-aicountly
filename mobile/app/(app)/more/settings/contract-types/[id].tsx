import { useLocalSearchParams } from 'expo-router';
import { ContractTypeEditorScreen } from '../../../../../src/components/settings/ContractTypeEditorScreen';
import { LoadingState, ErrorState } from '../../../../../src/components';
import { useAsync } from '../../../../../src/utils/useAsync';
import { getContractType } from '../../../../../src/api/endpoints/settings';

export default function ContractTypeDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { data, loading, error, reload } = useAsync(() => getContractType(Number(id)), [id]);

  if (loading && !data) return <LoadingState label="Loading contract type…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;
  if (!data) return null;

  return <ContractTypeEditorScreen row={data} />;
}
