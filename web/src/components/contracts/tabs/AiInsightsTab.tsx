import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { ArrowUpRight, Bot, Pencil, RefreshCw, Sparkles, Undo2 } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  EmptyState,
  ErrorState,
  Skeleton,
} from '../../ui'
import { AiDisclaimer } from '../AiDisclaimer'
import { AskContractPanel } from '../AskContractPanel'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { api } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract } from '../../../types/contracts'
import { formatDate, formatDateTime, humanise } from '../../../utils/format'

/**
 * What AI has read out of this contract, and what a human did with it.
 *
 * The model's own words are kept verbatim in `sections`; a reviewer's edit is
 * stored separately, so regenerating never destroys a reviewer's wording and
 * editing never destroys the original for comparison. Both are reachable here,
 * which is the difference between an assistant and an overwrite.
 */

interface ManagementAction {
  title?: string | null
  action?: string | null
  detail?: string | null
  description?: string | null
  severity?: string | null
  priority?: string | null
  due_date?: string | null
  owner?: string | null
  subject_type?: string | null
  record_type?: string | null
  subject_id?: number | string | null
  record_id?: number | string | null
}

interface AiSummary {
  id: number
  contract_id: number
  sections: Record<string, unknown>
  edited_sections: Record<string, unknown> | null
  executive_summary: string | null
  management_actions: ManagementAction[]
  provider: string | null
  model: string | null
  is_current: boolean
  edited_by: string | null
  edited_at: string | null
  created_at: string
}

interface AiJob {
  id: number
  status: string
  kind: string
}

interface AiStatus {
  configured: boolean
  provider?: string | null
  model?: string | null
  disclaimer?: string | null
}

/** Which workspace tab a management action is about. */
const SUBJECT_TAB: Record<string, string> = {
  obligation: 'obligations',
  obligations: 'obligations',
  occurrence: 'obligations',
  milestone: 'milestones',
  milestones: 'milestones',
  risk: 'risk',
  risk_finding: 'risk',
  renewal: 'renewal',
  amendment: 'amendments',
  clause: 'clauses',
  deviation: 'clauses',
  payment: 'payments',
  payment_schedule: 'payments',
  approval: 'approvals',
  document: 'document',
  version: 'versions',
  party: 'parties',
  parties: 'parties',
}

const SEVERITY_TONE: Record<string, 'danger' | 'warning' | 'info' | 'neutral'> = {
  critical: 'danger',
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
  informational: 'info',
  urgent: 'danger',
  normal: 'neutral',
}

/** A section may arrive as text, a list of points, or a small object. */
function sectionText(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'string') return value
  if (Array.isArray(value)) return value.map((item) => `• ${typeof item === 'string' ? item : JSON.stringify(item)}`).join('\n')
  if (typeof value === 'object') {
    return Object.entries(value as Record<string, unknown>)
      .map(([key, item]) => `${humanise(key)}: ${typeof item === 'string' ? item : JSON.stringify(item)}`)
      .join('\n')
  }
  return String(value)
}

