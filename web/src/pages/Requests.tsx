import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { FileSignature, Filter, Paperclip, Plus, Search, UserRound, X } from 'lucide-react'

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
  Modal,
  MoneyInput,
  PageHeader,
  Pagination,
  Select,
  StatusChip,
  Textarea,
} from '../components/ui'
import type { Column } from '../components/ui'
import { CounterpartyPicker } from '../components/contracts/CounterpartyPicker'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import type { FieldErrors } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import {
  CURRENCIES,
  OPEN_REQUEST_STATUSES,
  REQUEST_STATUSES,
  type ContractRequest,
  type ContractRequestListItem,
  type ContractTypeSummary,
  type DepartmentSummary,
  type Paged,
  type UploadSession,
} from '../types/contracts'
import { formatDate, formatMoney, formatRelativeDays, humanise } from '../utils/format'

/**
 * Contract intake: what the business has asked for, before there is a contract.
 *
 * The queue is the reviewer's screen and the requester's screen at once, so it
 * is filtered rather than split — a requester narrows it to their own, a
 * reviewer to what is waiting on them. Everything that narrows the list is
 * server-side, because a request the viewer is not allowed to see never reaches
 * the browser to be filtered out of.
 */

const SEARCH_DEBOUNCE_MS = 300
const NARROW_QUERY = '(max-width: 900px)'
const PER_PAGE_DEFAULT = 25

/** The extra option the status control offers over the raw statuses. */
const OPEN_STATUS_VALUE = 'open'

interface RequestFilters {
  q: string
  status: string
  contract_type_id: string
  department_id: string
  required_by: string
  mineOnly: boolean
}

const EMPTY_FILTERS: RequestFilters = {
  q: '',
  status: OPEN_STATUS_VALUE,
  contract_type_id: '',
  department_id: '',
  required_by: '',
  mineOnly: false,
}

interface TemplateOption {
  id: number
  name: string
  status?: string | null
}

interface Lookups {
  contractTypes: ContractTypeSummary[]
  departments: DepartmentSummary[]
  templates: TemplateOption[]
}

const EMPTY_LOOKUPS: Lookups = { contractTypes: [], departments: [], templates: [] }

type RequestSortKey =
  | 'updated_at'
  | 'created_at'
  | 'title'
  | 'request_number'
  | 'status'
  | 'required_by_date'
  | 'estimated_value'

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

function statusQuery(value: string): string[] {
  if (value === '') return []
  if (value === OPEN_STATUS_VALUE) return [...OPEN_REQUEST_STATUSES]
  return [value]
}

/** A request is late when the date it was needed by has passed and it is still open. */
function isLate(row: ContractRequestListItem): boolean {
  if (!row.required_by_date) return false
  if (row.status === 'converted' || row.status === 'rejected') return false
  return row.required_by_date < new Date().toISOString().slice(0, 10)
}

