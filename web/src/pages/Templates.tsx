import { useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Plus, Search, SlidersHorizontal, X } from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  DataTable,
  Drawer,
  ErrorState,
  Input,
  PageHeader,
  Pagination,
  Select,
  StatusChip,
} from '../components/ui'
import type { Column } from '../components/ui'
import { TemplateEditor } from '../components/contracts/TemplateEditor'
import { useSession } from '../context/SessionProvider'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import { TEMPLATE_STATUSES, type ContractTypeSummary, type Paged, type TemplateSummary } from '../types/contracts'
import { formatDateTime, humanise, truncate } from '../utils/format'

/**
 * The template library.
 *
 * The list and the editor are one route: a template is opened by putting its id
 * in the query string, so a drafter can send a colleague a link to the exact
 * wording under discussion, and the browser's back button leaves the editor
 * rather than the product.
 */

const SEARCH_DEBOUNCE_MS = 300
const NARROW_QUERY = '(max-width: 900px)'
const PER_PAGE = 25

/** Matches the shell's breakpoint, so filters move at the width the nav does. */
function useIsNarrow(): boolean {
  const [narrow, setNarrow] = useState(() => window.matchMedia?.(NARROW_QUERY).matches ?? false)

  useEffect(() => {
    const mq = window.matchMedia?.(NARROW_QUERY)
    if (!mq) return
    const onChange = (event: MediaQueryListEvent) => setNarrow(event.matches)
    mq.addEventListener('change', onChange)
    return () => mq.removeEventListener('change', onChange)
  }, [])

  return narrow
}

