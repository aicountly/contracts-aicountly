import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  ArrowLeft,
  CheckCircle2,
  FileSignature,
  HelpCircle,
  History,
  Send,
  ThumbsUp,
  XCircle,
} from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  Select,
  PageHeader,
  Skeleton,
  StatusChip,
  Textarea,
} from '../components/ui'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import type { FieldErrors } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import type {
  Contract,
  ContractRequest,
  ContractTypeSummary,
  Paged,
  RequestActivityEntry,
  RequestDecision,
  RequestStatus,
} from '../types/contracts'
import { formatDate, formatDateTime, formatMoney, humanise, truncate } from '../utils/format'

/**
 * One contract request: what was asked for, what happened to it, and what the
 * viewer can do about it now.
 *
 * The decision controls are driven by the status graph the API enforces rather
 * than by role alone — a reviewer looking at a request that has already been
 * approved is not offered "approve" again, because the server would refuse it
 * and a button that 409s teaches people to distrust the screen.
 */

interface TemplateOption {
  id: number
  name: string
}

/** Which verdicts the API will accept from this status. */
const DECISIONS_BY_STATUS: Record<RequestStatus, RequestDecision[]> = {
  draft: [],
  submitted: ['review', 'approve', 'more_info', 'reject'],
  under_review: ['approve', 'more_info', 'reject'],
  more_info_required: [],
  approved_for_drafting: ['reject'],
  rejected: [],
  converted: [],
}

const DECISION_LABEL: Record<RequestDecision, string> = {
  review: 'Start reviewing',
  approve: 'Approve for drafting',
  more_info: 'Need more information',
  reject: 'Reject',
}

const DECISION_DESCRIPTION: Record<RequestDecision, string> = {
  review: 'Marks the request as being looked at. It is not a verdict.',
  approve: 'The request may become a contract. Someone still has to draft it.',
  more_info: 'Sends it back to the requester, who can edit and submit it again.',
  reject: 'Ends the request. It cannot be reopened, so say why.',
}

