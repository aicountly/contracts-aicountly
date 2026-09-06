import { useMemo, useState } from 'react'
import {
  Ban,
  CalendarClock,
  Coins,
  CreditCard,
  Eye,
  EyeOff,
  RefreshCw,
  ShieldAlert,
  Sparkles,
  X,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

import { Button, Card, CardHeader, Chip, EmptyState, ErrorState, Skeleton } from '../ui'
import { useApiResource } from '../../hooks/useApiResource'
import { api } from '../../services/apiClient'
import type { ClassifiedChange, CompareResult, CompareSegment } from '../../types/contracts'
import { humanise } from '../../utils/format'

/**
 * Two versions of a contract, side by side as a redline.
 *
 * Legal review is a diff-reading job, so the presentation follows the paper
 * convention people already know: struck-through text was removed, underlined
 * text was added, and a replacement shows both. Colour carries none of that on
 * its own — every run is labelled in words as well, because the reader who
 * cannot separate the red from the green is exactly the reader who must not
 * miss a deleted indemnity.
 *
 * The classified changes come first. A 40-page agreement produces hundreds of
 * whitespace-level differences, and the four that move money or liability are
 * the reason anyone opened this screen.
 */

const AI_DISCLAIMER =
  'AI-generated contract analysis is provided for assistance and information. It does not constitute legal advice and should be reviewed by an authorized legal or professional reviewer before reliance.'

type SegmentKind = 'added' | 'removed' | 'changed' | 'unchanged'

const KIND_STYLE: Record<
  SegmentKind,
  { label: string; background: string; border: string; accent: string }
> = {
  added: {
    label: 'Added',
    background: 'var(--color-success-bg)',
    border: 'var(--color-success-border)',
    accent: 'var(--color-success)',
  },
  removed: {
    label: 'Removed',
    background: 'var(--color-danger-bg)',
    border: 'var(--color-danger-border)',
    accent: 'var(--color-danger)',
  },
  changed: {
    label: 'Changed',
    background: 'var(--color-warning-bg)',
    border: 'var(--color-warning-border)',
    accent: 'var(--color-warning)',
  },
  unchanged: {
    label: 'Unchanged',
    background: 'transparent',
    border: 'transparent',
    accent: 'var(--color-text-muted)',
  },
}

/** The change classes the server calls out, and how each is presented. */
const CATEGORY: Record<
  string,
  { label: string; icon: LucideIcon; tone: 'danger' | 'warning' | 'info' | 'primary' }
> = {
  amount: { label: 'Amount', icon: Coins, tone: 'warning' },
  value: { label: 'Amount', icon: Coins, tone: 'warning' },
  date: { label: 'Date', icon: CalendarClock, tone: 'info' },
  liability: { label: 'Liability', icon: ShieldAlert, tone: 'danger' },
  termination: { label: 'Termination', icon: Ban, tone: 'danger' },
  renewal: { label: 'Renewal', icon: RefreshCw, tone: 'info' },
  payment_terms: { label: 'Payment terms', icon: CreditCard, tone: 'warning' },
}

function segmentKind(segment: CompareSegment): SegmentKind {
  const raw = (segment.type ?? segment.op ?? '').toString().toLowerCase()

  if (raw.startsWith('add') || raw.startsWith('ins') || raw === '+') return 'added'
  if (raw.startsWith('rem') || raw.startsWith('del') || raw === '-') return 'removed'
  if (raw.startsWith('chang') || raw.startsWith('mod') || raw.startsWith('repl')) return 'changed'
  // No operation named, but both sides present: a replacement by any other name.
  if (!raw && segment.base_text != null && segment.target_text != null) return 'changed'
  return 'unchanged'
}

function segmentText(segment: CompareSegment): string {
  return (segment.text ?? segment.value ?? '').toString()
}

function changeTitle(change: ClassifiedChange): string {
  return (
    change.title ??
    change.summary ??
    change.description ??
    (change.category ? `${humanise(change.category)} change` : 'Change')
  )
}

export function VersionDiffViewer({
  contractId,
  base,
  target,
  baseLabel,
  targetLabel,
  onClose,
}: {
  contractId: number
  base: number
  target: number
  baseLabel?: string
  targetLabel?: string
  onClose?: () => void
}) {
  const [showUnchanged, setShowUnchanged] = useState(false)

  const comparison = useApiResource<CompareResult>(
    (signal) =>
      api.get<CompareResult>(`/contracts/${contractId}/compare`, { base, target }, signal),
    [contractId, base, target],
  )

  const segments = useMemo(() => comparison.data?.segments ?? [], [comparison.data])
  const classified = useMemo(() => comparison.data?.classified ?? [], [comparison.data])

  const kinds = useMemo(() => segments.map(segmentKind), [segments])
  const visible = useMemo(
    () => segments.filter((_, index) => showUnchanged || kinds[index] !== 'unchanged'),
    [segments, kinds, showUnchanged],
  )

  const stats = comparison.data?.stats ?? null
  const counted = (kind: SegmentKind) => kinds.filter((item) => item === kind).length
  const added = stats?.added ?? counted('added')
  const removed = stats?.removed ?? counted('removed')
  const changed = stats?.changed ?? counted('changed')
  const identical = !comparison.loading && !comparison.error && added + removed + changed === 0

  const heading = `Comparing ${baseLabel ?? `version ${base}`} with ${targetLabel ?? `version ${target}`}`

  return (
    <Card padded={false}>
      <div style={{ padding: '14px 16px', borderBottom: '1px solid rgb(var(--color-border))' }}>
        <CardHeader
          level={3}
          title={heading}
          description="Struck-through text is in the earlier version only; underlined text is new."
          action={
            onClose ? (
              <Button variant="ghost" size="sm" icon={<X size={14} />} onClick={onClose}>
                Close
              </Button>
            ) : null
          }
        />

        {comparison.loading ? null : (
          <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', alignItems: 'center' }}>
            <Chip tone="success">{added} added</Chip>
            <Chip tone="danger">{removed} removed</Chip>
            <Chip tone="warning">{changed} changed</Chip>
            {stats?.similarity != null ? (
              <Chip tone="neutral">{Math.round(stats.similarity)}% similar</Chip>
            ) : null}
            <div style={{ flex: 1 }} />
            {segments.length > 0 ? (
              <Button
                size="sm"
                variant="secondary"
                aria-pressed={showUnchanged}
                icon={showUnchanged ? <EyeOff size={13} /> : <Eye size={13} />}
                onClick={() => setShowUnchanged((current) => !current)}
              >
                {showUnchanged ? 'Hide unchanged text' : 'Show unchanged text'}
              </Button>
            ) : null}
          </div>
        )}
      </div>

      <p aria-live="polite" className="ct-sr-only">
        {comparison.loading
          ? 'Comparing versions'
          : comparison.error
            ? 'The comparison could not be produced'
            : `${added} additions, ${removed} deletions and ${changed} changes`}
      </p>

      {comparison.loading ? (
        <div style={{ display: 'grid', gap: 10, padding: 16 }} role="status" aria-label="Loading comparison">
          <Skeleton height={16} width="45%" />
          <Skeleton height={52} />
          <Skeleton height={52} />
          <Skeleton height={52} width="80%" />
        </div>
      ) : comparison.error ? (
        <ErrorState
          title="Could not compare these versions"
          detail={comparison.error.message}
          onRetry={comparison.reload}
          compact
        />
      ) : identical && segments.length === 0 ? (
        <EmptyState
          compact
          title="These versions match"
          description="The comparison found no textual difference between the two versions. If you expected changes, the newer file may be a scan — extracted text is compared, not page images."
        />
      ) : (
        <div style={{ display: 'grid', gap: 18, padding: 16 }}>
          {classified.length > 0 ? (
            <section aria-labelledby="diff-classified">
              <h4
                id="diff-classified"
                style={{ fontSize: 13, fontWeight: 700, marginBottom: 8, color: 'var(--color-text)' }}
              >
                Changes that matter
              </h4>
              <ul style={{ listStyle: 'none', display: 'grid', gap: 8 }}>
                {classified.map((change, index) => (
                  <ClassifiedRow key={change.id ?? index} change={change} />
                ))}
              </ul>
            </section>
          ) : null}

          {comparison.data?.ai_explanation ? (
            <section
              aria-labelledby="diff-ai"
              style={{
                border: '1px solid rgb(var(--color-border))',
                borderRadius: 'var(--radius-md)',
                background: 'var(--color-bg-subtle)',
                padding: 14,
              }}
            >
              <h4
                id="diff-ai"
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 7,
                  fontSize: 13,
                  fontWeight: 700,
                  color: 'var(--color-text)',
                }}
              >
                <Sparkles size={14} aria-hidden style={{ color: 'rgb(var(--color-primary))' }} />
                What changed, explained
                <Chip tone="primary" size="sm">
                  AI-generated
                </Chip>
              </h4>
              <p
                style={{
                  fontSize: 13,
                  lineHeight: 1.7,
                  color: 'var(--color-text)',
                  marginTop: 8,
                  whiteSpace: 'pre-wrap',
                }}
              >
                {comparison.data.ai_explanation}
              </p>
              <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 10, lineHeight: 1.6 }}>
                {AI_DISCLAIMER}
              </p>
            </section>
          ) : null}

          <section aria-labelledby="diff-redline">
            <h4
              id="diff-redline"
              style={{ fontSize: 13, fontWeight: 700, marginBottom: 8, color: 'var(--color-text)' }}
            >
              Redline
            </h4>

            {visible.length === 0 ? (
              <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
                {identical
                  ? 'No textual difference was found between these versions. Turn on “Show unchanged text” to read the document as it stands.'
                  : 'Every run is hidden by the current view. Turn on “Show unchanged text” to see the whole document.'}
              </p>
            ) : (
              <ol style={{ listStyle: 'none', display: 'grid', gap: 6 }}>
                {visible.map((segment, index) => (
                  <SegmentRow key={index} segment={segment} kind={segmentKind(segment)} />
                ))}
              </ol>
            )}
          </section>
        </div>
      )}
    </Card>
  )
}

