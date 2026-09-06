import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AlarmClock, CalendarSync, RefreshCw, Repeat, Search, UserRound, X } from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  DataTable,
  DateInput,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  PageHeader,
  Pagination,
  StatusChip,
  Tabs,
  Textarea,
} from '../components/ui'
import type { Column } from '../components/ui'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import type { FieldErrors } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import {
  RENEWAL_STATUSES,
  type Paged,
  type RenewalBucket,
  type RenewalDecisionName,
  type RenewalPipelineItem,
} from '../types/contracts'
import { formatDate, formatMoney, humanise } from '../utils/format'

/**
 * The renewal pipeline — the screen that stops a company renewing something it
 * did not want.
 *
 * The notice deadline is the most prominent thing on every row, and it is the
 * first column, because it is the only date on this screen that cannot be
 * recovered once it passes: an auto-renewing contract nobody served notice on
 * renews itself, on its own terms, for another term. The countdown is stated in
 * words ("11 days left", "deadline passed") as well as coloured, and a passed
 * deadline stays in the list rather than dropping out of it.
 */

const SEARCH_DEBOUNCE_MS = 300

const BUCKETS: { id: RenewalBucket; label: string; hint: string }[] = [
  { id: 'notice_due', label: 'Notice deadline approaching', hint: 'The window to serve notice is closing' },
  { id: 'auto_renewal_risk', label: 'Auto-renewal risk', hint: 'Renews itself unless somebody acts' },
  { id: 'expiring_30', label: 'Expiring in 30 days', hint: 'Ends within a month' },
  { id: 'expiring_60', label: 'Expiring in 60 days', hint: 'Ends within two months' },
  { id: 'expiring_90', label: 'Expiring in 90 days', hint: 'Ends within three months' },
  { id: 'all', label: 'All cycles', hint: 'Every renewal cycle in the company' },
]

const BUCKET_EMPTY: Record<RenewalBucket, { title: string; description: string }> = {
  notice_due: {
    title: 'No notice deadline is close',
    description:
      'Nothing needs notice served in the current alert window. Cycles appear here as their deadline comes into range.',
  },
  auto_renewal_risk: {
    title: 'Nothing is about to auto-renew',
    description:
      'No auto-renewing contract is sitting undecided with its notice window closing. This is the bucket you want empty.',
  },
  expiring_30: {
    title: 'Nothing expires in the next 30 days',
    description: 'No contract with an open renewal cycle ends within a month.',
  },
  expiring_60: {
    title: 'Nothing expires in the next 60 days',
    description: 'No contract with an open renewal cycle ends within two months.',
  },
  expiring_90: {
    title: 'Nothing expires in the next 90 days',
    description: 'No contract with an open renewal cycle ends within three months.',
  },
  all: {
    title: 'No renewal cycles yet',
    description:
      'A cycle opens as a contract goes active and holds its notice deadline, the recommendation and the decision. Contracts with no expiry date never get one.',
  },
}

const DECISION_LABEL: Record<RenewalDecisionName, string> = {
  renew: 'Renew',
  renegotiate: 'Renegotiate',
  defer: 'Defer the decision',
  terminate: 'Let it end',
}

const DECISION_DESCRIPTION: Record<RenewalDecisionName, string> = {
  renew: 'Extends the contract and opens the next cycle in the same step.',
  renegotiate: 'Keeps the cycle open and marks it as needing new terms before it rolls.',
  defer: 'Pushes the decision date out. The notice deadline does not move.',
  terminate: 'Records the intent to let it end. Ending it formally is a termination of its own.',
}

const RECOMMENDATION_TONE: Record<string, 'success' | 'warning' | 'danger' | 'info'> = {
  renew: 'success',
  renegotiate: 'warning',
  terminate: 'danger',
  review_manually: 'info',
}

const CLOSED_STATUSES = ['renewed', 'closed']

/** How urgent a deadline is. Kept as one function so every reading of it agrees. */
export function deadlineTone(days: number | null): 'danger' | 'warning' | 'neutral' {
  if (days === null) return 'neutral'
  if (days <= 14) return 'danger'
  if (days <= 45) return 'warning'
  return 'neutral'
}

