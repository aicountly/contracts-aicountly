import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { CheckCircle2, FileDiff, X } from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  DataTable,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
  Select,
  StatusChip,
} from '../components/ui'
import type { Column } from '../components/ui'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import { AMENDMENT_STATUSES, type AmendmentRegisterItem, type Paged } from '../types/contracts'
import { formatDate, humanise, truncate } from '../utils/format'

/**
 * The amendment register for the whole portfolio.
 *
 * An amendment is a record of what changed and when it took effect — the
 * original agreement is never rewritten — so this list is the answer to "what
 * have we varied lately", and each row hands off to the contract's own
 * amendments tab, where the field-by-field detail and the effective position
 * live.
 */

const APPLIED_HINT =
  'Applied means the change has been written onto the contract as well as recorded here.'

export default function Amendments() {
  const navigate = useNavigate()
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  const list = useApiResource<Paged<AmendmentRegisterItem>>(
    (signal) =>
      api.get<Paged<AmendmentRegisterItem>>(
        '/amendments',
        { status, page, per_page: perPage },
        signal,
      ),
    [status, page, perPage],
  )

  const rows = list.data?.items ?? []
  const total = list.data?.total ?? 0

  const columns = useMemo<Column<AmendmentRegisterItem>[]>(
    () => [
      {
        key: 'amendment',
        header: 'Amendment',
        render: (row) => (
          <div style={{ minWidth: 200 }}>
            <Link
              to={`/contracts/${row.contract_id}?tab=amendments`}
              className="ct-link"
              onClick={(event) => event.stopPropagation()}
            >
              {row.title}
            </Link>
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              Amendment {row.amendment_no}
              {row.description ? ` · ${truncate(row.description, 60)}` : ''}
            </div>
          </div>
        ),
      },
      {
        key: 'contract',
        header: 'Contract',
        hideBelow: 'sm',
        render: (row) => (
          <div style={{ minWidth: 150 }}>
            <Link
              to={`/contracts/${row.contract_id}`}
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
        key: 'status',
        header: 'Status',
        render: (row) => <StatusChip status={row.status} size="sm" />,
      },
      {
        key: 'changes',
        header: 'Changes',
        hideBelow: 'md',
        render: (row) => {
          const fields = Object.keys(row.affected_fields ?? {})
          if (fields.length === 0) {
            return <span style={{ color: 'var(--color-text-subtle)' }}>Wording only</span>
          }
          return (
            <Chip size="sm" title={fields.map((field) => humanise(field)).join(', ')}>
              {fields.length} {fields.length === 1 ? 'field' : 'fields'}
            </Chip>
          )
        },
      },
      {
        key: 'effective_date',
        header: 'Effective',
        hideBelow: 'sm',
        render: (row) => formatDate(row.effective_date),
      },
      {
        key: 'execution_date',
        header: 'Signed',
        hideBelow: 'lg',
        render: (row) => formatDate(row.execution_date),
      },
      {
        key: 'applied',
        header: 'Applied',
        hideBelow: 'md',
        render: (row) =>
          row.applied_at ? (
            <span
              title={APPLIED_HINT}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12 }}
            >
              <CheckCircle2 size={13} aria-hidden style={{ color: 'var(--color-success)' }} />
              {formatDate(row.applied_at)}
            </span>
          ) : (
            <span style={{ color: 'var(--color-text-muted)', fontSize: 12 }}>Not yet</span>
          ),
      },
    ],
    [],
  )

  return (
    <>
      <PageHeader
        title="Amendments"
        description="Every variation agreed across the portfolio. The original contract is never rewritten — an amendment records what changed, and applying it carries the change onto the contract."
      />

      <Card padded={false}>
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
              label="Status"
              value={status}
              onChange={(event) => {
                setStatus(event.target.value)
                setPage(1)
              }}
              options={AMENDMENT_STATUSES.map((option) => ({
                value: option,
                label: humanise(option),
              }))}
              placeholder="Any status"
            />
          </div>
          {status !== '' ? (
            <Button
              size="sm"
              variant="ghost"
              icon={<X size={13} />}
              onClick={() => {
                setStatus('')
                setPage(1)
              }}
            >
              Clear
            </Button>
          ) : null}
        </div>

        <p aria-live="polite" className="ct-sr-only">
          {list.loading
            ? 'Loading amendments'
            : `${total} ${total === 1 ? 'amendment' : 'amendments'} in the register`}
        </p>

        {list.error ? (
          <ErrorState
            title="Could not load the amendment register"
            detail={list.error.message}
            onRetry={list.reload}
          />
        ) : !list.loading && rows.length === 0 ? (
          <EmptyState
            icon={<FileDiff size={22} />}
            title={status === '' ? 'No amendments recorded' : 'None at this status'}
            description={
              status === ''
                ? 'When a contract is varied, the amendment is raised on the contract itself — open a contract and use its Amendments tab. Everything raised anywhere in the company is listed here.'
                : 'No amendment in the company is at this status. Clear the filter to see the whole register.'
            }
            action={
              status !== '' ? (
                <Button variant="secondary" onClick={() => setStatus('')}>
                  Clear filter
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
              caption="Amendments across the portfolio"
              onRowClick={(row) => navigate(`/contracts/${row.contract_id}?tab=amendments`)}
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
    </>
  )
}
