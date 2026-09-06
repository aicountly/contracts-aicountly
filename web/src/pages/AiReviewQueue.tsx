import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import {
  CheckCheck,
  CircleCheck,
  FileSearch,
  Pencil,
  RefreshCw,
  ScanText,
  Settings2,
  Sparkles,
  TriangleAlert,
  X,
} from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  ConfirmDialog,
  DataTable,
  EmptyState,
  ErrorState,
  Input,
  PageHeader,
  Pagination,
  Skeleton,
  StatusChip,
} from '../components/ui'
import type { Column } from '../components/ui'
import { AiDisclaimer } from '../components/contracts/AiDisclaimer'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { api } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import type {
  AiExtractionRow,
  AiJobRow,
  AiStatus,
  ApplyVerifiedResult,
  Paged,
} from '../types/contracts'
import { formatDateTime, humanise, truncate } from '../utils/format'

/**
 * The step where a person decides whether the machine was right.
 *
 * Everything on this screen is a suggestion until somebody confirms it. That is
 * not a caveat in small print — it is the layout: a field carries its
 * confidence and the sentence it was read from, low-confidence values are
 * marked before they are read, and the only bulk action is one that says
 * exactly which values it is about to accept. Nothing here writes to the
 * contract until a reviewer has been through it.
 */

/** Below this, the value is called out as needing a look at the source. */
const LOW_CONFIDENCE = 0.75

/** At or above this, a value may be accepted in bulk. */
const BULK_CONFIDENCE = 0.9

const PER_PAGE = 50

interface QueueData {
  status: AiStatus | null
  jobs: AiJobRow[]
  queue: Paged<AiExtractionRow>
}

const EMPTY_PAGE: Paged<AiExtractionRow> = {
  items: [],
  total: 0,
  page: 1,
  per_page: PER_PAGE,
  total_pages: 1,
}

/** PostgreSQL numerics can arrive as strings, so both forms are accepted. */
function confidencePercent(value: number | string | null): number | null {
  if (value === null || value === undefined || value === '') return null
  const numeric = typeof value === 'string' ? Number(value) : value
  return Number.isFinite(numeric) ? Math.round(numeric * 100) : null
}

function displayValue(row: AiExtractionRow): string {
  const value = row.accepted_value ?? row.normalised_value ?? row.extracted_value
  return value === null || value.trim() === '' ? '—' : value
}

function fieldLabel(row: AiExtractionRow): string {
  return row.field_label?.trim() ? row.field_label : humanise(row.field_key)
}

const REVIEW_LABEL: Record<AiExtractionRow['review_state'], string> = {
  pending: 'Not yet verified',
  accepted: 'Verified by a person',
  edited: 'Corrected and verified',
  rejected: 'Rejected',
}

