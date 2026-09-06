import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  Archive,
  ArchiveRestore,
  Clock3,
  Download,
  FileUp,
  Plus,
  Search,
  SlidersHorizontal,
  Star,
  X,
} from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  ConfirmDialog,
  DataTable,
  Drawer,
  EmptyState,
  ErrorState,
  Pagination,
  PageHeader,
  RiskChip,
} from '../components/ui'
import type { Column } from '../components/ui'
import { ColumnPicker, readStoredColumns, writeStoredColumns } from '../components/contracts/ColumnPicker'
import type { ColumnOption } from '../components/contracts/ColumnPicker'
import { ContractStatusBadge } from '../components/contracts/ContractStatusBadge'
import {
  RepositoryFilters,
  activeFilterChips,
  filtersToQuery,
  hasActiveFilters,
} from '../components/contracts/RepositoryFilters'
import type { FilterLookups } from '../components/contracts/RepositoryFilters'
import { SavedViews } from '../components/contracts/SavedViews'
import type { SavedView } from '../components/contracts/SavedViews'
import { useToast } from '../context/ToastProvider'
import { useSession } from '../context/SessionProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api, getCompanyContext } from '../services/apiClient'
import { getApiBaseUrl } from '../config'
import { ensureSesKey } from '../auth/portal'
import { PERMISSION } from '../types/permissions'
import {
  EMPTY_CONTRACT_FILTERS,
  type ContractFilters,
  type ContractListItem,
  type ContractSort,
  type ContractSortKey,
  type ContractTypeSummary,
  type DepartmentSummary,
  type Paged,
  type TagSummary,
} from '../types/contracts'
import { formatDate, formatDateTime, formatMoney, humanise, truncate } from '../utils/format'

/**
 * The contract repository — the screen people live in.
 *
 * Everything that narrows the list is server-side: search, filters, sort and
 * paging all go back to `GET /contracts`. Sorting a page the client happens to
 * hold would reorder a 25-row slice of a 4,000-row portfolio and read as data
 * loss, so the client never does it.
 */

const SEARCH_DEBOUNCE_MS = 300
const COLUMNS_STORAGE_KEY = 'aic.contracts.columns'
const RECENT_STORAGE_KEY = 'aic.contracts.recent'
const RECENT_LIMIT = 6
const NARROW_QUERY = '(max-width: 900px)'

interface RecentContract {
  id: number
  title: string
  contract_number: string | null
}

const COLUMN_OPTIONS: ColumnOption[] = [
  { key: 'title', label: 'Title', locked: true },
  { key: 'contract_number', label: 'Number' },
  { key: 'status', label: 'Status' },
  { key: 'counterparty', label: 'Counterparty' },
  { key: 'type', label: 'Type' },
  { key: 'department', label: 'Department' },
  { key: 'owner', label: 'Owner' },
  { key: 'effective_date', label: 'Effective' },
  { key: 'expiry_date', label: 'Expiry' },
  { key: 'value', label: 'Value' },
  { key: 'risk', label: 'Risk' },
  { key: 'health', label: 'Health' },
  { key: 'approval', label: 'Approval' },
  { key: 'signing', label: 'Signing' },
  { key: 'renewal', label: 'Renewal' },
  { key: 'updated_at', label: 'Updated' },
  { key: 'created_at', label: 'Created' },
]

const DEFAULT_COLUMNS = [
  'title',
  'status',
  'counterparty',
  'type',
  'expiry_date',
  'value',
  'risk',
  'updated_at',
]

function readRecent(): RecentContract[] {
  try {
    const raw = window.localStorage.getItem(RECENT_STORAGE_KEY)
    if (!raw) return []
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    return parsed.filter(
      (item): item is RecentContract =>
        typeof item === 'object' && item !== null && typeof (item as RecentContract).id === 'number',
    )
  } catch {
    return []
  }
}

function writeRecent(entries: RecentContract[]): void {
  try {
    window.localStorage.setItem(RECENT_STORAGE_KEY, JSON.stringify(entries))
  } catch {
    /* a convenience list is not worth an exception */
  }
}

