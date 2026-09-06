import { useMemo, useState } from 'react'
import { FileSearch, Lock, ShieldAlert, Sparkles } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  EmptyState,
  ErrorState,
  Modal,
  ProgressRing,
  Skeleton,
  StatusChip,
  Textarea,
} from '../../ui'
import { AiDisclaimer } from '../AiDisclaimer'
import { ContractHealthPanel } from '../ContractHealthPanel'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract, RiskLevel } from '../../../types/contracts'
import { formatDateTime, humanise, truncate } from '../../../utils/format'

/**
 * The risk assessment in force, and what a reviewer decided about each finding.
 *
 * Every number here is derived from findings that name a rule and, where the
 * finding came from a document, the paragraph it was read from. A risk score
 * with nothing behind it is a decoration; this one can always be traced back to
 * the sentence that caused it.
 */

type Severity = 'informational' | 'low' | 'medium' | 'high' | 'critical'
type ReviewStatus = 'open' | 'accepted' | 'mitigated' | 'false_positive' | 'resolved'

interface RiskFinding {
  id: number
  assessment_id: number
  contract_id: number
  rule_id: number | null
  rule_key: string | null
  clause_id: number | null
  risk_category: string
  severity: Severity
  title: string
  detail: string | null
  source_excerpt: string | null
  source_page: number | null
  recommendation: string | null
  detected_by: 'rules' | 'ai' | 'manual'
  ai_confidence: string | number | null
  score_impact: number
  review_status: ReviewStatus
  reviewed_at: string | null
  review_notes: string | null
  created_at: string
}

interface RiskAssessment {
  id: number
  contract_id: number
  overall_score: number
  risk_level: RiskLevel
  /** Risk points accumulated per category — higher is worse, unlike health. */
  category_scores: Record<string, number>
  health_score: number | null
  findings_count: number
  critical_count: number
  high_count: number
  engine_version: string
  ai_used: boolean
  summary: string | null
  is_current: boolean
  created_at: string
  findings?: RiskFinding[]
}

interface RiskPayload {
  assessment: RiskAssessment | null
  findings?: RiskFinding[]
}

const SEVERITY_ORDER: Severity[] = ['critical', 'high', 'medium', 'low', 'informational']

const SEVERITY_TONE: Record<Severity, 'danger' | 'warning' | 'neutral' | 'info'> = {
  critical: 'danger',
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
  informational: 'info',
}

const REVIEW_OPTIONS: { status: ReviewStatus; label: string }[] = [
  { status: 'accepted', label: 'Accept the risk' },
  { status: 'mitigated', label: 'Mitigated' },
  { status: 'resolved', label: 'Resolved' },
  { status: 'false_positive', label: 'Not a real finding' },
]

/** Risk points, so the scale runs the other way from health. */
function riskColour(points: number): string {
  if (points >= 60) return 'var(--color-danger)'
  if (points >= 30) return 'var(--color-warning)'
  return 'var(--color-success)'
}

