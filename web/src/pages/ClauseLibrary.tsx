import { useEffect, useMemo, useState } from 'react'
import { BookOpen, FolderTree, Library, Plus, Search, X } from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  Drawer,
  EmptyState,
  ErrorState,
  Input,
  PageHeader,
  Pagination,
  RiskChip,
  Skeleton,
  StatusChip,
} from '../components/ui'
import { ClauseEditor } from '../components/contracts/ClauseEditor'
import { useSession } from '../context/SessionProvider'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import type {
  ClauseCategory,
  ContractTypeSummary,
  LibraryClauseItem,
  Paged,
} from '../types/contracts'
import { formatDate, truncate } from '../utils/format'

/**
 * The approved wording, by subject.
 *
 * Categories are a sidebar rather than a filter dropdown because they are how
 * lawyers already think about a contract — liability, confidentiality,
 * termination — and the list is read by walking those subjects, not by
 * remembering which one you narrowed to last.
 */

const SEARCH_DEBOUNCE_MS = 300
const NARROW_QUERY = '(max-width: 900px)'
const PER_PAGE = 25

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

/**
 * Which clause the editor is on.
 *
 * The row itself is carried rather than an id: the API exposes the library as a
 * search, with no endpoint for one clause on its own, so the list is where a
 * clause's wording comes from.
 */
type Selection = { mode: 'new' } | { mode: 'edit'; clause: LibraryClauseItem } | null

