import { ScreenContainer, EmptyState } from '../../../src/components';

export default function AttentionScreen() {
  return (
    <ScreenContainer bottomInset={false}>
      <EmptyState icon="alert-circle-outline" title="Attention" message="Approvals, obligations, renewals, risks and AI review — building this next." />
    </ScreenContainer>
  );
}