export function RiskTab({
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
  const canView = can(PERMISSION.AI_RISK_VIEW)
  const canAssess = can(PERMISSION.CONTRACT_EDIT)

  const resource = useApiResource<RiskPayload>(
    (signal) => api.get<RiskPayload>(`/contracts/${contractId}/risk`, undefined, signal),
    [contractId],
    { enabled: canView },
  )

  const [reviewing, setReviewing] = useState<{ finding: RiskFinding; status: ReviewStatus } | null>(null)
  const [assessing, setAssessing] = useState(false)

  const assessment = resource.data?.assessment ?? null
  const findings = useMemo(() => {
    const rows = resource.data?.findings ?? assessment?.findings ?? []
    return [...rows].sort((a, b) => {
      const bySeverity = SEVERITY_ORDER.indexOf(a.severity) - SEVERITY_ORDER.indexOf(b.severity)
      return bySeverity !== 0 ? bySeverity : a.risk_category.localeCompare(b.risk_category)
    })
  }, [resource.data, assessment])

  const openFindings = findings.filter((finding) => finding.review_status === 'open').length
  const aiInvolved = (assessment?.ai_used ?? false) || findings.some((finding) => finding.detected_by === 'ai')

  const refresh = () => {
    resource.reload()
    onChanged()
  }

  const assess = async () => {
    setAssessing(true)
    try {
      await api.post(`/contracts/${contractId}/risk/assess`)
      toast.success('Risk reassessed')
      refresh()
    } catch (err) {
      toast.error('The assessment did not run', err instanceof Error ? err.message : undefined)
    } finally {
      setAssessing(false)
    }
  }

  if (!canView) {
    return (
      <EmptyState
        icon={<Lock size={22} />}
        title="Risk analysis is restricted"
        description="Your role does not include access to risk findings for this company. Ask an administrator for the risk permission if you need it."
      />
    )
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 16 }}>
        <Card>
          <div style={{ display: 'flex', gap: 20, alignItems: 'center' }}>
            <Skeleton width={92} height={92} radius={46} />
            <div style={{ flex: 1, display: 'grid', gap: 10 }}>
              <Skeleton height={13} width="50%" />
              <Skeleton height={11} width="70%" />
              <Skeleton height={11} width="35%" />
            </div>
          </div>
        </Card>
        <Card>
          <div style={{ display: 'grid', gap: 12 }}>
            {[0, 1, 2].map((row) => (
              <Skeleton key={row} height={64} radius={10} />
            ))}
          </div>
        </Card>
      </div>
    )
  }

  if (resource.error) {
    return <ErrorState title="Could not load the risk assessment" detail={resource.error.message} onRetry={resource.reload} />
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      {!assessment ? (
        <EmptyState
          icon={<ShieldAlert size={22} />}
          title="Not assessed yet"
          description="The risk engine reads the contract's terms and clauses against your rules, scores each category and lists what it found. Nothing here is guesswork — every finding names the rule that raised it."
          action={
            canAssess ? (
              <Button variant="primary" loading={assessing} onClick={() => void assess()}>
                Run an assessment
              </Button>
            ) : undefined
          }
        />
      ) : (
        <Card>
          <CardHeader
            level={3}
            title="Risk assessment"
            description={`Engine ${assessment.engine_version} · run ${formatDateTime(assessment.created_at)}`}
            action={
              canAssess ? (
                <Button size="sm" variant="secondary" loading={assessing} onClick={() => void assess()}>
                  Reassess
                </Button>
              ) : undefined
            }
          />

          <div style={{ display: 'flex', gap: 22, alignItems: 'center', flexWrap: 'wrap' }}>
            <ProgressRing
              value={assessment.overall_score}
              size={92}
              label="RISK"
              tone={
                assessment.risk_level === 'critical' || assessment.risk_level === 'high'
                  ? 'danger'
                  : assessment.risk_level === 'medium'
                    ? 'warning'
                    : 'success'
              }
            />
            <div style={{ minWidth: 220, flex: 1 }}>
              <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', alignItems: 'center' }}>
                <Chip tone={assessment.risk_level === 'low' ? 'success' : assessment.risk_level === 'medium' ? 'warning' : 'danger'}>
                  {humanise(assessment.risk_level)} risk
                </Chip>
                <Chip tone="neutral" size="sm">
                  {assessment.findings_count} finding{assessment.findings_count === 1 ? '' : 's'}
                </Chip>
                {assessment.critical_count > 0 ? (
                  <Chip tone="danger" size="sm">
                    {assessment.critical_count} critical
                  </Chip>
                ) : null}
                {assessment.high_count > 0 ? (
                  <Chip tone="danger" size="sm">
                    {assessment.high_count} high
                  </Chip>
                ) : null}
              </div>
              <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 9, lineHeight: 1.6 }}>
                {assessment.summary ??
                  'The score is the total impact of the findings below; a finding dismissed as a false positive stops counting toward it.'}
              </p>
            </div>
          </div>

          {Object.keys(assessment.category_scores ?? {}).length > 0 ? (
            <section style={{ marginTop: 18 }}>
              <h4
                style={{
                  fontSize: 12,
                  fontWeight: 700,
                  textTransform: 'uppercase',
                  letterSpacing: '.03em',
                  color: 'var(--color-text-muted)',
                  marginBottom: 10,
                }}
              >
                Risk by category — higher is worse
              </h4>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: '14px 26px' }}>
                {Object.entries(assessment.category_scores).map(([category, points]) => {
                  const value = Math.max(0, Math.min(100, Number(points)))
                  return (
                    <div key={category} style={{ display: 'grid', gap: 5 }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 12.5 }}>
                        <span style={{ color: 'var(--color-text-secondary)', fontWeight: 600 }}>
                          {humanise(category)}
                        </span>
                        <span style={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{value}</span>
                      </div>
                      <svg
                        width="100%"
                        height={8}
                        role="img"
                        aria-label={`${humanise(category)}: ${value} risk points out of 100`}
                        style={{ display: 'block' }}
                      >
                        <rect x={0} y={0} width="100%" height={8} rx={4} fill="rgb(var(--color-border))" />
                        <rect x={0} y={0} width={`${value}%`} height={8} rx={4} fill={riskColour(value)} />
                      </svg>
                    </div>
                  )
                })}
              </div>
            </section>
          ) : null}

          {aiInvolved ? <AiDisclaimer /> : null}
        </Card>
      )}

      {assessment ? (
        <Card padded={false}>
          <div style={{ padding: '16px 18px 12px' }}>
            <h3 style={{ fontSize: 14.5, fontWeight: 700 }}>Findings</h3>
            <p aria-live="polite" style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3 }}>
              {findings.length === 0
                ? 'Nothing was flagged in this assessment.'
                : `${findings.length} finding${findings.length === 1 ? '' : 's'} · ${openFindings} awaiting review`}
            </p>
          </div>

          {findings.length === 0 ? (
            <EmptyState
              compact
              title="No findings"
              description="The rules that ran against this contract raised nothing. That is a result, not an absence of analysis."
            />
          ) : (
            <ul style={{ listStyle: 'none', display: 'grid', gap: 0 }}>
              {findings.map((finding) => (
                <li key={finding.id} style={{ padding: '14px 18px', borderTop: '1px solid var(--color-border-light)' }}>
                  <FindingRow
                    finding={finding}
                    canReview={can(PERMISSION.CONTRACT_EDIT)}
                    onReview={(status) => setReviewing({ finding, status })}
                  />
                </li>
              ))}
            </ul>
          )}
        </Card>
      ) : null}

      <ContractHealthPanel
        contractId={contractId}
        findingsCount={assessment ? findings.length : null}
        assessedAt={assessment?.created_at ?? null}
      />

      {reviewing ? (
        <ReviewModal
          finding={reviewing.finding}
          status={reviewing.status}
          contractTitle={contract.title}
          onClose={() => setReviewing(null)}
          onSaved={() => {
            setReviewing(null)
            refresh()
          }}
        />
      ) : null}
    </div>
  )
}

