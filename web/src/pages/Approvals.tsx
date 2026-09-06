import { useMemo, useState } from 'react'
import type { MouseEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { AlarmClock, Check, CheckSquare, MessageSquare, Undo2, X } from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  DataTable,
  EmptyState,
  ErrorState,
  Modal,
  PageHeader,
  Pagination,
  RiskChip,
  Select,
  StatusChip,
  Tabs,
  Textarea,
  Tooltip,
} from '../components/ui'
import type { Column } from '../components/ui'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import type { FieldErrors } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import type {
  ApprovalActionName,
  ApprovalQueueItem,
  ContractListItem,
  Paged,
} from '../types/contracts'
import { formatDate, formatDateTime, formatMoney, humanise, truncate } from '../utils/format'

/**
 * The approvals screen: what is waiting on me, and what is in flight everywhere
 * else.
 *
 * The two are different questions with different answers from the server. "My
 * queue" is `GET /approvals/queue`, which returns only steps assigned to the
 * viewer *and currently open* — acting on anything else is refused. "Everything
 * I can see" is the contract repository filtered by approval status, because
 * approvals belong to contracts and the row-level rules that decide which
 * contracts a viewer may see are already enforced there.
 */

type Scope = 'mine' | 'all'

type QueueAction = Extract<ApprovalActionName, 'approve' | 'reject' | 'send_back' | 'comment'>

const ACT_LABEL: Record<QueueAction, string> = {
  approve: 'Approve',
  reject: 'Reject',
  send_back: 'Send back',
  comment: 'Comment',
}

const ACT_DONE: Record<QueueAction, string> = {
  approve: 'Approved',
  reject: 'Rejected',
  send_back: 'Sent back',
  comment: 'Comment added',
}

/** A comment is the whole point of these two; the API allows it to be empty, the queue does not. */
const REASON_REQUIRED: QueueAction[] = ['reject', 'send_back']