export default function RequestDetail() {
  const { id } = useParams<{ id: string }>()
  const requestId = Number(id)
  const navigate = useNavigate()
  const toast = useToast()
  const { can, session } = useSession()

  const [decision, setDecision] = useState<RequestDecision | null>(null)
  const [converting, setConverting] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  const resource = useApiResource<ContractRequest>(
    (signal) => api.get<ContractRequest>(`/requests/${requestId}`, undefined, signal),
    [requestId],
    { enabled: Number.isFinite(requestId) && requestId > 0 },
  )

  const request = resource.data

  const template = useApiResource<TemplateOption | null>(
    async (signal) => {
      const page = await api.get<Paged<TemplateOption>>(
        '/templates',
        { per_page: 100 },
        signal,
      )
      return page.items.find((item) => item.id === request?.preferred_template_id) ?? null
    },
    [request?.preferred_template_id ?? 0],
    { enabled: (request?.preferred_template_id ?? null) !== null },
  )

  if (!Number.isFinite(requestId) || requestId <= 0) {
    return <NotFound />
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 18 }}>
        <div style={{ display: 'grid', gap: 8 }}>
          <Skeleton width={120} height={12} />
          <Skeleton width="45%" height={22} />
        </div>
        <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))' }}>
          <Card>
            <Skeleton width="30%" height={14} />
            <div style={{ marginTop: 16, display: 'grid', gap: 12 }}>
              <Skeleton height={52} radius={10} />
              <Skeleton height={52} radius={10} />
              <Skeleton height={90} radius={10} />
            </div>
          </Card>
          <Card>
            <Skeleton width="40%" height={14} />
            <div style={{ marginTop: 16, display: 'grid', gap: 12 }}>
              <Skeleton height={40} radius={10} />
              <Skeleton height={40} radius={10} />
            </div>
          </Card>
        </div>
      </div>
    )
  }

  if (resource.error) {
    if (resource.error instanceof ApiError && resource.error.isNotFound) return <NotFound />
    return (
      <ErrorState
        title="Could not load this request"
        detail={resource.error.message}
        onRetry={resource.reload}
      />
    )
  }

  if (!request) return <NotFound />

  const isRequester = session?.uuid != null && session.uuid === request.requester_uuid
  const canReview = can(PERMISSION.REQUEST_REVIEW)
  const canSubmit =
    can(PERMISSION.REQUEST_CREATE) &&
    (isRequester || canReview) &&
    (request.status === 'draft' || request.status === 'more_info_required')
  const canConvert = can(PERMISSION.CONTRACT_CREATE) && request.status === 'approved_for_drafting'
  const available = canReview ? DECISIONS_BY_STATUS[request.status] : []

  const submitForReview = async () => {
    setSubmitting(true)
    try {
      const updated = await api.post<ContractRequest>(`/requests/${request.id}/submit`)
      resource.setData({ ...updated, activity: updated.activity ?? request.activity })
      toast.success('Sent for review', `${request.request_number} is now with the reviewers.`)
      resource.reload()
    } catch (err) {
      toast.error('Could not submit the request', err instanceof Error ? err.message : undefined)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <>
      <PageHeader
        title={request.title}
        description={`${request.request_number} · raised ${formatDate(request.created_at)}`}
        breadcrumb={
          <Link
            to="/requests"
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 5,
              fontSize: 12.5,
              fontWeight: 600,
              color: 'var(--color-text-secondary)',
            }}
          >
            <ArrowLeft size={13} aria-hidden />
            All requests
          </Link>
        }
        actions={
          <>
            {canSubmit ? (
              <Button
                variant={request.status === 'more_info_required' ? 'primary' : 'secondary'}
                icon={<Send size={14} />}
                loading={submitting}
                onClick={() => void submitForReview()}
              >
                Submit for review
              </Button>
            ) : null}
            {canConvert ? (
              <Button variant="primary" icon={<FileSignature size={15} />} onClick={() => setConverting(true)}>
                Convert to contract
              </Button>
            ) : null}
          </>
        }
      />

      <div style={{ display: 'grid', gap: 18, gridTemplateColumns: 'repeat(auto-fit, minmax(330px, 1fr))' }}>
        <div style={{ display: 'grid', gap: 18, alignContent: 'start' }}>
          <Card>
            <CardHeader level={2} title="What was asked for" />
            <dl
              style={{
                display: 'grid',
                gap: 14,
                gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))',
                marginBottom: 4,
              }}
            >
              <Detail label="Request type" value={request.contract_type_name ?? 'Not decided'} />
              <Detail label="Department" value={request.department_name ?? 'Not stated'} />
              <Detail label="Counterparty" value={request.counterparty_name ?? 'Not named yet'} />
              <Detail
                label="Required by"
                value={request.required_by_date ? formatDate(request.required_by_date) : 'No date given'}
              />
              {can(PERMISSION.COMMERCIALS_VIEW) ? (
                <Detail
                  label="Estimated value"
                  value={formatMoney(request.estimated_value, request.currency || 'INR')}
                />
              ) : null}
              {request.preferred_template_id ? (
                <Detail
                  label="Preferred template"
                  value={
                    template.loading
                      ? 'Loading…'
                      : (template.data?.name ?? `Template ${request.preferred_template_id}`)
                  }
                />
              ) : null}
            </dl>

            <Prose title="Purpose" body={request.purpose} fallback="No purpose was recorded." />
            <Prose
              title="Business justification"
              body={request.business_justification}
              fallback="No justification was recorded."
            />
            {request.notes ? <Prose title="Notes" body={request.notes} /> : null}
          </Card>

          {available.length > 0 ? (
            <Card>
              <CardHeader
                level={2}
                title="Your decision"
                description="You are one of the people who decides whether this request goes forward."
              />
              <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                {available.map((option) => (
                  <Button
                    key={option}
                    variant={
                      option === 'approve' ? 'primary' : option === 'reject' ? 'danger' : 'secondary'
                    }
                    icon={
                      option === 'approve' ? (
                        <ThumbsUp size={14} />
                      ) : option === 'reject' ? (
                        <XCircle size={14} />
                      ) : option === 'more_info' ? (
                        <HelpCircle size={14} />
                      ) : (
                        <CheckCircle2 size={14} />
                      )
                    }
                    onClick={() => setDecision(option)}
                  >
                    {DECISION_LABEL[option]}
                  </Button>
                ))}
              </div>
            </Card>
          ) : null}

          <Card>
            <CardHeader
              level={2}
              title="History"
              description="Everything that has happened to this request, most recent first."
            />
            <ActivityTimeline entries={request.activity ?? []} />
          </Card>
        </div>

        <div style={{ display: 'grid', gap: 18, alignContent: 'start' }}>
          <Card>
            <CardHeader level={2} title="Where it stands" action={<StatusChip status={request.status} />} />
            <dl style={{ display: 'grid', gap: 14 }}>
              <Detail label="Raised by" value={personLabel(request.requester_uuid, session?.uuid ?? null)} />
              <Detail label="Raised on" value={formatDateTime(request.created_at)} />
              <Detail
                label="Reviewer"
                value={
                  request.reviewer_uuid
                    ? personLabel(request.reviewer_uuid, session?.uuid ?? null)
                    : 'Whoever picks it up'
                }
              />
              {request.decided_at ? (
                <Detail
                  label="Decided"
                  value={`${formatDateTime(request.decided_at)} by ${personLabel(request.decided_by, session?.uuid ?? null)}`}
                />
              ) : null}
              <Detail label="Last updated" value={formatDateTime(request.updated_at)} />
            </dl>

            {request.decision_notes ? (
              <div
                style={{
                  marginTop: 16,
                  padding: '10px 13px',
                  borderRadius: 'var(--radius-md)',
                  background:
                    request.status === 'rejected' ? 'var(--color-danger-bg)' : 'var(--color-bg-subtle)',
                  border: `1px solid ${
                    request.status === 'rejected' ? 'var(--color-danger-border)' : 'rgb(var(--color-border))'
                  }`,
                  fontSize: 13,
                  lineHeight: 1.6,
                }}
              >
                <strong style={{ display: 'block', fontSize: 12, marginBottom: 3 }}>
                  Reviewer&rsquo;s note
                </strong>
                {request.decision_notes}
              </div>
            ) : null}

            {request.converted_contract_id ? (
              <div style={{ marginTop: 16 }}>
                <Link
                  to={`/contracts/${request.converted_contract_id}`}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontWeight: 700, fontSize: 13 }}
                >
                  <FileSignature size={14} aria-hidden />
                  {request.converted_contract_number ?? 'Open the contract'}
                </Link>
                <p style={{ fontSize: 12, color: 'var(--color-text-muted)', marginTop: 4 }}>
                  Converted {formatDateTime(request.converted_at)}.
                </p>
              </div>
            ) : null}
          </Card>

          {request.status === 'more_info_required' ? (
            <Card>
              <CardHeader level={2} title="Waiting on the requester" />
              <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
                A reviewer has asked for more information. The request can be edited while it is in this
                state, and submitting it again puts it back in the review queue.
              </p>
            </Card>
          ) : null}
        </div>
      </div>

      {decision ? (
        <DecisionModal
          request={request}
          decision={decision}
          onClose={() => setDecision(null)}
          onDecided={(updated) => {
            setDecision(null)
            // The decision response carries the request without its timeline;
            // the one already on screen stands until the reload brings the new
            // entry, rather than the history blinking out.
            resource.setData({ ...updated, activity: request.activity })
            resource.reload()
            toast.success(`Recorded: ${DECISION_LABEL[decision].toLowerCase()}`)
          }}
        />
      ) : null}

      {converting ? (
        <ConvertModal
          request={request}
          onClose={() => setConverting(false)}
          onConverted={(contract) => {
            setConverting(false)
            toast.success(`Contract ${contract.contract_number} created`, 'Opening it now.')
            navigate(`/contracts/${contract.id}`)
          }}
        />
      ) : null}
    </>
  )
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div style={{ minWidth: 0 }}>
      <dt style={{ fontSize: 11.5, fontWeight: 700, color: 'var(--color-text-muted)', textTransform: 'uppercase', letterSpacing: '.02em' }}>
        {label}
      </dt>
      <dd style={{ fontSize: 13.5, marginTop: 3, wordBreak: 'break-word' }}>{value}</dd>
    </div>
  )
}

