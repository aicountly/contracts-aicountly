import { useEffect, useMemo, useState } from 'react'
import {
  BookOpen,
  Check,
  FileSearch,
  Library,
  RefreshCw,
  ScrollText,
  Sparkles,
  X,
} from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  Skeleton,
  StatusChip,
} from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract, Paged } from '../../../types/contracts'
import { formatDate, humanise, truncate } from '../../../utils/format'

/**
 * The clauses this contract actually contains, and where each one departs from
 * the company playbook.
 *
 * Two reads, one panel: a clause on its own is a paragraph, and a deviation on
 * its own is an accusation with nothing to check it against. Shown together,
 * the reviewer can see the wording, the preferred wording and the severity in
 * the same glance — which is the whole job of this tab.
 */

type VerificationState = 'ai_extracted' | 'human_verified' | 'human_edited' | 'rejected'
type Severity = 'informational' | 'low' | 'medium' | 'high' | 'critical'
type DeviationStatus = 'open' | 'accepted' | 'rejected' | 'negotiating' | 'resolved'

interface ContractClause {
  id: number
  contract_id: number
  category_id: number | null
  category_name?: string | null
  category_code?: string | null
  library_clause_id: number | null
  library_clause_name?: string | null
  clause_number: string | null
  heading: string | null
  body_text: string
  source_page: number | null
  source_excerpt: string | null
  is_ai_extracted: boolean
  ai_confidence: number | string | null
  verification_state: VerificationState
  verified_by: string | null
  verified_at: string | null
  created_at: string
  updated_at: string
}

interface ClauseDeviation {
  id: number
  contract_id: number
  clause_id: number | null
  playbook_rule_id: number | null
  category_id: number | null
  category_name?: string | null
  contract_wording: string | null
  preferred_wording: string | null
  deviation_summary: string
  severity: Severity
  recommendation: string | null
  detected_by: 'rules' | 'ai' | 'manual'
  review_status: DeviationStatus
  review_notes: string | null
  reviewed_at: string | null
  rule_key?: string | null
  rule_label?: string | null
  rule_type?: string | null
  clause_heading?: string | null
  clause_number?: string | null
  created_at: string
}

interface LibraryClause {
  id: number
  name: string
  category_id: number | null
  category_name?: string | null
  standard_text: string
  risk_classification: string
  approval_status: string
}

const SEVERITY_TONE: Record<Severity, 'danger' | 'warning' | 'info' | 'neutral'> = {
  critical: 'danger',
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
  informational: 'info',
}

const SEVERITY_ORDER: Severity[] = ['critical', 'high', 'medium', 'low', 'informational']

const VERIFICATION_TONE: Record<VerificationState, 'success' | 'info' | 'warning' | 'danger'> = {
  human_verified: 'success',
  human_edited: 'success',
  ai_extracted: 'warning',
  rejected: 'danger',
}

const VERIFICATION_LABEL: Record<VerificationState, string> = {
  human_verified: 'Verified',
  human_edited: 'Edited by a reviewer',
  ai_extracted: 'Awaiting verification',
  rejected: 'Rejected',
}

function confidencePercent(value: number | string | null): number | null {
  if (value === null || value === '') return null
  const numeric = typeof value === 'string' ? Number(value) : value
  return Number.isFinite(numeric) ? Math.round(numeric * 100) : null
}