const APPROVAL_STATUS_OPTIONS = [
  { value: 'pending', label: 'Waiting to start' },
  { value: 'in_progress', label: 'Part-way through' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
]

export default function Approvals() {
  const { can, session, refresh } = useSession()
  const [scope, setScope] = useState<Scope>('mine')

  const pending = session?.counts.approvals ?? 0

  return (
    <>
      <PageHeader
        title="Approvals"
        description="Steps assigned to you, and the approvals running elsewhere in the company. Acting here does the same thing as acting on the contract itself."
      />

      <Card padded={false}>
        <div style={{ padding: '0 6px' }}>
          <Tabs
            ariaLabel="Approval scope"
            active={scope}
            onChange={(id) => setScope(id as Scope)}
            items={[
              { id: 'mine', label: 'My queue', badge: pending > 0 ? pending : undefined },
              { id: 'all', label: 'Everything I can see' },
            ]}
          />
        </div>

        {scope === 'mine' ? (
          <MyQueue canAct={can(PERMISSION.APPROVAL_ACT)} onActed={() => void refresh()} />
        ) : (
          <InFlight />
        )}
      </Card>
    </>
  )
}

/** Where a queued step's subject lives, so a row can be opened from the queue. */
function subjectLink(row: ApprovalQueueItem): string | null {
  if (row.subject_type === 'request') return `/requests/${row.subject_id}`
  if (row.contract_id) return `/contracts/${row.contract_id}?tab=approvals`
  return null
}

function MyQueue({ canAct, onActed }: { canAct: boolean; onActed: () => void }) {
  const navigate = useNavigate()
  const { session } = useSession()
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [acting, setActing] = useState<{ row: ApprovalQueueItem; action: QueueAction } | null>(null)

  const queue = useApiResource<Paged<ApprovalQueueItem>>(
    (signal) =>
      api.get<Paged<ApprovalQueueItem>>('/approvals/queue', { page, per_page: perPage }, signal),
    [page, perPage],
  )

  const rows = queue.data?.items ?? []
  const total = queue.data?.total ?? 0
  const canViewValue = session?.permissions.includes(PERMISSION.COMMERCIALS_VIEW) ?? false

  const columns = useMemo<Column<ApprovalQueueItem>[]>(() => {
    const built: Column<ApprovalQueueItem>[] = [
      {
        key: 'subject',
        header: 'Waiting on you',
        render: (row) => {
          const href = subjectLink(row)
          const title = row.contract_title ?? `${humanise(row.subject_type)} ${row.subject_id}`
          return (
            <div style={{ minWidth: 200 }}>
              {href ? (
                <Link to={href} className="ct-link" onClick={(event) => event.stopPropagation()}>
                  {title}
                </Link>
              ) : (
                <span style={{ fontWeight: 600 }}>{title}</span>
              )}
              <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                {row.contract_number ?? humanise(row.subject_type)}
                {row.counterparty_name ? ` · ${row.counterparty_name}` : ''}
              </div>
            </div>
          )
        },
      },
      {
        key: 'step',
        header: 'Step',
        hideBelow: 'sm',
        render: (row) => (
          <div>
            <div style={{ fontWeight: 600 }}>
              {row.step_name?.trim() || `Step ${row.step_no}`}
            </div>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
              {row.workflow_name ?? 'Ad-hoc approval'}
              {row.delegated_from ? ' · delegated to you' : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'due',
        header: 'Due',
        render: (row) => <DueCell row={row} />,
      },
      {
        key: 'submitted',
        header: 'Submitted',
        hideBelow: 'lg',
        render: (row) => (
          <span style={{ color: 'var(--color-text-secondary)' }}>
            {row.submitted_at ? formatDate(row.submitted_at) : '—'}
          </span>
        ),
      },
      {
        key: 'risk',
        header: 'Risk',
        hideBelow: 'md',
        render: (row) => <RiskChip level={row.risk_level} />,
      },
    ]

    if (canViewValue) {
      built.push({
        key: 'value',
        header: 'Value',
        align: 'right',
        hideBelow: 'md',
        render: (row) => (
          <span style={{ fontVariantNumeric: 'tabular-nums' }}>
            {formatMoney(row.total_value, row.currency || 'INR', { compact: true })}
          </span>
        ),
      })
    }

    if (canAct) {
      built.push({
        key: 'actions',
        header: 'Decision',
        width: 168,
        render: (row) => {
          const subject = row.contract_title ?? `step ${row.step_no}`
          const open = (action: QueueAction) => (event: MouseEvent<HTMLButtonElement>) => {
            // The row itself navigates; a decision button must not do both.
            event.stopPropagation()
            setActing({ row, action })
          }

          return (
            <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' }}>
              <Button
                size="sm"
                variant="primary"
                icon={<Check size={13} />}
                aria-label={`Approve ${subject}`}
                onClick={open('approve')}
              >
                Approve
              </Button>
              <Tooltip content="Send back to the submitter">
                <Button
                  size="sm"
                  variant="secondary"
                  aria-label={`Send back ${subject}`}
                  onClick={open('send_back')}
                >
                  <Undo2 size={13} aria-hidden />
                </Button>
              </Tooltip>
              <Tooltip content="Reject">
                <Button
                  size="sm"
                  variant="secondary"
                  aria-label={`Reject ${subject}`}
                  onClick={open('reject')}
                >
                  <X size={13} aria-hidden />
                </Button>
              </Tooltip>
              <Tooltip content="Comment without deciding">
                <Button
                  size="sm"
                  variant="ghost"
                  aria-label={`Comment on ${subject}`}
                  onClick={open('comment')}
                >
                  <MessageSquare size={13} aria-hidden />
                </Button>
              </Tooltip>
            </div>
          )
        },
      })
    }

    return built
  }, [canAct, canViewValue])

  return (
    <>
      <p aria-live="polite" className="ct-sr-only">
        {queue.loading
          ? 'Loading your approval queue'
          : `${total} ${total === 1 ? 'approval is' : 'approvals are'} waiting on you`}
      </p>

      {queue.error ? (
        <ErrorState
          title="Could not load your queue"
          detail={queue.error.message}
          onRetry={queue.reload}
        />
      ) : !queue.loading && rows.length === 0 ? (
        <EmptyState
          icon={<CheckSquare size={22} />}
          title="Nothing is waiting on you"
          description="Steps assigned to you appear here the moment a contract reaches them. Until then, there is nothing to do."
        />
      ) : (
        <>
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.assignment_id}
            loading={queue.loading}
            caption="Approval steps assigned to you"
            onRowClick={(row) => {
              const href = subjectLink(row)
              if (href) navigate(href)
            }}
            rowTone={(row) => (row.is_overdue ? 'danger' : undefined)}
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

      {acting ? (
        <ActModal
          row={acting.row}
          action={acting.action}
          onClose={() => setActing(null)}
          onDone={() => {
            setActing(null)
            queue.reload()
            onActed()
          }}
        />
      ) : null}
    </>
  )
}

/**
 * The due date, said in words as well as shown in colour.
 *
 * An overdue step is the one thing on this screen somebody must not miss, so it
 * carries an icon and the word "overdue" — a red row alone is invisible to a
 * good proportion of the people who work these queues.
 */
function DueCell({ row }: { row: ApprovalQueueItem }) {
  if (!row.due_at) {
    return <span style={{ color: 'var(--color-text-subtle)' }}>No deadline</span>
  }

  if (row.is_overdue) {
    return (
      <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
        <AlarmClock size={14} aria-hidden style={{ color: 'var(--color-danger)' }} />
        <span>
          <strong style={{ color: 'var(--color-danger)', display: 'block', fontSize: 12.5 }}>
            Overdue
          </strong>
          <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
            was due {formatDate(row.due_at)}
          </span>
        </span>
      </span>
    )
  }

  return (
    <span>
      <span style={{ display: 'block' }}>{formatDate(row.due_at)}</span>
      {row.escalated_at ? (
        <Chip tone="warning" size="sm">
          Escalated
        </Chip>
      ) : null}
    </span>
  )
}

function ActModal({
  row,
  action,
  onClose,
  onDone,
}: {
  row: ApprovalQueueItem
  action: QueueAction
  onClose: () => void
  onDone: () => void
}) {
  const toast = useToast()
  const [comment, setComment] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)

  const label = ACT_LABEL[action]
  const needsReason = REASON_REQUIRED.includes(action)

  const submit = async () => {
    if ((needsReason || action === 'comment') && comment.trim() === '') {
      setErrors({
        comment:
          action === 'comment'
            ? 'Write the comment you want to leave.'
            : 'Say what has to change. The submitter only sees this.',
      })
      return
    }

    setBusy(true)
    setErrors({})
    try {
      await api.post(`/approvals/${row.instance_id}/act`, {
        action,
        comment: comment.trim() || null,
      })
      toast.success(ACT_DONE[action], row.contract_title ?? undefined)
      onDone()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('That action did not go through', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`${label} — step ${row.step_no}`}
      description={row.contract_title ?? `${humanise(row.subject_type)} ${row.subject_id}`}
      width={520}
      closeOnBackdrop={!busy}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            variant={action === 'reject' ? 'danger' : 'primary'}
            loading={busy}
            onClick={() => void submit()}
          >
            {label}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <Chip tone="neutral">{row.contract_number ?? humanise(row.subject_type)}</Chip>
          <Chip tone="info">{row.workflow_name ?? 'Ad-hoc approval'}</Chip>
          {row.is_overdue ? <Chip tone="danger">Overdue</Chip> : null}
        </div>
        <Textarea
          label={needsReason ? 'Reason' : 'Comment'}
          required={needsReason}
          rows={4}
          value={comment}
          error={errors.comment}
          onChange={(event) => {
            setComment(event.target.value)
            setErrors((current) => (current.comment ? { ...current, comment: '' } : current))
          }}
          hint={
            action === 'approve'
              ? 'Optional. Anything you write is kept with the approval trail.'
              : undefined
          }
        />
      </div>
    </Modal>
  )
}

/**
 * Everything in flight, rather than only what is assigned to the viewer.
 *
 * Contracts, not assignments: the queue endpoint answers "mine" by definition,
 * so the wider view comes from the repository filtered on approval status, and
 * each row links to the contract's own approvals tab where the steps live.
 */
function InFlight() {
  const navigate = useNavigate()
  const [status, setStatus] = useState('pending')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  const list = useApiResource<Paged<ContractListItem>>(
    (signal) =>
      api.get<Paged<ContractListItem>>(
        '/contracts',
        {
          approval_status: status,
          page,
          per_page: perPage,
          sort: 'updated_at',
          dir: 'desc',
        },
        signal,
      ),
    [status, page, perPage],
  )

  const rows = list.data?.items ?? []
  const total = list.data?.total ?? 0

  const columns: Column<ContractListItem>[] = [
    {
      key: 'title',
      header: 'Contract',
      render: (row) => (
        <div style={{ minWidth: 200 }}>
          <Link
            to={`/contracts/${row.id}?tab=approvals`}
            className="ct-link"
            onClick={(event) => event.stopPropagation()}
          >
            {row.title}
          </Link>
          <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
            {row.contract_number || 'Not numbered yet'}
            {row.counterparty_name ? ` · ${row.counterparty_name}` : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'approval_status',
      header: 'Approval',
      render: (row) =>
        row.approval_status ? <StatusChip status={row.approval_status} size="sm" /> : '—',
    },
    {
      key: 'status',
      header: 'Contract status',
      hideBelow: 'sm',
      render: (row) => <StatusChip status={row.status} size="sm" />,
    },
    {
      key: 'owner',
      header: 'Owner',
      hideBelow: 'lg',
      render: (row) =>
        row.owner_uuid ? (
          <span title={row.owner_uuid} style={{ fontSize: 11.5, color: 'var(--color-text-secondary)' }}>
            {truncate(row.owner_uuid, 10)}
          </span>
        ) : (
          '—'
        ),
    },
    {
      key: 'updated_at',
      header: 'Updated',
      hideBelow: 'md',
      render: (row) => (
        <span style={{ color: 'var(--color-text-secondary)' }}>{formatDateTime(row.updated_at)}</span>
      ),
    },
  ]

  return (
    <>
      <div
        style={{
          display: 'flex',
          alignItems: 'flex-end',
          gap: 10,
          flexWrap: 'wrap',
          padding: '12px 14px',
          borderBottom: '1px solid rgb(var(--color-border))',
        }}
      >
        <div style={{ minWidth: 220 }}>
          <Select
            label="Approval status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            options={APPROVAL_STATUS_OPTIONS}
          />
        </div>
      </div>

      <p aria-live="polite" className="ct-sr-only">
        {list.loading
          ? 'Loading approvals across the company'
          : `${total} ${total === 1 ? 'contract' : 'contracts'} at this approval status`}
      </p>

      {list.error ? (
        <ErrorState
          title="Could not load approvals"
          detail={list.error.message}
          onRetry={list.reload}
        />
      ) : !list.loading && rows.length === 0 ? (
        <EmptyState
          icon={<CheckSquare size={22} />}
          title="Nothing at this stage"
          description="No contract you can see is at this approval status right now. Try another status, or start an approval from a contract."
        />
      ) : (
        <>
          <DataTable
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            loading={list.loading}
            caption="Contracts by approval status"
            onRowClick={(row) => navigate(`/contracts/${row.id}?tab=approvals`)}
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
    </>
  )
}
