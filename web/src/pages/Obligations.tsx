import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import {
  AlertTriangle,
  CheckCircle2,
  ClipboardList,
  Filter,
  Search,
  UserRound,
  X,
} from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  DataTable,
  Drawer,
  EmptyState,
  ErrorState,
  Field,
  Modal,
  MoneyInput,
  PageHeader,
  Pagination,
  Select,
  StatusChip,
  Textarea,
  Input,
} from '../components/ui'
import type { Column } from '../components/ui'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import type { FieldErrors } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import {
  OBLIGATION_STATUSES,
  RESPONSIBLE_PARTIES,
  type ContractDocument,
  type ContractListItem,
  type ObligationOccurrenceRow,
  type Paged,
} from '../types/contracts'
import { formatDate, formatMoney, formatRelativeDays, humanise, truncate } from '../utils/format'

/**
 * Every obligation occurrence in the portfolio, in the order they fall due.
 *
 * The row is an occurrence, not an obligation: "submit a quarterly SLA report"
 * is a commitment, and "the report due on 31 March" is the thing a person has
 * to do something about this week. Overdue rows are marked with an icon and the
 * word as well as a colour — these queues are worked by people who cannot all
 * distinguish a red row from a white one.
 */

const NARROW_QUERY = '(max-width: 900px)'
const SEARCH_DEBOUNCE_MS = 300

type DueRange = 'all' | 'overdue' | 'week' | 'month'

const RANGE_LABEL: Record<DueRange, string> = {
  all: 'Everything',
  overdue: 'Overdue',
  week: 'Due this week',
  month: 'Due this month',
}

const RANGE_HINT: Record<DueRange, string> = {
  all: 'Every occurrence, soonest first',
  overdue: 'Past its due date or grace period and still open',
  week: 'Falling due in the next 7 days',
  month: 'Falling due in the next 30 days',
}

interface ObligationFilters {
  range: DueRange
  status: string
  responsible_party: string
  owner_uuid: string
  contract_id: string
}

const EMPTY_FILTERS: ObligationFilters = {
  range: 'all',
  status: '',
  responsible_party: '',
  owner_uuid: '',
  contract_id: '',
}

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

/** Today as `YYYY-MM-DD` in the viewer's own timezone — a due date is a local business date. */
function isoDay(offsetDays = 0): string {
  const date = new Date()
  date.setDate(date.getDate() + offsetDays)
  const month = `${date.getMonth() + 1}`.padStart(2, '0')
  const day = `${date.getDate()}`.padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}

function rangeQuery(range: DueRange): { due_from?: string; due_to?: string; overdue_only?: string } {
  switch (range) {
    case 'overdue':
      return { overdue_only: '1' }
    case 'week':
      return { due_from: isoDay(), due_to: isoDay(7) }
    case 'month':
      return { due_from: isoDay(), due_to: isoDay(30) }
    case 'all':
    default:
      return {}
  }
}