function ClassifiedRow({ change }: { change: ClassifiedChange }) {
  const key = (change.category ?? '').toString().toLowerCase()
  const meta = CATEGORY[key]
  const Icon = meta?.icon
  const severity = (change.severity ?? '').toString().toLowerCase()

  return (
    <li
      style={{
        display: 'flex',
        gap: 11,
        alignItems: 'flex-start',
        padding: '10px 12px',
        border: '1px solid rgb(var(--color-border))',
        borderRadius: 'var(--radius-md)',
        background: 'var(--color-bg-card)',
      }}
    >
      <div style={{ flexShrink: 0, paddingTop: 1 }}>
        <Chip tone={meta?.tone ?? 'neutral'} size="sm">
          {Icon ? <Icon size={11} aria-hidden /> : null}
          {meta?.label ?? (change.category ? humanise(change.category) : 'Change')}
        </Chip>
      </div>

      <div style={{ minWidth: 0, flex: 1 }}>
        <div style={{ fontSize: 13, fontWeight: 600, color: 'var(--color-text)' }}>
          {changeTitle(change)}
        </div>

        {change.description && change.description !== changeTitle(change) ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3, lineHeight: 1.6 }}>
            {change.description}
          </p>
        ) : null}

        {change.base_value != null || change.target_value != null ? (
          <p style={{ fontSize: 12.5, marginTop: 5, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <span style={{ color: 'var(--color-text-muted)', textDecoration: 'line-through' }}>
              {change.base_value ?? '—'}
            </span>
            <span aria-hidden style={{ color: 'var(--color-text-subtle)' }}>
              →
            </span>
            <span className="ct-sr-only">became</span>
            <span style={{ color: 'var(--color-text)', fontWeight: 600 }}>
              {change.target_value ?? '—'}
            </span>
          </p>
        ) : null}

        {change.section ? (
          <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 4 }}>
            {change.section}
          </p>
        ) : null}
      </div>

      {severity ? (
        <Chip tone={severity === 'high' || severity === 'critical' ? 'danger' : 'neutral'} size="sm">
          {humanise(severity)}
        </Chip>
      ) : null}
    </li>
  )
}

