import { Component, Suspense, lazy, useCallback, useMemo, useState } from 'react'
import type { ComponentType, LazyExoticComponent, ReactNode } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { FileQuestion } from 'lucide-react'

import { Button, Card, EmptyState, ErrorState, Skeleton, Tabs } from '../components/ui'
import type { TabItem } from '../components/ui/Tabs'
import { WorkspaceHeader } from '../components/contracts/WorkspaceHeader'
import { useSession } from '../context/SessionProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import type { Contract, ContractTabCounts } from '../types/contracts'

/**
 * The contract workspace: one contract, sixteen ways of looking at it.
 *
 * Two rules shape this file.
 *
 * Only the active panel is mounted. Sixteen tabs that each fetch on mount would
 * be sixteen requests for a contract someone opened to check an expiry date, so
 * the panels are lazy in both senses — the code is split, and the component is
 * not rendered until its tab is selected.
 *
 * A panel this page does not own cannot take the page down with it. The panels
 * belonging to other teams are resolved at runtime and wrapped in an error
 * boundary: a module that is missing, or one that throws, costs its own tab and
 * nothing else. The header, the tab strip and every other panel keep working.
 */

interface TabPanelProps {
  contractId: number
  contract: Contract
  onChanged: () => void
}

interface TabDefinition {
  id: string
  label: string
  /** Count from the contract payload, shown as a badge. */
  count?: keyof ContractTabCounts
  permission?: string
  /**
   * Module basenames to try for a panel this page does not own, most likely
   * first. Absent for the panels defined below.
   */
  modules?: string[]
}

const TABS: TabDefinition[] = [
  { id: 'overview', label: 'Overview' },
  { id: 'document', label: 'Document', count: 'documents' },
  { id: 'parties', label: 'Parties', count: 'parties' },
  { id: 'commercials', label: 'Commercials', permission: PERMISSION.COMMERCIALS_VIEW },
  { id: 'clauses', label: 'Clauses', count: 'clauses', modules: ['ClausesTab'] },
  { id: 'obligations', label: 'Obligations', count: 'obligations', modules: ['ObligationsTab'] },
  { id: 'milestones', label: 'Milestones', count: 'milestones', modules: ['MilestonesTab'] },
  {
    id: 'payments',
    label: 'Payments',
    permission: PERMISSION.COMMERCIALS_VIEW,
    modules: ['PaymentsTab', 'PaymentScheduleTab'],
  },
  { id: 'approvals', label: 'Approvals', count: 'approvals', modules: ['ApprovalsTab'] },
  { id: 'versions', label: 'Versions', count: 'versions' },
  { id: 'amendments', label: 'Amendments', count: 'amendments', modules: ['AmendmentsTab'] },
  { id: 'renewal', label: 'Renewal', modules: ['RenewalTab', 'RenewalsTab'] },
  {
    id: 'risk',
    label: 'Risk',
    count: 'risks',
    permission: PERMISSION.AI_RISK_VIEW,
    modules: ['RiskTab', 'RiskAssessmentTab'],
  },
  {
    id: 'ai',
    label: 'AI Insights',
    permission: PERMISSION.AI_USE,
    modules: ['AiInsightsTab', 'AIInsightsTab', 'InsightsTab'],
  },
  { id: 'links', label: 'Linked Records', count: 'links' },
  { id: 'activity', label: 'Activity' },
]

const EMPTY_TAB_COUNTS: ContractTabCounts = {
  parties: 0,
  documents: 0,
  clauses: 0,
  obligations: 0,
  milestones: 0,
  approvals: 0,
  versions: 0,
  amendments: 0,
  risks: 0,
  comments: 0,
  links: 0,
}

const OverviewTab = lazy(() =>
  import('../components/contracts/tabs/OverviewTab').then((m) => ({ default: m.OverviewTab })),
)
const DocumentTab = lazy(() =>
  import('../components/contracts/tabs/DocumentTab').then((m) => ({ default: m.DocumentTab })),
)
const PartiesTab = lazy(() =>
  import('../components/contracts/tabs/PartiesTab').then((m) => ({ default: m.PartiesTab })),
)
const CommercialsTab = lazy(() =>
  import('../components/contracts/tabs/CommercialsTab').then((m) => ({ default: m.CommercialsTab })),
)
const VersionsTab = lazy(() =>
  import('../components/contracts/tabs/VersionsTab').then((m) => ({ default: m.VersionsTab })),
)
const LinkedRecordsTab = lazy(() =>
  import('../components/contracts/tabs/LinkedRecordsTab').then((m) => ({ default: m.LinkedRecordsTab })),
)
const ActivityTab = lazy(() =>
  import('../components/contracts/tabs/ActivityTab').then((m) => ({ default: m.ActivityTab })),
)