export default function Obligations() {
  const navigate = useNavigate()
  const { can, session } = useSession()
  const isNarrow = useIsNarrow()
  const [searchParams] = useSearchParams()

  const canManage = can(PERMISSION.OBLIGATION_MANAGE)
  const myUuid = session?.uuid ?? null

  const [filters, setFiltersState] = useState<ObligationFilters>(() => ({
    ...EMPTY_FILTERS,
    // A link from a contract lands here already narrowed to that contract.
    contract_id: searchParams.get('contract_id') ?? '',
  }))
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [completing, setCompleting] = useState<ObligationOccurrenceRow | null>(null)

  const setFilters = useCallback((next: ObligationFilters) => {
    setFiltersState(next)
    setPage(1)
  }, [])

  const filterKey = JSON.stringify(filters)

  const list = useApiResource<Paged<ObligationOccurrenceRow>>(
    (signal) =>
      api.get<Paged<ObligationOccurrenceRow>>(
        '/obligations',
        {
          ...rangeQuery(filters.range),
          status: filters.status,
          responsible_party: filters.responsible_party,
          owner_uuid: filters.owner_uuid,
          contract_id: filters.contract_id,
          page,
          per_page: perPage,
        },
        signal,
      ),
    [filterKey, page, perPage],
  )

  const rows = list.data?.items ?? []
  const total = list.data?.total ?? 0
  const overdueOnPage = rows.filter((row) => row.is_overdue).length
  const isFiltered =
    filters.range !== 'all' ||
    filters.status !== '' ||
    filters.responsible_party !== '' ||
    filters.owner_uuid !== '' ||
    filters.contract_id !== ''

  const columns = useMemo<Column<ObligationOccurrenceRow>[]>(() => {
    const built: Column<ObligationOccurrenceRow>[] = [
      {
        key: 'due',
        header: 'Due',
        width: 172,
        render: (row) => <DueCell row={row} />,
      },
      {
        key: 'obligation',
        header: 'Obligation',
        render: (row) => (
          <div style={{ minWidth: 190 }}>
            <span style={{ fontWeight: 600 }}>{row.obligation_title}</span>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.obligation_type ? humanise(row.obligation_type) : 'Obligation'}
              {row.frequency ? ` · ${humanise(row.frequency)}` : ''}
              {row.sequence_no ? ` · occurrence ${row.sequence_no}` : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'contract',
        header: 'Contract',
        hideBelow: 'sm',
        render: (row) => (
          <div style={{ minWidth: 160 }}>
            <Link
              to={`/contracts/${row.contract_id}?tab=obligations`}
              className="ct-link"
              onClick={(event) => event.stopPropagation()}
            >
              {row.contract_title ?? `Contract ${row.contract_id}`}
            </Link>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.contract_number ?? '—'}
            </div>
          </div>
        ),
      },
      {
        key: 'status',
        header: 'Status',
        render: (row) => <StatusChip status={row.status} size="sm" />,
      },
      {
        key: 'responsible',
        header: 'Responsible',
        hideBelow: 'md',
        render: (row) => (
          <Chip size="sm" tone={row.responsible_party === 'company' ? 'primary' : 'neutral'}>
            {row.responsible_party === 'company'
              ? 'Us'
              : row.responsible_party === 'counterparty'
                ? 'Them'
                : 'Both'}
          </Chip>
        ),
      },
      {
        key: 'owner',
        header: 'Owner',
        hideBelow: 'lg',
        render: (row) => {
          if (!row.owner_uuid) return <span style={{ color: 'var(--color-text-subtle)' }}>Unassigned</span>
          const mine = myUuid !== null && row.owner_uuid === myUuid
          return (
            <span
              title={row.owner_uuid}
              style={{
                fontSize: 12,
                fontWeight: mine ? 700 : 400,
                color: mine ? 'var(--color-text)' : 'var(--color-text-secondary)',
              }}
            >
              {mine ? 'You' : truncate(row.owner_uuid, 10)}
            </span>
          )
        },
      },
      {
        key: 'amount',
        header: 'Amount',
        align: 'right',
        hideBelow: 'lg',
        render: (row) =>
          row.amount ? (
            <span style={{ fontVariantNumeric: 'tabular-nums' }}>
              {formatMoney(row.amount, row.currency || 'INR', { compact: true })}
            </span>
          ) : (
            <span style={{ color: 'var(--color-text-subtle)' }}>—</span>
          ),
      },
    ]

    if (canManage) {
      built.push({
        key: 'actions',
        header: 'Action',
        width: 130,
        render: (row) =>
          row.status === 'completed' ? (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12, color: 'var(--color-success)' }}>
              <CheckCircle2 size={13} aria-hidden />
              {row.completed_at ? formatDate(row.completed_at) : 'Done'}
            </span>
          ) : (
            <Button
              size="sm"
              variant="secondary"
              icon={<CheckCircle2 size={13} />}
              aria-label={`Complete ${row.obligation_title}, due ${formatDate(row.due_date)}`}
              onClick={(event) => {
                event.stopPropagation()
                setCompleting(row)
              }}
            >
              Complete
            </Button>
          ),
      })
    }

    return built
  }, [canManage, myUuid])

  const filterPanel = (
    <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))' }}>
      <Select
        label="Status"
        value={filters.status}
        onChange={(event) => setFilters({ ...filters, status: event.target.value })}
        options={OBLIGATION_STATUSES.map((status) => ({ value: status, label: humanise(status) }))}
        placeholder="Any status"
      />
      <Select
        label="Responsible party"
        value={filters.responsible_party}
        onChange={(event) => setFilters({ ...filters, responsible_party: event.target.value })}
        options={RESPONSIBLE_PARTIES.map((party) => ({
          value: party,
          label: party === 'company' ? 'Us' : party === 'counterparty' ? 'The counterparty' : 'Both sides',
        }))}
        placeholder="Either side"
      />
      <ContractFilter
        value={filters.contract_id}
        onChange={(contractId) => setFilters({ ...filters, contract_id: contractId })}
      />
      <Field label="Owner" htmlFor="obligations-owner">
        <div style={{ display: 'flex', gap: 6 }}>
          <input
            id="obligations-owner"
            type="text"
            value={filters.owner_uuid}
            placeholder="Any owner"
            onChange={(event) => setFilters({ ...filters, owner_uuid: event.target.value })}
            style={{
              flex: 1,
              minWidth: 0,
              height: 36,
              padding: '0 10px',
              borderRadius: 'var(--radius-md)',
              border: '1px solid rgb(var(--color-border-strong))',
              background: 'var(--color-bg-card)',
              color: 'var(--color-text)',
              fontSize: 13.5,
            }}
          />
          <Button
            variant={myUuid !== null && filters.owner_uuid === myUuid ? 'primary' : 'secondary'}
            icon={<UserRound size={14} />}
            disabled={!myUuid}
            aria-pressed={myUuid !== null && filters.owner_uuid === myUuid}
            onClick={() =>
              setFilters({
                ...filters,
                owner_uuid: filters.owner_uuid === myUuid ? '' : (myUuid ?? ''),
              })
            }
          >
            Mine
          </Button>
        </div>
      </Field>
    </div>
  )

  return (
    <>
      <PageHeader
        title="Obligations"
        description="What the company and its counterparties have committed to do, and when each commitment next falls due."
      />

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
          <div role="group" aria-label="Due range" style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
            {(Object.keys(RANGE_LABEL) as DueRange[]).map((range) => (
              <Button
                key={range}
                size="sm"
                variant={filters.range === range ? 'primary' : 'secondary'}
                aria-pressed={filters.range === range}
                title={RANGE_HINT[range]}
                icon={range === 'overdue' ? <AlertTriangle size={13} /> : undefined}
                onClick={() => setFilters({ ...filters, range })}
              >
                {RANGE_LABEL[range]}
              </Button>
            ))}
          </div>

          <div style={{ flex: 1 }} />

          <Button
            size="sm"
            variant="secondary"
            icon={<Filter size={14} />}
            aria-expanded={filtersOpen}
            onClick={() => setFiltersOpen((open) => !open)}
          >
            Filters
          </Button>

          {isFiltered ? (
            <Button size="sm" variant="ghost" icon={<X size={13} />} onClick={() => setFilters(EMPTY_FILTERS)}>
              Clear
            </Button>
          ) : null}
        </div>

        {!isNarrow && filtersOpen ? (
          <div style={{ padding: 16, borderBottom: '1px solid rgb(var(--color-border))' }}>
            {filterPanel}
          </div>
        ) : null}

        <p aria-live="polite" className="ct-sr-only">
          {list.loading
            ? 'Loading obligations'
            : `${total} ${total === 1 ? 'occurrence' : 'occurrences'} match the current filters, ${overdueOnPage} overdue on this page`}
        </p>

        {list.error ? (
          <ErrorState
            title="Could not load obligations"
            detail={list.error.message}
            onRetry={list.reload}
          />
        ) : !list.loading && rows.length === 0 ? (
          isFiltered ? (
            <EmptyState
              icon={<Search size={22} />}
              title="Nothing matches these filters"
              description={
                filters.range === 'overdue'
                  ? 'Nothing is overdue right now — which is the answer you want from this screen.'
                  : 'No occurrence fits the conditions you have set. Widen them to see the rest of the portfolio.'
              }
              action={
                <Button variant="secondary" onClick={() => setFilters(EMPTY_FILTERS)}>
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={<ClipboardList size={22} />}
              title="No obligations recorded yet"
              description="Obligations are added on a contract — a payment, a report, a renewal notice — and each one generates the dated occurrences that appear here."
            />
          )
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              loading={list.loading}
              caption="Obligation occurrences matching the current filters"
              onRowClick={(row) => navigate(`/contracts/${row.contract_id}?tab=obligations`)}
              rowTone={(row) => (row.is_overdue ? 'danger' : row.status === 'due' ? 'warning' : undefined)}
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
        open={isNarrow && filtersOpen}
        onClose={() => setFiltersOpen(false)}
        title="Filters"
        footer={
          <>
            <Button variant="ghost" onClick={() => setFilters(EMPTY_FILTERS)}>
              Clear all
            </Button>
            <Button variant="primary" onClick={() => setFiltersOpen(false)}>
              Show results
            </Button>
          </>
        }
      >
        {filterPanel}
      </Drawer>

      {completing ? (
        <CompleteModal
          occurrence={completing}
          onClose={() => setCompleting(null)}
          onCompleted={() => {
            setCompleting(null)
            list.reload()
          }}
        />
      ) : null}
    </>
  )
}

/**
 * The due date, with its urgency spelled out.
 *
 * `is_overdue` is computed by the API against today rather than read off the
 * stored status, so this stays right between the nightly sweeps.
 */
function DueCell({ row }: { row: ObligationOccurrenceRow }) {
  if (row.is_overdue) {
    return (
      <div style={{ display: 'flex', gap: 7, alignItems: 'flex-start' }}>
        <AlertTriangle size={15} aria-hidden style={{ color: 'var(--color-danger)', marginTop: 2 }} />
        <div>
          <div style={{ fontWeight: 700 }}>{formatDate(row.due_date)}</div>
          <div style={{ fontSize: 11.5, color: 'var(--color-danger)', fontWeight: 700 }}>
            Overdue · {formatRelativeDays(row.days_to_due)}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div>
      <div style={{ fontWeight: row.status === 'due' ? 700 : 400 }}>{formatDate(row.due_date)}</div>
      <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
        {row.status === 'completed' ? 'Completed' : formatRelativeDays(row.days_to_due)}
        {row.grace_until ? ` · grace to ${formatDate(row.grace_until)}` : ''}
      </div>
    </div>
  )
}

/**
 * Narrow the queue to one contract.
 *
 * `GET /obligations` filters by contract id and has no text search of its own,
 * so the name is resolved through the repository search and the id is what
 * actually goes to the server.
 */
function ContractFilter({
  value,
  onChange,
}: {
  value: string
  onChange: (contractId: string) => void
}) {
  const inputId = useId()
  const listId = `${inputId}-results`
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [query])

  useEffect(() => {
    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [])

  const results = useApiResource<Paged<ContractListItem>>(
    (signal) =>
      api.get<Paged<ContractListItem>>('/contracts', { q: debounced, per_page: 8 }, signal),
    [debounced],
    { enabled: debounced.length >= 2 },
  )

  const selected = useApiResource<ContractListItem | null>(
    (signal) =>
      api
        .get<ContractListItem>(`/contracts/${value}`, undefined, signal)
        .catch(() => null),
    [value],
    { enabled: value !== '' },
  )

  if (value !== '') {
    return (
      <Field label="Contract" htmlFor={inputId}>
        <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
          <span
            id={inputId}
            style={{
              flex: 1,
              minWidth: 0,
              fontSize: 13,
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              whiteSpace: 'nowrap',
            }}
          >
            {selected.loading
              ? 'Loading…'
              : (selected.data?.title ?? `Contract ${value}`)}
          </span>
          <Button
            size="sm"
            variant="secondary"
            icon={<X size={13} />}
            onClick={() => {
              onChange('')
              setQuery('')
            }}
          >
            Clear
          </Button>
        </div>
      </Field>
    )
  }

  const items = results.data?.items ?? []

  return (
    <div ref={containerRef} style={{ position: 'relative' }}>
      <Field label="Contract" htmlFor={inputId} hint="Type at least two letters">
        <input
          id={inputId}
          type="text"
          role="combobox"
          autoComplete="off"
          aria-expanded={open && items.length > 0}
          aria-controls={listId}
          value={query}
          placeholder="Any contract"
          onChange={(event) => {
            setQuery(event.target.value)
            setOpen(true)
          }}
          onFocus={() => setOpen(true)}
          style={{
            width: '100%',
            height: 36,
            padding: '0 10px',
            borderRadius: 'var(--radius-md)',
            border: '1px solid rgb(var(--color-border-strong))',
            background: 'var(--color-bg-card)',
            color: 'var(--color-text)',
            fontSize: 13.5,
          }}
        />
      </Field>
      {open && items.length > 0 ? (
        <ul
          id={listId}
          role="listbox"
          aria-label="Matching contracts"
          style={{
            position: 'absolute',
            top: '100%',
            left: 0,
            right: 0,
            zIndex: 20,
            marginTop: 4,
            listStyle: 'none',
            maxHeight: 220,
            overflowY: 'auto',
            background: 'var(--color-bg-card)',
            border: '1px solid rgb(var(--color-border-strong))',
            borderRadius: 'var(--radius-md)',
            boxShadow: 'var(--shadow-md)',
          }}
        >
          {items.map((item) => (
            <li key={item.id} role="option" aria-selected={false}>
              <button
                type="button"
                onClick={() => {
                  onChange(String(item.id))
                  setOpen(false)
                }}
                style={{
                  display: 'block',
                  width: '100%',
                  textAlign: 'left',
                  padding: '8px 11px',
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer',
                  fontSize: 13,
                }}
              >
                <span style={{ fontWeight: 600 }}>{truncate(item.title, 46)}</span>
                <span style={{ display: 'block', fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                  {item.contract_number || 'Not numbered'}
                </span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}

function CompleteModal({
  occurrence,
  onClose,
  onCompleted,
}: {
  occurrence: ObligationOccurrenceRow
  onClose: () => void
  onCompleted: () => void
}) {
  const toast = useToast()
  const [note, setNote] = useState('')
  const [amount, setAmount] = useState(occurrence.amount ?? '')
  const [documentId, setDocumentId] = useState('')
  const [evidenceNote, setEvidenceNote] = useState('')
  const [externalRef, setExternalRef] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const documents = useApiResource<ContractDocument[]>(
    (signal) =>
      api
        .get<ContractDocument[]>(`/contracts/${occurrence.contract_id}/documents`, undefined, signal)
        .catch(() => []),
    [occurrence.contract_id],
  )

  const submit = async () => {
    const hasEvidence = documentId !== '' || evidenceNote.trim() !== '' || externalRef.trim() !== ''
    if (occurrence.evidence_required && !hasEvidence) {
      // The server refuses this with a 409, which would arrive as a toast; on
      // the field it says which box to fill in.
      setErrors({ evidence_note: 'This obligation needs evidence: a document, a note or a reference.' })
      return
    }

    setSaving(true)
    setErrors({})
    try {
      await api.post(`/occurrences/${occurrence.id}/complete`, {
        completion_note: note.trim() || null,
        amount: amount.trim() || null,
        document_id: documentId ? Number(documentId) : null,
        evidence_note: evidenceNote.trim() || null,
        external_ref: externalRef.trim() || null,
      })
      toast.success('Recorded as complete', `${occurrence.obligation_title} · due ${formatDate(occurrence.due_date)}`)
      onCompleted()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not record that', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  const documentOptions = (documents.data ?? []).map((document) => ({
    value: String(document.id),
    label: document.title?.trim() || `${humanise(document.doc_kind ?? 'document')} ${document.id}`,
  }))

  return (
    <Modal
      open
      onClose={onClose}
      title="Record this as complete"
      description={`${occurrence.obligation_title} · due ${formatDate(occurrence.due_date)}`}
      width={560}
      closeOnBackdrop={!saving}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            Mark complete
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        {occurrence.is_overdue ? (
          <p
            role="status"
            style={{
              display: 'flex',
              gap: 8,
              alignItems: 'flex-start',
              fontSize: 12.5,
              padding: '9px 12px',
              borderRadius: 'var(--radius-md)',
              background: 'var(--color-danger-bg)',
              border: '1px solid var(--color-danger-border)',
            }}
          >
            <AlertTriangle size={14} aria-hidden style={{ marginTop: 2, color: 'var(--color-danger)' }} />
            <span>
              This was due {formatDate(occurrence.due_date)} and is already overdue. Completing it now
              records the date it was actually done.
            </span>
          </p>
        ) : null}

        <Textarea
          label="What was done"
          rows={3}
          value={note}
          error={errors.completion_note}
          onChange={(event) => setNote(event.target.value)}
        />

        <MoneyInput
          label="Amount"
          currency={occurrence.currency ?? undefined}
          value={amount}
          error={errors.amount}
          hint="Only where the obligation is a payment."
          onChange={(event) => setAmount(event.target.value)}
        />

        <fieldset style={{ border: 'none', display: 'grid', gap: 14 }}>
          <legend style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--color-text-secondary)' }}>
            Evidence
            {occurrence.evidence_required ? (
              <span style={{ color: 'var(--color-danger)', marginLeft: 4 }}>required</span>
            ) : null}
          </legend>
          <Select
            label="Document"
            value={documentId}
            error={errors.document_id}
            onChange={(event) => setDocumentId(event.target.value)}
            options={documentOptions}
            placeholder={documents.loading ? 'Loading documents…' : 'No document'}
          />
          <Input
            label="Evidence note"
            value={evidenceNote}
            error={errors.evidence_note}
            onChange={(event) => {
              setEvidenceNote(event.target.value)
              setErrors((current) => (current.evidence_note ? { ...current, evidence_note: '' } : current))
            }}
            placeholder="Where the proof lives, or what it shows"
          />
          <Input
            label="External reference"
            value={externalRef}
            error={errors.external_ref}
            onChange={(event) => setExternalRef(event.target.value)}
            placeholder="Invoice number, ticket, email subject"
          />
        </fieldset>
      </div>
    </Modal>
  )
}