export default function Templates() {
  const { can } = useSession()
  const [params, setParams] = useSearchParams()
  const isNarrow = useIsNarrow()

  const canManage = can(PERMISSION.TEMPLATE_MANAGE)

  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [status, setStatus] = useState('')
  const [typeId, setTypeId] = useState('')
  const [page, setPage] = useState(1)
  const [filtersOpen, setFiltersOpen] = useState(false)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [query])

  // A narrowed list has a different first page than the one before it; keeping
  // the old page number would show "no results" on page 4 of a 2-page list.
  useEffect(() => {
    setPage(1)
  }, [debounced, status, typeId])

  // A malformed ?template= is treated as no selection rather than as a template
  // that does not exist; the list is a better answer than a 404 panel.
  const selected = params.get('template')
  const selectedId = Number(selected)
  const templateId: number | 'new' | null =
    selected === null
      ? null
      : selected === 'new'
        ? 'new'
        : Number.isInteger(selectedId) && selectedId > 0
          ? selectedId
          : null

  const types = useApiResource<ContractTypeSummary[]>(
    (signal) => api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal),
    [],
  )

  const list = useApiResource<Paged<TemplateSummary>>(
    (signal) =>
      api.get<Paged<TemplateSummary>>(
        '/templates',
        { q: debounced, status, contract_type_id: typeId, page, per_page: PER_PAGE },
        signal,
      ),
    [debounced, status, typeId, page],
    { enabled: templateId === null },
  )

  const contractTypes = useMemo(() => types.data ?? [], [types.data])
  const filtered = debounced !== '' || status !== '' || typeId !== ''

  const open = (id: number | 'new') => {
    setParams({ template: String(id) }, { replace: false })
  }

  const closeEditor = () => {
    setParams({}, { replace: false })
    list.reload()
  }

  if (templateId !== null) {
    return (
      <TemplateEditor
        templateId={templateId}
        contractTypes={contractTypes}
        onClose={closeEditor}
        onDeleted={closeEditor}
        onSaved={(saved) => {
          // A create lands on its own id, so the next save is an update rather
          // than a second template with the same name.
          if (templateId === 'new' && saved?.id) setParams({ template: String(saved.id) }, { replace: true })
        }}
      />
    )
  }

  const rows = list.data?.items ?? []

  const columns: Column<TemplateSummary>[] = [
    {
      key: 'name',
      header: 'Template',
      render: (row) => (
        <div style={{ minWidth: 0 }}>
          <button
            type="button"
            className="ct-link"
            onClick={(event) => {
              // The row is clickable too; without this the same navigation runs twice.
              event.stopPropagation()
              open(row.id)
            }}
            style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', textAlign: 'left', fontSize: 13.5 }}
          >
            {row.name}
          </button>
          {row.description ? (
            <p style={{ fontSize: 12, color: 'var(--color-text-secondary)', marginTop: 2 }}>
              {truncate(row.description, 90)}
            </p>
          ) : null}
        </div>
      ),
    },
    {
      key: 'type',
      header: 'Contract type',
      hideBelow: 'md',
      render: (row) =>
        row.contract_type_name ? (
          <Chip tone="neutral" size="sm">
            {row.contract_type_name}
          </Chip>
        ) : (
          <span style={{ color: 'var(--color-text-subtle)' }}>Any type</span>
        ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row) => <StatusChip status={row.status} size="sm" />,
    },
    {
      key: 'variables',
      header: 'Variables',
      align: 'right',
      hideBelow: 'lg',
      render: (row) => (
        <span style={{ fontVariantNumeric: 'tabular-nums' }}>{row.variables?.length ?? 0}</span>
      ),
    },
    {
      key: 'version',
      header: 'Version',
      align: 'right',
      hideBelow: 'md',
      render: (row) => <span style={{ fontVariantNumeric: 'tabular-nums' }}>{row.version}</span>,
    },
    {
      key: 'updated_at',
      header: 'Updated',
      hideBelow: 'sm',
      render: (row) => (
        <span style={{ color: 'var(--color-text-secondary)', whiteSpace: 'nowrap' }}>
          {formatDateTime(row.updated_at)}
        </span>
      ),
    },
  ]

  const filterControls = (
    <>
      <Select
        label="Status"
        value={status}
        placeholder="Any status"
        options={TEMPLATE_STATUSES.map((value) => ({ value, label: humanise(value) }))}
        onChange={(event) => setStatus(event.target.value)}
      />
      <Select
        label="Contract type"
        value={typeId}
        placeholder="Any type"
        options={contractTypes.map((type) => ({ value: String(type.id), label: type.name }))}
        onChange={(event) => setTypeId(event.target.value)}
      />
    </>
  )

  return (
    <div>
      <PageHeader
        title="Templates"
        description="The wording a contract starts from. A template merges the contract's own values into fixed text, so every draft of the same agreement reads the same way."
        actions={
          canManage ? (
            <Button variant="primary" icon={<Plus size={15} />} onClick={() => open('new')}>
              New template
            </Button>
          ) : undefined
        }
      />

      <Card padded={false}>
        <div
          style={{
            display: 'flex',
            gap: 12,
            alignItems: 'flex-end',
            flexWrap: 'wrap',
            padding: 14,
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <div style={{ flex: '1 1 260px', minWidth: 200 }}>
            <Input
              label="Search templates"
              value={query}
              placeholder="Name or description"
              onChange={(event) => setQuery(event.target.value)}
            />
          </div>

          {isNarrow ? (
            <Button variant="secondary" icon={<SlidersHorizontal size={14} />} onClick={() => setFiltersOpen(true)}>
              Filters
              {status !== '' || typeId !== '' ? (
                <span style={{ marginLeft: 4, fontWeight: 700 }}>·</span>
              ) : null}
            </Button>
          ) : (
            <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>{filterControls}</div>
          )}

          {filtered ? (
            <Button
              variant="ghost"
              icon={<X size={14} />}
              onClick={() => {
                setQuery('')
                setStatus('')
                setTypeId('')
              }}
            >
              Clear
            </Button>
          ) : null}
        </div>

        {list.error ? (
          <ErrorState title="Could not load templates" detail={list.error.message} onRetry={list.reload} />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              loading={list.loading}
              caption="Contract templates"
              onRowClick={(row) => open(row.id)}
              emptyTitle={filtered ? 'No template matches those filters' : canManage ? 'No templates yet' : 'Nothing to draft from yet'}
              emptyDescription={
                filtered
                  ? 'Try a different status or type, or clear the filters to see the whole library.'
                  : canManage
                    ? 'A template holds the standing wording of an agreement with merge variables where the contract’s own values belong. Create one and every draft of that agreement starts from the same place.'
                    : 'Templates are set up by whoever owns your contract standards. Once one exists it will appear here, ready to draft from.'
              }
              emptyAction={
                canManage && !filtered ? (
                  <Button variant="primary" icon={<Plus size={15} />} onClick={() => open('new')}>
                    New template
                  </Button>
                ) : filtered ? (
                  <Button
                    variant="secondary"
                    icon={<Search size={14} />}
                    onClick={() => {
                      setQuery('')
                      setStatus('')
                      setTypeId('')
                    }}
                  >
                    Clear filters
                  </Button>
                ) : undefined
              }
            />

            {list.data && list.data.total > 0 ? (
              <Pagination
                page={list.data.page}
                perPage={list.data.per_page}
                total={list.data.total}
                onPageChange={setPage}
              />
            ) : null}
          </>
        )}
      </Card>

      {types.error ? (
        <p role="status" style={{ marginTop: 10, fontSize: 12.5, color: 'var(--color-text-muted)' }}>
          Contract types could not be loaded, so the type filter is empty: {types.error.message}
        </p>
      ) : null}

      <Drawer
        open={filtersOpen && isNarrow}
        onClose={() => setFiltersOpen(false)}
        title="Filter templates"
        footer={
          <>
            <Button
              variant="ghost"
              onClick={() => {
                setStatus('')
                setTypeId('')
              }}
            >
              Reset
            </Button>
            <Button variant="primary" onClick={() => setFiltersOpen(false)}>
              Show results
            </Button>
          </>
        }
      >
        <div style={{ display: 'grid', gap: 14 }}>{filterControls}</div>
      </Drawer>

      {!list.loading && !list.error && rows.length > 0 ? (
        <p aria-live="polite" className="ct-sr-only">
          {list.data?.total ?? rows.length} templates found.
        </p>
      ) : null}
    </div>
  )
}