function Prose({ title, body, fallback }: { title: string; body: string | null; fallback?: string }) {
  if (!body && !fallback) return null

  return (
    <section style={{ marginTop: 16 }}>
      <h3 style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--color-text-muted)' }}>{title}</h3>
      <p
        style={{
          fontSize: 13.5,
          lineHeight: 1.65,
          marginTop: 4,
          whiteSpace: 'pre-wrap',
          color: body ? 'var(--color-text)' : 'var(--color-text-subtle)',
        }}
      >
        {body ?? fallback}
      </p>
    </section>
  )
}

/** A uuid is not a name; the whole one makes every line unreadable. */
function personLabel(uuid: string | null | undefined, meUuid: string | null): string {
  if (!uuid) return 'Nobody yet'
  if (meUuid && uuid === meUuid) return 'You'
  return truncate(uuid, 12)
}

function ActivityTimeline({ entries }: { entries: RequestActivityEntry[] }) {
  if (entries.length === 0) {
    return (
      <EmptyState
        compact
        icon={<History size={19} />}
        title="Nothing has happened yet"
        description="Submitting the request, a reviewer's decision and the conversion to a contract all land here."
      />
    )
  }

  return (
    <ol style={{ listStyle: 'none', display: 'grid', gap: 2 }}>
      {entries.map((entry, index) => {
        const rawNote = entry.metadata?.notes
        const note = typeof rawNote === 'string' ? rawNote.trim() : ''

        return (
          <li key={entry.id} style={{ display: 'flex', gap: 12 }}>
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }}>
              <span
                aria-hidden
                style={{
                  width: 9,
                  height: 9,
                  borderRadius: '50%',
                  marginTop: 6,
                  background:
                    index === 0 ? 'rgb(var(--color-primary))' : 'rgb(var(--color-border-strong))',
                }}
              />
              {index < entries.length - 1 ? (
                <span aria-hidden style={{ flex: 1, width: 1, background: 'rgb(var(--color-border))' }} />
              ) : null}
            </div>
            <div style={{ paddingBottom: 16, minWidth: 0 }}>
              <p style={{ fontSize: 13.5, fontWeight: 600 }}>
                {entry.summary?.trim() || humanise(entry.event_type)}
              </p>
              <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                {formatDateTime(entry.created_at)}
                {entry.actor_label ? ` · ${entry.actor_label}` : ''}
              </p>
              {note !== '' ? (
                <p
                  style={{
                    fontSize: 12.5,
                    color: 'var(--color-text-secondary)',
                    marginTop: 6,
                    padding: '8px 11px',
                    borderRadius: 'var(--radius-md)',
                    background: 'var(--color-bg-subtle)',
                    whiteSpace: 'pre-wrap',
                  }}
                >
                  {note}
                </p>
              ) : null}
            </div>
          </li>
        )
      })}
    </ol>
  )
}

