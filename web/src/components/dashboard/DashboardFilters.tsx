import { useEffect, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { SlidersHorizontal, X } from 'lucide-react'

import { Button, Drawer, Input, Select } from '../ui'
import { useApiResource } from '../../hooks/useApiResource'
import { api } from '../../services/apiClient'
import { useCompany } from '../../context/CompanyProvider'
import { useSession } from '../../context/SessionProvider'
import { humanise } from '../../utils/format'
import type {
  ContractTypeSummary,
  DashboardFilterState,
  DashboardPeriod,
  DepartmentSummary,
} from '../../types/contracts'

/**
 * What the dashboard is narrowed to.
 *
 * Changing anything here refetches: the figures are aggregates the server
 * computed, so filtering the numbers the browser already has would produce a
 * total that is quietly wrong. The company is not a filter — it is context, and
 * it travels on every request as a header.
 */

export const DEFAULT_DASHBOARD_FILTERS: DashboardFilterState = {
  branch_id: '',
  contract_type_id: '',
  department_id: '',
  owner_uuid: '',
  counterparty: '',
  status: '',
  risk_level: '',
  period: 'all',
}

const PERIOD_OPTIONS: { value: DashboardPeriod; label: string }[] = [
  { value: 'all', label: 'All time' },
  { value: 'last_30', label: 'Last 30 days' },
  { value: 'last_90', label: 'Last 90 days' },
  { value: 'last_12m', label: 'Last 12 months' },
  { value: 'financial_year', label: 'Current financial year' },
]

const STATUS_OPTIONS = [
  'draft',
  'under_review',
  'awaiting_approval',
  'approved',
  'negotiation',
  'awaiting_signature',
  'active',
  'renewal_review',
  'expired',
  'terminated',
  'cancelled',
].map((value) => ({ value, label: humanise(value) }))

const RISK_OPTIONS = ['low', 'medium', 'high', 'critical'].map((value) => ({
  value,
  label: humanise(value),
}))

export interface FinancialYearRange {
  start_date?: string | null
  end_date?: string | null
}

function isoDate(date: Date): string {
  const month = `${date.getMonth() + 1}`.padStart(2, '0')
  const day = `${date.getDate()}`.padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

function shiftedFrom(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() - days)
  return isoDate(date)
}

/**
 * A period preset resolved to the dates the API filters on.
 *
 * Resolved in the browser rather than sent as a keyword so the same window can
 * be handed to the contract list when a KPI tile is followed — the repository
 * filters on `effective_from`/`effective_to`, and a tile that drills through to
 * a different period than it counted is a bug report waiting to happen.
 */
export function resolvePeriod(
  period: DashboardPeriod,
  financialYear?: FinancialYearRange | null,
): { effective_from?: string; effective_to?: string } {
  switch (period) {
    case 'last_30':
      return { effective_from: shiftedFrom(30) }
    case 'last_90':
      return { effective_from: shiftedFrom(90) }
    case 'last_12m':
      return { effective_from: shiftedFrom(365) }
    case 'financial_year':
      return financialYear?.start_date
        ? {
            effective_from: financialYear.start_date.slice(0, 10),
            effective_to: financialYear.end_date?.slice(0, 10),
          }
        : {}
    case 'all':
    default:
      return {}
  }
}

/** The filters as API query parameters, with the empty ones dropped. */
export function dashboardQuery(
  filters: DashboardFilterState,
  financialYear?: FinancialYearRange | null,
): Record<string, string> {
  const candidates: Record<string, string | undefined> = {
    branch_id: filters.branch_id,
    contract_type_id: filters.contract_type_id,
    department_id: filters.department_id,
    owner_uuid: filters.owner_uuid,
    counterparty: filters.counterparty.trim(),
    status: filters.status,
    risk_level: filters.risk_level,
    ...resolvePeriod(filters.period, financialYear),
  }

  const query: Record<string, string> = {}
  for (const [key, value] of Object.entries(candidates)) {
    if (value) query[key] = value
  }
  return query
}

/**
 * The same filters as a query string for a drill-through link, plus whatever
 * the tile itself narrows by. The tile's own parameters win: "Draft" means
 * draft even if the dashboard is showing every status.
 */
export function filterSearch(
  filters: DashboardFilterState,
  financialYear: FinancialYearRange | null | undefined,
  extra: Record<string, string> = {},
): string {
  const params = new URLSearchParams({ ...dashboardQuery(filters, financialYear), ...extra })
  const query = params.toString()
  return query ? `?${query}` : ''
}

export function activeFilterCount(filters: DashboardFilterState): number {
  return Object.entries(filters).filter(([key, value]) =>
    key === 'period' ? value !== 'all' : value !== '',
  ).length
}

interface Props {
  value: DashboardFilterState
  onChange: (next: DashboardFilterState) => void
}

export function DashboardFilters({ value, onChange }: Props) {
  const { branches } = useCompany()
  const { session } = useSession()
  const [drawerOpen, setDrawerOpen] = useState(false)

  const types = useApiResource<ContractTypeSummary[]>(
    (signal) => api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal),
    [],
  )
  const departments = useApiResource<DepartmentSummary[]>(
    (signal) => api.get<DepartmentSummary[]>('/settings/departments', undefined, signal),
    [],
  )

  const set = <K extends keyof DashboardFilterState>(key: K, next: DashboardFilterState[K]) => {
    onChange({ ...value, [key]: next })
  }

  const options = {
    branches: (branches ?? []).map((branch) => ({ value: branch.id, label: branch.name })),
    types: (types.data ?? []).map((type) => ({ value: String(type.id), label: type.name })),
    departments: (departments.data ?? []).map((department) => ({
      value: String(department.id),
      label: department.name,
    })),
    owners: session?.uuid
      ? [{ value: session.uuid, label: 'Assigned to me' }]
      : [],
  }

  const lookupsFailed = Boolean(types.error) || Boolean(departments.error)
  const active = activeFilterCount(value)
  const reset = () => onChange(DEFAULT_DASHBOARD_FILTERS)

  const controls = (stacked: boolean) => (
    <FilterControls
      value={value}
      set={set}
      options={options}
      lookupsFailed={lookupsFailed}
      stacked={stacked}
    />
  )

  return (
    <section aria-label="Dashboard filters" className="ct-no-print" style={{ marginBottom: 16 }}>
      <style>{`
        .ct-dash-filters-compact { display: none; }
        @media (max-width: 900px) {
          .ct-dash-filters-wide { display: none; }
          .ct-dash-filters-compact { display: flex; }
        }
      `}</style>

      <div
        className="ct-card ct-dash-filters-wide"
        style={{
          padding: 14,
          display: 'flex',
          flexWrap: 'wrap',
          alignItems: 'flex-end',
          gap: 10,
        }}
      >
        {controls(false)}
        {active > 0 ? (
          <Button variant="ghost" size="sm" icon={<X size={13} />} onClick={reset}>
            Clear {active}
          </Button>
        ) : null}
      </div>

      <div className="ct-dash-filters-compact" style={{ gap: 8, alignItems: 'center' }}>
        <Button
          variant="secondary"
          icon={<SlidersHorizontal size={14} />}
          onClick={() => setDrawerOpen(true)}
        >
          Filters
          {active > 0 ? (
            <span
              style={{
                minWidth: 18,
                marginLeft: 2,
                padding: '0 5px',
                borderRadius: 999,
                background: 'var(--color-primary-muted)',
                color: 'rgb(var(--color-primary-active))',
                fontSize: 10.5,
                lineHeight: '17px',
              }}
            >
              {active}
            </span>
          ) : null}
        </Button>
        {active > 0 ? (
          <Button variant="ghost" size="sm" onClick={reset}>
            Clear
          </Button>
        ) : null}
      </div>

      <Drawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        title="Filter dashboard"
        footer={
          <>
            <Button variant="secondary" onClick={reset}>
              Clear all
            </Button>
            <Button variant="primary" onClick={() => setDrawerOpen(false)}>
              Done
            </Button>
          </>
        }
      >
        <div style={{ display: 'grid', gap: 14 }}>{controls(true)}</div>
      </Drawer>
    </section>
  )
}