/** Matches the shell's own breakpoint, so filters move at the same width the nav does. */
function useIsNarrow(): boolean {
  const [narrow, setNarrow] = useState(() => window.matchMedia?.(NARROW_QUERY).matches ?? false)

  useEffect(() => {
    const query = window.matchMedia?.(NARROW_QUERY)
    if (!query) return
    const onChange = () => setNarrow(query.matches)
    query.addEventListener('change', onChange)
    return () => query.removeEventListener('change', onChange)
  }, [])

  return narrow
}

function csvCell(value: string | number | null | undefined): string {
  const text = value === null || value === undefined ? '' : String(value)
  return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
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
 * Download the server's CSV for the current filters.
 *
 * This is the one place the app does not go through `apiClient`: that client
 * exists to unwrap the JSON envelope and would choke on `text/csv`, and the
 * endpoint needs the same bearer token and company headers, so a plain link
 * cannot fetch it either. The auth and context plumbing is reused rather than
 * re-implemented.
 */
async function downloadContractsCsv(filters: ContractFilters): Promise<void> {
  const context = getCompanyContext()
  if (!context) throw new ApiError('Select a company first.', 400, 'MISSING_COMPANY_CONTEXT')

  const sesKey = await ensureSesKey()
  const url = new URL(`${getApiBaseUrl()}/contracts/export`, window.location.origin)

  for (const [key, value] of Object.entries(filtersToQuery(filters))) {
    if (value === undefined || value === null || value === '') continue
    if (Array.isArray(value)) {
      for (const item of value) url.searchParams.append(key, String(item))
      continue
    }
    url.searchParams.set(key, String(value))
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
        ? 'You do not have permission to export contracts.'
        : 'The export could not be produced.',
      response.status,
    )
  }

  saveBlob(await response.blob(), `contracts-${new Date().toISOString().slice(0, 10)}.csv`)
}

