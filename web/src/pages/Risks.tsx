import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Lock, ShieldAlert, Sparkles, X } from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  DataTable,
  EmptyState,
  ErrorState,
  PageHeader,
  Pagination,
  RiskChip,
  Select,
} from '../components/ui'
import type { Column } from '../components/ui'
import { AiDisclaimer } from '../components/contracts/AiDisclaimer'
import { useSession } from '../context/SessionProvider'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import {
  RISK_CATEGORIES,
  RISK_REVIEW_STATUSES,
  RISK_SEVERITIES,
  type Paged,
  type PortfolioRiskFinding,
} from '../types/contracts'
import { formatDate, humanise, truncate } from '../utils/format'

/**
 * Every open risk finding in the portfolio, worst first.
 *
 * The register exists to be worked through, so it defaults to what is still
 * open — a resolved finding is history and would drown the list. The detail of
 * a finding, and the decision about it, belong to the contract it was raised
 * against, which is where each row leads.
 */

const DETECTOR_LABEL: Record<string, string> = {
  rules: 'Rule',
  ai: 'AI',
  manual: 'Person',
}

export default function Risks() {
  const navigate = useNavigate()
  const { can } = useSession()

  const [severity, setSeverity] = useState('')
  const [category, setCategory] = useState('')
  const [reviewStatus, setReviewStatus] = useState('open')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)

  const canView = can(PERMISSION.AI_RISK_VIEW)

  const list = useApiResource<Paged<PortfolioRiskFinding>>(
    (signal) =>
      api.get<Paged<PortfolioRiskFinding>>(
        '/risks',
        { severity, category, review_status: reviewStatus, page, per_page: perPage },
        signal,
      ),
    [severity, category, reviewStatus, page, perPage],
    { enabled: canView },
  )

  const rows = list.data?.items ?? []
  const total = list.data?.total ?? 0
  const isFiltered = severity !== '' || category !== '' || reviewStatus !== 'open'
  const aiPresent = rows.some((row) => row.detected_by === 'ai')

  const columns = useMemo<Column<PortfolioRiskFinding>[]>(
    () => [
      {
        key: 'severity',
        header: 'Severity',
        width: 118,
        render: (row) => <RiskChip level={row.severity} />,
      },
      {
        key: 'finding',
        header: 'Finding',
        render: (row) => (
          <div style={{ minWidth: 220 }}>
            <Link
              to={`/contracts/${row.contract_id}?tab=risk`}
              className="ct-link"
              onClick={(event) => event.stopPropagation()}
            >
              {row.title}
            </Link>
            {row.detail ? (
              <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2, maxWidth: 460 }}>
                {truncate(row.detail, 130)}
              </div>
            ) : null}
          </div>
        ),
      },
      {
        key: 'category',
        header: 'Category',
        hideBelow: 'md',
        render: (row) => <Chip size="sm">{humanise(row.risk_category)}</Chip>,
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
        key: 'detected_by',
        header: 'Found by',
        hideBelow: 'lg',
        render: (row) => {
          const confidence =
            row.ai_confidence === null || row.ai_confidence === undefined
              ? null
              : Math.round(Number(row.ai_confidence) * 100)

          return (
            <Chip
              size="sm"
              tone={row.detected_by === 'ai' ? 'info' : 'neutral'}
              title={
                row.rule_key
                  ? `Rule ${row.rule_key}`
                  : row.detected_by === 'ai'
                    ? 'Read from the document by AI'
                    : 'Raised by a person'
              }
            >
              {row.detected_by === 'ai' ? <Sparkles size={11} aria-hidden /> : null}
              {DETECTOR_LABEL[row.detected_by] ?? humanise(row.detected_by)}
              {confidence !== null && Number.isFinite(confidence) ? ` ${confidence}%` : ''}
            </Chip>
          )
        },
      },
      {
        key: 'created_at',
        header: 'Raised',
        hideBelow: 'md',
        render: (row) => (
          <span style={{ color: 'var(--color-text-secondary)' }}>{formatDate(row.created_at)}</span>
        ),
      },
    ],
    [],
  )

  if (!canView) {
    return (
      <>
        <PageHeader title="Risks" />
        <Card>
          <EmptyState
            icon={<Lock size={22} />}
            title="Risk findings are not part of your access"
            description="Risk assessments can name commercial and legal exposure in detail, so they are granted separately. Ask an administrator for the risk permission if you need them."
          />
        </Card>
      </>
    )
  }

  const clearFilters = () => {
    setSeverity('')
    setCategory('')
    setReviewStatus('open')
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Risks"
        description="Every open finding across the portfolio, worst first. A finding always names the rule or the passage it came from, and is decided on the contract it belongs to."
      />

      <Card padded={false}>
        <div
          style={{
            display: 'grid',
            gap: 12,
            gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))',
            alignItems: 'end',
            padding: '12px 14px',
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <Select
            label="Severity"
            value={severity}
            onChange={(event) => {
              setSeverity(event.target.value)
              setPage(1)
            }}
            options={RISK_SEVERITIES.map((option) => ({ value: option, label: humanise(option) }))}
            placeholder="Any severity"
          />
          <Select
            label="Category"
            value={category}
            onChange={(event) => {
              setCategory(event.target.value)
              setPage(1)
            }}
            options={RISK_CATEGORIES.map((option) => ({ value: option, label: humanise(option) }))}
            placeholder="Any category"
          />
          <Select
            label="Review status"
            value={reviewStatus}
            onChange={(event) => {
              setReviewStatus(event.target.value)
              setPage(1)
            }}
            options={RISK_REVIEW_STATUSES.map((option) => ({ value: option, label: humanise(option) }))}
            hint="Open findings are the ones still to be dealt with"
          />
          {isFiltered ? (
            <div>
              <Button size="sm" variant="ghost" icon={<X size={13} />} onClick={clearFilters}>
                Clear filters
              </Button>
            </div>
          ) : null}
        </div>

        <p aria-live="polite" className="ct-sr-only">
          {list.loading
            ? 'Loading risk findings'
            : `${total} ${total === 1 ? 'finding matches' : 'findings match'} the current filters`}
        </p>

        {list.error ? (
          <ErrorState
            title="Could not load risk findings"
            detail={list.error.message}
            onRetry={list.reload}
          />
        ) : !list.loading && rows.length === 0 ? (
          <EmptyState
            icon={<ShieldAlert size={22} />}
            title={isFiltered ? 'Nothing matches these filters' : 'No open findings'}
            description={
              isFiltered
                ? 'No finding fits the conditions you have set. Widen them, or look at findings that have already been dealt with.'
                : 'Nothing in the portfolio is flagged as open risk. Findings appear here when a contract is assessed against the risk rules or read by AI.'
            }
            action={
              isFiltered ? (
                <Button variant="secondary" onClick={clearFilters}>
                  Clear filters
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
              caption="Risk findings across the portfolio"
              onRowClick={(row) => navigate(`/contracts/${row.contract_id}?tab=risk`)}
              rowTone={(row) =>
                row.severity === 'critical' ? 'danger' : row.severity === 'high' ? 'warning' : undefined
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

        {aiPresent ? (
          <div style={{ padding: '0 16px 14px' }}>
            <AiDisclaimer compact />
          </div>
        ) : null}
      </Card>
    </>
  )
}