export function AiInsightsTab({
  contractId,
  contract,
  onChanged,
}: {
  contractId: number
  contract: Contract
  onChanged: () => void
}) {
  const { can, session } = useSession()
  const toast = useToast()
  const canUseAi = can(PERMISSION.AI_USE)

  const resource = useApiResource<{ summary: AiSummary | null; status: AiStatus | null }>(
    async (signal) => {
      const [summary, status] = await Promise.all([
        api.get<AiSummary | null>(`/ai/contracts/${contractId}/summary`, undefined, signal),
        api.get<AiStatus | null>('/ai/status', undefined, signal),
      ])
      return { summary: summary ?? null, status: status ?? null }
    },
    [contractId],
  )

  const [generating, setGenerating] = useState(false)
  const [editing, setEditing] = useState(false)
  const [drafts, setDrafts] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [showOriginal, setShowOriginal] = useState<Record<string, boolean>>({})

  const summary = resource.data?.summary ?? null
  const status = resource.data?.status ?? null
  const aiConfigured = status?.configured ?? session?.ai.configured ?? false

  const sectionKeys = useMemo(() => {
    const keys = new Set<string>()
    for (const key of Object.keys(summary?.sections ?? {})) keys.add(key)
    for (const key of Object.keys(summary?.edited_sections ?? {})) keys.add(key)
    return [...keys]
  }, [summary])

  const effective = (key: string): string => {
    const edited = summary?.edited_sections?.[key]
    return sectionText(edited !== undefined && edited !== null ? edited : summary?.sections?.[key])
  }

  const isEdited = (key: string): boolean => {
    const edited = summary?.edited_sections?.[key]
    return edited !== undefined && edited !== null && sectionText(edited) !== sectionText(summary?.sections?.[key])
  }

  const regenerate = async () => {
    setGenerating(true)
    try {
      const result = await api.post<AiSummary | AiJob | { job: AiJob }>(`/ai/contracts/${contractId}/summarize`)
      const job = result && 'job' in result ? result.job : null
      const queued = job !== null || (result !== null && 'status' in result && !('sections' in result))

      toast.success(
        queued ? 'Summary queued' : 'Summary regenerated',
        queued ? 'It is being generated; refresh in a moment.' : undefined,
      )
      resource.reload()
      onChanged()
    } catch (err) {
      toast.error('Could not generate the summary', err instanceof Error ? err.message : undefined)
    } finally {
      setGenerating(false)
    }
  }

  const startEditing = () => {
    const initial: Record<string, string> = {}
    for (const key of sectionKeys) initial[key] = effective(key)
    setDrafts(initial)
    setEditing(true)
  }

  const saveEdits = async () => {
    setSaving(true)
    try {
      await api.put(`/ai/contracts/${contractId}/summary`, { sections: drafts })
      toast.success('Summary updated', 'The original AI wording is kept alongside your edit.')
      setEditing(false)
      resource.reload()
      onChanged()
    } catch (err) {
      toast.error('Could not save your edit', err instanceof Error ? err.message : undefined)
    } finally {
      setSaving(false)
    }
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 16 }}>
        <Card>
          <Skeleton width="35%" height={14} />
          <div style={{ marginTop: 14, display: 'grid', gap: 9 }}>
            <Skeleton height={11} />
            <Skeleton height={11} />
            <Skeleton height={11} width="70%" />
          </div>
        </Card>
        <Card>
          <div style={{ display: 'grid', gap: 12 }}>
            {[0, 1].map((row) => (
              <Skeleton key={row} height={72} radius={10} />
            ))}
          </div>
        </Card>
      </div>
    )
  }

  if (resource.error) {
    return <ErrorState title="Could not load the AI summary" detail={resource.error.message} onRetry={resource.reload} />
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      {!summary ? (
        <EmptyState
          icon={<Sparkles size={22} />}
          title="No AI summary yet"
          description={
            aiConfigured
              ? 'A summary reads the executed document and lays out the commercial terms, the obligations it creates and what management needs to act on. Every section stays editable, and your edit never overwrites the original.'
              : 'AI is not connected for this company yet. An administrator can configure a provider in Console, and summaries will be available here.'
          }
          action={
            canUseAi && aiConfigured ? (
              <Button variant="primary" icon={<Sparkles size={15} />} loading={generating} onClick={() => void regenerate()}>
                Generate a summary
              </Button>
            ) : undefined
          }
        />
      ) : (
        <Card>
          <CardHeader
            level={3}
            title="AI summary"
            description={[
              summary.provider ? `${summary.provider}${summary.model ? ` · ${summary.model}` : ''}` : null,
              `generated ${formatDateTime(summary.created_at)}`,
              summary.edited_at ? `edited ${formatDateTime(summary.edited_at)}` : null,
            ]
              .filter(Boolean)
              .join(' · ')}
            action={
              canUseAi ? (
                <div style={{ display: 'flex', gap: 7 }}>
                  {editing ? (
                    <>
                      <Button size="sm" variant="ghost" disabled={saving} onClick={() => setEditing(false)}>
                        Cancel
                      </Button>
                      <Button size="sm" variant="primary" loading={saving} onClick={() => void saveEdits()}>
                        Save edits
                      </Button>
                    </>
                  ) : (
                    <>
                      <Button size="sm" variant="ghost" icon={<Pencil size={13} />} onClick={startEditing}>
                        Edit
                      </Button>
                      <Button
                        size="sm"
                        variant="secondary"
                        icon={<RefreshCw size={13} />}
                        loading={generating}
                        disabled={!aiConfigured}
                        onClick={() => void regenerate()}
                      >
                        Regenerate
                      </Button>
                    </>
                  )}
                </div>
              ) : undefined
            }
          />

          {summary.executive_summary ? (
            <section
              style={{
                padding: 14,
                borderRadius: 'var(--radius-md)',
                background: 'var(--color-bg-subtle)',
                border: '1px solid rgb(var(--color-border))',
                marginBottom: 16,
              }}
            >
              <h4 style={{ fontSize: 12, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.03em', color: 'var(--color-text-muted)' }}>
                In short
              </h4>
              <p style={{ fontSize: 13.5, lineHeight: 1.7, marginTop: 7, whiteSpace: 'pre-wrap' }}>
                {summary.executive_summary}
              </p>
            </section>
          ) : null}

          {sectionKeys.length === 0 ? (
            <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
              The summary has no sections yet. Regenerate it once a document has been uploaded and
              text extracted.
            </p>
          ) : (
            <div style={{ display: 'grid', gap: 18 }}>
              {sectionKeys.map((key) => (
                <section key={key}>
                  <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', marginBottom: 7 }}>
                    <h4 style={{ fontSize: 13.5, fontWeight: 700 }}>{humanise(key)}</h4>
                    {isEdited(key) ? (
                      <>
                        <Chip tone="primary" size="sm">
                          Edited by a reviewer
                        </Chip>
                        <Button
                          size="sm"
                          variant="ghost"
                          icon={<Undo2 size={12} />}
                          onClick={() =>
                            setShowOriginal((current) => ({ ...current, [key]: !current[key] }))
                          }
                        >
                          {showOriginal[key] ? 'Hide the original' : 'Show the original'}
                        </Button>
                      </>
                    ) : (
                      <Chip tone="info" size="sm">
                        <Bot size={11} aria-hidden />
                        As generated
                      </Chip>
                    )}
                  </div>

                  {editing ? (
                    <>
                      <label htmlFor={`section-${key}`} className="ct-sr-only">
                        {humanise(key)}
                      </label>
                      <textarea
                        id={`section-${key}`}
                        rows={5}
                        value={drafts[key] ?? ''}
                        onChange={(event) =>
                          setDrafts((current) => ({ ...current, [key]: event.target.value }))
                        }
                        style={{
                          width: '100%',
                          padding: '9px 11px',
                          borderRadius: 'var(--radius-md)',
                          border: '1px solid rgb(var(--color-border-strong))',
                          background: 'var(--color-bg-card)',
                          color: 'var(--color-text)',
                          fontSize: 13,
                          lineHeight: 1.65,
                          resize: 'vertical',
                        }}
                      />
                    </>
                  ) : (
                    <p style={{ fontSize: 13, lineHeight: 1.7, whiteSpace: 'pre-wrap', color: 'var(--color-text)' }}>
                      {effective(key) || '—'}
                    </p>
                  )}

                  {!editing && showOriginal[key] ? (
                    <div
                      style={{
                        marginTop: 9,
                        padding: '10px 12px',
                        borderRadius: 'var(--radius-md)',
                        background: 'var(--color-bg-subtle)',
                        borderLeft: '3px solid rgb(var(--color-border-strong))',
                      }}
                    >
                      <p style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '.03em', color: 'var(--color-text-muted)' }}>
                        Originally generated
                      </p>
                      <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 5, lineHeight: 1.65, whiteSpace: 'pre-wrap' }}>
                        {sectionText(summary.sections?.[key]) || '—'}
                      </p>
                    </div>
                  ) : null}
                </section>
              ))}
            </div>
          )}

          <AiDisclaimer text={status?.disclaimer} />
        </Card>
      )}

      {summary && (summary.management_actions?.length ?? 0) > 0 ? (
        <Card>
          <CardHeader
            level={3}
            title="What management needs to act on"
            description="Each item points at the record it concerns, so it can be dealt with rather than noted."
          />
          <ul style={{ listStyle: 'none', display: 'grid', gap: 10 }}>
            {summary.management_actions.map((action, index) => {
              const subject = (action.subject_type ?? action.record_type ?? '').toLowerCase()
              const tab = SUBJECT_TAB[subject]
              const label = action.title ?? action.action ?? 'Action'
              const detail = action.detail ?? action.description ?? null
              const severity = (action.severity ?? action.priority ?? '').toLowerCase()

              return (
                <li
                  key={`${label}-${index}`}
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    gap: 12,
                    flexWrap: 'wrap',
                    padding: '12px 13px',
                    border: '1px solid rgb(var(--color-border))',
                    borderRadius: 'var(--radius-md)',
                  }}
                >
                  <div style={{ minWidth: 0 }}>
                    <p style={{ fontSize: 13.5, fontWeight: 700 }}>{label}</p>
                    {detail ? (
                      <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4, lineHeight: 1.6 }}>
                        {detail}
                      </p>
                    ) : null}
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginTop: 7 }}>
                      {severity ? (
                        <Chip tone={SEVERITY_TONE[severity] ?? 'neutral'} size="sm">
                          {humanise(severity)}
                        </Chip>
                      ) : null}
                      {action.due_date ? (
                        <Chip tone="neutral" size="sm">
                          Due {formatDate(action.due_date)}
                        </Chip>
                      ) : null}
                      {subject ? (
                        <Chip tone="neutral" size="sm">
                          {humanise(subject)}
                        </Chip>
                      ) : null}
                    </div>
                  </div>

                  {tab ? (
                    <Link
                      to={`/contracts/${contractId}?tab=${tab}`}
                      style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 5,
                        alignSelf: 'center',
                        fontSize: 12.5,
                        fontWeight: 700,
                      }}
                    >
                      Open {humanise(tab).toLowerCase()}
                      <ArrowUpRight size={13} aria-hidden />
                    </Link>
                  ) : null}
                </li>
              )
            })}
          </ul>
        </Card>
      ) : null}

      <AskContractPanel
        contractId={contractId}
        contractTitle={contract.title}
        disclaimer={status?.disclaimer}
        enabled={canUseAi && aiConfigured}
        disabledReason={
          !canUseAi
            ? 'Your role does not include using AI. Ask an administrator for the AI permission if you need to question contracts here.'
            : undefined
        }
      />
    </div>
  )
}