export default function AiReviewQueue() {
  const { can, session } = useSession()
  const toast = useToast()
  const canAct = can(PERMISSION.AI_USE)

  const [page, setPage] = useState(1)
  const [busyId, setBusyId] = useState<number | null>(null)
  const [bulkFor, setBulkFor] = useState<number | null>(null)
  const [bulkRunning, setBulkRunning] = useState(false)
  const [applyingFor, setApplyingFor] = useState<number | null>(null)

  const resource = useApiResource<QueueData>(
    async (signal) => {
      const [status, jobs, queue] = await Promise.all([
        api.get<AiStatus>('/ai/status', undefined, signal).catch(() => null),
        api.get<Paged<AiJobRow>>('/ai/jobs', { per_page: 25, page: 1 }, signal),
        api.get<Paged<AiExtractionRow>>('/ai/review-queue', { page, per_page: PER_PAGE }, signal),
      ])

      return {
        status,
        jobs: jobs?.items ?? [],
        queue: queue ?? EMPTY_PAGE,
      }
    },
    [page],
  )

  const data = resource.data
  const rows = useMemo(() => data?.queue.items ?? [], [data])

  /** Rows keep their place after a decision, so the reviewer can see what they did. */
  const replaceRow = (updated: AiExtractionRow) => {
    if (!data) return
    resource.setData({
      ...data,
      queue: {
        ...data.queue,
        items: data.queue.items.map((row) => (row.id === updated.id ? updated : row)),
      },
    })
  }

  const replaceMany = (updates: AiExtractionRow[]) => {
    if (!data || updates.length === 0) return
    const byId = new Map(updates.map((row) => [row.id, row]))
    resource.setData({
      ...data,
      queue: {
        ...data.queue,
        items: data.queue.items.map((row) => byId.get(row.id) ?? row),
      },
    })
  }

  const groups = useMemo(() => {
    const map = new Map<number, { contractId: number; label: string; title: string | null; rows: AiExtractionRow[] }>()
    for (const row of rows) {
      const existing = map.get(row.contract_id)
      if (existing) {
        existing.rows.push(row)
        continue
      }
      map.set(row.contract_id, {
        contractId: row.contract_id,
        label: row.contract_number ?? `Contract ${row.contract_id}`,
        title: row.contract_title ?? null,
        rows: [row],
      })
    }
    return [...map.values()]
  }, [rows])

  const stages = useMemo(() => {
    const jobs = data?.jobs ?? []
    const processing = jobs.filter((job) => job.status === 'queued' || job.status === 'running').length
    const extracted = jobs.filter((job) => job.status === 'succeeded').length
    const pending = rows.filter((row) => row.review_state === 'pending').length
    const verified = rows.filter((row) => row.review_state === 'accepted' || row.review_state === 'edited').length

    return [
      { key: 'uploaded', label: 'Uploaded', value: jobs.length, hint: 'Documents an extraction has been asked for' },
      { key: 'processing', label: 'Processing', value: processing, hint: 'Queued or running right now' },
      { key: 'extracted', label: 'Extracted', value: extracted, hint: 'The model has finished reading' },
      { key: 'review', label: 'Needs review', value: pending, hint: 'Fields on this page waiting for a person' },
      { key: 'verified', label: 'Verified', value: verified, hint: 'Confirmed here by a person' },
    ]
  }, [data, rows])

  const act = async (row: AiExtractionRow, action: 'accept' | 'reject', value?: string) => {
    setBusyId(row.id)
    try {
      const updated =
        action === 'accept'
          ? await api.post<AiExtractionRow>(
              `/ai/extractions/${row.id}/accept`,
              value === undefined ? {} : { value },
            )
          : await api.post<AiExtractionRow>(`/ai/extractions/${row.id}/reject`)

      replaceRow(updated ?? row)
      toast.success(
        action === 'reject'
          ? `${fieldLabel(row)} rejected`
          : value === undefined
            ? `${fieldLabel(row)} verified`
            : `${fieldLabel(row)} corrected and verified`,
      )
    } catch (err) {
      toast.error(
        action === 'reject' ? 'Could not reject that field' : 'Could not record that verification',
        err instanceof Error ? err.message : undefined,
      )
    } finally {
      setBusyId(null)
    }
  }

  const bulkGroup = groups.find((group) => group.contractId === bulkFor) ?? null
  const bulkCandidates = (bulkGroup?.rows ?? []).filter(
    (row) => row.review_state === 'pending' && (row.confidence ?? 0) >= BULK_CONFIDENCE,
  )

  const runBulk = async () => {
    if (bulkCandidates.length === 0) return
    setBulkRunning(true)

    const accepted: AiExtractionRow[] = []
    let failed = 0

    // Sequential: each accept is an audited decision on its own row, and a
    // burst of parallel writes makes a partial failure impossible to describe.
    for (const row of bulkCandidates) {
      try {
        const updated = await api.post<AiExtractionRow>(`/ai/extractions/${row.id}/accept`, {})
        accepted.push(updated ?? { ...row, review_state: 'accepted' })
      } catch {
        failed += 1
      }
    }

    replaceMany(accepted)
    setBulkRunning(false)
    setBulkFor(null)

    if (failed === 0) {
      toast.success(`${accepted.length} high-confidence ${accepted.length === 1 ? 'field' : 'fields'} verified`)
    } else {
      toast.warning(
        `${accepted.length} verified, ${failed} could not be`,
        'The fields that failed are still waiting for a decision.',
      )
    }
  }

  const applyVerified = async (contractId: number) => {
    setApplyingFor(contractId)
    try {
      const result = await api.post<ApplyVerifiedResult>(`/ai/contracts/${contractId}/apply-verified`)
      const applied =
        typeof result?.applied === 'number'
          ? result.applied
          : result?.applied
            ? Object.keys(result.applied).length
            : 0
      const skipped = result?.skipped ? Object.keys(result.skipped).length : 0

      toast.success(
        `${applied} verified ${applied === 1 ? 'value' : 'values'} written to the contract`,
        skipped > 0 ? `${skipped} were left alone; open the contract to see why.` : undefined,
      )
      resource.reload()
    } catch (err) {
      toast.error('Could not apply the verified values', err instanceof Error ? err.message : undefined)
    } finally {
      setApplyingFor(null)
    }
  }

  const retryJob = async (job: AiJobRow) => {
    setBusyId(-job.id)
    try {
      await api.post(`/ai/jobs/${job.id}/retry`)
      toast.success('Extraction queued again', job.contract_number ?? undefined)
      resource.reload()
    } catch (err) {
      toast.error('Could not retry that job', err instanceof Error ? err.message : undefined)
    } finally {
      setBusyId(null)
    }
  }

  const jobColumns: Column<AiJobRow>[] = [
    {
      key: 'kind',
      header: 'Job',
      render: (job) => (
        <div style={{ minWidth: 0 }}>
          <span style={{ fontWeight: 700 }}>{humanise(job.kind)}</span>
          <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
            {job.attempts > 1 ? `Attempt ${job.attempts} of ${job.max_attempts}` : `Created ${formatDateTime(job.created_at)}`}
          </p>
        </div>
      ),
    },
    {
      key: 'contract',
      header: 'Contract',
      render: (job) =>
        job.contract_id ? (
          <Link to={`/contracts/${job.contract_id}`} className="ct-link" style={{ fontSize: 13 }}>
            {job.contract_number ?? `Contract ${job.contract_id}`}
          </Link>
        ) : (
          <span style={{ color: 'var(--color-text-subtle)' }}>—</span>
        ),
    },
    {
      key: 'status',
      header: 'State',
      render: (job) => (
        <div style={{ display: 'flex', gap: 6, alignItems: 'center', flexWrap: 'wrap' }}>
          <StatusChip status={job.status} size="sm" />
          {job.error_message ? (
            <span style={{ fontSize: 11.5, color: 'var(--color-danger)' }} title={job.error_message}>
              {truncate(job.error_message, 60)}
            </span>
          ) : null}
        </div>
      ),
    },
    {
      key: 'model',
      header: 'Model',
      hideBelow: 'md',
      render: (job) => (
        <span style={{ fontSize: 12, color: 'var(--color-text-secondary)' }}>
          {[job.provider, job.model].filter(Boolean).join(' · ') || '—'}
        </span>
      ),
    },
    {
      key: 'finished',
      header: 'Finished',
      hideBelow: 'lg',
      render: (job) => (
        <span style={{ fontSize: 12, color: 'var(--color-text-secondary)', whiteSpace: 'nowrap' }}>
          {job.completed_at ? formatDateTime(job.completed_at) : '—'}
        </span>
      ),
    },
    {
      key: 'actions',
      header: '',
      srLabel: 'Actions',
      align: 'right',
      render: (job) =>
        canAct && job.status === 'failed' ? (
          <Button
            size="sm"
            variant="secondary"
            icon={<RefreshCw size={13} />}
            loading={busyId === -job.id}
            onClick={() => void retryJob(job)}
          >
            Retry
          </Button>
        ) : null,
    },
  ]

  const pendingTotal = data?.queue.total ?? session?.counts.review_queue ?? 0

  return (
    <div>
      <PageHeader
        title="AI review queue"
        description="Values a model read out of your documents, waiting for a person to confirm them. Nothing here reaches a contract until somebody accepts it."
        actions={
          <Button variant="secondary" icon={<RefreshCw size={14} />} onClick={resource.reload}>
            Refresh
          </Button>
        }
      />

      <div style={{ display: 'grid', gap: 16 }}>
        {!resource.loading && data?.status && !data.status.configured ? (
          <Card style={{ borderColor: 'var(--color-warning-border)', background: 'var(--color-warning-bg)' }}>
            <div style={{ display: 'flex', gap: 12, alignItems: 'flex-start' }}>
              <Settings2 size={18} aria-hidden style={{ color: 'var(--color-warning)', marginTop: 2 }} />
              <div>
                <h2 style={{ fontSize: 14, fontWeight: 700 }}>No AI provider is connected</h2>
                <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4, lineHeight: 1.6 }}>
                  New extractions cannot run until an administrator configures a provider in Console.
                  Anything already extracted is still listed below and can still be verified.
                </p>
              </div>
            </div>
          </Card>
        ) : null}

        <Card>
          <h2 style={{ fontSize: 14, fontWeight: 700 }}>How a document becomes contract data</h2>
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3, lineHeight: 1.6 }}>
            A value is a suggestion at every step until the last one. Only the fields a person has
            accepted are written to the contract record.
          </p>
          <PipelineStrip stages={stages} loading={resource.loading} />
        </Card>

        {resource.error ? (
          <Card>
            <ErrorState
              title="Could not load the review queue"
              detail={resource.error.message}
              onRetry={resource.reload}
            />
          </Card>
        ) : (
          <>
            <Card padded={false}>
              <div style={{ padding: '14px 18px', borderBottom: '1px solid rgb(var(--color-border))' }}>
                <h2 style={{ fontSize: 14, fontWeight: 700 }}>Extraction jobs</h2>
                <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3 }}>
                  The most recent 25 runs, whatever their outcome.
                </p>
              </div>
              <DataTable
                columns={jobColumns}
                rows={data?.jobs ?? []}
                rowKey={(job) => job.id}
                loading={resource.loading}
                caption="Recent AI extraction jobs"
                emptyTitle="No extraction has been run yet"
                emptyDescription="Upload a signed document on a contract and ask for an extraction; the job and its result appear here."
              />
            </Card>

            <section>
              <header style={{ marginBottom: 12 }}>
                <h2 style={{ fontSize: 15, fontWeight: 700 }}>Fields waiting for a person</h2>
                <p aria-live="polite" style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3 }}>
                  {resource.loading
                    ? 'Reading the queue…'
                    : `${pendingTotal} extracted ${pendingTotal === 1 ? 'field' : 'fields'} across ${groups.length} ${
                        groups.length === 1 ? 'contract' : 'contracts'
                      }. The least certain are first.`}
                </p>
              </header>

              {resource.loading ? (
                <div style={{ display: 'grid', gap: 12 }}>
                  {[0, 1].map((row) => (
                    <Card key={row}>
                      <Skeleton width="38%" height={14} />
                      <div style={{ marginTop: 14, display: 'grid', gap: 10 }}>
                        <Skeleton height={52} radius={10} />
                        <Skeleton height={52} radius={10} />
                      </div>
                    </Card>
                  ))}
                </div>
              ) : groups.length === 0 ? (
                <Card>
                  <EmptyState
                    icon={<CircleCheck size={22} />}
                    title="Nothing is waiting for review"
                    description="When an extraction finishes, every field it read appears here with its confidence and the sentence it came from, for someone to confirm."
                    action={
                      <Link
                        to="/contracts"
                        style={{
                          display: 'inline-flex',
                          height: 36,
                          alignItems: 'center',
                          padding: '0 16px',
                          borderRadius: 'var(--radius-md)',
                          border: '1px solid rgb(var(--color-border-strong))',
                          fontWeight: 700,
                          fontSize: 13.5,
                          color: 'var(--color-text)',
                        }}
                      >
                        Go to the repository
                      </Link>
                    }
                  />
                </Card>
              ) : (
                <div style={{ display: 'grid', gap: 14 }}>
                  {groups.map((group) => {
                    const pending = group.rows.filter((row) => row.review_state === 'pending')
                    const verified = group.rows.filter(
                      (row) => row.review_state === 'accepted' || row.review_state === 'edited',
                    )
                    const lowConfidence = pending.filter((row) => (row.confidence ?? 0) < LOW_CONFIDENCE)
                    const bulkable = pending.filter((row) => (row.confidence ?? 0) >= BULK_CONFIDENCE)

                    return (
                      <Card key={group.contractId} padded={false}>
                        <header
                          style={{
                            display: 'flex',
                            justifyContent: 'space-between',
                            gap: 12,
                            flexWrap: 'wrap',
                            padding: '14px 18px',
                            borderBottom: '1px solid rgb(var(--color-border))',
                          }}
                        >
                          <div style={{ minWidth: 0 }}>
                            <h3 style={{ fontSize: 14, fontWeight: 700 }}>
                              <Link to={`/contracts/${group.contractId}`} className="ct-link">
                                {group.label}
                              </Link>
                            </h3>
                            {group.title ? (
                              <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
                                {group.title}
                              </p>
                            ) : null}
                            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8 }}>
                              <Chip tone={pending.length > 0 ? 'warning' : 'success'} size="sm">
                                {pending.length > 0
                                  ? `${pending.length} awaiting verification`
                                  : 'Every field verified'}
                              </Chip>
                              {lowConfidence.length > 0 ? (
                                <Chip tone="danger" size="sm">
                                  <TriangleAlert size={11} aria-hidden />
                                  {lowConfidence.length} low confidence
                                </Chip>
                              ) : null}
                              {verified.length > 0 ? (
                                <Chip tone="success" size="sm">
                                  {verified.length} verified by a person
                                </Chip>
                              ) : null}
                            </div>
                          </div>

                          {canAct ? (
                            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-start' }}>
                              <Button
                                size="sm"
                                variant="secondary"
                                icon={<CheckCheck size={13} />}
                                disabled={bulkable.length === 0}
                                onClick={() => setBulkFor(group.contractId)}
                                title={
                                  bulkable.length === 0
                                    ? 'Bulk accept is only offered for fields at 90% confidence or above'
                                    : undefined
                                }
                              >
                                Accept {bulkable.length} high-confidence
                              </Button>
                              {verified.length > 0 ? (
                                <Button
                                  size="sm"
                                  variant="primary"
                                  loading={applyingFor === group.contractId}
                                  onClick={() => void applyVerified(group.contractId)}
                                >
                                  Apply verified values
                                </Button>
                              ) : null}
                            </div>
                          ) : null}
                        </header>

                        <ul style={{ listStyle: 'none' }}>
                          {group.rows.map((row) => (
                            <li key={row.id} style={{ borderBottom: '1px solid var(--color-border-light)' }}>
                              <ExtractionField
                                row={row}
                                canAct={canAct}
                                busy={busyId === row.id}
                                onAccept={(value) => void act(row, 'accept', value)}
                                onReject={() => void act(row, 'reject')}
                              />
                            </li>
                          ))}
                        </ul>
                      </Card>
                    )
                  })}

                  {data && data.queue.total > PER_PAGE ? (
                    <Card padded={false}>
                      <Pagination
                        page={data.queue.page}
                        perPage={data.queue.per_page}
                        total={data.queue.total}
                        onPageChange={setPage}
                      />
                    </Card>
                  ) : null}
                </div>
              )}
            </section>

            <Card>
              <AiDisclaimer text={data?.status?.disclaimer} compact />
            </Card>
          </>
        )}
      </div>

      <ConfirmDialog
        open={bulkFor !== null}
        busy={bulkRunning}
        title={`Accept ${bulkCandidates.length} high-confidence ${bulkCandidates.length === 1 ? 'field' : 'fields'}?`}
        confirmLabel="Verify these fields"
        onClose={() => setBulkFor(null)}
        onConfirm={() => void runBulk()}
        message={
          <>
            <p>
              These are the fields at {Math.round(BULK_CONFIDENCE * 100)}% confidence or above on{' '}
              <strong>{bulkGroup?.label}</strong>. Accepting records that you have verified them, so
              read the values first.
            </p>
            <ul style={{ marginTop: 12, display: 'grid', gap: 6, listStyle: 'none' }}>
              {bulkCandidates.map((row) => (
                <li
                  key={row.id}
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    gap: 12,
                    padding: '7px 10px',
                    background: 'var(--color-bg-subtle)',
                    borderRadius: 'var(--radius-sm)',
                    fontSize: 12.5,
                  }}
                >
                  <span style={{ color: 'var(--color-text-muted)' }}>{fieldLabel(row)}</span>
                  <strong style={{ color: 'var(--color-text)', textAlign: 'right' }}>
                    {truncate(displayValue(row), 60)}
                  </strong>
                </li>
              ))}
            </ul>
            <p style={{ marginTop: 12, fontSize: 12.5 }}>
              Anything below {Math.round(BULK_CONFIDENCE * 100)}% is left for you to read one at a
              time.
            </p>
          </>
        }
      />
    </div>
  )
}

