import { ScreenContainer, EmptyState } from '../../../src/components';

export default function MoreScreen() {
  return (
    <ScreenContainer bottomInset={false}>
      <EmptyState icon="grid-outline" title="More" message="Templates, clause library, AI insights, notifications and settings — building this next." />
    </ScreenContainer>
  );
}