export function ClausesTab({
  contractId,
  contract,
  onChanged,
}: {
  contractId: number
  contract: Contract
  onChanged: () => void
}) {
  const { can } = useSession()
  const toast = useToast()
  const canManage = can(PERMISSION.CLAUSE_MANAGE)

  const resource = useApiResource<{ clauses: ContractClause[]; deviations: ClauseDeviation[] }>(
    async (signal) => {
      const [clauses, deviations] = await Promise.all([
        api.get<ContractClause[]>(`/contracts/${contractId}/clauses`, undefined, signal),
        api.get<ClauseDeviation[]>(`/contracts/${contractId}/deviations`, undefined, signal),
      ])
      return { clauses: clauses ?? [], deviations: deviations ?? [] }
    },
    [contractId],
  )

  const [attachOpen, setAttachOpen] = useState(false)
  const [evaluating, setEvaluating] = useState(false)
  const [busyClauseId, setBusyClauseId] = useState<number | null>(null)
  const [busyDeviationId, setBusyDeviationId] = useState<number | null>(null)

  const clauses = resource.data?.clauses ?? []
  const deviations = resource.data?.deviations ?? []

  const deviationsByClause = useMemo(() => {
    const map = new Map<number, ClauseDeviation[]>()
    for (const deviation of deviations) {
      if (deviation.clause_id === null) continue
      const bucket = map.get(deviation.clause_id) ?? []
      bucket.push(deviation)
      map.set(deviation.clause_id, bucket)
    }
    return map
  }, [deviations])

  // A deviation with no clause is the playbook reporting something absent — a
  // mandatory clause the contract never contains. It has no paragraph to sit
  // under, so it gets its own section rather than being dropped.
  const unattachedDeviations = useMemo(
    () =>
      deviations
        .filter((deviation) => deviation.clause_id === null)
        .sort((a, b) => SEVERITY_ORDER.indexOf(a.severity) - SEVERITY_ORDER.indexOf(b.severity)),
    [deviations],
  )

  const grouped = useMemo(() => {
    const groups = new Map<string, ContractClause[]>()
    for (const clause of clauses) {
      const key = clause.category_name?.trim() || 'Uncategorised'
      const bucket = groups.get(key) ?? []
      bucket.push(clause)
      groups.set(key, bucket)
    }
    return [...groups.entries()].sort(([a], [b]) => {
      if (a === 'Uncategorised') return 1
      if (b === 'Uncategorised') return -1
      return a.localeCompare(b)
    })
  }, [clauses])

  const openDeviations = deviations.filter((deviation) => deviation.review_status === 'open').length

  const setVerification = async (clause: ContractClause, state: VerificationState) => {
    setBusyClauseId(clause.id)
    try {
      await api.put(`/contract-clauses/${clause.id}`, { verification_state: state })
      toast.success(state === 'rejected' ? 'Clause rejected' : 'Clause verified')
      resource.reload()
      onChanged()
    } catch (err) {
      toast.error('Could not update the clause', err instanceof Error ? err.message : undefined)
    } finally {
      setBusyClauseId(null)
    }
  }

  const reviewDeviation = async (deviation: ClauseDeviation, status: DeviationStatus) => {
    setBusyDeviationId(deviation.id)
    try {
      await api.post(`/deviations/${deviation.id}/review`, { status })
      toast.success(`Deviation marked ${humanise(status).toLowerCase()}`)
      resource.reload()
      onChanged()
    } catch (err) {
      toast.error('Could not record that decision', err instanceof Error ? err.message : undefined)
    } finally {
      setBusyDeviationId(null)
    }
  }

  const evaluate = async () => {
    setEvaluating(true)
    try {
      await api.post(`/contracts/${contractId}/deviations/evaluate`)
      toast.success('Playbook check finished')
      resource.reload()
      onChanged()
    } catch (err) {
      toast.error('The playbook check did not run', err instanceof Error ? err.message : undefined)
    } finally {
      setEvaluating(false)
    }
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 14 }}>
        {[0, 1, 2].map((row) => (
          <Card key={row}>
            <Skeleton width="35%" height={13} />
            <div style={{ marginTop: 12, display: 'grid', gap: 8 }}>
              <Skeleton height={11} />
              <Skeleton height={11} width="80%" />
            </div>
          </Card>
        ))}
      </div>
    )
  }

  if (resource.error) {
    return (
      <ErrorState
        title="Could not load clauses"
        detail={resource.error.message}
        onRetry={resource.reload}
      />
    )
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <header
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          gap: 12,
          flexWrap: 'wrap',
        }}
      >
        <p aria-live="polite" style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
          {clauses.length} clause{clauses.length === 1 ? '' : 's'} on file
          {openDeviations > 0
            ? ` · ${openDeviations} open playbook deviation${openDeviations === 1 ? '' : 's'}`
            : deviations.length > 0
              ? ' · every deviation reviewed'
              : ''}
        </p>
        {canManage ? (
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Button
              size="sm"
              variant="secondary"
              icon={<RefreshCw size={13} />}
              loading={evaluating}
              onClick={() => void evaluate()}
            >
              Re-check playbook
            </Button>
            <Button
              size="sm"
              variant="primary"
              icon={<Library size={14} />}
              onClick={() => setAttachOpen(true)}
            >
              Attach from library
            </Button>
          </div>
        ) : null}
      </header>

      {unattachedDeviations.length > 0 ? (
        <Card>
          <h3 style={{ fontSize: 14, fontWeight: 700, marginBottom: 4 }}>Playbook gaps</h3>
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginBottom: 12 }}>
            The playbook expects these and no clause in the contract satisfies them.
          </p>
          <div style={{ display: 'grid', gap: 10 }}>
            {unattachedDeviations.map((deviation) => (
              <DeviationCard
                key={deviation.id}
                deviation={deviation}
                canManage={canManage}
                busy={busyDeviationId === deviation.id}
                onReview={(status) => void reviewDeviation(deviation, status)}
              />
            ))}
          </div>
        </Card>
      ) : null}

      {clauses.length === 0 ? (
        <EmptyState
          icon={<ScrollText size={22} />}
          title="No clauses recorded yet"
          description="Clauses are what the playbook, the risk engine and every deviation are measured against. Attach the wording from your library, or run an AI extraction from the document once one is uploaded."
          action={
            canManage ? (
              <Button variant="primary" icon={<Library size={15} />} onClick={() => setAttachOpen(true)}>
                Attach from library
              </Button>
            ) : undefined
          }
        />
      ) : (
        grouped.map(([category, rows]) => (
          <section key={category} style={{ display: 'grid', gap: 10 }}>
            <h3
              style={{
                fontSize: 12,
                fontWeight: 700,
                textTransform: 'uppercase',
                letterSpacing: '.03em',
                color: 'var(--color-text-muted)',
              }}
            >
              {category}
              <span style={{ marginLeft: 8, fontWeight: 600, textTransform: 'none', letterSpacing: 0 }}>
                {rows.length}
              </span>
            </h3>

            {rows.map((clause) => (
              <ClauseCard
                key={clause.id}
                clause={clause}
                deviations={deviationsByClause.get(clause.id) ?? []}
                canManage={canManage}
                busy={busyClauseId === clause.id}
                busyDeviationId={busyDeviationId}
                onVerify={(state) => void setVerification(clause, state)}
                onReviewDeviation={(deviation, status) => void reviewDeviation(deviation, status)}
              />
            ))}
          </section>
        ))
      )}

      {attachOpen ? (
        <AttachFromLibrary
          contractId={contractId}
          currency={contract.currency}
          onClose={() => setAttachOpen(false)}
          onAttached={() => {
            setAttachOpen(false)
            resource.reload()
            onChanged()
          }}
        />
      ) : null}
    </div>
  )
}

