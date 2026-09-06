import { useState } from 'react'
import type { ReactNode } from 'react'
import { Activity, ScrollText } from 'lucide-react'

import { Card, EmptyState, ErrorState, Pagination, Skeleton } from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { api } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { ActivityEntry, Contract, Paged } from '../../../types/contracts'
import { formatDateTime, humanise, initials } from '../../../utils/format'

/**
 * What has happened to this contract.
 *
 * Two streams, not one: activity is the human-readable story (submitted,
 * approved, uploaded), and the audit trail is the field-level record of what
 * changed from what to what. They answer different questions and only the
 * second is permission-gated, so mixing them would mean withholding the whole
 * history from anyone without audit rights.
 */

interface Props {
  contractId: number
  contract: Contract
  onChanged: () => void
}

/** `GET /contracts/{id}/audit` — a field-level change record. */
interface AuditEntry {
  id: number | string
  action?: string | null
  actor_name?: string | null
  actor_uuid?: string | null
  field?: string | null
  old_value?: string | number | null
  new_value?: string | number | null
  changes?: Record<string, { old?: unknown; new?: unknown }> | null
  ip_address?: string | null
  created_at: string
}

type Stream = 'activity' | 'audit'

const PER_PAGE = 20

export function ActivityTab({ contractId }: Props) {
  const { can } = useSession()
  const canAudit = can(PERMISSION.AUDIT_VIEW)

  const [stream, setStream] = useState<Stream>('activity')
  const [page, setPage] = useState(1)

  const activity = useApiResource<Paged<ActivityEntry>>(
    (signal) =>
      api.get<Paged<ActivityEntry>>(
        `/contracts/${contractId}/activity`,
        { page, per_page: PER_PAGE },
        signal,
      ),
    [contractId, page],
    { enabled: stream === 'activity' },
  )

  const audit = useApiResource<Paged<AuditEntry>>(
    (signal) =>
      api.get<Paged<AuditEntry>>(
        `/contracts/${contractId}/audit`,
        { page, per_page: PER_PAGE },
        signal,
      ),
    [contractId, page],
    { enabled: stream === 'audit' && canAudit },
  )

  const resource = stream === 'activity' ? activity : audit
  const total = resource.data?.total ?? 0
  const perPage = resource.data?.per_page ?? PER_PAGE

  const switchStream = (next: Stream) => {
    setStream(next)
    setPage(1)
  }

  return (
    <Card padded={false}>
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 10,
          flexWrap: 'wrap',
          padding: '12px 14px',
          borderBottom: '1px solid rgb(var(--color-border))',
        }}
      >
        <div style={{ flex: '1 1 200px', minWidth: 0 }}>
          <h3 style={{ fontSize: 14, fontWeight: 700 }}>
            {stream === 'activity' ? 'Activity' : 'Audit trail'}
          </h3>
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
            {stream === 'activity'
              ? 'Everything people and the system have done to this contract.'
              : 'Field-level changes, with the value before and after.'}
          </p>
        </div>

        {canAudit ? (
          <div
            role="group"
            aria-label="Choose a history"
            style={{
              display: 'inline-flex',
              padding: 2,
              gap: 2,
              borderRadius: 'var(--radius-md)',
              background: 'var(--color-bg-inset)',
            }}
          >
            <StreamButton
              active={stream === 'activity'}
              icon={<Activity size={13} />}
              label="Activity"
              onClick={() => switchStream('activity')}
            />
            <StreamButton
              active={stream === 'audit'}
              icon={<ScrollText size={13} />}
              label="Audit trail"
              onClick={() => switchStream('audit')}
            />
          </div>
        ) : null}
      </div>

      <p aria-live="polite" className="ct-sr-only">
        {resource.loading ? 'Loading history' : `${total} entries`}
      </p>

      {resource.loading ? (
        <div style={{ display: 'grid', gap: 14, padding: 16 }} role="status" aria-label="Loading history">
          {Array.from({ length: 5 }).map((_, index) => (
            <div key={index} style={{ display: 'flex', gap: 12, alignItems: 'center' }}>
              <Skeleton width={28} height={28} radius={14} />
              <div style={{ flex: 1, display: 'grid', gap: 6 }}>
                <Skeleton height={12} width="45%" />
                <Skeleton height={11} width="70%" />
              </div>
            </div>
          ))}
        </div>
      ) : resource.error ? (
        <ErrorState
          title={stream === 'activity' ? 'Could not load the activity' : 'Could not load the audit trail'}
          detail={resource.error.message}
          onRetry={resource.reload}
        />
      ) : (resource.data?.items ?? []).length === 0 ? (
        <EmptyState
          icon={stream === 'activity' ? <Activity size={21} /> : <ScrollText size={21} />}
          title={stream === 'activity' ? 'Nothing has happened yet' : 'No recorded changes'}
          description={
            stream === 'activity'
              ? 'Every status change, upload, approval and comment on this contract will appear here as it happens.'
              : 'No field on this contract has been changed since it was created.'
          }
        />
      ) : (
        <ol style={{ listStyle: 'none', padding: '6px 0' }}>
          {stream === 'activity'
            ? (activity.data?.items ?? []).map((entry) => (
                <TimelineRow
                  key={entry.id}
                  actor={entry.actor_name ?? entry.actor_uuid ?? null}
                  title={humanise(entry.action)}
                  detail={entry.description ?? null}
                  at={entry.created_at}
                />
              ))
            : (audit.data?.items ?? []).map((entry) => (
                <TimelineRow
                  key={entry.id}
                  actor={entry.actor_name ?? entry.actor_uuid ?? null}
                  title={humanise(entry.action)}
                  detail={null}
                  at={entry.created_at}
                  changes={<AuditChanges entry={entry} />}
                />
              ))}
        </ol>
      )}

      {!resource.loading && !resource.error && total > perPage ? (
        <Pagination page={page} perPage={perPage} total={total} onPageChange={setPage} />
      ) : null}
    </Card>
  )
}