export default function ClauseLibrary() {
  const { can } = useSession()
  const isNarrow = useIsNarrow()
  const canManage = can(PERMISSION.CLAUSE_MANAGE)

  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [categoryId, setCategoryId] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const [selection, setSelection] = useState<Selection>(null)
  const [categoriesOpen, setCategoriesOpen] = useState(false)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [query])

  useEffect(() => {
    setPage(1)
  }, [debounced, categoryId])

  const categories = useApiResource<ClauseCategory[]>(
    (signal) => api.get<ClauseCategory[]>('/clause-categories', undefined, signal),
    [],
  )

  const types = useApiResource<ContractTypeSummary[]>(
    (signal) => api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal),
    [],
  )

  const list = useApiResource<Paged<LibraryClauseItem>>(
    (signal) =>
      api.get<Paged<LibraryClauseItem>>(
        '/clauses',
        { q: debounced, category_id: categoryId ?? '', page, per_page: PER_PAGE },
        signal,
      ),
    [debounced, categoryId, page],
    { enabled: selection === null },
  )

  const categoryRows = useMemo(() => categories.data ?? [], [categories.data])
  const contractTypes = useMemo(() => types.data ?? [], [types.data])
  const rows = list.data?.items ?? []
  const filtered = debounced !== '' || categoryId !== null

  const activeCategory = categoryRows.find((category) => category.id === categoryId) ?? null

  if (selection !== null) {
    return (
      <ClauseEditor
        key={selection.mode === 'edit' ? selection.clause.id : 'new'}
        clause={selection.mode === 'edit' ? selection.clause : null}
        categories={categoryRows}
        contractTypes={contractTypes}
        defaultCategoryId={categoryId}
        onClose={() => {
          setSelection(null)
          list.reload()
        }}
        onDeleted={() => {
          setSelection(null)
          list.reload()
        }}
        onSaved={(saved) => {
          setSelection({ mode: 'edit', clause: saved })
          list.reload()
        }}
      />
    )
  }

  const categoryList = (
    <nav aria-label="Clause categories">
      <ul style={{ listStyle: 'none', display: 'grid', gap: 2 }}>
        <li>
          <CategoryButton
            label="All clauses"
            count={list.data?.total ?? null}
            active={categoryId === null}
            onClick={() => {
              setCategoryId(null)
              setCategoriesOpen(false)
            }}
          />
        </li>
        {categories.loading ? (
          <li style={{ display: 'grid', gap: 6, padding: '8px 0' }}>
            <Skeleton height={30} radius={8} />
            <Skeleton height={30} radius={8} />
            <Skeleton height={30} radius={8} />
          </li>
        ) : categories.error ? (
          <li>
            <ErrorState
              compact
              title="Categories did not load"
              detail={categories.error.message}
              onRetry={categories.reload}
            />
          </li>
        ) : (
          categoryRows.map((category) => (
            <li key={category.id}>
              <CategoryButton
                label={category.name}
                count={category.clause_count ?? null}
                active={categoryId === category.id}
                onClick={() => {
                  setCategoryId(category.id)
                  setCategoriesOpen(false)
                }}
              />
            </li>
          ))
        )}
      </ul>
    </nav>
  )

  return (
    <div>
      <style>{`
        @media (max-width: 900px) {
          .ct-clause-grid { grid-template-columns: minmax(0, 1fr) !important; }
          .ct-clause-sidebar { display: none !important; }
        }
      `}</style>

      <PageHeader
        title="Clause library"
        description="The wording your company stands behind, what it will accept instead, and what it will not accept at all. Contracts are measured against what is stored here."
        actions={
          canManage ? (
            <Button variant="primary" icon={<Plus size={15} />} onClick={() => setSelection({ mode: 'new' })}>
              New clause
            </Button>
          ) : undefined
        }
      />

      <div
        className="ct-clause-grid"
        style={{ display: 'grid', gridTemplateColumns: '236px minmax(0, 1fr)', gap: 16, alignItems: 'start' }}
      >
        <Card className="ct-clause-sidebar" style={{ position: 'sticky', top: 16 }}>
          <h2
            style={{
              fontSize: 11.5,
              fontWeight: 700,
              textTransform: 'uppercase',
              letterSpacing: '.03em',
              color: 'var(--color-text-muted)',
              marginBottom: 10,
            }}
          >
            Categories
          </h2>
          {categoryList}
        </Card>

        <div style={{ display: 'grid', gap: 14 }}>
          <Card>
            <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end', flexWrap: 'wrap' }}>
              <div style={{ flex: '1 1 240px', minWidth: 200 }}>
                <Input
                  label="Search the library"
                  value={query}
                  placeholder="Liability, confidentiality, termination…"
                  onChange={(event) => setQuery(event.target.value)}
                />
              </div>
              {isNarrow ? (
                <Button variant="secondary" icon={<FolderTree size={14} />} onClick={() => setCategoriesOpen(true)}>
                  {activeCategory ? activeCategory.name : 'Categories'}
                </Button>
              ) : null}
              {filtered ? (
                <Button
                  variant="ghost"
                  icon={<X size={14} />}
                  onClick={() => {
                    setQuery('')
                    setCategoryId(null)
                  }}
                >
                  Clear
                </Button>
              ) : null}
            </div>

            <p aria-live="polite" style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 10 }}>
              {list.loading
                ? 'Searching the library…'
                : list.error
                  ? 'The library could not be read.'
                  : `${list.data?.total ?? 0} clause${(list.data?.total ?? 0) === 1 ? '' : 's'}${
                      activeCategory ? ` in ${activeCategory.name}` : ''
                    }${debounced ? ` matching “${debounced}”` : ''}`}
            </p>
          </Card>

          {list.loading ? (
            <div style={{ display: 'grid', gap: 12 }}>
              {[0, 1, 2, 3].map((row) => (
                <Card key={row}>
                  <Skeleton width="42%" height={14} />
                  <div style={{ marginTop: 12, display: 'grid', gap: 8 }}>
                    <Skeleton height={11} />
                    <Skeleton height={11} width="72%" />
                  </div>
                </Card>
              ))}
            </div>
          ) : list.error ? (
            <Card>
              <ErrorState
                title="Could not load the clause library"
                detail={list.error.message}
                onRetry={list.reload}
              />
            </Card>
          ) : rows.length === 0 ? (
            <Card>
              <EmptyState
                icon={<Library size={22} />}
                title={filtered ? 'Nothing here matches' : 'The library is empty'}
                description={
                  filtered
                    ? 'Try a shorter search, or look in another category — a clause only appears under the category it was filed in.'
                    : 'A library clause is wording your company has agreed to stand behind. Add the ones you negotiate most often and every draft can start from approved text.'
                }
                action={
                  filtered ? (
                    <Button
                      variant="secondary"
                      icon={<Search size={14} />}
                      onClick={() => {
                        setQuery('')
                        setCategoryId(null)
                      }}
                    >
                      Clear search
                    </Button>
                  ) : canManage ? (
                    <Button variant="primary" icon={<Plus size={15} />} onClick={() => setSelection({ mode: 'new' })}>
                      Add the first clause
                    </Button>
                  ) : undefined
                }
              />
            </Card>
          ) : (
            <>
              <ul style={{ listStyle: 'none', display: 'grid', gap: 12 }}>
                {rows.map((clause) => (
                  <li key={clause.id}>
                    <ClauseRow clause={clause} onOpen={() => setSelection({ mode: 'edit', clause })} />
                  </li>
                ))}
              </ul>

              {list.data && list.data.total > PER_PAGE ? (
                <Card padded={false}>
                  <Pagination
                    page={list.data.page}
                    perPage={list.data.per_page}
                    total={list.data.total}
                    onPageChange={setPage}
                  />
                </Card>
              ) : null}
            </>
          )}
        </div>
      </div>

      <Drawer
        open={categoriesOpen && isNarrow}
        onClose={() => setCategoriesOpen(false)}
        title="Categories"
        side="left"
        width={300}
      >
        {categoryList}
      </Drawer>
    </div>
  )
}

