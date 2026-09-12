/**
 * Display formatting.
 *
 * Dates come from the API as `YYYY-MM-DD` in UTC and are rendered without ever
 * being put through a Date constructor that applies a timezone — a contract
 * that expires on 31 March must not read as 30 March for a user west of UTC.
 */

const MONTHS = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
]

export function formatDate(value?: string | null): string {
  if (!value) return '—'
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value)
  if (!match) return value

  const [, year, month, day] = match
  const monthName = MONTHS[Number(month) - 1] ?? month

  return `${Number(day)} ${monthName} ${year}`
}

export function formatDateTime(value?: string | null): string {
  if (!value) return '—'
  const match = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/.exec(value)
  if (!match) return formatDate(value)

  const [, year, month, day, hour, minute] = match
  return `${Number(day)} ${MONTHS[Number(month) - 1] ?? month} ${year}, ${hour}:${minute}`
}

/** "in 17 days" / "12 days ago" — the phrasing a deadline actually needs. */
export function formatRelativeDays(days?: number | null): string {
  if (days === null || days === undefined) return '—'
  if (days === 0) return 'today'
  if (days === 1) return 'tomorrow'
  if (days === -1) return 'yesterday'
  if (days > 0) return `in ${days} days`
  return `${Math.abs(days)} days ago`
}

export function formatMoney(
  amount?: string | number | null,
  currency = 'INR',
  options: { compact?: boolean } = {},
): string {
  if (amount === null || amount === undefined || amount === '') return '—'

  const value = typeof amount === 'string' ? Number(amount) : amount
  if (!Number.isFinite(value)) return '—'

  try {
    return new Intl.NumberFormat(currency === 'INR' ? 'en-IN' : 'en-US', {
      style: 'currency',
      currency,
      maximumFractionDigits: options.compact ? 1 : 2,
      minimumFractionDigits: options.compact ? 0 : 2,
      notation: options.compact ? 'compact' : 'standard',
    }).format(value)
  } catch {
    // An unrecognised currency code should still show the number rather than
    // throwing a screen away.
    return `${currency} ${value.toLocaleString()}`
  }
}

export function formatNumber(value?: number | string | null): string {
  if (value === null || value === undefined || value === '') return '—'
  const numeric = typeof value === 'string' ? Number(value) : value
  return Number.isFinite(numeric) ? numeric.toLocaleString() : '—'
}

export function humanise(value?: string | null): string {
  if (!value) return '—'
  return value
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

export function truncate(value: string, max: number): string {
  return value.length <= max ? value : `${value.slice(0, max - 1)}…`
}

/** A stable colour for an avatar or a chart series, derived from a string. */
export function hashHue(value: string): number {
  let hash = 0
  for (let i = 0; i < value.length; i++) {
    hash = (hash * 31 + value.charCodeAt(i)) % 360
  }
  return hash
}

export function initials(name?: string | null): string {
  if (!name) return '?'
  const parts = name.trim().split(/\s+/).slice(0, 2)
  return parts.map((p) => p.charAt(0).toUpperCase()).join('') || '?'
}