export default function Renewals() {
  const navigate = useNavigate()
  const { can, session } = useSession()

  const canManage = can(PERMISSION.RENEWAL_MANAGE)
  const canTerminate = can(PERMISSION.CONTRACT_TERMINATE)
  const canViewValue = can(PERMISSION.COMMERCIALS_VIEW)
  const myUuid = session?.uuid ?? null

  const [bucket, setBucket] = useState<RenewalBucket>('notice_due')
  const [status, setStatus] = useState('')
  const [mineOnly, setMineOnly] = useState(false)
  const [searchText, setSearchText] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [deciding, setDeciding] = useState<RenewalPipelineItem | null>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery(searchText)
      // A new search on page 3 of the old result reads as an empty bucket.
      setPage(1)
    }, SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [searchText])

  const list = useApiResource<Paged<RenewalPipelineItem>>(
    (signal) =>
      api.get<Paged<RenewalPipelineItem>>(
        '/renewals',
        {
          bucket,
          status,
          q: query,
          owner_uuid: mineOnly && myUuid ? myUuid : '',
          page,
          per_page: perPage,
        },
        signal,
      ),
    [bucket, status, query, mineOnly, myUuid, page, perPage],
  )

  const rows = list.data?.items ?? []
  const total = list.data?.total ?? 0

  const changeBucket = useCallback((next: RenewalBucket) => {
    setBucket(next)
    setPage(1)
  }, [])

  const columns = useMemo<Column<RenewalPipelineItem>[]>(() => {
    const built: Column<RenewalPipelineItem>[] = [
      {
        key: 'notice',
        header: 'Notice deadline',
        width: 210,
        render: (row) => <NoticeCountdown row={row} />,
      },
      {
        key: 'contract',
        header: 'Contract',
        render: (row) => (
          <div style={{ minWidth: 190 }}>
            <Link
              to={`/contracts/${row.contract_id}?tab=renewal`}
              className="ct-link"
              onClick={(event) => event.stopPropagation()}
            >
              {row.contract_title ?? `Contract ${row.contract_id}`}
            </Link>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {row.contract_number ?? '—'}
              {row.counterparty_name ? ` · ${row.counterparty_name}` : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'expiry',
        header: 'Expires',
        hideBelow: 'sm',
        render: (row) => (
          <div>
            <div>{formatDate(row.current_expiry)}</div>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
              {row.days_to_expiry === null
                ? 'No expiry date'
                : row.days_to_expiry < 0
                  ? `${Math.abs(row.days_to_expiry)} days ago`
                  : `in ${row.days_to_expiry} days`}
            </div>
          </div>
        ),
      },
      {
        key: 'renewal',
        header: 'Renews',
        hideBelow: 'md',
        render: (row) => (
          <div style={{ display: 'grid', gap: 4, justifyItems: 'start' }}>
            {row.auto_renewal ? (
              <Chip tone="warning" size="sm">
                <Repeat size={11} aria-hidden />
                Automatically
              </Chip>
            ) : (
              <Chip size="sm">{row.renewal_type ? humanise(row.renewal_type) : 'Manually'}</Chip>
            )}
            <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
              Cycle {row.cycle_no}
              {row.renewal_frequency ? ` · ${humanise(row.renewal_frequency)}` : ''}
            </span>
          </div>
        ),
      },
      {
        key: 'recommendation',
        header: 'Recommendation',
        hideBelow: 'lg',
        render: (row) =>
          row.recommendation ? (
            <Chip
              tone={RECOMMENDATION_TONE[row.recommendation] ?? 'neutral'}
              size="sm"
              title={row.recommendation_reason?.trim() || 'No reason recorded'}
            >
              {humanise(row.recommendation)}
            </Chip>
          ) : (
            <span style={{ color: 'var(--color-text-subtle)', fontSize: 12.5 }}>None yet</span>
          ),
      },
    ]

    if (canViewValue) {
      built.push({
        key: 'value',
        header: 'Value',
        align: 'right',
        hideBelow: 'lg',
        render: (row) => (
          <span style={{ fontVariantNumeric: 'tabular-nums' }}>
            {formatMoney(row.total_value, row.currency || 'INR', { compact: true })}
          </span>
        ),
      })
    }

    built.push({
      key: 'decision',
      header: 'Decision',
      width: 150,
      render: (row) => {
        if (row.decision) {
          return (
            <div style={{ display: 'grid', gap: 3, justifyItems: 'start' }}>
              <StatusChip status={row.decision} size="sm" />
              <span style={{ fontSize: 11, color: 'var(--color-text-muted)' }}>
                {row.decision_at ? formatDate(row.decision_at) : 'Recorded'}
              </span>
            </div>
          )
        }

        if (CLOSED_STATUSES.includes(row.status)) {
          return <StatusChip status={row.status} size="sm" />
        }

        if (!canManage) {
          return <StatusChip status={row.status} size="sm" />
        }

        return (
          <Button
            size="sm"
            variant="primary"
            aria-label={`Decide the renewal of ${row.contract_title ?? `contract ${row.contract_id}`}`}
            onClick={(event) => {
              event.stopPropagation()
              setDeciding(row)
            }}
          >
            Decide
          </Button>
        )
      },
    })

    return built
  }, [canManage, canViewValue])

  const activeBucket = BUCKETS.find((item) => item.id === bucket) ?? BUCKETS[0]
  const isFiltered = status !== '' || query !== '' || mineOnly

  return (
    <>
      <PageHeader
        title="Renewals"
        description="Every contract with a renewal decision still to make, ordered by how soon it has to be made. The notice deadline is the date that cannot be recovered once it passes."
      />

      <Card padded={false}>
        <div style={{ padding: '0 6px' }}>
          <Tabs
            ariaLabel="Renewal pipeline buckets"
            active={bucket}
            onChange={(id) => changeBucket(id as RenewalBucket)}
            items={BUCKETS.map((item) => ({ id: item.id, label: item.label }))}
          />
        </div>

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
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', flex: '1 1 220px' }}>
            {activeBucket.hint}
          </p>

          <div style={{ position: 'relative', flex: '0 1 240px', minWidth: 190 }}>
            <label htmlFor="renewals-search" className="ct-sr-only">
              Search renewals
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
              id="renewals-search"
              type="search"
              value={searchText}
              onChange={(event) => setSearchText(event.target.value)}
              placeholder="Contract, number or counterparty"
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

          <label style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
            <span className="ct-sr-only">Cycle status</span>
            <select
              value={status}
              onChange={(event) => {
                setStatus(event.target.value)
                setPage(1)
              }}
              style={{
                height: 34,
                padding: '0 8px',
                borderRadius: 'var(--radius-md)',
                border: '1px solid rgb(var(--color-border-strong))',
                background: 'var(--color-bg-card)',
                color: 'var(--color-text)',
                fontSize: 13,
              }}
            >
              <option value="">Any cycle status</option>
              {RENEWAL_STATUSES.map((option) => (
                <option key={option} value={option}>
                  {humanise(option)}
                </option>
              ))}
            </select>
          </label>

          <Button
            size="sm"
            variant={mineOnly ? 'primary' : 'secondary'}
            aria-pressed={mineOnly}
            icon={<UserRound size={14} />}
            disabled={!myUuid}
            onClick={() => {
              setMineOnly((current) => !current)
              setPage(1)
            }}
          >
            Mine
          </Button>

          {isFiltered ? (
            <Button
              size="sm"
              variant="ghost"
              icon={<X size={13} />}
              onClick={() => {
                setStatus('')
                setMineOnly(false)
                setSearchText('')
                setQuery('')
                setPage(1)
              }}
            >
              Clear
            </Button>
          ) : null}
        </div>

        <p aria-live="polite" className="ct-sr-only">
          {list.loading
            ? 'Loading renewals'
            : `${total} ${total === 1 ? 'renewal cycle' : 'renewal cycles'} in ${activeBucket.label}`}
        </p>

        {list.error ? (
          <ErrorState
            title="Could not load renewals"
            detail={list.error.message}
            onRetry={list.reload}
          />
        ) : !list.loading && rows.length === 0 ? (
          <EmptyState
            icon={<CalendarSync size={22} />}
            title={isFiltered ? 'Nothing matches these filters' : BUCKET_EMPTY[bucket].title}
            description={
              isFiltered
                ? 'No cycle in this bucket fits the search and filters you have set.'
                : BUCKET_EMPTY[bucket].description
            }
            action={
              isFiltered ? (
                <Button
                  variant="secondary"
                  onClick={() => {
                    setStatus('')
                    setMineOnly(false)
                    setSearchText('')
                    setQuery('')
                  }}
                >
                  Clear filters
                </Button>
              ) : bucket !== 'all' ? (
                <Button variant="secondary" icon={<RefreshCw size={14} />} onClick={() => changeBucket('all')}>
                  See every cycle
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            <DataTable
              columns={columns}
              rows={rows}
              rowKey={(row) => row.id}
              loading={list.loading}
              caption="Renewal cycles awaiting a decision"
              onRowClick={(row) => navigate(`/contracts/${row.contract_id}?tab=renewal`)}
              rowTone={(row) => {
                if (row.decision !== null || CLOSED_STATUSES.includes(row.status)) return undefined
                const tone = deadlineTone(row.days_to_notice)
                return tone === 'neutral' ? undefined : tone
              }}
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

      {deciding ? (
        <DecisionModal
          renewal={deciding}
          canTerminate={canTerminate}
          onClose={() => setDeciding(null)}
          onDecided={() => {
            setDeciding(null)
            list.reload()
          }}
        />
      ) : null}
    </>
  )
}

/**
 * The countdown to the cancellation deadline.
 *
 * Deliberately the loudest thing in the row. The bar is decoration over the
 * words rather than the other way round — it is `aria-hidden`, and the number
 * of days is written out beside it, because "how long have I got" is a question
 * a bar cannot answer precisely.
 */
export function NoticeCountdown({ row }: { row: RenewalPipelineItem }) {
  const days = row.days_to_notice
  const decided = row.decision !== null

  if (!row.notice_deadline || days === null) {
    return (
      <div>
        <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text-secondary)' }}>
          No notice required
        </div>
        <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
          Nothing has to be served before it ends
        </div>
      </div>
    )
  }

  const tone = decided ? 'neutral' : deadlineTone(days)
  const colour =
    tone === 'danger'
      ? 'var(--color-danger)'
      : tone === 'warning'
        ? 'var(--color-warning)'
        : 'var(--color-text-secondary)'

  const headline =
    days < 0
      ? `Deadline passed ${Math.abs(days)} ${Math.abs(days) === 1 ? 'day' : 'days'} ago`
      : days === 0
        ? 'Deadline is today'
        : `${days} ${days === 1 ? 'day' : 'days'} left`

  // 90 days is the horizon the pipeline works to; past that the bar is full and
  // the row is not urgent anyway.
  const fraction = Math.max(0, Math.min(1, days / 90))

  return (
    <div style={{ display: 'grid', gap: 3, minWidth: 170 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
        <AlarmClock size={15} aria-hidden style={{ color: colour, flexShrink: 0 }} />
        <strong style={{ fontSize: 14.5, color: colour, letterSpacing: '-.01em' }}>{headline}</strong>
      </div>
      <svg width="100%" height={5} aria-hidden style={{ display: 'block', maxWidth: 168 }}>
        <rect width="100%" height={5} rx={2.5} fill="rgb(var(--color-border))" />
        <rect
          width={`${Math.round(fraction * 100)}%`}
          height={5}
          rx={2.5}
          fill={
            tone === 'danger'
              ? 'var(--color-danger)'
              : tone === 'warning'
                ? 'var(--color-warning)'
                : 'var(--color-success)'
          }
        />
      </svg>
      <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
        Serve notice by {formatDate(row.notice_deadline)}
      </span>
      {days < 0 && !decided && row.auto_renewal ? (
        <span style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--color-danger)' }}>
          It will roll forward on its own
        </span>
      ) : null}
    </div>
  )
}

function DecisionModal({
  renewal,
  canTerminate,
  onClose,
  onDecided,
}: {
  renewal: RenewalPipelineItem
  canTerminate: boolean
  onClose: () => void
  onDecided: () => void
}) {
  const toast = useToast()
  const [decision, setDecision] = useState<RenewalDecisionName>(
    renewal.recommendation === 'terminate' && canTerminate
      ? 'terminate'
      : renewal.recommendation === 'renegotiate'
        ? 'renegotiate'
        : 'renew',
  )
  const [notes, setNotes] = useState('')
  const [termMonths, setTermMonths] = useState(
    renewal.renewal_term_months ? String(renewal.renewal_term_months) : '12',
  )
  const [proposedStart, setProposedStart] = useState(renewal.proposed_start ?? '')
  const [proposedExpiry, setProposedExpiry] = useState(renewal.proposed_expiry ?? '')
  const [deferUntil, setDeferUntil] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)

  const options: RenewalDecisionName[] = canTerminate
    ? ['renew', 'renegotiate', 'defer', 'terminate']
    : ['renew', 'renegotiate', 'defer']

  const submit = async () => {
    if (decision === 'defer' && deferUntil === '') {
      setErrors({ defer_until: 'Say when the decision will be made instead.' })
      return
    }

    setBusy(true)
    setErrors({})
    try {
      await api.post(`/renewals/${renewal.id}/decision`, {
        decision,
        notes: notes.trim() || null,
        renewal_term_months: decision === 'renew' && termMonths ? Number(termMonths) : null,
        proposed_start: decision === 'renew' ? proposedStart || null : null,
        proposed_expiry: decision === 'renew' ? proposedExpiry || null : null,
        defer_until: decision === 'defer' ? deferUntil || null : null,
      })
      toast.success(`Decision recorded: ${DECISION_LABEL[decision].toLowerCase()}`, renewal.contract_title ?? undefined)
      onDecided()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not record the decision', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`Renewal decision — cycle ${renewal.cycle_no}`}
      description={renewal.contract_title ?? `Contract ${renewal.contract_id}`}
      width={560}
      closeOnBackdrop={!busy}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            variant={decision === 'terminate' ? 'danger' : 'primary'}
            loading={busy}
            onClick={() => void submit()}
          >
            Record decision
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 16 }}>
        <div
          style={{
            padding: '11px 13px',
            borderRadius: 'var(--radius-md)',
            border: '1px solid rgb(var(--color-border))',
            background: 'var(--color-bg-subtle)',
          }}
        >
          <NoticeCountdown row={renewal} />
        </div>

        <fieldset style={{ border: 'none' }}>
          <legend style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--color-text-secondary)', marginBottom: 8 }}>
            What happens to this contract
          </legend>
          <div style={{ display: 'grid', gap: 8 }}>
            {options.map((option) => (
              <label
                key={option}
                style={{
                  display: 'flex',
                  gap: 9,
                  alignItems: 'flex-start',
                  padding: '9px 11px',
                  borderRadius: 'var(--radius-md)',
                  border: `1px solid ${
                    decision === option ? 'rgb(var(--color-primary))' : 'rgb(var(--color-border))'
                  }`,
                  background: decision === option ? 'var(--color-primary-muted)' : 'transparent',
                  cursor: 'pointer',
                }}
              >
                <input
                  type="radio"
                  name="renewal-decision"
                  value={option}
                  checked={decision === option}
                  onChange={() => setDecision(option)}
                  style={{ marginTop: 3, accentColor: 'rgb(var(--color-primary))' }}
                />
                <span>
                  <span style={{ display: 'block', fontSize: 13.5, fontWeight: 700 }}>
                    {DECISION_LABEL[option]}
                  </span>
                  <span style={{ display: 'block', fontSize: 12, color: 'var(--color-text-secondary)' }}>
                    {DECISION_DESCRIPTION[option]}
                  </span>
                </span>
              </label>
            ))}
          </div>
        </fieldset>

        {decision === 'renew' ? (
          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))' }}>
            <Input
              label="New term (months)"
              inputMode="numeric"
              value={termMonths}
              error={errors.renewal_term_months}
              onChange={(event) => setTermMonths(event.target.value.replace(/[^0-9]/g, ''))}
            />
            <DateInput
              label="Starts"
              value={proposedStart}
              error={errors.proposed_start}
              onChange={(event) => setProposedStart(event.target.value)}
            />
            <DateInput
              label="New expiry"
              value={proposedExpiry}
              error={errors.proposed_expiry}
              hint="Leave empty to let the term decide it"
              onChange={(event) => setProposedExpiry(event.target.value)}
            />
          </div>
        ) : null}

        {decision === 'defer' ? (
          <DateInput
            label="Decide by"
            required
            value={deferUntil}
            error={errors.defer_until}
            hint="The notice deadline does not move — only the reminder does."
            onChange={(event) => {
              setDeferUntil(event.target.value)
              setErrors((current) => (current.defer_until ? { ...current, defer_until: '' } : current))
            }}
          />
        ) : null}

        <Textarea
          label="Notes"
          rows={3}
          value={notes}
          error={errors.notes}
          onChange={(event) => setNotes(event.target.value)}
          hint="Why this is the right call. It stays on the cycle for whoever reviews the next one."
        />

        {decision === 'terminate' ? (
          <p
            role="note"
            style={{
              fontSize: 12.5,
              lineHeight: 1.6,
              padding: '9px 12px',
              borderRadius: 'var(--radius-md)',
              background: 'var(--color-warning-bg)',
              border: '1px solid var(--color-warning-border)',
            }}
          >
            This records the intent only. Ending the contract still needs a termination, with its own
            notice and approval, from the contract itself.
          </p>
        ) : null}
      </div>
    </Modal>
  )
}
