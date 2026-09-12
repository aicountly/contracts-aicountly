import { useEffect, useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft,
  BarChart3,
  Download,
  FileSpreadsheet,
  Lock,
  Printer,
  Search,
  SlidersHorizontal,
  X,
} from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  DataTable,
  DateInput,
  Drawer,
  EmptyState,
  ErrorState,
  Input,
  RiskChip,
  PageHeader,
  Pagination,
  Select,
  SkeletonCards,
  StatusChip,
} from '../components/ui'
import type { Column } from '../components/ui'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ensureSesKey } from '../auth/portal'
import { getApiBaseUrl } from '../config'
import { ApiError, api, getCompanyContext } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import {
  CONTRACT_STATUSES,
  REPORT_FILTER_KEYS,
  RISK_LEVELS,
  type ContractTypeSummary,
  type DepartmentSummary,
  type ReportColumnDefinition,
  type ReportColumnsPayload,
  type ReportDefinition,
  type ReportFilterKey,
  type ReportResult,
  type ReportRow,
} from '../types/contracts'
import { formatDate, formatDateTime, formatMoney, formatNumber, humanise } from '../utils/format'

/**
 * Every report in the catalogue, and one report at a time.
 *
 * There are two dozen reports and there will be more, so this screen is driven
 * by the definition the server publishes rather than written once per report:
 * a report added to the catalogue appears here, with its own columns and its
 * own filters, without a line of front-end code. That also means the screen
 * must survive a definition it has never seen — an unknown column type renders
 * as text rather than throwing the page away.
 */

const DEFAULT_FILTERS: ReportFilterKey[] = ['status', 'date_from', 'date_to']

const NARROW_QUERY = '(max-width: 900px)'

/** Sorting orders the page the server sent, so a page has to be worth sorting. */
const PER_PAGE_OPTIONS = [50, 100, 200]