function ClauseCard({
  clause,
  deviations,
  canManage,
  busy,
  busyDeviationId,
  onVerify,
  onReviewDeviation,
}: {
  clause: ContractClause
  deviations: ClauseDeviation[]
  canManage: boolean
  busy: boolean
  busyDeviationId: number | null
  onVerify: (state: VerificationState) => void
  onReviewDeviation: (deviation: ClauseDeviation, status: DeviationStatus) => void
}) {
  const [expanded, setExpanded] = useState(false)
  const confidence = confidencePercent(clause.ai_confidence)
  const body = clause.body_text ?? ''
  const isLong = body.length > 420

  return (
    <Card>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0 }}>
          <h4 style={{ fontSize: 14, fontWeight: 700 }}>
            {clause.clause_number ? (
              <span style={{ color: 'var(--color-text-muted)', marginRight: 7 }}>{clause.clause_number}</span>
            ) : null}
            {clause.heading?.trim() || 'Untitled clause'}
          </h4>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 7 }}>
            <Chip tone={VERIFICATION_TONE[clause.verification_state]} size="sm">
              {VERIFICATION_LABEL[clause.verification_state]}
            </Chip>
            {clause.is_ai_extracted ? (
              <Chip tone="info" size="sm" title="Extracted by AI from the contract document">
                <Sparkles size={11} aria-hidden />
                AI extracted{confidence !== null ? ` · ${confidence}% confidence` : ''}
              </Chip>
            ) : (
              <Chip tone="neutral" size="sm">
                Entered by a person
              </Chip>
            )}
            {clause.library_clause_id !== null ? (
              <Chip tone="primary" size="sm">
                <BookOpen size={11} aria-hidden />
                {clause.library_clause_name?.trim() || 'From the clause library'}
              </Chip>
            ) : null}
          </div>
        </div>

        {canManage && clause.verification_state === 'ai_extracted' ? (
          <div style={{ display: 'flex', gap: 6, flexShrink: 0 }}>
            <Button
              size="sm"
              variant="secondary"
              icon={<Check size={13} />}
              loading={busy}
              onClick={() => onVerify('human_verified')}
            >
              Verify
            </Button>
            <Button
              size="sm"
              variant="ghost"
              icon={<X size={13} />}
              disabled={busy}
              onClick={() => onVerify('rejected')}
            >
              Reject
            </Button>
          </div>
        ) : null}
      </div>

      <p
        style={{
          marginTop: 12,
          fontSize: 13,
          lineHeight: 1.7,
          color: 'var(--color-text)',
          whiteSpace: 'pre-wrap',
        }}
      >
        {isLong && !expanded ? truncate(body, 420) : body}
      </p>
      {isLong ? (
        <Button size="sm" variant="ghost" onClick={() => setExpanded((open) => !open)}>
          {expanded ? 'Show less' : 'Show the full clause'}
        </Button>
      ) : null}

      {clause.source_page !== null || clause.source_excerpt ? (
        <div
          style={{
            marginTop: 12,
            padding: '10px 12px',
            background: 'var(--color-bg-subtle)',
            borderRadius: 'var(--radius-md)',
            borderLeft: '3px solid rgb(var(--color-border-strong))',
          }}
        >
          <p
            style={{
              fontSize: 11,
              fontWeight: 700,
              textTransform: 'uppercase',
              letterSpacing: '.03em',
              color: 'var(--color-text-muted)',
            }}
          >
            <FileSearch size={11} aria-hidden style={{ marginRight: 5, verticalAlign: -1 }} />
            Source{clause.source_page !== null ? ` · page ${clause.source_page}` : ''}
          </p>
          {clause.source_excerpt ? (
            <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 5, lineHeight: 1.6 }}>
              “{truncate(clause.source_excerpt, 300)}”
            </p>
          ) : null}
        </div>
      ) : null}

      {clause.verified_at ? (
        <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 10 }}>
          Verified {formatDate(clause.verified_at)}
        </p>
      ) : null}

      {deviations.length > 0 ? (
        <div style={{ marginTop: 14, display: 'grid', gap: 10 }}>
          {deviations.map((deviation) => (
            <DeviationCard
              key={deviation.id}
              deviation={deviation}
              canManage={canManage}
              busy={busyDeviationId === deviation.id}
              onReview={(status) => onReviewDeviation(deviation, status)}
            />
          ))}
        </div>
      ) : null}
    </Card>
  )
}

