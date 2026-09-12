import { useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowRight, CheckSquare, ClipboardList, RefreshCw, Sparkles } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

import { Card, CardHeader, Chip, EmptyState, ErrorState, Skeleton, StatusChip, Tabs } from '../ui'
import { useSession } from '../../context/SessionProvider'
import { PERMISSION } from '../../types/permissions'
import { formatDate, formatMoney, formatRelativeDays } from '../../utils/format'
import type { MyActionItem, MyActions } from '../../types/contracts'

/**
 * The work that is waiting on this person specifically.
 *
 * Separate from the KPI tiles because the tiles describe the portfolio and this
 * describes a to-do list — the two answer different questions, and mixing them
 * is how a dashboard ends up being read as neither.
 */

interface Group {
  id: keyof MyActions
  label: string
  icon: LucideIcon
  to: string
  empty: string
  permission?: string
}

const GROUPS: Group[] = [
  {
    id: 'approvals',
    label: 'Approvals',
    icon: CheckSquare,
    to: '/approvals',
    empty: 'Nothing is waiting for your approval.',
  },
  {
    id: 'obligations',
    label: 'Obligations',
    icon: ClipboardList,
    to: '/obligations',
    empty: 'No obligations are assigned to you right now.',
  },
  {
    id: 'renewals',
    label: 'Renewals',
    icon: RefreshCw,
    to: '/renewals',
    empty: 'No renewal decisions are due from you.',
  },
  {
    id: 'ai_reviews',
    label: 'AI reviews',
    icon: Sparkles,
    to: '/ai/review',
    empty: 'The AI review queue is clear.',
    permission: PERMISSION.AI_USE,
  },
]

export function MyActionsPanel({
  data,
  loading,
  error,
  onRetry,
}: {
  data: MyActions | null
  loading: boolean
  error: Error | null
  onRetry: () => void
}) {
  const { can } = useSession()
  const groups = GROUPS.filter((group) => !group.permission || can(group.permission))
  const [active, setActive] = useState<keyof MyActions>(groups[0]?.id ?? 'approvals')

  const countOf = (id: keyof MyActions) => data?.[id]?.length ?? 0
  const total = groups.reduce((sum, group) => sum + countOf(group.id), 0)

  // The tab a user landed on can become the empty one once the data arrives, so
  // the opening tab follows the work rather than the list order.
  const [autoSelected, setAutoSelected] = useState(false)
  if (data && !autoSelected) {
    const firstWithWork = groups.find((group) => countOf(group.id) > 0)
    if (firstWithWork && countOf(active) === 0) setActive(firstWithWork.id)
    setAutoSelected(true)
  }

  const activeGroup = groups.find((group) => group.id === active) ?? groups[0]

  return (
    <Card padded={false}>
      <div style={{ padding: '16px 16px 0' }}>
        <CardHeader
          title="Requiring my action"
          description="Approvals, obligations, renewals and AI extractions assigned to you."
          action={total > 0 ? <Chip tone="warning">{total} open</Chip> : null}
        />
      </div>

      {activeGroup ? (
        <>
          <div style={{ padding: '0 16px' }}>
            <Tabs
              ariaLabel="Work assigned to me"
              active={activeGroup.id}
              onChange={(id) => setActive(id as keyof MyActions)}
              items={groups.map((group) => ({
                id: group.id,
                label: group.label,
                icon: <group.icon size={13} aria-hidden />,
                badge: loading ? undefined : countOf(group.id),
              }))}
            />
          </div>

          <div
            role="tabpanel"
            id={`panel-${activeGroup.id}`}
            aria-labelledby={`tab-${activeGroup.id}`}
            tabIndex={0}
          >
            {loading ? (
              <ActionSkeleton />
            ) : error ? (
              <ErrorState
                compact
                title="Could not load your actions"
                detail={error.message}
                onRetry={onRetry}
              />
            ) : countOf(activeGroup.id) === 0 ? (
              <EmptyState
                compact
                icon={<activeGroup.icon size={19} />}
                title="Nothing waiting on you"
                description={activeGroup.empty}
              />
            ) : (
              <>
                <ul style={{ listStyle: 'none' }}>
                  {(data?.[activeGroup.id] ?? []).slice(0, 6).map((item) => (
                    <ActionRow key={`${activeGroup.id}-${item.id}`} item={item} fallbackTo={activeGroup.to} />
                  ))}
                </ul>
                <div
                  style={{
                    padding: '10px 16px',
                    borderTop: '1px solid rgb(var(--color-border))',
                    textAlign: 'right',
                  }}
                >
                  <Link
                    to={activeGroup.to}
                    style={{
                      display: 'inline-flex',
                      alignItems: 'center',
                      gap: 5,
                      fontSize: 12.5,
                      fontWeight: 700,
                    }}
                  >
                    All {activeGroup.label.toLowerCase()}
                    <ArrowRight size={13} aria-hidden />
                  </Link>
                </div>
              </>
            )}
          </div>
        </>
      ) : (
        <EmptyState
          compact
          title="Nothing assigned to you"
          description="Your role does not include any of the queues this panel tracks."
        />
      )}
    </Card>
  )
}

