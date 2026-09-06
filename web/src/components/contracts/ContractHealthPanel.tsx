import { HeartPulse } from 'lucide-react'

import { Card, CardHeader, ErrorState, ProgressRing, Skeleton } from '../ui'
import { useApiResource } from '../../hooks/useApiResource'
import { api } from '../../services/apiClient'
import { formatDateTime, humanise } from '../../utils/format'

/**
 * `GET /contracts/{id}/health`.
 *
 * The server scores five categories out of 100 and returns the deductions that
 * produced each one, which is the only reason this panel is worth showing: a
 * health number with no explanation is decoration, and people quote it in
 * meetings as if it were measured.
 */
interface HealthPayload {
  overall: number
  categories: Record<string, number>
  explanations: string[]
}

function toneFor(score: number): 'success' | 'warning' | 'danger' {
  return score >= 75 ? 'success' : score >= 50 ? 'warning' : 'danger'
}

const BAR_COLOUR = {
  success: 'var(--color-success)',
  warning: 'var(--color-warning)',
  danger: 'var(--color-danger)',
} as const

function CategoryBar({ label, score }: { label: string; score: number }) {
  const clamped = Math.max(0, Math.min(100, Math.round(score)))

  return (
    <div style={{ display: 'grid', gap: 5 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, fontSize: 12.5 }}>
        <span style={{ color: 'var(--color-text-secondary)', fontWeight: 600 }}>{label}</span>
        <span style={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>{clamped}</span>
      </div>
      <svg
        width="100%"
        height={8}
        role="img"
        aria-label={`${label}: ${clamped} out of 100`}
        style={{ display: 'block', overflow: 'visible' }}
      >
        <rect x={0} y={0} width="100%" height={8} rx={4} fill="rgb(var(--color-border))" />
        <rect x={0} y={0} width={`${clamped}%`} height={8} rx={4} fill={BAR_COLOUR[toneFor(clamped)]} />
      </svg>
    </div>
  )
}

export function ContractHealthPanel({
  contractId,
  findingsCount = null,
  assessedAt = null,
}: {
  contractId: number
  /** Findings in the assessment in force, so the score can name what it came from. */
  findingsCount?: number | null
  assessedAt?: string | null
}) {
  const health = useApiResource<HealthPayload | null>(
    (signal) => api.get<HealthPayload | null>(`/contracts/${contractId}/health`, undefined, signal),
    [contractId],
  )

  return (
    <Card>
      <CardHeader
        level={3}
        title="Contract health"
        description="Each category starts at 100 and loses points for risk findings and for contract data that is missing."
      />

      {health.loading ? (
        <div style={{ display: 'flex', gap: 20, alignItems: 'center' }}>
          <Skeleton width={92} height={92} radius={46} />
          <div style={{ flex: 1, display: 'grid', gap: 12 }}>
            {[0, 1, 2, 3, 4].map((row) => (
              <Skeleton key={row} height={10} />
            ))}
          </div>
        </div>
      ) : health.error ? (
        <ErrorState
          compact
          title="Health could not be scored"
          detail={health.error.message}
          onRetry={health.reload}
        />
      ) : !health.data ? (
        <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
          This contract has not been scored yet. Health is computed from the risk assessment, so run
          an assessment above and the score will appear here.
        </p>
      ) : (
        <>
          <div
            style={{
              display: 'flex',
              gap: 22,
              alignItems: 'center',
              flexWrap: 'wrap',
              marginBottom: 18,
            }}
          >
            <ProgressRing value={health.data.overall} size={92} label="HEALTH" />
            <div style={{ minWidth: 220, flex: 1 }}>
              <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
                {findingsCount === null
                  ? 'Scored from the risk assessment in force and the contract data on file.'
                  : findingsCount === 0
                    ? 'No open findings in the assessment in force; the deductions below are for contract data that is missing.'
                    : `Scored from ${findingsCount} finding${findingsCount === 1 ? '' : 's'} in the assessment in force, and from contract data that is missing.`}
              </p>
              {assessedAt ? (
                <p style={{ fontSize: 12, color: 'var(--color-text-muted)', marginTop: 5 }}>
                  Assessment run {formatDateTime(assessedAt)}
                </p>
              ) : null}
            </div>
          </div>

          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))',
              gap: '14px 26px',
            }}
          >
            {Object.entries(health.data.categories).map(([category, score]) => (
              <CategoryBar key={category} label={humanise(category)} score={Number(score)} />
            ))}
          </div>

          <section style={{ marginTop: 18 }}>
            <h4
              style={{
                fontSize: 12,
                fontWeight: 700,
                textTransform: 'uppercase',
                letterSpacing: '.03em',
                color: 'var(--color-text-muted)',
                marginBottom: 8,
              }}
            >
              How this score was reached
            </h4>
            {health.data.explanations.length === 0 ? (
              <p
                style={{
                  display: 'flex',
                  gap: 7,
                  alignItems: 'center',
                  fontSize: 13,
                  color: 'var(--color-text-secondary)',
                }}
              >
                <HeartPulse size={14} aria-hidden style={{ color: 'var(--color-success)' }} />
                Nothing deducted — no findings were raised and the contract record is complete.
              </p>
            ) : (
              <ul style={{ display: 'grid', gap: 6, paddingLeft: 18, fontSize: 12.5, color: 'var(--color-text-secondary)' }}>
                {health.data.explanations.map((explanation, index) => (
                  <li key={`${index}-${explanation}`} style={{ lineHeight: 1.6 }}>
                    {explanation}
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}
    </Card>
  )
}