function DeviationCard({
  deviation,
  canManage,
  busy,
  onReview,
}: {
  deviation: ClauseDeviation
  canManage: boolean
  busy: boolean
  onReview: (status: DeviationStatus) => void
}) {
  return (
    <article
      style={{
        border: '1px solid rgb(var(--color-border))',
        borderLeft: `3px solid ${
          SEVERITY_TONE[deviation.severity] === 'danger'
            ? 'var(--color-danger)'
            : SEVERITY_TONE[deviation.severity] === 'warning'
              ? 'var(--color-warning)'
              : 'rgb(var(--color-border-strong))'
        }`,
        borderRadius: 'var(--radius-md)',
        padding: 13,
        background: 'var(--color-bg-card)',
      }}
    >
      <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
        <Chip tone={SEVERITY_TONE[deviation.severity]} size="sm">
          {humanise(deviation.severity)} deviation
        </Chip>
        <StatusChip status={deviation.review_status} size="sm" />
        <Chip tone="neutral" size="sm">
          {deviation.detected_by === 'ai' ? 'Found by AI' : deviation.detected_by === 'rules' ? 'Playbook rule' : 'Raised by a person'}
        </Chip>
        {deviation.rule_label ? (
          <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>{deviation.rule_label}</span>
        ) : null}
      </div>

      <p style={{ fontSize: 13, marginTop: 9, lineHeight: 1.6 }}>{deviation.deviation_summary}</p>

      {deviation.preferred_wording ? (
        <div style={{ marginTop: 10 }}>
          <p style={{ fontSize: 11, fontWeight: 700, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '.03em' }}>
            Preferred wording
          </p>
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4, lineHeight: 1.65 }}>
            {deviation.preferred_wording}
          </p>
        </div>
      ) : null}

      {deviation.recommendation ? (
        <p style={{ fontSize: 12.5, marginTop: 10, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
          <strong style={{ color: 'var(--color-text)' }}>Recommended: </strong>
          {deviation.recommendation}
        </p>
      ) : null}

      {deviation.review_notes ? (
        <p style={{ fontSize: 12, marginTop: 8, color: 'var(--color-text-muted)' }}>
          Reviewer note: {deviation.review_notes}
        </p>
      ) : null}

      {canManage && deviation.review_status === 'open' ? (
        <div style={{ display: 'flex', gap: 7, marginTop: 12, flexWrap: 'wrap' }}>
          <Button size="sm" variant="secondary" loading={busy} onClick={() => onReview('accepted')}>
            Accept
          </Button>
          <Button size="sm" variant="secondary" disabled={busy} onClick={() => onReview('negotiating')}>
            Negotiating
          </Button>
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => onReview('rejected')}>
            Reject
          </Button>
        </div>
      ) : deviation.reviewed_at ? (
        <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 10 }}>
          Reviewed {formatDate(deviation.reviewed_at)}
        </p>
      ) : null}
    </article>
  )
}

