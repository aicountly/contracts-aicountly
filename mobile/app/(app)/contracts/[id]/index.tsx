import { useState } from 'react';
import { ScrollView, Text, View } from 'react-native';
import { useLocalSearchParams } from 'expo-router';

import { ScreenContainer, LoadingState, ErrorState, StatusBadge } from '../../../../src/components';
import { useAsync } from '../../../../src/utils/useAsync';
import { getContract } from '../../../../src/api/endpoints/contracts';
import { colors } from '../../../../src/theme/colors';
import { formatMoney } from '../../../../src/utils/format';
import { OverviewTab } from '../../../../src/components/contracts/tabs/OverviewTab';
import { DocumentTab } from '../../../../src/components/contracts/tabs/DocumentTab';
import { PartiesTab } from '../../../../src/components/contracts/tabs/PartiesTab';
import { CommercialsTab } from '../../../../src/components/contracts/tabs/CommercialsTab';
import { ClausesTab } from '../../../../src/components/contracts/tabs/ClausesTab';
import { ObligationsTab } from '../../../../src/components/contracts/tabs/ObligationsTab';
import { MilestonesTab } from '../../../../src/components/contracts/tabs/MilestonesTab';
import { PaymentsTab } from '../../../../src/components/contracts/tabs/PaymentsTab';
import { ApprovalsTab } from '../../../../src/components/contracts/tabs/ApprovalsTab';
import { VersionsTab } from '../../../../src/components/contracts/tabs/VersionsTab';
import { AmendmentsTab } from '../../../../src/components/contracts/tabs/AmendmentsTab';
import { RenewalTab } from '../../../../src/components/contracts/tabs/RenewalTab';
import { RiskTab } from '../../../../src/components/contracts/tabs/RiskTab';
import { AiTab } from '../../../../src/components/contracts/tabs/AiTab';
import { LinksTab } from '../../../../src/components/contracts/tabs/LinksTab';
import { ActivityTab } from '../../../../src/components/contracts/tabs/ActivityTab';
import { SignaturesTab } from '../../../../src/components/contracts/tabs/SignaturesTab';
import type { ContractTabCounts } from '../../../../src/types/contracts';

type TabId =
  | 'overview'
  | 'document'
  | 'parties'
  | 'commercials'
  | 'clauses'
  | 'obligations'
  | 'milestones'
  | 'payments'
  | 'approvals'
  | 'versions'
  | 'amendments'
  | 'renewal'
  | 'risk'
  | 'ai'
  | 'links'
  | 'activity'
  | 'signatures';

/** Mirrors web/src/pages/ContractWorkspace.tsx's TABS array — same ids, same order. */
const TABS: { id: TabId; label: string; countKey: keyof ContractTabCounts | null }[] = [
  { id: 'overview', label: 'Overview', countKey: null },
  { id: 'document', label: 'Document', countKey: 'documents' },
  { id: 'parties', label: 'Parties', countKey: 'parties' },
  { id: 'commercials', label: 'Commercials', countKey: null },
  { id: 'clauses', label: 'Clauses', countKey: 'clauses' },
  { id: 'obligations', label: 'Obligations', countKey: 'obligations' },
  { id: 'milestones', label: 'Milestones', countKey: 'milestones' },
  { id: 'payments', label: 'Payments', countKey: null },
  { id: 'approvals', label: 'Approvals', countKey: 'approvals' },
  { id: 'versions', label: 'Versions', countKey: 'versions' },
  { id: 'amendments', label: 'Amendments', countKey: 'amendments' },
  { id: 'renewal', label: 'Renewal', countKey: null },
  { id: 'risk', label: 'Risk', countKey: 'risks' },
  { id: 'ai', label: 'AI Insights', countKey: null },
  { id: 'links', label: 'Linked Records', countKey: 'links' },
  { id: 'activity', label: 'Activity', countKey: null },
  // Not one of web's 16 named tabs (comments are folded into Activity there,
  // and signatures are reached another way) — surfaced as its own tab here
  // since SignatureController is a full, real feature and there's no
  // sensible place to bury it without a dedicated tab strip to hide it in.
  { id: 'signatures', label: 'Signatures', countKey: null },
];