interface ControlProps {
  value: DashboardFilterState
  set: <K extends keyof DashboardFilterState>(key: K, next: DashboardFilterState[K]) => void
  options: {
    branches: { value: string; label: string }[]
    types: { value: string; label: string }[]
    departments: { value: string; label: string }[]
    owners: { value: string; label: string }[]
  }
  lookupsFailed: boolean
  /** In the drawer the controls are a column, so they take the full width. */
  stacked: boolean
}

function FilterControls({ value, set, options, lookupsFailed, stacked }: ControlProps) {
  return (
    <>
      <FilterSlot stacked={stacked}>
        <Select
          label="Period"
          value={value.period}
          options={PERIOD_OPTIONS}
          onChange={(event) => set('period', event.target.value as DashboardPeriod)}
        />
      </FilterSlot>

      {options.branches.length > 1 ? (
        <FilterSlot stacked={stacked}>
          <Select
            label="Branch"
            value={value.branch_id}
            placeholder="All branches"
            options={options.branches}
            onChange={(event) => set('branch_id', event.target.value)}
          />
        </FilterSlot>
      ) : null}

      <FilterSlot stacked={stacked}>
        <Select
          label="Contract type"
          value={value.contract_type_id}
          placeholder="All types"
          options={options.types}
          onChange={(event) => set('contract_type_id', event.target.value)}
        />
      </FilterSlot>

      <FilterSlot stacked={stacked}>
        <Select
          label="Department"
          value={value.department_id}
          placeholder="All departments"
          options={options.departments}
          onChange={(event) => set('department_id', event.target.value)}
        />
      </FilterSlot>

      {options.owners.length > 0 ? (
        <FilterSlot stacked={stacked}>
          <Select
            label="Owner"
            value={value.owner_uuid}
            placeholder="Anyone"
            options={options.owners}
            onChange={(event) => set('owner_uuid', event.target.value)}
          />
        </FilterSlot>
      ) : null}

      <FilterSlot stacked={stacked}>
        <Select
          label="Status"
          value={value.status}
          placeholder="Any status"
          options={STATUS_OPTIONS}
          onChange={(event) => set('status', event.target.value)}
        />
      </FilterSlot>

      <FilterSlot stacked={stacked}>
        <Select
          label="Risk"
          value={value.risk_level}
          placeholder="Any risk"
          options={RISK_OPTIONS}
          onChange={(event) => set('risk_level', event.target.value)}
        />
      </FilterSlot>

      <FilterSlot stacked={stacked} width={200}>
        <CounterpartyInput value={value.counterparty} onCommit={(next) => set('counterparty', next)} />
      </FilterSlot>

      {lookupsFailed ? (
        <p
          role="status"
          style={{ fontSize: 12, color: 'var(--color-warning-text)', alignSelf: 'center' }}
        >
          Type and department lists could not be loaded, so those filters are empty.
        </p>
      ) : null}
    </>
  )
}