/**
 * Every panel module the bundler can see.
 *
 * A static import of a panel another team is still writing would fail the whole
 * build; a glob resolves at runtime, so a module that does not exist yet costs
 * exactly one tab.
 */
const TAB_MODULES: Record<string, () => Promise<unknown>> = import.meta.glob(
  '../components/contracts/tabs/*Tab.tsx',
)

const foreignPanels = new Map<string, LazyExoticComponent<ComponentType<TabPanelProps>>>()

function foreignPanel(
  candidates: string[],
  label: string,
): LazyExoticComponent<ComponentType<TabPanelProps>> {
  const cacheKey = candidates.join('|')
  const cached = foreignPanels.get(cacheKey)
  if (cached) return cached

  const panel = lazy(async () => {
    for (const name of candidates) {
      const loader = TAB_MODULES[`../components/contracts/tabs/${name}.tsx`]
      if (!loader) continue

      const module = (await loader()) as Record<string, unknown>
      const exported = module.default ?? module[name]
      if (typeof exported === 'function') {
        return { default: exported as ComponentType<TabPanelProps> }
      }
    }

    return { default: () => <PanelUnavailable label={label} /> }
  })

  foreignPanels.set(cacheKey, panel)
  return panel
}

export default function ContractWorkspace() {
  const navigate = useNavigate()
  const params = useParams<{ id: string }>()
  const [searchParams, setSearchParams] = useSearchParams()
  const { can } = useSession()

  const contractId = Number(params.id)
  const validId = Number.isInteger(contractId) && contractId > 0

  const [uploadIntent, setUploadIntent] = useState<'executed' | null>(null)

  const resource = useApiResource<Contract>(
    (signal) => api.get<Contract>(`/contracts/${contractId}`, undefined, signal),
    [contractId],
    { enabled: validId },
  )

  const contract = useMemo<Contract | null>(() => {
    if (!resource.data) return null
    return { ...resource.data, tabs: { ...EMPTY_TAB_COUNTS, ...resource.data.tabs } }
  }, [resource.data])

  const visibleTabs = useMemo(
    () => TABS.filter((tab) => !tab.permission || can(tab.permission)),
    [can],
  )

  const requested = searchParams.get('tab') ?? 'overview'
  const active = visibleTabs.some((tab) => tab.id === requested) ? requested : 'overview'

  const openTab = useCallback(
    (tabId: string) => {
      setSearchParams(
        (current) => {
          const next = new URLSearchParams(current)
          next.set('tab', tabId)
          return next
        },
        // Replaced rather than pushed: someone who reads four tabs then presses
        // back means "take me to the repository", not "the tab before this one".
        { replace: true },
      )
    },
    [setSearchParams],
  )

  const reload = useCallback(() => resource.reload(), [resource])

  if (!validId) {
    return (
      <Card>
        <EmptyState
          icon={<FileQuestion size={22} />}
          title="That is not a contract reference"
          description="The address does not point at a contract. Open one from the repository."
          action={
            <Button variant="primary" onClick={() => navigate('/contracts')}>
              Go to the repository
            </Button>
          }
        />
      </Card>
    )
  }

  if (resource.loading && !contract) {
    return <WorkspaceSkeleton />
  }

  if (resource.error) {
    const notFound = resource.error instanceof ApiError && resource.error.isNotFound

    return (
      <Card>
        {notFound ? (
          <EmptyState
            icon={<FileQuestion size={22} />}
            title="Contract not found"
            description="It may have been deleted, or it belongs to a company you do not have access to."
            action={
              <Button variant="primary" onClick={() => navigate('/contracts')}>
                Back to the repository
              </Button>
            }
          />
        ) : (
          <ErrorState
            title="Could not open this contract"
            detail={resource.error.message}
            onRetry={resource.reload}
          />
        )}
      </Card>
    )
  }

  if (!contract) return <WorkspaceSkeleton />

  const items: TabItem[] = visibleTabs.map((tab) => ({
    id: tab.id,
    label: tab.label,
    badge: tab.count ? contract.tabs[tab.count] : undefined,
  }))

  const definition = visibleTabs.find((tab) => tab.id === active) ?? visibleTabs[0]

  return (
    <>
      <WorkspaceHeader
        contract={contract}
        onChanged={reload}
        onOpenTab={openTab}
        onUploadExecuted={() => {
          setUploadIntent('executed')
          openTab('document')
        }}
      />

      <Tabs items={items} active={active} onChange={openTab} ariaLabel="Contract sections" />

      <div
        role="tabpanel"
        id={`panel-${active}`}
        aria-labelledby={`tab-${active}`}
        tabIndex={0}
        style={{ paddingTop: 18 }}
      >
        <PanelBoundary key={active} label={definition.label}>
          <Suspense fallback={<PanelSkeleton />}>
            <Panel
              active={active}
              definition={definition}
              contract={contract}
              contractId={contractId}
              onChanged={reload}
              onOpenTab={openTab}
              uploadIntent={uploadIntent}
              onUploadIntentHandled={() => setUploadIntent(null)}
            />
          </Suspense>
        </PanelBoundary>
      </div>
    </>
  )
}