function CategoryButton({
  label,
  count,
  active,
  onClick,
}: {
  label: string
  count: number | null
  active: boolean
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-current={active ? 'true' : undefined}
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: 8,
        width: '100%',
        padding: '7px 9px',
        borderRadius: 'var(--radius-sm)',
        border: '1px solid transparent',
        background: active ? 'var(--color-primary-muted)' : 'transparent',
        color: active ? 'rgb(var(--color-primary-active))' : 'var(--color-text-secondary)',
        fontWeight: active ? 700 : 600,
        fontSize: 13,
        textAlign: 'left',
        cursor: 'pointer',
      }}
    >
      <span style={{ minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>{label}</span>
      {count !== null ? (
        <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--color-text-muted)' }}>{count}</span>
      ) : null}
    </button>
  )
}

function ClauseRow({ clause, onOpen }: { clause: LibraryClauseItem; onOpen: () => void }) {
  const expired = clause.effective_to !== null && clause.effective_to < new Date().toISOString().slice(0, 10)

  return (
    <Card>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0 }}>
          <h3 style={{ fontSize: 14.5, fontWeight: 700 }}>
            <button
              type="button"
              className="ct-link"
              onClick={onOpen}
              style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', font: 'inherit' }}
            >
              {clause.name}
            </button>
          </h3>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 7, alignItems: 'center' }}>
            {clause.category_name ? (
              <Chip tone="neutral" size="sm">
                <BookOpen size={11} aria-hidden />
                {clause.category_name}
              </Chip>
            ) : null}
            <StatusChip status={clause.approval_status} size="sm" />
            <RiskChip level={clause.risk_classification} />
            {clause.jurisdiction ? (
              <Chip tone="info" size="sm">
                {clause.jurisdiction}
              </Chip>
            ) : null}
            {expired ? (
              <Chip tone="warning" size="sm" title={`Effective to ${formatDate(clause.effective_to)}`}>
                Past its effective date
              </Chip>
            ) : null}
          </div>
        </div>
        <div style={{ flexShrink: 0 }}>
          <Button size="sm" variant="secondary" onClick={onOpen}>
            Open
          </Button>
        </div>
      </div>

      {clause.description ? (
        <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 10, lineHeight: 1.6 }}>
          {clause.description}
        </p>
      ) : null}

      <p
        style={{
          marginTop: 10,
          padding: '10px 12px',
          background: 'var(--color-bg-subtle)',
          borderRadius: 'var(--radius-md)',
          fontSize: 12.5,
          lineHeight: 1.7,
          color: 'var(--color-text)',
        }}
      >
        {truncate(clause.standard_text, 260)}
      </p>

      <div
        style={{
          display: 'flex',
          gap: 14,
          flexWrap: 'wrap',
          marginTop: 10,
          fontSize: 11.5,
          color: 'var(--color-text-muted)',
        }}
      >
        <span>Version {clause.version}</span>
        {clause.fallback_text ? <span>Fallback wording on file</span> : <span>No fallback wording</span>}
        {clause.prohibited_wording ? <span>Prohibited wording defined</span> : null}
        {clause.effective_from ? <span>From {formatDate(clause.effective_from)}</span> : null}
        {clause.effective_to ? <span>To {formatDate(clause.effective_to)}</span> : null}
      </div>
    </Card>
  )
}