export default function Requests() {
  const navigate = useNavigate()
  const toast = useToast()
  const { can, session } = useSession()
  const isNarrow = useIsNarrow()

  const canCreate = can(PERMISSION.REQUEST_CREATE)
  const canViewValue = can(PERMISSION.COMMERCIALS_VIEW)

  const [filters, setFiltersState] = useState<RequestFilters>(EMPTY_FILTERS)
  const [searchText, setSearchText] = useState('')
  const [sort, setSort] = useState<{ key: RequestSortKey; dir: 'asc' | 'desc' }>({
    key: 'updated_at',
    dir: 'desc',
  })
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(PER_PAGE_DEFAULT)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [creating, setCreating] = useState(false)

  const setFilters = useCallback((next: RequestFilters) => {
    setFiltersState(next)
    // Page 7 of a two-page result reads as an empty queue.
    setPage(1)
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setFiltersState((current) => (current.q === searchText ? current : { ...current, q: searchText }))
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [searchText])

  const lookups = useApiResource<Lookups>(async (signal) => {
    // Vocabularies are convenience: a user without settings access still gets a
    // working queue, just without the type and department dropdowns filled.
    const [contractTypes, departments, templates] = await Promise.all([
      api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal).catch(() => []),
      api.get<DepartmentSummary[]>('/settings/departments', undefined, signal).catch(() => []),
      api
        .get<Paged<TemplateOption>>('/templates', { status: 'active', per_page: 100 }, signal)
        .catch(() => null),
    ])
    return {
      contractTypes: contractTypes ?? [],
      departments: departments ?? [],
      templates: templates?.items ?? [],
    }
  }, [])

  const lookupValue = lookups.data ?? EMPTY_LOOKUPS
  const filterKey = JSON.stringify(filters)
  const myUuid = session?.uuid ?? null

  const list = useApiResource<Paged<ContractRequestListItem>>(
    (signal) =>
      api.get<Paged<ContractRequestListItem>>(
        '/requests',
        {
          q: filters.q,
          status: statusQuery(filters.status),
          contract_type_id: filters.contract_type_id,
          department_id: filters.department_id,
          required_by: filters.required_by,
          requester: filters.mineOnly && myUuid ? myUuid : '',
          page,
          per_page: perPage,
          sort: sort.key,
          dir: sort.dir,
        },
        signal,
      ),
    [filterKey, page, perPage, sort.key, sort.dir, myUuid],
  )

  const rows = list.data?.items ?? []
  const total = list.data?.total ?? 0
  // Measured against the default view rather than against "no filters at all":
  // the queue opens on what is still open, and that is not a filter the user
  // set, so it must not put a "clear filters" button on a fresh screen.
  const isFiltered = filterKey !== JSON.stringify(EMPTY_FILTERS)

  const columns = useMemo<Column<ContractRequestListItem>[]>(() => {
    const built: Column<ContractRequestListItem>[] = [
      {
        key: 'title',
        header: 'Request',
        sortKey: 'title',
        render: (row) => (
          <div style={{ minWidth: 190 }}>
            <Link
              to={`/requests/${row.id}`}
              className="ct-link"
              onClick={(event) => event.stopPropagation()}
            >
              {row.title}
            </Link>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.request_number}
              {row.contract_type_name ? ` · ${row.contract_type_name}` : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'status',
        header: 'Status',
        sortKey: 'status',
        render: (row) => (
          <div style={{ display: 'grid', gap: 4, justifyItems: 'start' }}>
            <StatusChip status={row.status} size="sm" />
            {row.converted_contract_id ? (
              <Link
                to={`/contracts/${row.converted_contract_id}`}
                onClick={(event) => event.stopPropagation()}
                style={{ fontSize: 11, fontWeight: 600 }}
              >
                {row.converted_contract_number ?? 'Open contract'}
              </Link>
            ) : null}
          </div>
        ),
      },
      {
        key: 'department',
        header: 'Department',
        hideBelow: 'lg',
        render: (row) => row.department_name ?? '—',
      },
      {
        key: 'counterparty',
        header: 'Counterparty',
        hideBelow: 'md',
        render: (row) => row.counterparty_name ?? '—',
      },
      {
        key: 'required_by',
        header: 'Needed by',
        sortKey: 'required_by_date',
        hideBelow: 'sm',
        render: (row) => {
          if (!row.required_by_date) return <span style={{ color: 'var(--color-text-subtle)' }}>—</span>
          const late = isLate(row)
          return (
            <div>
              <div style={{ fontWeight: late ? 700 : 400 }}>{formatDate(row.required_by_date)}</div>
              <div style={{ fontSize: 11, color: late ? 'var(--color-danger)' : 'var(--color-text-muted)' }}>
                {late ? 'Past the date needed' : formatRelativeDays(daysUntil(row.required_by_date))}
              </div>
            </div>
          )
        },
      },
    ]

    if (canViewValue) {
      built.push({
        key: 'estimated_value',
        header: 'Estimated',
        sortKey: 'estimated_value',
        align: 'right',
        hideBelow: 'md',
        render: (row) => (
          <span style={{ fontVariantNumeric: 'tabular-nums' }}>
            {formatMoney(row.estimated_value, row.currency || 'INR', { compact: true })}
          </span>
        ),
      })
    }

    built.push({
      key: 'updated_at',
      header: 'Updated',
      sortKey: 'updated_at',
      hideBelow: 'lg',
      render: (row) => (
        <span style={{ color: 'var(--color-text-secondary)' }}>{formatDate(row.updated_at)}</span>
      ),
    })

    return built
  }, [canViewValue])

  const filterPanel = (
    <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))' }}>
      <Select
        label="Status"
        value={filters.status}
        onChange={(event) => setFilters({ ...filters, status: event.target.value })}
        options={[
          { value: OPEN_STATUS_VALUE, label: 'Open — anything in flight' },
          ...REQUEST_STATUSES.map((status) => ({ value: status, label: humanise(status) })),
        ]}
        placeholder="Any status"
      />
      <Select
        label="Contract type"
        value={filters.contract_type_id}
        onChange={(event) => setFilters({ ...filters, contract_type_id: event.target.value })}
        options={lookupValue.contractTypes.map((type) => ({ value: String(type.id), label: type.name }))}
        placeholder="Any type"
      />
      <Select
        label="Department"
        value={filters.department_id}
        onChange={(event) => setFilters({ ...filters, department_id: event.target.value })}
        options={lookupValue.departments.map((dept) => ({ value: String(dept.id), label: dept.name }))}
        placeholder="Any department"
      />
      <DateInput
        label="Needed on or before"
        value={filters.required_by}
        onChange={(event) => setFilters({ ...filters, required_by: event.target.value })}
      />
    </div>
  )

  return (
    <>
      <PageHeader
        title="Contract requests"
        description="What the business has asked for, and where each request has got to. A request becomes a contract only after a reviewer approves it for drafting."
        actions={
          canCreate ? (
            <Button variant="primary" icon={<Plus size={15} />} onClick={() => setCreating(true)}>
              New request
            </Button>
          ) : null
        }
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
          <div style={{ position: 'relative', flex: '1 1 220px', minWidth: 190 }}>
            <label htmlFor="requests-search" className="ct-sr-only">
              Search requests
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
              id="requests-search"
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

          <Button
            size="sm"
            variant={filters.mineOnly ? 'primary' : 'secondary'}
            aria-pressed={filters.mineOnly}
            icon={<UserRound size={14} />}
            disabled={!myUuid}
            onClick={() => setFilters({ ...filters, mineOnly: !filters.mineOnly })}
          >
            Raised by me
          </Button>

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
            <Button
              size="sm"
              variant="ghost"
              icon={<X size={13} />}
              onClick={() => {
                setFilters(EMPTY_FILTERS)
                setSearchText('')
              }}
            >
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
            ? 'Loading requests'
            : `${total} ${total === 1 ? 'request' : 'requests'} match the current filters`}
        </p>

        {list.error ? (
          <ErrorState
            title="Could not load requests"
            detail={list.error.message}
            onRetry={list.reload}
          />
        ) : !list.loading && rows.length === 0 ? (
          isFiltered ? (
            <EmptyState
              icon={<Search size={22} />}
              title="No requests match these filters"
              description="Nothing in this company fits the conditions you have set. Widen them, or start again from the whole queue."
              action={
                <Button
                  variant="secondary"
                  onClick={() => {
                    setFilters(EMPTY_FILTERS)
                    setSearchText('')
                  }}
                >
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={<FileSignature size={22} />}
              title="Nothing is open"
              description="This is where the business asks for an agreement — what it is for, who it is with and when it is needed. A reviewer approves it, and it becomes a draft contract. Requests already converted or rejected are still here under their own status."
              action={
                <div style={{ display: 'flex', gap: 8, justifyContent: 'center', flexWrap: 'wrap' }}>
                  {canCreate ? (
                    <Button variant="primary" icon={<Plus size={15} />} onClick={() => setCreating(true)}>
                      Raise a request
                    </Button>
                  ) : null}
                  <Button variant="secondary" onClick={() => setFilters({ ...filters, status: '' })}>
                    Show every status
                  </Button>
                </div>
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
              caption="Contract requests matching the current filters"
              sort={sort}
              onSortChange={(key, dir) => {
                setSort({ key: key as RequestSortKey, dir })
                setPage(1)
              }}
              onRowClick={(row) => navigate(`/requests/${row.id}`)}
              rowTone={(row) => (isLate(row) ? 'warning' : undefined)}
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
            <Button
              variant="ghost"
              onClick={() => {
                setFilters(EMPTY_FILTERS)
                setSearchText('')
              }}
            >
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

      {creating ? (
        <NewRequestModal
          lookups={lookupValue}
          canAttach={can(PERMISSION.DOCUMENT_UPLOAD)}
          onClose={() => setCreating(false)}
          onCreated={(request) => {
            setCreating(false)
            toast.success(`Request ${request.request_number} raised`, request.title)
            navigate(`/requests/${request.id}`)
          }}
        />
      ) : null}
    </>
  )
}

/** Whole days from today to a `YYYY-MM-DD` date, without a timezone shift. */
function daysUntil(date: string): number {
  const [year, month, day] = date.slice(0, 10).split('-').map(Number)
  const target = Date.UTC(year, (month ?? 1) - 1, day ?? 1)
  const now = new Date()
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())
  return Math.round((target - today) / 86_400_000)
}

interface FormState {
  title: string
  contract_type_id: string
  department_id: string
  required_by_date: string
  counterparty_name: string
  contact_ref_id: string
  purpose: string
  business_justification: string
  estimated_value: string
  currency: string
  preferred_template_id: string
  notes: string
}

const EMPTY_FORM: FormState = {
  title: '',
  contract_type_id: '',
  department_id: '',
  required_by_date: '',
  counterparty_name: '',
  contact_ref_id: '',
  purpose: '',
  business_justification: '',
  estimated_value: '',
  currency: 'INR',
  preferred_template_id: '',
  notes: '',
}

function hasHeader(headers: Record<string, string>, name: string): boolean {
  return Object.keys(headers).some((key) => key.toLowerCase() === name.toLowerCase())
}

/**
 * Attach a file to a request through the upload session flow.
 *
 * The bytes go straight to the storage provider rather than through the API,
 * which is why this one PUT is a plain `fetch`: the URL is not ours, it takes
 * no bearer token and returns no JSON envelope, so `apiClient` would be wrong
 * on all three counts. Every step that *is* ours goes through `api`.
 */
async function attachToRequest(requestId: number, file: File): Promise<void> {
  const session = await api.post<UploadSession>('/uploads/sessions', {
    request_id: requestId,
    filename: file.name,
    content_type: file.type || 'application/octet-stream',
    size_bytes: file.size,
    doc_kind: 'request_attachment',
    version_status: 'internal_draft',
  })

  try {
    const headers = { ...(session.headers ?? {}) }
    if (file.type && !hasHeader(headers, 'content-type')) headers['Content-Type'] = file.type

    const response = await fetch(session.upload_url, {
      method: session.method || 'PUT',
      headers,
      body: file,
    })
    if (!response.ok) throw new Error('The storage service refused the file.')

    await api.post(`/uploads/sessions/${session.session_id}/complete`)
    await api.post(`/uploads/sessions/${session.session_id}/finalize`, {
      version_status: 'internal_draft',
    })
  } catch (err) {
    // An abandoned session holds a storage slot until a timer clears it.
    void api.post(`/uploads/sessions/${session.session_id}/abort`).catch(() => undefined)
    throw err
  }
}

function NewRequestModal({
  lookups,
  canAttach,
  onClose,
  onCreated,
}: {
  lookups: Lookups
  canAttach: boolean
  onClose: () => void
  onCreated: (request: ContractRequest) => void
}) {
  const toast = useToast()
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [files, setFiles] = useState<File[]>([])
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setForm((current) => ({ ...current, [key]: value }))
    setErrors((current) => (current[key] ? { ...current, [key]: '' } : current))
  }

  const submit = async (thenSubmitForReview: boolean) => {
    const localErrors: FieldErrors = {}
    if (form.title.trim() === '') localErrors.title = 'Give the request a title.'
    // The server refuses a submission with no purpose; catching it here saves a
    // round trip and puts the message on the field rather than in a toast.
    if (thenSubmitForReview && form.purpose.trim() === '') {
      localErrors.purpose = 'Describe what this contract is for before submitting it.'
    }
    if (Object.keys(localErrors).length > 0) {
      setErrors(localErrors)
      return
    }

    setSaving(true)
    setErrors({})

    try {
      const created = await api.post<ContractRequest>('/requests', {
        title: form.title.trim(),
        contract_type_id: form.contract_type_id ? Number(form.contract_type_id) : null,
        department_id: form.department_id ? Number(form.department_id) : null,
        required_by_date: form.required_by_date || null,
        counterparty_name: form.counterparty_name.trim() || null,
        contact_ref_id: form.contact_ref_id || null,
        purpose: form.purpose.trim() || null,
        business_justification: form.business_justification.trim() || null,
        estimated_value: form.estimated_value.trim() || null,
        currency: form.currency,
        preferred_template_id: form.preferred_template_id ? Number(form.preferred_template_id) : null,
        notes: form.notes.trim() || null,
      })

      const failedAttachments: string[] = []
      for (const file of files) {
        try {
          await attachToRequest(created.id, file)
        } catch {
          failedAttachments.push(file.name)
        }
      }
      if (failedAttachments.length > 0) {
        toast.warning(
          'Some attachments did not upload',
          `${failedAttachments.join(', ')}. The request itself was saved — add them again from the request.`,
        )
      }

      if (!thenSubmitForReview) {
        onCreated(created)
        return
      }

      try {
        const submitted = await api.post<ContractRequest>(`/requests/${created.id}/submit`)
        onCreated(submitted)
      } catch (err) {
        toast.warning(
          'Saved as a draft',
          err instanceof Error ? err.message : 'It could not be sent for review.',
        )
        onCreated(created)
      }
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not raise the request', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="New contract request"
      description="Ask for an agreement. A reviewer decides whether it goes forward, and the request stays as the record of why it exists."
      width={760}
      closeOnBackdrop={!saving}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="subtle" onClick={() => void submit(false)} disabled={saving}>
            Save as draft
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit(true)}>
            Save and submit
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <Input
          label="Title"
          required
          value={form.title}
          error={errors.title}
          onChange={(event) => set('title', event.target.value)}
          placeholder="Master services agreement with Acme"
        />

        <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))' }}>
          <Select
            label="Request type"
            value={form.contract_type_id}
            error={errors.contract_type_id}
            onChange={(event) => set('contract_type_id', event.target.value)}
            options={lookups.contractTypes.map((type) => ({ value: String(type.id), label: type.name }))}
            placeholder="Not decided yet"
          />
          <Select
            label="Department"
            value={form.department_id}
            error={errors.department_id}
            onChange={(event) => set('department_id', event.target.value)}
            options={lookups.departments.map((dept) => ({ value: String(dept.id), label: dept.name }))}
            placeholder="Not stated"
          />
          <Input
            label="Requester"
            value="You"
            readOnly
            hint="A request is always raised in the name of the person raising it."
          />
          <DateInput
            label="Required by"
            value={form.required_by_date}
            error={errors.required_by_date}
            onChange={(event) => set('required_by_date', event.target.value)}
          />
        </div>

        <CounterpartyPicker
          value={form.counterparty_name}
          error={errors.counterparty_name}
          hint="Who the agreement will be with. Type a name if they are not in Contacts yet."
          onChange={(name, contact) => {
            setForm((current) => ({
              ...current,
              counterparty_name: name,
              contact_ref_id: contact?.uuid ? String(contact.uuid) : contact?.id ? String(contact.id) : '',
            }))
            setErrors((current) => (current.counterparty_name ? { ...current, counterparty_name: '' } : current))
          }}
        />

        <Textarea
          label="Purpose"
          rows={3}
          value={form.purpose}
          error={errors.purpose}
          onChange={(event) => set('purpose', event.target.value)}
          hint="What the agreement is for, in the words a reviewer needs to judge it."
        />

        <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))' }}>
          <MoneyInput
            label="Estimated value"
            currency={form.currency}
            value={form.estimated_value}
            error={errors.estimated_value}
            onChange={(event) => set('estimated_value', event.target.value)}
          />
          <Select
            label="Currency"
            value={form.currency}
            error={errors.currency}
            onChange={(event) => set('currency', event.target.value)}
            options={CURRENCIES.map((code) => ({ value: code, label: code }))}
          />
          <Select
            label="Preferred template"
            value={form.preferred_template_id}
            error={errors.preferred_template_id}
            onChange={(event) => set('preferred_template_id', event.target.value)}
            options={lookups.templates.map((template) => ({
              value: String(template.id),
              label: template.name,
            }))}
            placeholder="No preference"
          />
        </div>

        <Textarea
          label="Business justification"
          rows={3}
          value={form.business_justification}
          error={errors.business_justification}
          onChange={(event) => set('business_justification', event.target.value)}
          hint="Why the company should enter into it — the case a reviewer is being asked to accept."
        />

        {canAttach ? (
          <AttachmentPicker files={files} onChange={setFiles} disabled={saving} />
        ) : null}

        <Textarea
          label="Notes"
          rows={2}
          value={form.notes}
          error={errors.notes}
          onChange={(event) => set('notes', event.target.value)}
        />
      </div>
    </Modal>
  )
}

