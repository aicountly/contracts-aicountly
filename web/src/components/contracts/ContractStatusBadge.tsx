import { Archive, CalendarClock } from 'lucide-react'

import { Chip, StatusChip } from '../ui'
import { formatRelativeDays } from '../../utils/format'

/**
 * A contract's state in one place.
 *
 * The lifecycle status alone is not the whole answer people need in a list: an
 * `active` contract that lapses in nine days is the one they are looking for.
 * Archived and expiry urgency therefore ride alongside the status chip rather
 * than being colour applied to it, so the row still reads correctly to anyone
 * who cannot separate amber from red — every chip spells its meaning out.
 */

/** Past this, a countdown is noise rather than a warning. */
const EXPIRY_HORIZON_DAYS = 90
const EXPIRY_URGENT_DAYS = 30

const SETTLED_STATUSES = new Set(['expired', 'terminated', 'cancelled'])

export function ContractStatusBadge({
  status,
  archivedAt = null,
  daysToExpiry = null,
  size = 'md',
  showExpiry = true,
}: {
  status: string
  archivedAt?: string | null
  daysToExpiry?: number | null
  size?: 'sm' | 'md'
  showExpiry?: boolean
}) {
  const withinHorizon =
    showExpiry &&
    !SETTLED_STATUSES.has(status) &&
    daysToExpiry !== null &&
    daysToExpiry !== undefined &&
    daysToExpiry <= EXPIRY_HORIZON_DAYS

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
      <StatusChip status={status} size={size} />

      {archivedAt ? (
        <Chip tone="neutral" size={size} title={`Archived on ${archivedAt.slice(0, 10)}`}>
          <Archive size={11} aria-hidden />
          Archived
        </Chip>
      ) : null}

      {withinHorizon ? (
        <Chip
          tone={daysToExpiry! <= EXPIRY_URGENT_DAYS ? 'danger' : 'warning'}
          size={size}
          title={`Expires ${formatRelativeDays(daysToExpiry)}`}
        >
          <CalendarClock size={11} aria-hidden />
          {daysToExpiry! < 0
            ? `Lapsed ${Math.abs(daysToExpiry!)}d`
            : `${daysToExpiry}d left`}
        </Chip>
      ) : null}
    </span>
  )
}