function Panel({
  active,
  definition,
  contract,
  contractId,
  onChanged,
  onOpenTab,
  uploadIntent,
  onUploadIntentHandled,
}: {
  active: string
  definition: TabDefinition
  contract: Contract
  contractId: number
  onChanged: () => void
  onOpenTab: (tabId: string) => void
  uploadIntent: 'executed' | null
  onUploadIntentHandled: () => void
}) {
  switch (active) {
    case 'overview':
      return (
        <OverviewTab
          contractId={contractId}
          contract={contract}
          onChanged={onChanged}
          onOpenTab={onOpenTab}
        />
      )
    case 'document':
      return (
        <DocumentTab
          contractId={contractId}
          contract={contract}
          onChanged={onChanged}
          uploadIntent={uploadIntent}
          onUploadIntentHandled={onUploadIntentHandled}
        />
      )
    case 'parties':
      return <PartiesTab contractId={contractId} contract={contract} onChanged={onChanged} />
    case 'commercials':
      return (
        <CommercialsTab
          contractId={contractId}
          contract={contract}
          onChanged={onChanged}
          onOpenTab={onOpenTab}
        />
      )
    case 'versions':
      return <VersionsTab contractId={contractId} contract={contract} onChanged={onChanged} />
    case 'links':
      return <LinkedRecordsTab contractId={contractId} contract={contract} onChanged={onChanged} />
    case 'activity':
      return <ActivityTab contractId={contractId} contract={contract} onChanged={onChanged} />
    default: {
      const Foreign = foreignPanel(definition.modules ?? [], definition.label)
      return <Foreign contractId={contractId} contract={contract} onChanged={onChanged} />
    }
  }
}

function WorkspaceSkeleton() {
  return (
    <div role="status" aria-label="Loading contract">
      <span className="ct-sr-only">Loading contract…</span>
      <div style={{ display: 'grid', gap: 10, marginBottom: 18 }}>
        <Skeleton height={14} width={160} />
        <Skeleton height={26} width="55%" />
        <Skeleton height={13} width="35%" />
        <div style={{ display: 'flex', gap: 8, marginTop: 4 }}>
          <Skeleton height={20} width={90} radius={999} />
          <Skeleton height={20} width={70} radius={999} />
        </div>
      </div>
      <Skeleton height={36} />
      <div style={{ marginTop: 18 }}>
        <PanelSkeleton />
      </div>
    </div>
  )
}

function PanelSkeleton() {
  return (
    <div
      role="status"
      aria-label="Loading section"
      style={{ display: 'grid', gap: 16, gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))' }}
    >
      <Skeleton height={200} radius={14} />
      <Skeleton height={200} radius={14} />
    </div>
  )
}

function PanelUnavailable({ label }: { label: string }) {
  return (
    <Card>
      <EmptyState
        title={`${label} is unavailable`}
        description="This section could not be loaded in this build of the workspace. Everything else on the contract is unaffected — reload the page to try again."
        action={
          <Button variant="secondary" onClick={() => window.location.reload()}>
            Reload
          </Button>
        }
      />
    </Card>
  )
}

/**
 * One panel's failure stays in that panel.
 *
 * Keyed on the tab id by the caller, so switching tabs clears a failure rather
 * than leaving the workspace stuck on the error of a tab the user has left.
 */
class PanelBoundary extends Component<{ label: string; children: ReactNode }, { failed: boolean }> {
  state = { failed: false }

  static getDerivedStateFromError() {
    return { failed: true }
  }

  render() {
    if (this.state.failed) return <PanelUnavailable label={this.props.label} />
    return this.props.children
  }
}
