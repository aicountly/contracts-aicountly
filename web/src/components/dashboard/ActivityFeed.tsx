import { Link } from 'react-router-dom'
import { History } from 'lucide-react'

import { Card, CardHeader, EmptyState, ErrorState, Skeleton } from '../ui'
import { formatDateTime, humanise, initials } from '../../utils/format'
import type { ActivityEntry } from '../../types/contracts'

/**
 * What has happened lately, newest first.
 *
 * Deliberately a plain reverse-chronological list rather than a grouped or
 * filtered one: its job is peripheral awareness — "somebody executed the
 * Acme MSA an hour ago" — and every affordance added to it turns it into a
 * second, worse audit screen. The real audit trail is on the contract.
 */
export function ActivityFeed({
  entries,
  loading,
  error,
  onRetry,
}: {
  entries: ActivityEntry[] | null
  loading: boolean
  error: Error | null
  onRetry: () => void
}) {
  return (
    <Card padded={false}>
      <div style={{ padding: '16px 16px 0' }}>
        <CardHeader title="Recent activity" description="The latest changes across this company." />
      </div>

      <div aria-live="polite" aria-busy={loading}>
        {loading ? (
          <FeedSkeleton />
        ) : error ? (
          <ErrorState
            compact
            title="Could not load activity"
            detail={error.message}
            onRetry={onRetry}
          />
        ) : !entries || entries.length === 0 ? (
          <EmptyState
            compact
            icon={<History size={19} />}
            title="No activity yet"
            description="Once contracts are created, approved or signed, the trail shows up here."
          />
        ) : (
          <ol style={{ listStyle: 'none' }}>
            {entries.map((entry) => (
              <FeedRow key={entry.id} entry={entry} />
            ))}
          </ol>
        )}
      </div>
    </Card>
  )
}

function FeedRow({ entry }: { entry: ActivityEntry }) {
  const subject = entry.contract_title ?? entry.contract_number ?? null
  const body = (
    <>
      <span style={{ fontSize: 12.5, color: 'var(--color-text)', lineHeight: 1.45 }}>
        <strong style={{ fontWeight: 700 }}>{entry.actor_name ?? 'Someone'}</strong>{' '}
        {entry.description ?? humanise(entry.action).toLowerCase()}
        {subject ? (
          <>
            {' — '}
            <span style={{ fontWeight: 600 }}>{subject}</span>
          </>
        ) : null}
      </span>
      <time
        dateTime={entry.created_at}
        style={{ display: 'block', fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}
      >
        {formatDateTime(entry.created_at)}
      </time>
    </>
  )

  return (
    <li
      style={{
        display: 'flex',
        gap: 10,
        padding: '11px 16px',
        borderTop: '1px solid var(--color-border-light)',
      }}
    >
      <span
        aria-hidden
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 26,
          height: 26,
          borderRadius: '50%',
          background: 'var(--color-bg-inset)',
          color: 'var(--color-text-secondary)',
          fontSize: 10.5,
          fontWeight: 800,
          flexShrink: 0,
        }}
      >
        {initials(entry.actor_name)}
      </span>

      <span style={{ minWidth: 0 }}>
        {entry.contract_id ? (
          <Link to={`/contracts/${entry.contract_id}`} style={{ color: 'inherit' }}>
            {body}
          </Link>
        ) : (
          body
        )}
      </span>
    </li>
  )
}

function FeedSkeleton() {
  return (
    <div role="status" aria-label="Loading activity" style={{ padding: 16, display: 'grid', gap: 14 }}>
      <span className="ct-sr-only">Loading activity…</span>
      {Array.from({ length: 6 }).map((_, index) => (
        <div key={index} style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <Skeleton width={26} height={26} radius={999} />
          <div style={{ flex: 1, display: 'grid', gap: 6 }}>
            <Skeleton width={index % 2 === 0 ? '78%' : '62%'} height={11} />
            <Skeleton width="30%" height={9} />
          </div>
        </div>
      ))}
    </div>
  )
}
