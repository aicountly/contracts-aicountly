import { useLocalSearchParams } from 'expo-router';
import { TemplateEditorScreen } from '../../../../src/components/templates/TemplateEditorScreen';

export default function TemplateDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <TemplateEditorScreen templateId={Number(id)} />;
}
