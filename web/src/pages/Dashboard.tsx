import { useState } from 'react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  CalendarClock,
  CheckCircle2,
  CheckSquare,
  ClipboardList,
  FilePlus2,
  FileText,
  PencilLine,
  RefreshCcw,
  RefreshCw,
  ShieldAlert,
  Signature,
  TrendingDown,
  TrendingUp,
  Wallet,
} from 'lucide-react'

import {
  BarChart,
  ChartFrame,
  toChartSeries,
  type ChartSeriesPoint,
} from '../components/dashboard/BarChart'
import { DonutChart } from '../components/dashboard/DonutChart'
import { TimelineChart, type TimelinePoint } from '../components/dashboard/TimelineChart'
import { KpiGrid, KpiGridSkeleton, KpiTile } from '../components/dashboard/KpiTile'
import { MyActionsPanel } from '../components/dashboard/MyActionsPanel'
import { ActivityFeed } from '../components/dashboard/ActivityFeed'
import {
  DEFAULT_DASHBOARD_FILTERS,
  DashboardFilters,
  dashboardQuery,
  filterSearch,
} from '../components/dashboard/DashboardFilters'
import { Button, Card, EmptyState, ErrorState, PageHeader, SkeletonCards } from '../components/ui'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import { useCompany } from '../context/CompanyProvider'
import { useSession } from '../context/SessionProvider'
import { PERMISSION } from '../types/permissions'
import { formatMoney, formatNumber } from '../utils/format'
import type {
  ActivityEntry,
  DashboardCharts,
  DashboardKpis,
  MyActions,
} from '../types/contracts'

/**
 * What we have, what needs a decision, and what is about to bite us.
 *
 * The four regions load independently. A slow charts query must not hold the
 * KPI row hostage, and the two personal panels are not filtered at all — "what
 * is waiting on me" does not change because someone narrowed the portfolio to
 * one department.
 */
