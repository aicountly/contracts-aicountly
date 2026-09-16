import { useLocalSearchParams } from 'expo-router';
import { CustomFieldEditorScreen } from '../../../../../src/components/settings/CustomFieldEditorScreen';
import { LoadingState, ErrorState } from '../../../../../src/components';
import { useAsync } from '../../../../../src/utils/useAsync';
import { getCustomField } from '../../../../../src/api/endpoints/settings';

export default function CustomFieldDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { data, loading, error, reload } = useAsync(() => getCustomField(Number(id)), [id]);

  if (loading && !data) return <LoadingState label="Loading custom field…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;
  if (!data) return null;

  return <CustomFieldEditorScreen row={data} />;
}