function FindingRow({
  finding,
  canReview,
  onReview,
}: {
  finding: RiskFinding
  canReview: boolean
  onReview: (status: ReviewStatus) => void
}) {
  const confidence =
    finding.ai_confidence === null || finding.ai_confidence === ''
      ? null
      : Math.round(Number(finding.ai_confidence) * 100)

  return (
    <article>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0 }}>
          <h4 style={{ fontSize: 13.5, fontWeight: 700 }}>{finding.title}</h4>
          <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 7 }}>
            <Chip tone={SEVERITY_TONE[finding.severity]} size="sm">
              {humanise(finding.severity)}
            </Chip>
            <Chip tone="neutral" size="sm">
              {humanise(finding.risk_category)}
            </Chip>
            <StatusChip status={finding.review_status} size="sm" />
            {finding.detected_by === 'ai' ? (
              <Chip tone="info" size="sm">
                <Sparkles size={11} aria-hidden />
                AI{confidence !== null ? ` · ${confidence}%` : ''}
              </Chip>
            ) : (
              <Chip tone="neutral" size="sm">
                {finding.detected_by === 'rules' ? `Rule${finding.rule_key ? ` · ${finding.rule_key}` : ''}` : 'Raised by a person'}
              </Chip>
            )}
            <Chip tone="neutral" size="sm" title="How many points this finding adds to the risk score">
              +{finding.score_impact} risk
            </Chip>
          </div>
        </div>
      </div>

      {finding.detail ? (
        <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 10, lineHeight: 1.65 }}>
          {finding.detail}
        </p>
      ) : null}

      {finding.source_excerpt ? (
        <div
          style={{
            marginTop: 10,
            padding: '10px 12px',
            background: 'var(--color-bg-subtle)',
            borderRadius: 'var(--radius-md)',
            borderLeft: '3px solid rgb(var(--color-border-strong))',
          }}
        >
          <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.03em', color: 'var(--color-text-muted)' }}>
            <FileSearch size={11} aria-hidden style={{ marginRight: 5, verticalAlign: -1 }} />
            From the contract{finding.source_page !== null ? ` · page ${finding.source_page}` : ''}
          </p>
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 5, lineHeight: 1.6 }}>
            “{truncate(finding.source_excerpt, 320)}”
          </p>
        </div>
      ) : null}

      {finding.recommendation ? (
        <p style={{ fontSize: 12.5, marginTop: 10, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
          <strong style={{ color: 'var(--color-text)' }}>Recommended: </strong>
          {finding.recommendation}
        </p>
      ) : null}

      {finding.review_notes ? (
        <p style={{ fontSize: 12, color: 'var(--color-text-muted)', marginTop: 8 }}>
          Reviewer note: {finding.review_notes}
          {finding.reviewed_at ? ` · ${formatDateTime(finding.reviewed_at)}` : ''}
        </p>
      ) : null}

      {canReview ? (
        <div style={{ display: 'flex', gap: 7, marginTop: 12, flexWrap: 'wrap' }}>
          {REVIEW_OPTIONS.filter((option) => option.status !== finding.review_status).map((option) => (
            <Button key={option.status} size="sm" variant="secondary" onClick={() => onReview(option.status)}>
              {option.label}
            </Button>
          ))}
        </div>
      ) : null}
    </article>
  )
}