export default function Dashboard() {
  const { can } = useSession()
  const { company, financialYears, fyId } = useCompany()
  const [filters, setFilters] = useState(DEFAULT_DASHBOARD_FILTERS)

  const financialYear = financialYears.find((year) => year.id === fyId) ?? null
  const query = dashboardQuery(filters, financialYear)
  const queryKey = JSON.stringify(query)

  const kpis = useApiResource<DashboardKpis>(
    (signal) => api.get<DashboardKpis>('/dashboard/kpis', query, signal),
    [queryKey],
  )
  const charts = useApiResource<DashboardCharts>(
    (signal) => api.get<DashboardCharts>('/dashboard/charts', query, signal),
    [queryKey],
  )
  const actions = useApiResource<MyActions>(
    (signal) => api.get<MyActions>('/dashboard/my-actions', undefined, signal),
    [],
  )
  const activity = useApiResource<ActivityEntry[]>(
    (signal) => api.get<ActivityEntry[]>('/dashboard/activity', { limit: 12 }, signal),
    [],
  )

  const reloadAll = () => {
    kpis.reload()
    charts.reload()
    actions.reload()
    activity.reload()
  }

  const canViewContracts = can(PERMISSION.CONTRACT_VIEW) || can(PERMISSION.CONTRACT_VIEW_ALL)
  const canViewCommercials = can(PERMISSION.COMMERCIALS_VIEW)
  const canViewRisk = can(PERMISSION.AI_RISK_VIEW)

  const currency = kpis.data?.currency ?? company?.currency ?? 'INR'
  const linkTo = (path: string, extra: Record<string, string> = {}) =>
    `${path}${filterSearch(filters, financialYear, extra)}`

  if (!canViewContracts) {
    return (
      <>
        <PageHeader title="Dashboard" />
        <Card>
          <EmptyState
            title="No contract access in this company"
            description="Your role here does not include viewing contracts. An administrator can grant it under Settings, Roles."
          />
        </Card>
      </>
    )
  }

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={
          company ? `Contract portfolio for ${company.name}.` : 'Your contract portfolio at a glance.'
        }
        actions={
          <>
            <Button variant="secondary" icon={<RefreshCcw size={14} />} onClick={reloadAll}>
              Refresh
            </Button>
            {can(PERMISSION.CONTRACT_CREATE) ? (
              /* A link, not a button in a link: the browser will not nest two
                 interactive elements, and people middle-click this one. */
              <Link
                to="/contracts/new"
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 7,
                  height: 36,
                  padding: '0 14px',
                  borderRadius: 'var(--radius-md)',
                  background: 'rgb(var(--color-primary))',
                  color: '#fff',
                  fontSize: 13.5,
                  fontWeight: 600,
                }}
              >
                <FilePlus2 size={14} aria-hidden />
                New contract
              </Link>
            ) : null}
          </>
        }
      />

      <DashboardFilters value={filters} onChange={setFilters} />

      <p className="ct-sr-only" aria-live="polite">
        {kpis.loading
          ? 'Loading dashboard figures.'
          : kpis.error
            ? `Dashboard figures failed to load. ${kpis.error.message}`
            : 'Dashboard figures updated for the selected filters.'}
      </p>

      <SectionHeading>Key figures</SectionHeading>

      {kpis.loading ? (
        <KpiGridSkeleton />
      ) : kpis.error ? (
        <Card>
          <ErrorState
            title="Could not load the key figures"
            detail={kpis.error.message}
            onRetry={kpis.reload}
          />
        </Card>
      ) : (
        <KpiGrid>
          <KpiTile
            label="Total contracts"
            value={count(kpis.data?.total_contracts)}
            to={linkTo('/contracts')}
            icon={FileText}
            tone="neutral"
          />
          <KpiTile
            label="Active"
            value={count(kpis.data?.active)}
            to={linkTo('/contracts', { status: 'active' })}
            icon={CheckCircle2}
            tone="success"
            note="In force today"
          />
          <KpiTile
            label="Draft"
            value={count(kpis.data?.draft)}
            to={linkTo('/contracts', { status: 'draft' })}
            icon={PencilLine}
            tone="neutral"
            note="Not yet submitted"
          />
          <KpiTile
            label="Awaiting approval"
            value={count(kpis.data?.awaiting_approval)}
            to={linkTo('/contracts', { status: 'awaiting_approval' })}
            icon={CheckSquare}
            tone="warning"
            emphasis="Needs a decision"
          />
          <KpiTile
            label="Awaiting signature"
            value={count(kpis.data?.awaiting_signature)}
            to={linkTo('/contracts', { status: 'awaiting_signature' })}
            icon={Signature}
            tone="warning"
            emphasis="With the counterparty"
          />
          <KpiTile
            label="Expiring soon"
            value={count(kpis.data?.expiring_soon)}
            to={linkTo('/contracts', {
              expiring_within_days: String(kpis.data?.expiring_within_days ?? 90),
            })}
            icon={CalendarClock}
            tone="warning"
            note={`Within ${kpis.data?.expiring_within_days ?? 90} days`}
          />
          <KpiTile
            label="Renewals due"
            value={count(kpis.data?.renewals_due)}
            to="/renewals?bucket=due"
            icon={RefreshCw}
            tone="warning"
            note="Decision window open"
          />
          <KpiTile
            label="Obligations due"
            value={count(kpis.data?.obligations_due)}
            to="/obligations?status=due"
            icon={ClipboardList}
            tone="info"
            note="Deliverables and payments"
          />
          <KpiTile
            label="Overdue obligations"
            value={count(kpis.data?.overdue_obligations)}
            to="/obligations?status=overdue"
            icon={AlertTriangle}
            tone="danger"
            emphasis="Past due"
          />
          {canViewRisk ? (
            <KpiTile
              label="High-risk contracts"
              value={count(kpis.data?.high_risk)}
              to={linkTo('/contracts', { risk_level: 'high' })}
              icon={ShieldAlert}
              tone="danger"
              emphasis="Flagged"
            />
          ) : null}
          {canViewCommercials ? (
            <>
              {/* The three money tiles land on the same list ordered by value:
                  the repository has no receivable/payable filter, so the split
                  itself is answered by the customer-versus-vendor chart below. */}
              <KpiTile
                label="Total contract value"
                value={money(kpis.data?.total_value, currency, true)}
                fullValue={money(kpis.data?.total_value, currency, false) ?? undefined}
                to={linkTo('/contracts', { sort: 'total_value', dir: 'desc' })}
                icon={Wallet}
                tone="primary"
                note="Sum of contracted value"
              />
              <KpiTile
                label="Receivable commitments"
                value={money(kpis.data?.receivable_commitments, currency, true)}
                fullValue={money(kpis.data?.receivable_commitments, currency, false) ?? undefined}
                to={linkTo('/contracts', { sort: 'total_value', dir: 'desc' })}
                icon={TrendingUp}
                tone="success"
                note="Money owed to us"
              />
              <KpiTile
                label="Payable commitments"
                value={money(kpis.data?.payable_commitments, currency, true)}
                fullValue={money(kpis.data?.payable_commitments, currency, false) ?? undefined}
                to={linkTo('/contracts', { sort: 'total_value', dir: 'desc' })}
                icon={TrendingDown}
                tone="neutral"
                note="Money we owe"
              />
            </>
          ) : null}
        </KpiGrid>
      )}

      <SectionHeading>Portfolio</SectionHeading>

      {charts.loading ? (
        <SkeletonCards count={4} height={220} />
      ) : charts.error ? (
        <Card>
          <ErrorState
            title="Could not load the charts"
            detail={charts.error.message}
            onRetry={charts.reload}
          />
        </Card>
      ) : (
        <ChartsGrid>
          <ChartCell title="Contracts by status" series={toChartSeries(charts.data?.by_status)}>
            {(title, series) => (
              <BarChart title={title} series={series} formatValue={formatCount} />
            )}
          </ChartCell>

          <ChartCell title="Contracts by type" series={toChartSeries(charts.data?.by_type)}>
            {(title, series) => (
              <BarChart title={title} series={series} formatValue={formatCount} />
            )}
          </ChartCell>

          <ChartCell title="Contracts by department" series={toChartSeries(charts.data?.by_department)}>
            {(title, series) => (
              <BarChart title={title} series={series} formatValue={formatCount} />
            )}
          </ChartCell>

          <ChartCell title="Renewal pipeline" series={toChartSeries(charts.data?.renewal_pipeline)}>
            {(title, series) => (
              <BarChart
                title={title}
                description="Where each upcoming renewal has got to."
                series={series}
                formatValue={formatCount}
              />
            )}
          </ChartCell>

          {canViewRisk ? (
            <ChartCell title="Risk distribution" series={toChartSeries(charts.data?.risk_distribution)}>
              {(title, series) => (
                <DonutChart title={title} series={series} formatValue={formatCount} colourBy="level" />
              )}
            </ChartCell>
          ) : null}

          <ChartCell title="Customer versus vendor" series={toChartSeries(charts.data?.customer_vs_vendor)}>
            {(title, series) => (
              <DonutChart
                title={title}
                description="Which side of the table we are on."
                series={series}
                formatValue={formatCount}
              />
            )}
          </ChartCell>

          {canViewCommercials ? (
            <ChartCell title="Value by category" series={toChartSeries(charts.data?.value_by_category)}>
              {(title, series) => (
                <BarChart
                  title={title}
                  series={series}
                  valueHeader="Value"
                  formatValue={(value) => formatMoney(value, currency, { compact: true })}
                />
              )}
            </ChartCell>
          ) : null}

          <ChartCell
            wide
            title="Expiry timeline"
            series={monthly(toChartSeries(charts.data?.expiry_timeline))}
          >
            {(title, series) => (
              <TimelineChart
                title={title}
                description="Contracts reaching their expiry date over the next twelve months."
                series={series}
                formatValue={formatCount}
                colourIndex={1}
              />
            )}
          </ChartCell>

          <ChartCell
            wide
            title="Obligations due"
            series={withOverdueTone(monthly(toChartSeries(charts.data?.obligations_timeline)))}
          >
            {(title, series) => (
              <TimelineChart
                title={title}
                description="Deliverables and payments falling due, by period."
                series={series}
                formatValue={formatCount}
                colourIndex={4}
              />
            )}
          </ChartCell>

          <ChartCell
            title="Counterparty concentration"
            series={toChartSeries(charts.data?.counterparty_mix)}
          >
            {(title, series) => (
              <BarChart
                title={title}
                description="Who the portfolio is with. The tail is summed rather than dropped."
                series={series}
                formatValue={formatCount}
              />
            )}
          </ChartCell>

          <ChartCell
            title="Approval turnaround"
            series={monthly(toChartSeries(charts.data?.approval_throughput))}
          >
            {(title, series) => (
              <TimelineChart
                title={title}
                description="Approvals completed each month. Runs still open are not counted — an approval sitting on a desk has no duration yet."
                series={series}
                formatValue={formatCount}
                colourIndex={3}
              />
            )}
          </ChartCell>

          <ChartCell
            wide
            title="Contracts executed"
            series={monthly(toChartSeries(charts.data?.monthly_executed))}
          >
            {(title, series) => (
              <TimelineChart
                title={title}
                description="Signed and in force, by month."
                series={series}
                formatValue={formatCount}
                variant="line"
                colourIndex={2}
              />
            )}
          </ChartCell>
        </ChartsGrid>
      )}

      <SectionHeading>Waiting on people</SectionHeading>

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))',
          gap: 14,
          alignItems: 'start',
        }}
      >
        <MyActionsPanel
          data={actions.data}
          loading={actions.loading}
          error={actions.error}
          onRetry={actions.reload}
        />
        <ActivityFeed
          entries={activity.data}
          loading={activity.loading}
          error={activity.error}
          onRetry={activity.reload}
        />
      </div>
    </>
  )
}