function StreamButton({
  active,
  label,
  icon,
  onClick,
}: {
  active: boolean
  label: string
  icon: ReactNode
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        height: 28,
        padding: '0 10px',
        borderRadius: 'var(--radius-sm)',
        border: 'none',
        cursor: 'pointer',
        fontSize: 12.5,
        fontWeight: 700,
        background: active ? 'var(--color-bg-card)' : 'transparent',
        color: active ? 'var(--color-text)' : 'var(--color-text-secondary)',
        boxShadow: active ? 'var(--shadow-sm)' : undefined,
      }}
    >
      {icon}
      {label}
    </button>
  )
}

function TimelineRow({
  actor,
  title,
  detail,
  at,
  changes,
}: {
  actor: string | null
  title: string
  detail: string | null
  at: string
  changes?: ReactNode
}) {
  return (
    <li
      style={{
        display: 'flex',
        gap: 12,
        padding: '11px 16px',
        borderBottom: '1px solid var(--color-border-light)',
      }}
    >
      <span
        aria-hidden
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 28,
          height: 28,
          borderRadius: '50%',
          flexShrink: 0,
          background: 'var(--color-bg-inset)',
          color: 'var(--color-text-secondary)',
          fontSize: 11,
          fontWeight: 800,
        }}
      >
        {initials(actor)}
      </span>

      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'baseline' }}>
          <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text)' }}>{title}</span>
          <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
            {actor ?? 'System'} · <time dateTime={at}>{formatDateTime(at)}</time>
          </span>
        </div>
        {detail ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3, lineHeight: 1.6 }}>
            {detail}
          </p>
        ) : null}
        {changes}
      </div>
    </li>
  )
}

/** The before and after of an audit row, however the server phrased it. */
function AuditChanges({ entry }: { entry: AuditEntry }) {
  const pairs: { field: string; from: string; to: string }[] = []

  if (entry.field) {
    pairs.push({
      field: entry.field,
      from: entry.old_value === null || entry.old_value === undefined ? '—' : String(entry.old_value),
      to: entry.new_value === null || entry.new_value === undefined ? '—' : String(entry.new_value),
    })
  }

  for (const [field, change] of Object.entries(entry.changes ?? {})) {
    pairs.push({
      field,
      from: change?.old === null || change?.old === undefined ? '—' : String(change.old),
      to: change?.new === null || change?.new === undefined ? '—' : String(change.new),
    })
  }

  if (pairs.length === 0) return null

  return (
    <ul style={{ listStyle: 'none', display: 'grid', gap: 4, marginTop: 6 }}>
      {pairs.map((pair, index) => (
        <li key={`${pair.field}-${index}`} style={{ fontSize: 12, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <span style={{ fontWeight: 700, color: 'var(--color-text-muted)', minWidth: 120 }}>
            {humanise(pair.field)}
          </span>
          <span style={{ color: 'var(--color-text-muted)', textDecoration: 'line-through' }}>{pair.from}</span>
          <span aria-hidden style={{ color: 'var(--color-text-subtle)' }}>
            →
          </span>
          <span className="ct-sr-only">changed to</span>
          <span style={{ color: 'var(--color-text)' }}>{pair.to}</span>
        </li>
      ))}
    </ul>
  )
}