function useIsNarrow(): boolean {
  const [narrow, setNarrow] = useState(
    () => typeof window !== 'undefined' && window.matchMedia?.(NARROW_QUERY).matches === true,
  )

  useEffect(() => {
    const query = window.matchMedia?.(NARROW_QUERY)
    if (!query) return
    const onChange = (event: MediaQueryListEvent) => setNarrow(event.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  return narrow
}

function reportName(definition: ReportDefinition): string {
  return definition.name ?? definition.title ?? humanise(definition.key)
}

function reportGroup(definition: ReportDefinition): string {
  return definition.group ?? definition.category ?? 'Reports'
}

/**
 * Columns, whichever way the catalogue spells them.
 *
 * The API contract says `columns`, not what one looks like, and the server's
 * own CSV fallback already reads three forms — a list of objects, a key → label
 * map, and bare names. Normalising here means the table below only ever handles
 * one shape.
 */
function normaliseColumns(payload: ReportColumnsPayload | null | undefined): ReportColumnDefinition[] {
  if (!payload) return []

  if (Array.isArray(payload)) {
    return payload.map((entry) =>
      typeof entry === 'string' ? { key: entry, label: humanise(entry) } : entry,
    )
  }

  return Object.entries(payload).map(([key, label]) => ({ key, label }))
}

/** Columns inferred from the rows, for a report whose definition carries none. */
function columnsFromRows(rows: ReportRow[]): ReportColumnDefinition[] {
  const first = rows[0]
  if (!first) return []
  return Object.keys(first).map((key) => ({ key, label: humanise(key) }))
}

function columnLabel(column: ReportColumnDefinition): string {
  return column.label ?? column.header ?? humanise(column.key)
}

function isNumericType(type: string | null | undefined): boolean {
  return type === 'number' || type === 'money' || type === 'percent'
}

function toComparable(value: unknown): number | string | null {
  if (value === null || value === undefined || value === '') return null
  if (typeof value === 'number') return value
  if (typeof value === 'boolean') return value ? 1 : 0
  const text = String(value)
  // A date is already sortable as text in ISO form, so only a plain number is
  // worth converting — "2026-01-05" must not become NaN and sort as nothing.
  if (/^-?\d+(\.\d+)?$/.test(text)) return Number(text)
  return text.toLowerCase()
}

function currencyFor(row: ReportRow, column: ReportColumnDefinition): string {
  const key = column.currency_key ?? 'currency'
  const value = row[key]
  return typeof value === 'string' && value.length === 3 ? value : 'INR'
}

function renderCell(column: ReportColumnDefinition, row: ReportRow): React.ReactNode {
  const value = row[column.key]

  if (value === null || value === undefined || value === '') {
    return <span style={{ color: 'var(--color-text-subtle)' }}>—</span>
  }

  switch (column.type) {
    case 'money':
      return (
        <span style={{ fontVariantNumeric: 'tabular-nums' }}>
          {formatMoney(value as string | number, currencyFor(row, column))}
        </span>
      )
    case 'number':
      return (
        <span style={{ fontVariantNumeric: 'tabular-nums' }}>
          {formatNumber(value as string | number)}
        </span>
      )
    case 'percent':
      return (
        <span style={{ fontVariantNumeric: 'tabular-nums' }}>
          {formatNumber(value as string | number)}%
        </span>
      )
    case 'date':
      return formatDate(String(value))
    case 'datetime':
      return formatDateTime(String(value))
    case 'boolean':
      return <Chip size="sm" tone={value ? 'primary' : 'neutral'}>{value ? 'Yes' : 'No'}</Chip>
    case 'status':
      return <StatusChip status={String(value)} size="sm" />
    case 'risk':
      return <RiskChip level={String(value)} />
    default:
      break
  }

  const text = String(value)
  const linkId = column.link_key ? row[column.link_key] : undefined

  if (linkId !== null && linkId !== undefined && linkId !== '') {
    return (
      <Link to={`/contracts/${String(linkId)}`} className="ct-link">
        {text}
      </Link>
    )
  }

  return text
}

function saveBlob(blob: Blob, filename: string): void {
  const href = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = href
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(href)
}

/**
 * The server's CSV for the filters on screen.
 *
 * As on the repository, this is the one call that does not go through
 * `apiClient`: that client unwraps a JSON envelope and would choke on
 * `text/csv`, while a plain link cannot carry the bearer token or the company
 * headers the endpoint requires.
 */
async function downloadReportCsv(
  key: string,
  filters: Record<string, string>,
  filename: string,
): Promise<void> {
  const context = getCompanyContext()
  if (!context) throw new ApiError('Select a company first.', 400, 'MISSING_COMPANY_CONTEXT')

  const sesKey = await ensureSesKey()
  const url = new URL(`${getApiBaseUrl()}/reports/${key}/export`, window.location.origin)
  for (const [name, value] of Object.entries(filters)) {
    if (value !== '') url.searchParams.set(name, value)
  }

  const response = await fetch(url.toString(), {
    headers: {
      Authorization: `Bearer ${sesKey}`,
      Accept: 'text/csv',
      'X-AIC-CMP-ID': context.cmp_id,
      'X-AIC-FY-ID': context.fy_id,
      'X-AIC-BO-ID': context.bo_id,
    },
  })

  if (!response.ok) {
    throw new ApiError(
      response.status === 403
        ? 'You do not have permission to export reports.'
        : response.status === 429
          ? 'Too many exports in a short time. Wait a few minutes and try again.'
          : 'The export could not be produced.',
      response.status,
    )
  }

  saveBlob(await response.blob(), filename)
}

export default function Reports() {
  const { reportKey } = useParams<{ reportKey: string }>()
  const { can } = useSession()

  const catalogue = useApiResource<ReportDefinition[]>(
    (signal) => api.get<ReportDefinition[]>('/reports', undefined, signal),
    [],
    { enabled: can(PERMISSION.REPORT_VIEW) },
  )

  if (!can(PERMISSION.REPORT_VIEW)) {
    return (
      <>
        <PageHeader title="Reports" />
        <Card>
          <EmptyState
            icon={<Lock size={22} />}
            title="Reports are not part of your access"
            description="A report can summarise commercial terms across the whole portfolio, so it is granted separately. Ask an administrator for the reporting permission if you need it."
          />
        </Card>
      </>
    )
  }

  if (catalogue.error) {
    return (
      <>
        <PageHeader title="Reports" />
        <Card>
          <ErrorState
            title="Could not load the report catalogue"
            detail={catalogue.error.message}
            onRetry={catalogue.reload}
          />
        </Card>
      </>
    )
  }

  if (catalogue.loading) {
    return (
      <>
        <PageHeader title="Reports" description="Loading the catalogue…" />
        <SkeletonCards count={6} height={104} />
      </>
    )
  }

  const definitions = catalogue.data ?? []

  if (!reportKey) return <ReportCatalogue definitions={definitions} />

  const definition = definitions.find((entry) => entry.key === reportKey)

  if (!definition) {
    return (
      <>
        <PageHeader title="Reports" breadcrumb={<CatalogueLink />} />
        <Card>
          <EmptyState
            icon={<BarChart3 size={22} />}
            title="No such report"
            description={`Nothing in the catalogue is called “${reportKey}”. It may have been renamed, or the link may be from an older version of the product.`}
            action={
              <Link to="/reports">
                <Button variant="primary">Browse all reports</Button>
              </Link>
            }
          />
        </Card>
      </>
    )
  }

  // Keyed on the report so switching between two reports resets filters, sort
  // and page rather than running the new report with the old report's state.
  return <ReportView key={definition.key} definition={definition} />
}

function CatalogueLink() {
  return (
    <Link
      to="/reports"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        fontSize: 12.5,
        fontWeight: 600,
        color: 'var(--color-text-secondary)',
      }}
    >
      <ArrowLeft size={13} aria-hidden />
      All reports
    </Link>
  )
}

/* --- The catalogue -------------------------------------------------------- */

function ReportCatalogue({ definitions }: { definitions: ReportDefinition[] }) {
  const [query, setQuery] = useState('')

  const groups = useMemo(() => {
    const needle = query.trim().toLowerCase()
    const matched = needle
      ? definitions.filter((definition) =>
          [reportName(definition), definition.description ?? '', definition.key]
            .join(' ')
            .toLowerCase()
            .includes(needle),
        )
      : definitions

    const byGroup = new Map<string, ReportDefinition[]>()
    for (const definition of matched) {
      const group = reportGroup(definition)
      byGroup.set(group, [...(byGroup.get(group) ?? []), definition])
    }

    return [...byGroup.entries()]
  }, [definitions, query])

  const matchCount = groups.reduce((sum, [, entries]) => sum + entries.length, 0)

  return (
    <>
      <PageHeader
        title="Reports"
        description="Standing views of the portfolio, each one filterable, printable and exportable. Nothing here changes a contract."
      />

      {definitions.length === 0 ? (
        <Card>
          <EmptyState
            icon={<BarChart3 size={22} />}
            title="No reports are available"
            description="The report catalogue came back empty. That usually means this company has just been set up; reports appear once there are contracts to report on."
          />
        </Card>
      ) : (
        <>
          <div className="ct-no-print" style={{ maxWidth: 380, marginBottom: 18 }}>
            <label htmlFor="report-search" className="ct-sr-only">
              Search reports
            </label>
            <div style={{ position: 'relative' }}>
              <Search
                size={15}
                aria-hidden
                style={{
                  position: 'absolute',
                  left: 10,
                  top: '50%',
                  transform: 'translateY(-50%)',
                  color: 'var(--color-text-subtle)',
                  pointerEvents: 'none',
                }}
              />
              <input
                id="report-search"
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={`Search ${definitions.length} reports…`}
                style={{
                  width: '100%',
                  height: 36,
                  padding: '0 10px 0 32px',
                  borderRadius: 'var(--radius-md)',
                  border: '1px solid rgb(var(--color-border-strong))',
                  background: 'var(--color-bg-card)',
                  fontSize: 13.5,
                }}
              />
            </div>
          </div>

          <p aria-live="polite" className="ct-sr-only">
            {matchCount} {matchCount === 1 ? 'report matches' : 'reports match'} your search
          </p>

          {matchCount === 0 ? (
            <Card>
              <EmptyState
                title="No report matches that"
                description="Try a shorter word — reports are named for what they answer, such as “expiring”, “renewal” or “obligation”."
                action={
                  <Button variant="secondary" onClick={() => setQuery('')}>
                    Clear search
                  </Button>
                }
              />
            </Card>
          ) : (
            <div style={{ display: 'grid', gap: 24 }}>
              {groups.map(([group, entries]) => (
                <section key={group}>
                  <h2
                    style={{
                      fontSize: 11,
                      fontWeight: 800,
                      letterSpacing: '.07em',
                      textTransform: 'uppercase',
                      color: 'var(--color-text-subtle)',
                      marginBottom: 10,
                    }}
                  >
                    {group}
                  </h2>
                  <ul
                    style={{
                      listStyle: 'none',
                      display: 'grid',
                      gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))',
                      gap: 12,
                    }}
                  >
                    {entries.map((definition) => (
                      <li key={definition.key}>
                        <Link
                          to={`/reports/${definition.key}`}
                          className="ct-card"
                          style={{
                            display: 'block',
                            height: '100%',
                            padding: 15,
                            color: 'inherit',
                            transition: 'border-color .12s ease, box-shadow .12s ease',
                          }}
                        >
                          <span
                            aria-hidden
                            style={{
                              display: 'inline-flex',
                              alignItems: 'center',
                              justifyContent: 'center',
                              width: 30,
                              height: 30,
                              borderRadius: 'var(--radius-md)',
                              background: 'var(--color-primary-muted)',
                              color: 'rgb(var(--color-primary-active))',
                              marginBottom: 10,
                            }}
                          >
                            <BarChart3 size={16} />
                          </span>
                          <span
                            style={{
                              display: 'block',
                              fontSize: 13.8,
                              fontWeight: 700,
                              color: 'var(--color-text)',
                            }}
                          >
                            {reportName(definition)}
                          </span>
                          {definition.description ? (
                            <span
                              style={{
                                display: 'block',
                                fontSize: 12.3,
                                color: 'var(--color-text-secondary)',
                                marginTop: 4,
                                lineHeight: 1.55,
                              }}
                            >
                              {definition.description}
                            </span>
                          ) : null}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </section>
              ))}
            </div>
          )}
        </>
      )}
    </>
  )
}

/* --- One report ----------------------------------------------------------- */

interface TableRow {
  key: string
  row: ReportRow
}

function ReportView({ definition }: { definition: ReportDefinition }) {
  const toast = useToast()
  const { can } = useSession()
  const narrow = useIsNarrow()

  const [searchParams, setSearchParams] = useSearchParams()
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(PER_PAGE_OPTIONS[0])
  const [sort, setSort] = useState<{ key: string; dir: 'asc' | 'desc' } | undefined>()
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [exporting, setExporting] = useState(false)

  const filterKeys = useMemo<ReportFilterKey[]>(() => {
    const declared = definition.filters
    if (!declared || declared.length === 0) return DEFAULT_FILTERS
    return REPORT_FILTER_KEYS.filter((key) => declared.includes(key))
  }, [definition])

  // The filters live in the URL so a filtered report can be sent to a colleague
  // and comes back the same after a reload or a browser back.
  const filters = useMemo<Record<string, string>>(() => {
    const active: Record<string, string> = {}
    for (const key of filterKeys) {
      const value = searchParams.get(key) ?? definition.default_filters?.[key] ?? ''
      if (value !== '') active[key] = value
    }
    return active
  }, [filterKeys, searchParams, definition])

  const filterSignature = JSON.stringify(filters)

  const setFilter = (key: ReportFilterKey, value: string) => {
    const next = new URLSearchParams(searchParams)
    if (value === '') next.delete(key)
    else next.set(key, value)
    setSearchParams(next, { replace: true })
    setPage(1)
  }

  const clearFilters = () => {
    setSearchParams(new URLSearchParams(), { replace: true })
    setPage(1)
  }

  const needsTypes = filterKeys.includes('contract_type_id')
  const needsDepartments = filterKeys.includes('department_id')

  const types = useApiResource<ContractTypeSummary[]>(
    (signal) => api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal),
    [],
    { enabled: needsTypes },
  )

  const departments = useApiResource<DepartmentSummary[]>(
    (signal) => api.get<DepartmentSummary[]>('/settings/departments', undefined, signal),
    [],
    { enabled: needsDepartments },
  )

  const result = useApiResource<ReportResult>(
    (signal) =>
      api.get<ReportResult>(
        `/reports/${definition.key}`,
        { ...filters, page, per_page: perPage },
        signal,
      ),
    [definition.key, filterSignature, page, perPage],
  )

  const rawRows = useMemo<ReportRow[]>(
    () => result.data?.rows ?? result.data?.items ?? [],
    [result.data],
  )

  const columns = useMemo<ReportColumnDefinition[]>(() => {
    const fromResult = normaliseColumns(result.data?.columns)
    if (fromResult.length > 0) return fromResult
    const fromDefinition = normaliseColumns(definition.columns)
    if (fromDefinition.length > 0) return fromDefinition
    return columnsFromRows(rawRows)
  }, [result.data, definition.columns, rawRows])

  const tableRows = useMemo<TableRow[]>(() => {
    const wrapped = rawRows.map((row, index) => ({ key: String(index), row }))
    if (!sort) return wrapped

    const column = columns.find((entry) => entry.key === sort.key)
    const numeric = isNumericType(column?.type)
    const direction = sort.dir === 'asc' ? 1 : -1

    return [...wrapped].sort((a, b) => {
      const left = toComparable(a.row[sort.key])
      const right = toComparable(b.row[sort.key])

      // An empty cell sorts last in both directions: a blank is not a small
      // value, and floating blanks to the top of a descending sort buries the
      // rows someone opened the report to see.
      if (left === null && right === null) return 0
      if (left === null) return 1
      if (right === null) return -1

      if (numeric || (typeof left === 'number' && typeof right === 'number')) {
        return (Number(left) - Number(right)) * direction
      }
      return String(left).localeCompare(String(right)) * direction
    })
  }, [rawRows, sort, columns])

  const tableColumns = useMemo<Column<TableRow>[]>(
    () =>
      columns.map((column) => ({
        key: column.key,
        header: columnLabel(column),
        sortKey: column.key,
        align: column.align ?? (isNumericType(column.type) ? 'right' : 'left'),
        width: column.width ?? undefined,
        hideBelow: column.hide_below ?? undefined,
        render: (entry: TableRow) => renderCell(column, entry.row),
      })),
    [columns],
  )

  const total = result.data?.total ?? rawRows.length
  const summary = result.data?.summary ?? null
  const isFiltered = Object.keys(filters).length > 0
  const canExport = can(PERMISSION.EXPORT)

  const runExport = async () => {
    setExporting(true)
    try {
      await downloadReportCsv(
        definition.key,
        filters,
        `report-${definition.key}-${new Date().toISOString().slice(0, 10)}.csv`,
      )
      toast.success('Export ready', 'The CSV has been downloaded.')
    } catch (err) {
      toast.error('Export failed', err instanceof Error ? err.message : undefined)
    } finally {
      setExporting(false)
    }
  }

  const filterControls = (
    <div
      style={{
        display: 'grid',
        gap: 12,
        gridTemplateColumns: narrow ? '1fr' : 'repeat(auto-fit, minmax(180px, 1fr))',
        alignItems: 'end',
      }}
    >
      {filterKeys.map((key) => (
        <ReportFilterControl
          key={key}
          filterKey={key}
          value={filters[key] ?? ''}
          onChange={(value) => setFilter(key, value)}
          types={types.data ?? []}
          departments={departments.data ?? []}
        />
      ))}
      {isFiltered ? (
        <div>
          <Button size="sm" variant="ghost" icon={<X size={13} />} onClick={clearFilters}>
            Clear filters
          </Button>
        </div>
      ) : null}
    </div>
  )

  return (
    <>
      <style>{`
        .ct-print-only { display: none; }
        @media print {
          .ct-print-only { display: block; }
        }
      `}</style>

      <PageHeader
        title={reportName(definition)}
        description={definition.description ?? undefined}
        breadcrumb={<CatalogueLink />}
        actions={
          <>
            {narrow && filterKeys.length > 0 ? (
              <Button
                variant="secondary"
                icon={<SlidersHorizontal size={14} />}
                onClick={() => setFiltersOpen(true)}
              >
                Filters
                {isFiltered ? (
                  <span style={{ color: 'rgb(var(--color-primary))' }}>
                    {Object.keys(filters).length}
                  </span>
                ) : null}
              </Button>
            ) : null}
            <Button variant="secondary" icon={<Printer size={14} />} onClick={() => window.print()}>
              Print
            </Button>
            {canExport ? (
              <Button
                variant="primary"
                icon={<Download size={14} />}
                loading={exporting}
                onClick={() => void runExport()}
              >
                Export CSV
              </Button>
            ) : null}
          </>
        }
      />

      <div
        className="ct-print-only"
        style={{ marginBottom: 12, fontSize: 12, color: 'var(--color-text-secondary)' }}
      >
        {isFiltered ? `Filtered by ${describeFilters(filters)}. ` : 'No filters applied. '}
        Produced {formatDate(new Date().toISOString().slice(0, 10))}.
      </div>

      {filterKeys.length > 0 && !narrow ? (
        <Card className="ct-no-print" style={{ marginBottom: 16, padding: 14 }}>
          {filterControls}
        </Card>
      ) : null}

      <Drawer
        open={narrow && filtersOpen}
        onClose={() => setFiltersOpen(false)}
        title="Filters"
        footer={
          <>
            <Button variant="ghost" onClick={clearFilters}>
              Clear all
            </Button>
            <Button variant="primary" onClick={() => setFiltersOpen(false)}>
              Show results
            </Button>
          </>
        }
      >
        {filterControls}
      </Drawer>

      {summary ? <ReportSummaryStrip summary={summary} /> : null}

      <Card padded={false}>
        <p aria-live="polite" className="ct-sr-only">
          {result.loading
            ? 'Running the report'
            : `${total} ${total === 1 ? 'row' : 'rows'} in ${reportName(definition)}`}
        </p>

        {result.error ? (
          <ErrorState
            title="Could not run this report"
            detail={result.error.message}
            onRetry={result.reload}
          />
        ) : !result.loading && rawRows.length === 0 ? (
          <EmptyState
            icon={<FileSpreadsheet size={22} />}
            title={isFiltered ? 'Nothing matches these filters' : 'This report has nothing to show'}
            description={
              isFiltered
                ? 'No row fits the conditions you have set. Widen the dates, or clear a filter and start again.'
                : 'The report ran, and there is nothing in the portfolio that belongs in it yet. It will fill as contracts are added.'
            }
            action={
              isFiltered ? (
                <Button variant="secondary" onClick={clearFilters}>
                  Clear filters
                </Button>
              ) : (
                <Link to="/contracts">
                  <Button variant="secondary">Open the repository</Button>
                </Link>
              )
            }
          />
        ) : (
          <>
            <DataTable
              columns={tableColumns}
              rows={tableRows}
              rowKey={(entry) => entry.key}
              loading={result.loading}
              caption={`${reportName(definition)} — ${total} rows`}
              sort={sort}
              onSortChange={(key, dir) => setSort({ key, dir })}
            />

            {total > rawRows.length ? (
              <p
                className="ct-no-print"
                style={{
                  padding: '8px 14px 0',
                  fontSize: 11.5,
                  color: 'var(--color-text-muted)',
                }}
              >
                Sorting orders the rows on this page. Export the CSV to sort the whole report.
              </p>
            ) : null}

            <div className="ct-no-print">
              <Pagination
                page={page}
                perPage={perPage}
                total={total}
                onPageChange={setPage}
                onPerPageChange={(next) => {
                  setPerPage(next)
                  setPage(1)
                }}
                perPageOptions={PER_PAGE_OPTIONS}
              />
            </div>
          </>
        )}
      </Card>
    </>
  )
}

function describeFilters(filters: Record<string, string>): string {
  return Object.entries(filters)
    .map(([key, value]) => `${humanise(key)}: ${value}`)
    .join(', ')
}

function ReportFilterControl({
  filterKey,
  value,
  onChange,
  types,
  departments,
}: {
  filterKey: ReportFilterKey
  value: string
  onChange: (value: string) => void
  types: ContractTypeSummary[]
  departments: DepartmentSummary[]
}) {
  switch (filterKey) {
    case 'status':
      return (
        <Select
          label="Status"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          options={CONTRACT_STATUSES.map((status) => ({ value: status, label: humanise(status) }))}
          placeholder="Any status"
        />
      )
    case 'risk_level':
      return (
        <Select
          label="Risk level"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          options={RISK_LEVELS.map((level) => ({ value: level, label: humanise(level) }))}
          placeholder="Any risk level"
        />
      )
    case 'contract_type_id':
      return (
        <Select
          label="Contract type"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          options={types.map((type) => ({ value: String(type.id), label: type.name }))}
          placeholder="Any type"
        />
      )
    case 'department_id':
      return (
        <Select
          label="Department"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          options={departments.map((department) => ({
            value: String(department.id),
            label: department.name,
          }))}
          placeholder="Any department"
        />
      )
    case 'counterparty':
      return (
        <Input
          label="Counterparty"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder="Name contains…"
        />
      )
    case 'owner_uuid':
      return (
        <Input
          label="Owner"
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder="User id"
          hint="The owner's AICOUNTLY user id"
        />
      )
    case 'date_from':
      return (
        <DateInput label="From" value={value} onChange={(event) => onChange(event.target.value)} />
      )
    case 'date_to':
      return (
        <DateInput label="To" value={value} onChange={(event) => onChange(event.target.value)} />
      )
    default:
      return null
  }
}

/**
 * The report's own totals.
 *
 * A summary entry is either a plain value or an object carrying its own label
 * and format, because the catalogue is server-side data and the second form is
 * what a money total needs to render as money rather than as a long integer.
 */
function ReportSummaryStrip({ summary }: { summary: Record<string, unknown> }) {
  const tiles = Object.entries(summary)
    .map(([key, raw]) => {
      if (raw !== null && typeof raw === 'object' && !Array.isArray(raw)) {
        const entry = raw as {
          label?: string
          value?: unknown
          format?: string
          currency?: string
        }
        return {
          key,
          label: entry.label ?? humanise(key),
          value: formatSummaryValue(entry.value, entry.format, entry.currency),
        }
      }
      if (raw === null || raw === undefined || Array.isArray(raw)) return null
      return { key, label: humanise(key), value: formatSummaryValue(raw) }
    })
    .filter((tile): tile is { key: string; label: string; value: string } => tile !== null)

  if (tiles.length === 0) return null

  return (
    <dl
      style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))',
        gap: 12,
        marginBottom: 16,
      }}
    >
      {tiles.map((tile) => (
        <div key={tile.key} className="ct-card" style={{ padding: '12px 14px' }}>
          <dt
            style={{
              fontSize: 11,
              fontWeight: 700,
              letterSpacing: '.03em',
              textTransform: 'uppercase',
              color: 'var(--color-text-muted)',
            }}
          >
            {tile.label}
          </dt>
          <dd
            style={{
              fontSize: 19,
              fontWeight: 800,
              color: 'var(--color-text)',
              marginTop: 4,
              fontVariantNumeric: 'tabular-nums',
            }}
          >
            {tile.value}
          </dd>
        </div>
      ))}
    </dl>
  )
}

function formatSummaryValue(value: unknown, format?: string, currency?: string): string {
  if (value === null || value === undefined || value === '') return '—'
  if (format === 'money') return formatMoney(value as string | number, currency ?? 'INR')
  if (format === 'percent') return `${formatNumber(value as string | number)}%`
  if (format === 'date') return formatDate(String(value))
  if (typeof value === 'boolean') return value ? 'Yes' : 'No'
  if (typeof value === 'number') return formatNumber(value)
  return String(value)
}