function FilterSlot({
  children,
  stacked,
  width = 168,
}: {
  children: ReactNode
  stacked: boolean
  width?: number
}) {
  return (
    <div style={{ flex: '1 1 auto', minWidth: 140, maxWidth: stacked ? undefined : width }}>
      {children}
    </div>
  )
}

/**
 * Typing a counterparty should not fire a request per keystroke — each one
 * refetches four aggregates — so the value settles for a moment first.
 */
function CounterpartyInput({
  value,
  onCommit,
}: {
  value: string
  onCommit: (next: string) => void
}) {
  const [draft, setDraft] = useState(value)

  // The parent rebuilds its handler on every render; holding it in a ref keeps
  // that from restarting the timer and swallowing the commit.
  const commit = useRef(onCommit)
  commit.current = onCommit

  // A reset from outside (Clear all) has to reach the box the user is looking at.
  useEffect(() => setDraft(value), [value])

  useEffect(() => {
    if (draft === value) return
    const timer = window.setTimeout(() => commit.current(draft), 400)
    return () => window.clearTimeout(timer)
  }, [draft, value])

  return (
    <Input
      label="Counterparty"
      type="search"
      value={draft}
      placeholder="Name contains…"
      onChange={(event) => setDraft(event.target.value)}
    />
  )
}