function SegmentRow({ segment, kind }: { segment: CompareSegment; kind: SegmentKind }) {
  const style = KIND_STYLE[kind]
  const text = segmentText(segment)

  return (
    <li
      style={{
        display: 'flex',
        gap: 10,
        padding: kind === 'unchanged' ? '4px 10px' : '8px 10px',
        borderRadius: 'var(--radius-sm)',
        background: style.background,
        border: `1px solid ${style.border}`,
      }}
    >
      <span
        style={{
          flexShrink: 0,
          width: 74,
          fontSize: 10.5,
          fontWeight: 700,
          textTransform: 'uppercase',
          letterSpacing: '.03em',
          color: style.accent,
          paddingTop: 2,
        }}
      >
        {kind === 'unchanged' ? <span className="ct-sr-only">{style.label}</span> : style.label}
      </span>

      <div style={{ minWidth: 0, flex: 1, fontSize: 13, lineHeight: 1.7, color: 'var(--color-text)' }}>
        {segment.section ? (
          <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--color-text-muted)', marginBottom: 2 }}>
            {segment.section}
            {segment.page != null ? ` · page ${segment.page}` : ''}
          </div>
        ) : null}

        {kind === 'changed' ? (
          <>
            <p style={{ textDecoration: 'line-through', color: 'var(--color-text-muted)' }}>
              {segment.base_text ?? text}
            </p>
            <p style={{ textDecoration: 'underline', textUnderlineOffset: 2 }}>
              {segment.target_text ?? text}
            </p>
          </>
        ) : (
          <p
            style={{
              textDecoration:
                kind === 'removed' ? 'line-through' : kind === 'added' ? 'underline' : undefined,
              textUnderlineOffset: 2,
              color: kind === 'unchanged' ? 'var(--color-text-secondary)' : undefined,
            }}
          >
            {text || (kind === 'removed' ? segment.base_text : segment.target_text) || ' '}
          </p>
        )}
      </div>
    </li>
  )
}