function DecisionModal({
  request,
  decision,
  onClose,
  onDecided,
}: {
  request: ContractRequest
  decision: RequestDecision
  onClose: () => void
  onDecided: (request: ContractRequest) => void
}) {
  const toast = useToast()
  const [notes, setNotes] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    // The API refuses a rejection with no reason, and a requester who is told
    // "no" with no words simply raises the request again.
    if (decision === 'reject' && notes.trim() === '') {
      setErrors({ notes: 'Say why the request is being rejected.' })
      return
    }

    setBusy(true)
    setErrors({})
    try {
      const updated = await api.post<ContractRequest>(`/requests/${request.id}/decision`, {
        decision,
        notes: notes.trim() || null,
      })
      onDecided(updated)
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('The decision was not recorded', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={DECISION_LABEL[decision]}
      description={DECISION_DESCRIPTION[decision]}
      width={520}
      closeOnBackdrop={!busy}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant={decision === 'reject' ? 'danger' : 'primary'} loading={busy} onClick={() => void submit()}>
            {DECISION_LABEL[decision]}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
          {request.request_number} · {request.title}
        </p>
        <Textarea
          label={decision === 'reject' ? 'Reason' : 'Note to the requester'}
          required={decision === 'reject'}
          rows={4}
          value={notes}
          error={errors.notes}
          onChange={(event) => {
            setNotes(event.target.value)
            setErrors((current) => (current.notes ? { ...current, notes: '' } : current))
          }}
          hint={
            decision === 'more_info'
              ? 'Name what is missing. The requester sees this on their copy of the request.'
              : undefined
          }
        />
      </div>
    </Modal>
  )
}

function ConvertModal({
  request,
  onClose,
  onConverted,
}: {
  request: ContractRequest
  onClose: () => void
  onConverted: (contract: Contract) => void
}) {
  const toast = useToast()
  const [title, setTitle] = useState(request.title)
  const [typeId, setTypeId] = useState(
    request.contract_type_id ? String(request.contract_type_id) : '',
  )
  const [errors, setErrors] = useState<FieldErrors>({})
  const [busy, setBusy] = useState(false)

  const types = useApiResource<ContractTypeSummary[]>(
    (signal) =>
      api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal).catch(() => []),
    [],
  )

  const submit = async () => {
    if (title.trim() === '') {
      setErrors({ title: 'The contract needs a title.' })
      return
    }

    setBusy(true)
    setErrors({})
    try {
      const result = await api.post<{ contract: Contract }>(`/requests/${request.id}/convert`, {
        title: title.trim(),
        contract_type_id: typeId ? Number(typeId) : null,
      })
      onConverted(result.contract)
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not create the contract', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Convert to a contract"
      description="A draft contract is created from this request. The request stays as the record of who asked for it and why."
      width={520}
      closeOnBackdrop={!busy}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            Create the contract
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        <Input
          label="Contract title"
          required
          value={title}
          error={errors.title}
          onChange={(event) => {
            setTitle(event.target.value)
            setErrors((current) => (current.title ? { ...current, title: '' } : current))
          }}
        />
        <Select
          label="Contract type"
          value={typeId}
          error={errors.contract_type_id}
          onChange={(event) => setTypeId(event.target.value)}
          options={(types.data ?? []).map((type) => ({ value: String(type.id), label: type.name }))}
          placeholder="Decide later"
          hint={types.loading ? 'Loading the configured types…' : undefined}
        />
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <Chip tone="info">Counterparty: {request.counterparty_name ?? 'none recorded'}</Chip>
          <Chip tone="info">
            Value: {formatMoney(request.estimated_value, request.currency || 'INR', { compact: true })}
          </Chip>
        </div>
        <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
          The counterparty, department, currency and estimated value carry across, and you are taken
          straight to the new draft to fill in the rest.
        </p>
      </div>
    </Modal>
  )
}

function NotFound() {
  return (
    <EmptyState
      icon={<FileSignature size={22} />}
      title="That request is not here"
      description="It may have been removed, or it belongs to a company you are not signed in to."
      action={
        <Link to="/requests">
          <Button variant="secondary" icon={<ArrowLeft size={14} />}>
            Back to requests
          </Button>
        </Link>
      }
    />
  )
}