function SectionHeading({ children }: { children: ReactNode }) {
  return (
    <h2
      style={{
        fontSize: 12,
        fontWeight: 800,
        letterSpacing: '.06em',
        textTransform: 'uppercase',
        color: 'var(--color-text-muted)',
        margin: '26px 0 12px',
      }}
    >
      {children}
    </h2>
  )
}

function ChartsGrid({ children }: { children: ReactNode }) {
  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))',
        gap: 14,
        alignItems: 'start',
      }}
    >
      {children}
    </div>
  )
}

/**
 * One chart in its card, including the case the API answers honestly with
 * nothing. An empty chart still says what it would have shown, so a filter that
 * matches no contracts reads as an empty result rather than a broken panel.
 */
function ChartCell({
  title,
  series,
  wide = false,
  children,
}: {
  title: string
  series: ChartSeriesPoint[]
  wide?: boolean
  children: (title: string, series: ChartSeriesPoint[]) => ReactNode
}) {
  return (
    <Card style={wide ? { gridColumn: '1 / -1' } : undefined}>
      {series.length === 0 ? (
        <ChartFrame
          title={title}
          series={series}
          formatValue={formatCount}
          summary="No data for the current filters."
        >
          {() => (
            <EmptyState
              compact
              title="Nothing to chart yet"
              description="No contracts match the current filters for this view."
            />
          )}
        </ChartFrame>
      ) : (
        children(title, series)
      )}
    </Card>
  )
}