export default function Repository() {
  const navigate = useNavigate()
  const toast = useToast()
  const { can } = useSession()
  const isNarrow = useIsNarrow()

  const canCreate = can(PERMISSION.CONTRACT_CREATE)
  const canArchive = can(PERMISSION.CONTRACT_ARCHIVE)
  const canExport = can(PERMISSION.EXPORT)
  const canViewCommercials = can(PERMISSION.COMMERCIALS_VIEW)

  const [filters, setFiltersState] = useState<ContractFilters>(EMPTY_CONTRACT_FILTERS)
  const [searchText, setSearchText] = useState('')
  const [sort, setSort] = useState<ContractSort>({ key: 'updated_at', dir: 'desc' })
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [visibleColumns, setVisibleColumns] = useState<string[]>(() =>
    readStoredColumns(COLUMNS_STORAGE_KEY, DEFAULT_COLUMNS),
  )
  const [selected, setSelected] = useState<number[]>([])
  const [activeViewId, setActiveViewId] = useState<string | null>(null)
  const [filtersExpanded, setFiltersExpanded] = useState(false)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [archiveIntent, setArchiveIntent] = useState<boolean | null>(null)
  const [bulkBusy, setBulkBusy] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [recent, setRecent] = useState<RecentContract[]>(() => readRecent())

  const setFilters = useCallback((next: ContractFilters) => {
    setFiltersState(next)
    // A filter change with the old page number lands on page 7 of a two-page
    // result and looks like an empty repository.
    setPage(1)
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setFiltersState((current) => (current.q === searchText ? current : { ...current, q: searchText }))
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [searchText])

  const lookups = useApiResource<FilterLookups>(
    async (signal) => {
      // Filter vocabularies are convenience, not content: a user without
      // settings access still gets a working repository, just without the
      // type/department/tag dropdowns populated.
      const [contractTypes, departments, tags] = await Promise.all([
        api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal).catch(() => []),
        api.get<DepartmentSummary[]>('/settings/departments', undefined, signal).catch(() => []),
        api.get<TagSummary[]>('/settings/tags', undefined, signal).catch(() => []),
      ])
      return {
        contractTypes: contractTypes ?? [],
        departments: departments ?? [],
        tags: tags ?? [],
      }
    },
    [],
  )

  const filterKey = JSON.stringify(filters)

  const list = useApiResource<Paged<ContractListItem>>(
    (signal) =>
      api.get<Paged<ContractListItem>>(
        '/contracts',
        { ...filtersToQuery(filters), page, per_page: perPage, sort: sort.key, dir: sort.dir },
        signal,
      ),
    [filterKey, page, perPage, sort.key, sort.dir],
  )

  const rows = useMemo(() => list.data?.items ?? [], [list.data])
  const total = list.data?.total ?? 0

  useEffect(() => {
    setSelected([])
  }, [filterKey, page, perPage])

  const lookupValue = useMemo<FilterLookups>(
    () => lookups.data ?? { contractTypes: [], departments: [], tags: [] },
    [lookups.data],
  )

  const chips = useMemo(() => activeFilterChips(filters, lookupValue), [filters, lookupValue])
  const filtered = hasActiveFilters(filters)

  const applyView = useCallback((view: SavedView) => {
    setFiltersState(view.filters)
    setSearchText(view.filters.q)
    setSort(view.sort)
    setVisibleColumns(view.columns)
    setPerPage(view.perPage)
    setPage(1)
    setActiveViewId(view.id)
  }, [])

  const clearView = useCallback(() => {
    setActiveViewId(null)
    setFilters(EMPTY_CONTRACT_FILTERS)
    setSearchText('')
  }, [setFilters])

  const changeColumns = (next: string[]) => {
    setVisibleColumns(next)
    writeStoredColumns(COLUMNS_STORAGE_KEY, next)
  }

  const rememberVisit = useCallback((row: ContractListItem) => {
    setRecent((current) => {
      const next = [
        { id: row.id, title: row.title, contract_number: row.contract_number },
        ...current.filter((item) => item.id !== row.id),
      ].slice(0, RECENT_LIMIT)
      writeRecent(next)
      return next
    })
  }, [])

  const patchRow = useCallback(
    (id: number, patch: Partial<ContractListItem>) => {
      const current = list.data
      if (!current) return
      list.setData({
        ...current,
        items: current.items.map((item) => (item.id === id ? { ...item, ...patch } : item)),
      })
    },
    [list],
  )

  const toggleFavourite = async (row: ContractListItem) => {
    const next = !row.is_favourite
    // Optimistic: a star is cosmetic, and the only cost of a failure is the
    // revert below plus a toast.
    patchRow(row.id, { is_favourite: next })
    try {
      await api.post<{ favourite: boolean }>(`/contracts/${row.id}/favourite`, { favourite: next })
    } catch (err) {
      patchRow(row.id, { is_favourite: !next })
      toast.error('Could not update the star', err instanceof Error ? err.message : undefined)
    }
  }

  const runBulkArchive = async () => {
    if (archiveIntent === null) return
    setBulkBusy(true)

    const results = await Promise.allSettled(
      selected.map((id) => api.post(`/contracts/${id}/archive`, { archived: archiveIntent })),
    )
    const failed = results.filter((result) => result.status === 'rejected').length
    const done = results.length - failed
    const verb = archiveIntent ? 'Archived' : 'Restored'

    setBulkBusy(false)
    setArchiveIntent(null)
    setSelected([])
    list.reload()

    if (failed === 0) {
      toast.success(`${verb} ${done} ${done === 1 ? 'contract' : 'contracts'}`)
    } else {
      toast.warning(
        `${verb} ${done} of ${results.length}`,
        `${failed} could not be changed — they may be locked or outside your access.`,
      )
    }
  }

  const exportAll = async () => {
    setExporting(true)
    try {
      await downloadContractsCsv(filters)
      toast.success('Export ready', 'The CSV has been downloaded.')
    } catch (err) {
      toast.error('Export failed', err instanceof Error ? err.message : undefined)
    } finally {
      setExporting(false)
    }
  }

  /**
   * The selection is a set of rows, and `GET /contracts/export` filters rather
   * than takes ids — so the selected rows are written from what the table
   * already holds instead of asking the server for a different question.
   */
  const exportSelection = () => {
    const chosen = rows.filter((row) => selected.includes(row.id))
    if (chosen.length === 0) return

    const columns = COLUMN_OPTIONS.filter((option) => visibleColumns.includes(option.key))
    const header = columns.map((column) => csvCell(column.label)).join(',')
    const body = chosen
      .map((row) => columns.map((column) => csvCell(csvValue(row, column.key))).join(','))
      .join('\n')

    saveBlob(
      new Blob([`${header}\n${body}\n`], { type: 'text/csv;charset=utf-8' }),
      `contracts-selection-${chosen.length}.csv`,
    )
  }

  const columns = useMemo<Column<ContractListItem>[]>(() => {
    const allSelected = rows.length > 0 && selected.length === rows.length
    const someSelected = selected.length > 0 && !allSelected

    const built: Column<ContractListItem>[] = [
      {
        key: 'select',
        width: 38,
        header: (
          <input
            type="checkbox"
            checked={allSelected}
            ref={(node) => {
              if (node) node.indeterminate = someSelected
            }}
            onChange={(event) => setSelected(event.target.checked ? rows.map((row) => row.id) : [])}
            aria-label={allSelected ? 'Clear selection' : 'Select all rows on this page'}
            style={{ width: 15, height: 15, accentColor: 'rgb(var(--color-primary))', cursor: 'pointer' }}
          />
        ),
        render: (row) => (
          <input
            type="checkbox"
            checked={selected.includes(row.id)}
            onClick={(event) => event.stopPropagation()}
            onChange={(event) =>
              setSelected((current) =>
                event.target.checked
                  ? [...current, row.id]
                  : current.filter((id) => id !== row.id),
              )
            }
            aria-label={`Select ${row.title}`}
            style={{ width: 15, height: 15, accentColor: 'rgb(var(--color-primary))', cursor: 'pointer' }}
          />
        ),
      },
      {
        key: 'favourite',
        width: 36,
        header: <Star size={13} aria-hidden />,
        srLabel: 'Favourite',
        render: (row) => (
          <button
            type="button"
            aria-label={row.is_favourite ? `Unstar ${row.title}` : `Star ${row.title}`}
            aria-pressed={row.is_favourite}
            onClick={(event) => {
              event.stopPropagation()
              void toggleFavourite(row)
            }}
            style={{
              background: 'none',
              border: 'none',
              cursor: 'pointer',
              padding: 2,
              lineHeight: 0,
              color: row.is_favourite ? 'var(--color-warning)' : 'var(--color-text-subtle)',
            }}
          >
            <Star size={15} fill={row.is_favourite ? 'currentColor' : 'none'} aria-hidden />
          </button>
        ),
      },
    ]

    const definitions: Record<string, Column<ContractListItem>> = {
      title: {
        key: 'title',
        header: 'Contract',
        sortKey: 'title',
        render: (row) => (
          <div style={{ minWidth: 200 }}>
            <Link
              to={`/contracts/${row.id}`}
              className="ct-link"
              onClick={(event) => {
                event.stopPropagation()
                rememberVisit(row)
              }}
            >
              {row.title}
            </Link>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.contract_number || 'Not numbered yet'}
            </div>
          </div>
        ),
      },
      contract_number: {
        key: 'contract_number',
        header: 'Number',
        sortKey: 'contract_number',
        hideBelow: 'md',
        render: (row) => row.contract_number || '—',
      },
      status: {
        key: 'status',
        header: 'Status',
        sortKey: 'status',
        render: (row) => (
          <ContractStatusBadge
            status={row.status}
            archivedAt={row.archived_at}
            daysToExpiry={row.days_to_expiry}
            size="sm"
          />
        ),
      },
      counterparty: {
        key: 'counterparty',
        header: 'Counterparty',
        sortKey: 'counterparty',
        hideBelow: 'sm',
        render: (row) => row.counterparty_name ?? '—',
      },
      type: {
        key: 'type',
        header: 'Type',
        hideBelow: 'md',
        render: (row) => row.contract_type_name ?? '—',
      },
      department: {
        key: 'department',
        header: 'Department',
        hideBelow: 'lg',
        render: (row) => row.department_name ?? '—',
      },
      owner: {
        key: 'owner',
        header: 'Owner',
        hideBelow: 'lg',
        align: 'center',
        // The API identifies the owner by user id only; there is no directory
        // endpoint to resolve it to a name, so the id is shown truncated with
        // the full value on hover rather than invented initials.
        render: (row) =>
          row.owner_uuid ? (
            <span
              title={row.owner_uuid}
              style={{ fontSize: 11.5, color: 'var(--color-text-secondary)' }}
            >
              {truncate(row.owner_uuid, 10)}
            </span>
          ) : (
            '—'
          ),
      },
      effective_date: {
        key: 'effective_date',
        header: 'Effective',
        sortKey: 'effective_date',
        hideBelow: 'lg',
        render: (row) => formatDate(row.effective_date),
      },
      expiry_date: {
        key: 'expiry_date',
        header: 'Expiry',
        sortKey: 'expiry_date',
        hideBelow: 'sm',
        render: (row) => (
          <div>
            <div>{formatDate(row.expiry_date)}</div>
            {row.notice_deadline ? (
              <div style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>
                Notice by {formatDate(row.notice_deadline)}
              </div>
            ) : null}
          </div>
        ),
      },
      value: {
        key: 'value',
        header: 'Value',
        sortKey: 'total_value',
        align: 'right',
        hideBelow: 'sm',
        render: (row) => (
          <span style={{ fontVariantNumeric: 'tabular-nums' }}>
            {formatMoney(row.total_value, row.currency || 'INR', { compact: true })}
          </span>
        ),
      },
      risk: {
        key: 'risk',
        header: 'Risk',
        sortKey: 'risk',
        hideBelow: 'md',
        render: (row) => <RiskChip level={row.risk_level} score={row.ai_risk_score} />,
      },
      health: {
        key: 'health',
        header: 'Health',
        hideBelow: 'lg',
        render: (row) => <HealthBar score={row.health_score} />,
      },
      approval: {
        key: 'approval',
        header: 'Approval',
        hideBelow: 'lg',
        render: (row) =>
          row.approval_status ? <Chip size="sm">{humanise(row.approval_status)}</Chip> : '—',
      },
      signing: {
        key: 'signing',
        header: 'Signing',
        hideBelow: 'lg',
        render: (row) =>
          row.signing_status ? <Chip size="sm">{humanise(row.signing_status)}</Chip> : '—',
      },
      renewal: {
        key: 'renewal',
        header: 'Renewal',
        hideBelow: 'lg',
        render: (row) =>
          row.auto_renewal ? (
            <Chip tone="info" size="sm">
              Auto
            </Chip>
          ) : (
            <span style={{ color: 'var(--color-text-muted)' }}>
              {row.renewal_type ? humanise(row.renewal_type) : 'Manual'}
            </span>
          ),
      },
      updated_at: {
        key: 'updated_at',
        header: 'Updated',
        sortKey: 'updated_at',
        hideBelow: 'md',
        render: (row) => (
          <span style={{ color: 'var(--color-text-secondary)' }}>
            {formatDateTime(row.updated_at)}
          </span>
        ),
      },
      created_at: {
        key: 'created_at',
        header: 'Created',
        sortKey: 'created_at',
        hideBelow: 'lg',
        render: (row) => (
          <span style={{ color: 'var(--color-text-secondary)' }}>{formatDate(row.created_at)}</span>
        ),
      },
    }

    for (const option of COLUMN_OPTIONS) {
      if (option.key === 'value' && !canViewCommercials) continue
      if (!option.locked && !visibleColumns.includes(option.key)) continue
      const definition = definitions[option.key]
      if (definition) built.push(definition)
    }

    return built
    // Deliberately keyed on what the cells actually render. `toggleFavourite`
    // is re-created every render, and listing it here would rebuild the whole
    // column set on each keystroke in the search box.
  }, [rows, selected, visibleColumns, canViewCommercials, rememberVisit])

  const columnOptions = canViewCommercials
    ? COLUMN_OPTIONS
    : COLUMN_OPTIONS.filter((option) => option.key !== 'value')

  const filterPanel = (
    <RepositoryFilters
      value={filters}
      onChange={(next) => {
        setFilters(next)
        setSearchText(next.q)
      }}
      lookups={lookupValue}
      showCommercials={canViewCommercials}
      idPrefix={isNarrow ? 'drw' : 'bar'}
    />
  )

  const archivedView = filters.archived === 'only'

  return (
    <>
      <PageHeader
        title="Contract repository"
        description="Every contract in this company, with the filters and columns you need to find one."
        actions={
          <>
            {canExport ? (
              <Button
                variant="secondary"
                icon={<Download size={14} />}
                loading={exporting}
                onClick={() => void exportAll()}
              >
                Export CSV
              </Button>
            ) : null}
            {canCreate ? (
              <Button variant="primary" icon={<Plus size={15} />} onClick={() => navigate('/contracts/new')}>
                New contract
              </Button>
            ) : null}
          </>
        }
      />

      {recent.length > 0 ? (
        <nav
          aria-label="Recently viewed contracts"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            flexWrap: 'wrap',
            marginBottom: 14,
          }}
        >
          <span
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 5,
              fontSize: 12,
              fontWeight: 700,
              color: 'var(--color-text-muted)',
            }}
          >
            <Clock3 size={13} aria-hidden />
            Recent
          </span>
          {recent.map((item) => (
            <Link
              key={item.id}
              to={`/contracts/${item.id}`}
              style={{
                padding: '3px 10px',
                borderRadius: 999,
                fontSize: 12,
                fontWeight: 600,
                color: 'var(--color-text-secondary)',
                background: 'var(--color-bg-card)',
                border: '1px solid rgb(var(--color-border))',
                maxWidth: 260,
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
              }}
            >
              {item.title}
            </Link>
          ))}
        </nav>
      ) : null}

      <Card padded={false}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            flexWrap: 'wrap',
            padding: '12px 14px',
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <div style={{ position: 'relative', flex: '1 1 240px', minWidth: 200 }}>
            <label htmlFor="repo-search" className="ct-sr-only">
              Search contracts
            </label>
            <Search
              size={14}
              aria-hidden
              style={{
                position: 'absolute',
                left: 10,
                top: '50%',
                transform: 'translateY(-50%)',
                color: 'var(--color-text-subtle)',
              }}
            />
            <input
              id="repo-search"
              type="search"
              value={searchText}
              onChange={(event) => setSearchText(event.target.value)}
              placeholder="Search title, number or counterparty"
              style={{
                width: '100%',
                height: 34,
                padding: '0 10px 0 30px',
                borderRadius: 'var(--radius-md)',
                border: '1px solid rgb(var(--color-border-strong))',
                background: 'var(--color-bg-card)',
                color: 'var(--color-text)',
                fontSize: 13,
              }}
            />
          </div>

          <SavedViews
            current={{ filters, sort, columns: visibleColumns, perPage }}
            activeId={activeViewId}
            onApply={applyView}
            onClear={clearView}
          />

          <Button
            size="sm"
            variant={filters.favourites_only ? 'primary' : 'secondary'}
            aria-pressed={filters.favourites_only}
            icon={<Star size={14} fill={filters.favourites_only ? 'currentColor' : 'none'} />}
            onClick={() => setFilters({ ...filters, favourites_only: !filters.favourites_only })}
          >
            Favourites
          </Button>

          <Button
            size="sm"
            variant="secondary"
            icon={<SlidersHorizontal size={14} />}
            aria-expanded={isNarrow ? drawerOpen : filtersExpanded}
            onClick={() => (isNarrow ? setDrawerOpen(true) : setFiltersExpanded((open) => !open))}
          >
            Filters
            {chips.length > 0 ? (
              <span
                style={{
                  minWidth: 17,
                  padding: '0 4px',
                  borderRadius: 999,
                  background: 'var(--color-primary-muted)',
                  color: 'rgb(var(--color-primary-active))',
                  fontSize: 10.5,
                  lineHeight: '16px',
                }}
              >
                {chips.length}
              </span>
            ) : null}
          </Button>

          <ColumnPicker
            options={columnOptions}
            visible={visibleColumns}
            onChange={changeColumns}
            onReset={() => changeColumns(DEFAULT_COLUMNS)}
          />
        </div>

        {!isNarrow && filtersExpanded ? (
          <div style={{ padding: 16, borderBottom: '1px solid rgb(var(--color-border))' }}>
            {filterPanel}
          </div>
        ) : null}

        {chips.length > 0 ? (
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 6,
              flexWrap: 'wrap',
              padding: '10px 14px',
              borderBottom: '1px solid rgb(var(--color-border))',
              background: 'var(--color-bg-subtle)',
            }}
          >
            {chips.map((chip) => (
              <button
                key={chip.id}
                type="button"
                onClick={() => {
                  setFilters(chip.next)
                  setSearchText(chip.next.q)
                }}
                aria-label={`Remove filter ${chip.label}`}
                style={{
                  display: 'inline-flex',
                  alignItems: 'center',
                  gap: 5,
                  padding: '2px 8px',
                  borderRadius: 999,
                  fontSize: 11.5,
                  fontWeight: 600,
                  cursor: 'pointer',
                  background: 'var(--color-bg-card)',
                  color: 'var(--color-text-secondary)',
                  border: '1px solid rgb(var(--color-border-strong))',
                }}
              >
                {chip.label}
                <X size={11} aria-hidden />
              </button>
            ))}
            <Button size="sm" variant="ghost" onClick={clearView}>
              Clear all
            </Button>
          </div>
        ) : null}

        {selected.length > 0 ? (
          <div
            role="region"
            aria-label="Bulk actions"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              flexWrap: 'wrap',
              padding: '10px 14px',
              borderBottom: '1px solid rgb(var(--color-border))',
              background: 'var(--color-primary-muted)',
            }}
          >
            <strong style={{ fontSize: 12.5 }}>
              {selected.length} selected on this page
            </strong>
            <div style={{ flex: 1 }} />
            {canExport ? (
              <Button size="sm" variant="secondary" icon={<Download size={13} />} onClick={exportSelection}>
                Export selection
              </Button>
            ) : null}
            {canArchive ? (
              <Button
                size="sm"
                variant="secondary"
                icon={archivedView ? <ArchiveRestore size={13} /> : <Archive size={13} />}
                onClick={() => setArchiveIntent(!archivedView)}
              >
                {archivedView ? 'Restore' : 'Archive'}
              </Button>
            ) : null}
            <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
              Clear
            </Button>
          </div>
        ) : null}

        <p aria-live="polite" className="ct-sr-only">
          {list.loading
            ? 'Loading contracts'
            : `${total} ${total === 1 ? 'contract' : 'contracts'} match the current filters`}
        </p>

        {list.error ? (
          <ErrorState
            title="Could not load contracts"
            detail={list.error.message}
            onRetry={list.reload}
          />
        ) : !list.loading && rows.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={<Search size={22} />}
              title="No contracts match these filters"
              description="Nothing in this company fits the conditions you have set. Widen them, or start again from the whole repository."
              action={
                <Button variant="secondary" onClick={clearView}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              title="No contracts yet"
              description="This is where every executed, drafted and expiring agreement in the company will live. Start by creating one, or let AI read the documents you already have."
              action={
                canCreate ? (
                  <div style={{ display: 'flex', gap: 8, justifyContent: 'center', flexWrap: 'wrap' }}>
                    <Button
                      variant="primary"
                      icon={<Plus size={15} />}
                      onClick={() => navigate('/contracts/new')}
                    >
                      New contract
                    </Button>
                    <Button
                      variant="secondary"
                      icon={<FileUp size={15} />}
                      onClick={() => navigate('/insights')}
                    >
                      Upload documents
                    </Button>
                  </div>
                ) : undefined
              }
            />
          )
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              loading={list.loading}
              caption="Contracts matching the current filters"
              sort={{ key: sort.key, dir: sort.dir }}
              onSortChange={(key, dir) => {
                setSort({ key: key as ContractSortKey, dir })
                setPage(1)
              }}
              onRowClick={(row) => {
                rememberVisit(row)
                navigate(`/contracts/${row.id}`)
              }}
              rowTone={(row) =>
                row.days_to_expiry !== null && row.days_to_expiry < 0 && row.status === 'active'
                  ? 'warning'
                  : undefined
              }
            />
            <Pagination
              page={page}
              perPage={perPage}
              total={total}
              onPageChange={setPage}
              onPerPageChange={(next) => {
                setPerPage(next)
                setPage(1)
              }}
            />
          </>
        )}
      </Card>

      <Drawer
        open={isNarrow && drawerOpen}
        onClose={() => setDrawerOpen(false)}
        title="Filters"
        footer={
          <>
            <Button variant="ghost" onClick={clearView}>
              Clear all
            </Button>
            <Button variant="primary" onClick={() => setDrawerOpen(false)}>
              Show results
            </Button>
          </>
        }
      >
        {filterPanel}
      </Drawer>

      <ConfirmDialog
        open={archiveIntent !== null}
        busy={bulkBusy}
        onClose={() => setArchiveIntent(null)}
        onConfirm={() => void runBulkArchive()}
        title={archiveIntent ? 'Archive contracts' : 'Restore contracts'}
        confirmLabel={archiveIntent ? 'Archive' : 'Restore'}
        message={
          archiveIntent
            ? `${selected.length} contract${selected.length === 1 ? '' : 's'} will be hidden from the default view. Nothing is deleted, and obligations already recorded stay in place.`
            : `${selected.length} contract${selected.length === 1 ? '' : 's'} will return to the active repository.`
        }
      />
    </>
  )
}