/** The five states a document passes through, with the counts we actually have. */
function PipelineStrip({
  stages,
  loading,
}: {
  stages: { key: string; label: string; value: number; hint: string }[]
  loading: boolean
}) {
  return (
    <ol
      className="ct-scroll-x"
      style={{
        listStyle: 'none',
        display: 'flex',
        gap: 8,
        marginTop: 14,
        paddingBottom: 4,
      }}
    >
      {stages.map((stage, index) => (
        <li
          key={stage.key}
          style={{
            flex: '1 1 150px',
            minWidth: 140,
            padding: '11px 13px',
            borderRadius: 'var(--radius-md)',
            background: 'var(--color-bg-subtle)',
            borderLeft: `3px solid ${
              index === stages.length - 1 ? 'var(--color-success)' : 'rgb(var(--color-border-strong))'
            }`,
          }}
        >
          <p style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--color-text-muted)' }}>
            {index + 1}. {stage.label}
          </p>
          {loading ? (
            <div style={{ marginTop: 6 }}>
              <Skeleton width={36} height={18} />
            </div>
          ) : (
            <p style={{ fontSize: 19, fontWeight: 800, marginTop: 2, fontVariantNumeric: 'tabular-nums' }}>
              {stage.value}
            </p>
          )}
          <p style={{ fontSize: 11, color: 'var(--color-text-muted)', marginTop: 2, lineHeight: 1.5 }}>
            {stage.hint}
          </p>
        </li>
      ))}
    </ol>
  )
}