function ActionRow({ item, fallbackTo }: { item: MyActionItem; fallbackTo: string }) {
  const to = item.contract_id ? `/contracts/${item.contract_id}` : fallbackTo
  const heading = item.title ?? item.contract_title ?? item.contract_number ?? 'Untitled'
  const overdue = item.days_remaining !== null && item.days_remaining !== undefined && item.days_remaining < 0
  const soon =
    !overdue &&
    item.days_remaining !== null &&
    item.days_remaining !== undefined &&
    item.days_remaining <= 7

  return (
    <li style={{ borderTop: '1px solid var(--color-border-light)' }}>
      <Link
        to={to}
        style={{
          display: 'flex',
          gap: 12,
          alignItems: 'center',
          padding: '11px 16px',
          color: 'inherit',
        }}
      >
        <span style={{ minWidth: 0, flex: 1 }}>
          <span className="ct-link" style={{ display: 'block', fontSize: 13, lineHeight: 1.4 }}>
            {heading}
          </span>
          <span
            style={{
              display: 'flex',
              gap: 8,
              flexWrap: 'wrap',
              fontSize: 11.5,
              color: 'var(--color-text-muted)',
              marginTop: 2,
            }}
          >
            {item.contract_number ? <span>{item.contract_number}</span> : null}
            {item.description ? <span>{item.description}</span> : null}
            {item.amount != null ? (
              <span>{formatMoney(item.amount, item.currency ?? 'INR', { compact: true })}</span>
            ) : null}
            {item.due_date ? <span>Due {formatDate(item.due_date)}</span> : null}
          </span>
        </span>

        {item.days_remaining !== null && item.days_remaining !== undefined ? (
          <Chip tone={overdue ? 'danger' : soon ? 'warning' : 'neutral'} size="sm">
            {overdue ? 'Overdue' : 'Due'} {formatRelativeDays(item.days_remaining)}
          </Chip>
        ) : item.status ? (
          <StatusChip status={item.status} size="sm" />
        ) : null}
      </Link>
    </li>
  )
}

function ActionSkeleton() {
  return (
    <div role="status" aria-label="Loading your actions" style={{ padding: 16, display: 'grid', gap: 14 }}>
      <span className="ct-sr-only">Loading your actions…</span>
      {Array.from({ length: 4 }).map((_, index) => (
        <div key={index} style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
          <div style={{ flex: 1, display: 'grid', gap: 6 }}>
            <Skeleton width="65%" height={12} />
            <Skeleton width="40%" height={10} />
          </div>
          <Skeleton width={72} height={18} radius={999} />
        </div>
      ))}
    </div>
  )
}