/** The value written to a CSV cell for a column, mirroring what the table shows. */
function csvValue(row: ContractListItem, key: string): string | number | null {
  switch (key) {
    case 'title':
      return row.title
    case 'contract_number':
      return row.contract_number
    case 'status':
      return humanise(row.status)
    case 'counterparty':
      return row.counterparty_name
    case 'type':
      return row.contract_type_name
    case 'department':
      return row.department_name
    case 'owner':
      return row.owner_uuid
    case 'effective_date':
      return row.effective_date
    case 'expiry_date':
      return row.expiry_date
    case 'value':
      return row.total_value === null ? null : `${row.currency ?? ''} ${row.total_value}`.trim()
    case 'risk':
      return row.risk_level
    case 'health':
      return row.health_score
    case 'approval':
      return row.approval_status
    case 'signing':
      return row.signing_status
    case 'renewal':
      return row.auto_renewal ? 'Auto' : (row.renewal_type ?? 'Manual')
    case 'updated_at':
      return row.updated_at
    case 'created_at':
      return row.created_at
    default:
      return null
  }
}

/**
 * Contract health as a bar.
 *
 * Inline SVG rather than a chart library: it is forty bytes of geometry, and
 * the number is printed beside it so the bar is a comparison aid, not the only
 * way to read the value.
 */
function HealthBar({ score }: { score: number | null }) {
  if (score === null || score === undefined) {
    return <span style={{ color: 'var(--color-text-subtle)' }}>—</span>
  }

  const clamped = Math.max(0, Math.min(100, Math.round(score)))
  const colour =
    clamped >= 75 ? 'var(--color-success)' : clamped >= 50 ? 'var(--color-warning)' : 'var(--color-danger)'

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7 }}>
      <svg width={48} height={6} role="img" aria-label={`Health ${clamped} of 100`}>
        <rect width={48} height={6} rx={3} fill="rgb(var(--color-border))" />
        <rect width={(clamped / 100) * 48} height={6} rx={3} fill={colour} />
      </svg>
      <span style={{ fontSize: 12, fontVariantNumeric: 'tabular-nums' }}>{clamped}</span>
    </span>
  )
}
