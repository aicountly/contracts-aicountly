import { ScreenContainer, EmptyState } from '../../../src/components';

export default function ReportsScreen() {
  return (
    <ScreenContainer bottomInset={false}>
      <EmptyState icon="bar-chart-outline" title="Reports" message="Building this next." />
    </ScreenContainer>
  );
}