/**
 * A single screen with an internal tab switcher rather than one Expo Router
 * route per tab — switching tabs is a state change, not a navigation event,
 * so there's no 16-deep back-stack to unwind. `?tab=` still gives deep
 * links into a specific tab (e.g. from a notification) via the initial
 * state below.
 */
export default function ContractWorkspaceScreen() {
  const { id, tab: initialTab } = useLocalSearchParams<{ id: string; tab?: string }>();
  const [activeTab, setActiveTab] = useState<TabId>((initialTab as TabId) ?? 'overview');

  const { data: contract, loading, error, reload } = useAsync(() => getContract(id), [id]);

  if (loading && !contract) return <LoadingState label="Loading contract…" />;
  if (error && !contract) return <ErrorState message={error} onRetry={reload} />;
  if (!contract) return null;

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ paddingHorizontal: 16, paddingTop: 12, paddingBottom: 12, borderBottomWidth: 1, borderBottomColor: colors.borderSoft }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 17, color: colors.textPrimary }} numberOfLines={2}>
          {contract.title}
        </Text>
        <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>
          {contract.contract_number}
          {contract.counterparty_name ? ` · ${contract.counterparty_name}` : ''}
        </Text>
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginTop: 8, alignItems: 'center' }}>
          <StatusBadge value={contract.status} kind="contract" />
          {contract.risk_level ? <StatusBadge value={contract.risk_level} kind="risk" /> : null}
          {contract.total_value ? (
            <Text style={{ fontSize: 12, fontFamily: 'Nunito_600SemiBold', color: colors.textSecondary }}>
              {formatMoney(contract.total_value, contract.currency)}
            </Text>
          ) : null}
        </View>
      </View>

      <ScrollView
        horizontal
        showsHorizontalScrollIndicator={false}
        style={{ flexGrow: 0, borderBottomWidth: 1, borderBottomColor: colors.borderSoft }}
        contentContainerStyle={{ paddingHorizontal: 12 }}
      >
        {TABS.map((t) => {
          const active = t.id === activeTab;
          const count = t.countKey ? contract.tabs?.[t.countKey] : undefined;
          return (
            <Text
              key={t.id}
              onPress={() => setActiveTab(t.id)}
              style={{
                paddingHorizontal: 12,
                paddingVertical: 12,
                fontFamily: active ? 'Nunito_700Bold' : 'Nunito_600SemiBold',
                fontSize: 13,
                color: active ? colors.primary : colors.textMuted,
                borderBottomWidth: 2,
                borderBottomColor: active ? colors.primary : 'transparent',
              }}
            >
              {t.label}
              {count ? ` (${count})` : ''}
            </Text>
          );
        })}
      </ScrollView>

      <View style={{ flex: 1 }}>
        {activeTab === 'overview' ? <OverviewTab contract={contract} onReload={reload} /> : null}
        {activeTab === 'document' ? <DocumentTab contractId={contract.id} /> : null}
        {activeTab === 'parties' ? <PartiesTab contractId={contract.id} /> : null}
        {activeTab === 'commercials' ? <CommercialsTab contract={contract} /> : null}
        {activeTab === 'clauses' ? <ClausesTab contractId={contract.id} /> : null}
        {activeTab === 'obligations' ? <ObligationsTab contractId={contract.id} /> : null}
        {activeTab === 'milestones' ? <MilestonesTab contractId={contract.id} /> : null}
        {activeTab === 'payments' ? <PaymentsTab contractId={contract.id} /> : null}
        {activeTab === 'approvals' ? <ApprovalsTab contractId={contract.id} /> : null}
        {activeTab === 'versions' ? <VersionsTab contractId={contract.id} /> : null}
        {activeTab === 'amendments' ? <AmendmentsTab contractId={contract.id} /> : null}
        {activeTab === 'renewal' ? <RenewalTab contractId={contract.id} /> : null}
        {activeTab === 'risk' ? <RiskTab contractId={contract.id} /> : null}
        {activeTab === 'ai' ? <AiTab contractId={contract.id} /> : null}
        {activeTab === 'links' ? <LinksTab contractId={contract.id} /> : null}
        {activeTab === 'activity' ? <ActivityTab contractId={contract.id} /> : null}
        {activeTab === 'signatures' ? <SignaturesTab contractId={contract.id} /> : null}
      </View>
    </ScreenContainer>
  );
}