const SHORT_MONTHS = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
]

/**
 * Month buckets arrive as `2026-03`, which is unreadable as an axis label and
 * far too wide for twelve of them. `formatDate` is for whole dates, so the
 * month-only case is shortened here.
 */
function monthly(series: ChartSeriesPoint[]): ChartSeriesPoint[] {
  return series.map((point) => {
    const match = /^(\d{4})-(\d{2})/.exec(point.key)
    if (!match) return point
    return { ...point, label: `${SHORT_MONTHS[Number(match[2]) - 1] ?? match[2]} ${match[1].slice(2)}` }
  })
}

/** The overdue bucket is drawn as a hazard, not merely as another column. */
function withOverdueTone(series: ChartSeriesPoint[]): TimelinePoint[] {
  return series.map((point) => {
    if (/overdue|past.?due/i.test(point.key) || /overdue|past due/i.test(point.label)) {
      return { ...point, tone: 'danger' as const }
    }
    return point
  })
}

function formatCount(value: number): string {
  return formatNumber(value)
}

function count(value: number | null | undefined): string | null {
  return value === null || value === undefined ? null : formatNumber(value)
}

function money(
  value: string | number | null | undefined,
  currency: string,
  compact: boolean,
): string | null {
  if (value === null || value === undefined || value === '') return null
  return formatMoney(value, currency, { compact })
}