function AttachmentPicker({
  files,
  onChange,
  disabled,
}: {
  files: File[]
  onChange: (files: File[]) => void
  disabled: boolean
}) {
  return (
    <div style={{ display: 'grid', gap: 8 }}>
      <label htmlFor="request-attachments" style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--color-text-secondary)' }}>
        Attachments
      </label>
      <input
        id="request-attachments"
        type="file"
        multiple
        disabled={disabled}
        aria-describedby="request-attachments-hint"
        onChange={(event) => {
          onChange([...files, ...Array.from(event.target.files ?? [])])
          event.target.value = ''
        }}
        style={{ fontSize: 12.5 }}
      />
      <p id="request-attachments-hint" style={{ fontSize: 12, color: 'var(--color-text-muted)' }}>
        PDF, Word, RTF, text or scans. They are uploaded once the request is saved and travel with it
        into the contract.
      </p>
      {files.length > 0 ? (
        <ul style={{ listStyle: 'none', display: 'grid', gap: 6 }}>
          {files.map((file, index) => (
            <li
              key={`${file.name}-${index}`}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                padding: '6px 10px',
                borderRadius: 'var(--radius-md)',
                background: 'var(--color-bg-subtle)',
                border: '1px solid rgb(var(--color-border))',
                fontSize: 12.5,
              }}
            >
              <Paperclip size={13} aria-hidden style={{ color: 'var(--color-text-muted)' }} />
              <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {file.name}
              </span>
              <Chip size="sm">{Math.max(1, Math.round(file.size / 1024))} KB</Chip>
              <button
                type="button"
                aria-label={`Remove ${file.name}`}
                disabled={disabled}
                onClick={() => onChange(files.filter((_, position) => position !== index))}
                style={{
                  background: 'none',
                  border: 'none',
                  cursor: 'pointer',
                  color: 'var(--color-text-muted)',
                  lineHeight: 0,
                  padding: 2,
                }}
              >
                <X size={13} aria-hidden />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