function ReviewModal({
  finding,
  status,
  contractTitle,
  onClose,
  onSaved,
}: {
  finding: RiskFinding
  status: ReviewStatus
  contractTitle: string
  onClose: () => void
  onSaved: () => void
}) {
  const toast = useToast()
  const [notes, setNotes] = useState(finding.review_notes ?? '')
  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const submit = async () => {
    setSaving(true)
    setErrors({})
    try {
      await api.post(`/risk-findings/${finding.id}/review`, { status, notes: notes.trim() || null })
      toast.success(`Finding marked ${humanise(status).toLowerCase()}`)
      onSaved()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not record that review', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`Mark as ${humanise(status).toLowerCase()}`}
      description={`${finding.title} — ${contractTitle}`}
      width={520}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button variant="primary" loading={saving} onClick={() => void submit()}>
            Record review
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 12 }}>
        {status === 'false_positive' ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
            A false positive stops counting toward the risk score, and the finding is kept so the
            decision is visible next time the same rule fires.
          </p>
        ) : null}
        <Textarea
          label="Reviewer note"
          rows={4}
          value={notes}
          error={errors.notes}
          hint="Why this is acceptable, or what was done about it."
          onChange={(event) => setNotes(event.target.value)}
        />
      </div>
    </Modal>
  )
}