function AttachFromLibrary({
  contractId,
  currency,
  onClose,
  onAttached,
}: {
  contractId: number
  currency: string
  onClose: () => void
  onAttached: () => void
}) {
  const toast = useToast()
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [attachingId, setAttachingId] = useState<number | null>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [query])

  const library = useApiResource<Paged<LibraryClause>>(
    (signal) =>
      api.get<Paged<LibraryClause>>('/clauses', { q: debounced, per_page: 20 }, signal),
    [debounced],
  )

  const attach = async (clause: LibraryClause) => {
    setAttachingId(clause.id)
    try {
      await api.post(`/contracts/${contractId}/clauses`, {
        library_clause_id: clause.id,
        category_id: clause.category_id,
        heading: clause.name,
        body_text: clause.standard_text,
        verification_state: 'human_verified',
      })
      toast.success('Clause attached', clause.name)
      onAttached()
    } catch (err) {
      toast.error(
        'Could not attach that clause',
        err instanceof ApiError ? err.message : err instanceof Error ? err.message : undefined,
      )
    } finally {
      setAttachingId(null)
    }
  }

  const items = library.data?.items ?? []

  return (
    <Modal
      open
      onClose={onClose}
      title="Attach a clause from the library"
      description={`Approved wording is copied onto this contract, in ${currency}. Editing it afterwards does not change the library.`}
      width={640}
      footer={
        <Button variant="secondary" onClick={onClose}>
          Done
        </Button>
      }
    >
      <Input
        label="Search the library"
        placeholder="Liability, confidentiality, termination…"
        value={query}
        onChange={(event) => setQuery(event.target.value)}
      />

      <div style={{ marginTop: 14, display: 'grid', gap: 10, maxHeight: 380, overflowY: 'auto' }}>
        {library.loading ? (
          [0, 1, 2].map((row) => <Skeleton key={row} height={58} radius={10} />)
        ) : library.error ? (
          <ErrorState compact title="The library did not load" detail={library.error.message} onRetry={library.reload} />
        ) : items.length === 0 ? (
          <EmptyState
            compact
            icon={<Library size={19} />}
            title={debounced ? 'Nothing matches that' : 'The clause library is empty'}
            description={
              debounced
                ? 'Try a shorter search, or a category name.'
                : 'Approved wording added in the clause library can be attached to any contract from here.'
            }
          />
        ) : (
          items.map((clause) => (
            <div
              key={clause.id}
              style={{
                display: 'flex',
                gap: 12,
                alignItems: 'flex-start',
                justifyContent: 'space-between',
                padding: 12,
                border: '1px solid rgb(var(--color-border))',
                borderRadius: 'var(--radius-md)',
              }}
            >
              <div style={{ minWidth: 0 }}>
                <p style={{ fontSize: 13.5, fontWeight: 700 }}>{clause.name}</p>
                <div style={{ display: 'flex', gap: 6, marginTop: 5, flexWrap: 'wrap' }}>
                  {clause.category_name ? (
                    <Chip tone="neutral" size="sm">
                      {clause.category_name}
                    </Chip>
                  ) : null}
                  <StatusChip status={clause.approval_status} size="sm" />
                </div>
                <p style={{ fontSize: 12, color: 'var(--color-text-secondary)', marginTop: 7, lineHeight: 1.6 }}>
                  {truncate(clause.standard_text, 180)}
                </p>
              </div>
              <Button
                size="sm"
                variant="primary"
                loading={attachingId === clause.id}
                onClick={() => void attach(clause)}
              >
                Attach
              </Button>
            </div>
          ))
        )}
      </div>
    </Modal>
  )
}