/**
 * One extracted field.
 *
 * The confidence, the value and the sentence it came from sit together because
 * that is the check a reviewer performs: does the quoted text really say this.
 * A low-confidence field is marked before the value is read, not after.
 */
function ExtractionField({
  row,
  canAct,
  busy,
  onAccept,
  onReject,
}: {
  row: AiExtractionRow
  canAct: boolean
  busy: boolean
  onAccept: (value?: string) => void
  onReject: () => void
}) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(displayValue(row) === '—' ? '' : displayValue(row))

  const percent = confidencePercent(row.confidence)
  const low = (row.confidence ?? 0) < LOW_CONFIDENCE
  const pending = row.review_state === 'pending'

  return (
    <div
      style={{
        padding: '14px 18px',
        borderLeft: `3px solid ${
          !pending
            ? row.review_state === 'rejected'
              ? 'var(--color-danger)'
              : 'var(--color-success)'
            : low
              ? 'var(--color-warning)'
              : 'transparent'
        }`,
        background: pending && low ? 'var(--color-warning-bg)' : undefined,
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0, flex: '1 1 320px' }}>
          <p style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '.03em' }}>
            {fieldLabel(row)}
          </p>

          {editing ? (
            <div style={{ marginTop: 8, maxWidth: 420 }}>
              <Input
                label={`Corrected ${fieldLabel(row).toLowerCase()}`}
                value={draft}
                autoFocus
                onChange={(event) => setDraft(event.target.value)}
              />
            </div>
          ) : (
            <p style={{ fontSize: 15, fontWeight: 700, marginTop: 4, wordBreak: 'break-word' }}>
              {displayValue(row)}
            </p>
          )}

          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 8, alignItems: 'center' }}>
            <Chip tone={row.review_state === 'rejected' ? 'danger' : pending ? 'warning' : 'success'} size="sm">
              {pending ? <Sparkles size={11} aria-hidden /> : null}
              {REVIEW_LABEL[row.review_state]}
            </Chip>
            <ConfidenceMeter percent={percent} low={low} />
            <Chip tone="neutral" size="sm">
              {humanise(row.value_type)}
            </Chip>
            {row.reviewed_at ? (
              <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                {formatDateTime(row.reviewed_at)}
              </span>
            ) : null}
          </div>
        </div>

        {canAct && pending ? (
          <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', alignItems: 'flex-start' }}>
            {editing ? (
              <>
                <Button size="sm" variant="primary" loading={busy} onClick={() => onAccept(draft)}>
                  Save and verify
                </Button>
                <Button size="sm" variant="ghost" disabled={busy} onClick={() => setEditing(false)}>
                  Cancel
                </Button>
              </>
            ) : (
              <>
                <Button size="sm" variant="secondary" loading={busy} onClick={() => onAccept()}>
                  Accept
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  icon={<Pencil size={13} />}
                  disabled={busy}
                  onClick={() => setEditing(true)}
                >
                  Edit
                </Button>
                <Button
                  size="sm"
                  variant="ghost"
                  icon={<X size={13} />}
                  disabled={busy}
                  onClick={onReject}
                  aria-label={`Reject ${fieldLabel(row)}`}
                >
                  Reject
                </Button>
              </>
            )}
          </div>
        ) : null}
      </div>

      {row.source_excerpt || row.source_page !== null ? (
        <blockquote
          style={{
            marginTop: 12,
            padding: '10px 12px',
            background: 'var(--color-bg-card)',
            border: '1px solid rgb(var(--color-border))',
            borderRadius: 'var(--radius-md)',
          }}
        >
          <p
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 6,
              fontSize: 11,
              fontWeight: 700,
              textTransform: 'uppercase',
              letterSpacing: '.03em',
              color: 'var(--color-text-muted)',
            }}
          >
            <FileSearch size={11} aria-hidden />
            Read from the document
            {row.source_page !== null ? ` · page ${row.source_page}` : ''}
          </p>
          {row.source_excerpt ? (
            <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 5, lineHeight: 1.65 }}>
              “{truncate(row.source_excerpt, 320)}”
            </p>
          ) : null}
        </blockquote>
      ) : (
        <p
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 6,
            fontSize: 11.5,
            color: 'var(--color-text-muted)',
            marginTop: 10,
          }}
        >
          <ScanText size={12} aria-hidden />
          No source passage was recorded for this value — check it against the document itself.
        </p>
      )}
    </div>
  )
}

/**
 * Confidence as a number and a bar.
 *
 * The number is always present: a bar alone invites "looks fine" on a value the
 * model was 40% sure of, and the low-confidence case is named in words as well
 * as coloured.
 */
function ConfidenceMeter({ percent, low }: { percent: number | null; low: boolean }) {
  if (percent === null) {
    return (
      <Chip tone="danger" size="sm" title="The model returned no confidence for this value">
        No confidence score
      </Chip>
    )
  }

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 7 }}>
      <span
        role="img"
        aria-label={`Model confidence ${percent} per cent${low ? ', low' : ''}`}
        style={{
          display: 'inline-block',
          width: 54,
          height: 6,
          borderRadius: 999,
          background: 'var(--color-bg-inset)',
          overflow: 'hidden',
        }}
      >
        <span
          style={{
            display: 'block',
            width: `${percent}%`,
            height: '100%',
            background: low ? 'var(--color-warning)' : 'var(--color-success)',
          }}
        />
      </span>
      <span style={{ fontSize: 11.5, fontWeight: 700, color: low ? 'var(--color-warning-text)' : 'var(--color-text-secondary)' }}>
        {percent}% confident{low ? ' · low' : ''}
      </span>
    </span>
  )
}
