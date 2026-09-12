import { useMemo, useState } from 'react'
import {
  Check,
  CircleUser,
  History,
  MessageSquare,
  Send,
  ShieldCheck,
  Undo2,
  UserRoundPlus,
  X,
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
  Skeleton,
  StatusChip,
  Textarea,
} from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract } from '../../../types/contracts'
import { formatDateTime, humanise } from '../../../utils/format'

/**
 * Who has to approve this contract, what they did, and what the viewer can do
 * about it right now.
 *
 * The step is the gate, not the workflow: someone assigned to step three sees
 * no buttons while step one is still open, because the server will refuse the
 * action and an approver who is shown a button that 403s stops trusting the
 * screen.
 */

type ApprovalAction =
  | 'approve'
  | 'reject'
  | 'send_back'
  | 'request_changes'
  | 'comment'
  | 'reassign'
  | 'escalate'
  | 'cancel'

interface ApprovalAssignment {
  id: number
  step_no: number
  step_name: string | null
  approver_uuid: string
  delegated_from: string | null
  status: 'pending' | 'approved' | 'rejected' | 'sent_back' | 'reassigned' | 'skipped'
  assigned_at: string
  acted_at: string | null
  due_at: string | null
  escalated_at: string | null
  comment: string | null
}

interface ApprovalActionEntry {
  id: number
  assignment_id: number | null
  step_no: number
  actor_uuid: string
  action: ApprovalAction
  comment: string | null
  reassigned_to: string | null
  created_at: string
}

interface ApprovalStepSnapshot {
  step_no: number
  name?: string | null
  execution?: 'sequential' | 'parallel'
  approver_type?: string
  approver_value?: string | null
  min_approvals?: number
}

interface ApprovalInstance {
  id: number
  subject_type: string
  subject_id: number
  contract_id: number | null
  workflow_id: number | null
  workflow_name: string | null
  status: 'pending' | 'in_progress' | 'approved' | 'rejected' | 'sent_back' | 'cancelled'
  current_step: number
  steps_snapshot: ApprovalStepSnapshot[]
  submitted_by: string | null
  submitted_at: string
  completed_at: string | null
  outcome_note: string | null
  assignments: ApprovalAssignment[]
  actions: ApprovalActionEntry[]
  is_open: boolean
}

const ACTION_LABEL: Record<ApprovalAction, string> = {
  approve: 'Approved',
  reject: 'Rejected',
  send_back: 'Sent back',
  request_changes: 'Requested changes',
  comment: 'Commented',
  reassign: 'Reassigned',
  escalate: 'Escalated',
  cancel: 'Cancelled',
}

const ACTION_TONE: Record<ApprovalAction, 'success' | 'danger' | 'warning' | 'info' | 'neutral'> = {
  approve: 'success',
  reject: 'danger',
  send_back: 'warning',
  request_changes: 'warning',
  comment: 'neutral',
  reassign: 'info',
  escalate: 'warning',
  cancel: 'neutral',
}

/** A uuid is not a name; showing the whole one makes every row unreadable. */
function personLabel(uuid: string | null | undefined, meUuid: string | null): string {
  if (!uuid) return 'Unassigned'
  if (meUuid && uuid === meUuid) return 'You'
  return uuid.length > 12 ? `${uuid.slice(0, 8)}…` : uuid
}

export function ApprovalsTab({
  contractId,
  contract,
  onChanged,
}: {
  contractId: number
  contract: Contract
  onChanged: () => void
}) {
  const { can, canAny, session } = useSession()
  const toast = useToast()
  const meUuid = session?.uuid ?? null
  const canAct = can(PERMISSION.APPROVAL_ACT)
  const canSubmit = canAny([PERMISSION.APPROVAL_ACT, PERMISSION.CONTRACT_EDIT])

  const resource = useApiResource<ApprovalInstance[]>(
    (signal) =>
      api.get<ApprovalInstance[]>(
        '/approvals/instances',
        { subject_type: 'contract', subject_id: contractId },
        signal,
      ),
    [contractId],
  )

  const [pendingAction, setPendingAction] = useState<{ instance: ApprovalInstance; action: ApprovalAction } | null>(null)
  const [cancelling, setCancelling] = useState<ApprovalInstance | null>(null)
  const [submitting, setSubmitting] = useState(false)

  const instances = resource.data ?? []
  const current = useMemo(
    () => instances.find((instance) => instance.is_open) ?? instances[0] ?? null,
    [instances],
  )
  const history = instances.filter((instance) => instance.id !== current?.id)

  const myPendingAssignment = current
    ? (current.assignments.find(
        (assignment) =>
          assignment.approver_uuid === meUuid &&
          assignment.status === 'pending' &&
          assignment.step_no === current.current_step,
      ) ?? null)
    : null

  const refresh = () => {
    resource.reload()
    onChanged()
  }

  const submit = async () => {
    setSubmitting(true)
    try {
      await api.post('/approvals/submit', { subject_type: 'contract', subject_id: contractId })
      toast.success('Sent for approval', contract.title)
      refresh()
    } catch (err) {
      toast.error('Could not send it for approval', err instanceof Error ? err.message : undefined)
    } finally {
      setSubmitting(false)
    }
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 16 }}>
        <Card>
          <Skeleton width="40%" height={14} />
          <div style={{ marginTop: 16, display: 'grid', gap: 12 }}>
            {[0, 1, 2].map((row) => (
              <Skeleton key={row} height={46} radius={10} />
            ))}
          </div>
        </Card>
      </div>
    )
  }

  if (resource.error) {
    return <ErrorState title="Could not load approvals" detail={resource.error.message} onRetry={resource.reload} />
  }

  if (!current) {
    return (
      <EmptyState
        icon={<ShieldCheck size={22} />}
        title="Not submitted for approval"
        description="When this contract is sent for approval, the routing rules pick the workflow and this tab shows every step, who it is waiting on, and what each approver said."
        action={
          canSubmit ? (
            <Button variant="primary" icon={<Send size={15} />} loading={submitting} onClick={() => void submit()}>
              Send for approval
            </Button>
          ) : undefined
        }
      />
    )
  }

  const canCancel =
    current.is_open && (current.submitted_by === meUuid || can(PERMISSION.WORKFLOW_MANAGE))

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Card>
        <CardHeader
          level={3}
          title={current.workflow_name?.trim() || 'Approval'}
          description={`Submitted ${formatDateTime(current.submitted_at)} by ${personLabel(current.submitted_by, meUuid)}`}
          action={<StatusChip status={current.status} />}
        />

        <p aria-live="polite" style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginBottom: 14 }}>
          {current.is_open
            ? `Waiting on step ${current.current_step}${
                myPendingAssignment ? ' — your decision' : ''
              }`
            : `Closed ${current.completed_at ? formatDateTime(current.completed_at) : ''}`}
          {current.outcome_note ? ` · ${current.outcome_note}` : ''}
        </p>

        <StepList instance={current} meUuid={meUuid} />

        {myPendingAssignment && canAct ? (
          <div
            style={{
              marginTop: 16,
              padding: 14,
              borderRadius: 'var(--radius-md)',
              border: '1px solid var(--color-primary-border)',
              background: 'var(--color-primary-muted)',
            }}
          >
            <p style={{ fontSize: 13, fontWeight: 700, marginBottom: 10 }}>
              This step is assigned to you
            </p>
            <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
              <Button
                variant="primary"
                size="sm"
                icon={<Check size={14} />}
                onClick={() => setPendingAction({ instance: current, action: 'approve' })}
              >
                Approve
              </Button>
              <Button
                variant="danger"
                size="sm"
                icon={<X size={14} />}
                onClick={() => setPendingAction({ instance: current, action: 'reject' })}
              >
                Reject
              </Button>
              <Button
                variant="secondary"
                size="sm"
                icon={<Undo2 size={14} />}
                onClick={() => setPendingAction({ instance: current, action: 'send_back' })}
              >
                Send back
              </Button>
              <Button
                variant="secondary"
                size="sm"
                onClick={() => setPendingAction({ instance: current, action: 'request_changes' })}
              >
                Request changes
              </Button>
              <Button
                variant="secondary"
                size="sm"
                icon={<MessageSquare size={14} />}
                onClick={() => setPendingAction({ instance: current, action: 'comment' })}
              >
                Comment
              </Button>
              <Button
                variant="secondary"
                size="sm"
                icon={<UserRoundPlus size={14} />}
                onClick={() => setPendingAction({ instance: current, action: 'reassign' })}
              >
                Reassign
              </Button>
            </div>
          </div>
        ) : null}

        {canCancel ? (
          <div style={{ marginTop: 14 }}>
            <Button size="sm" variant="ghost" onClick={() => setCancelling(current)}>
              Cancel this approval
            </Button>
          </div>
        ) : null}
      </Card>

      <Card>
        <CardHeader level={3} title="What has happened" description="Every action taken on this approval, in order." />
        {current.actions.length === 0 ? (
          <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
            Nothing has been actioned yet — the first approver has not responded.
          </p>
        ) : (
          <ol style={{ listStyle: 'none', display: 'grid', gap: 12 }}>
            {current.actions.map((entry) => (
              <li key={entry.id} style={{ display: 'flex', gap: 11 }}>
                <span
                  aria-hidden
                  style={{
                    width: 28,
                    height: 28,
                    borderRadius: '50%',
                    background: 'var(--color-bg-subtle)',
                    display: 'inline-flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    color: 'var(--color-text-muted)',
                    flexShrink: 0,
                  }}
                >
                  <CircleUser size={15} />
                </span>
                <div style={{ minWidth: 0 }}>
                  <p style={{ fontSize: 13, display: 'flex', gap: 7, alignItems: 'center', flexWrap: 'wrap' }}>
                    <strong>{personLabel(entry.actor_uuid, meUuid)}</strong>
                    <Chip tone={ACTION_TONE[entry.action]} size="sm">
                      {ACTION_LABEL[entry.action] ?? humanise(entry.action)}
                    </Chip>
                    <span style={{ color: 'var(--color-text-muted)', fontSize: 12 }}>
                      step {entry.step_no} · {formatDateTime(entry.created_at)}
                    </span>
                  </p>
                  {entry.reassigned_to ? (
                    <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3 }}>
                      Reassigned to {personLabel(entry.reassigned_to, meUuid)}
                    </p>
                  ) : null}
                  {entry.comment ? (
                    <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 4, lineHeight: 1.6 }}>
                      {entry.comment}
                    </p>
                  ) : null}
                </div>
              </li>
            ))}
          </ol>
        )}
      </Card>

      {history.length > 0 ? (
        <Card>
          <CardHeader
            level={3}
            title="Earlier approval runs"
            description="A contract sent back and resubmitted starts a new run; the earlier ones are kept."
          />
          <ul style={{ listStyle: 'none', display: 'grid', gap: 10 }}>
            {history.map((instance) => (
              <li
                key={instance.id}
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  gap: 12,
                  alignItems: 'center',
                  flexWrap: 'wrap',
                  padding: '10px 12px',
                  border: '1px solid rgb(var(--color-border))',
                  borderRadius: 'var(--radius-md)',
                }}
              >
                <div>
                  <p style={{ fontSize: 13, fontWeight: 600 }}>
                    <History size={12} aria-hidden style={{ marginRight: 6, verticalAlign: -1 }} />
                    {instance.workflow_name?.trim() || 'Approval'}
                  </p>
                  <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
                    {formatDateTime(instance.submitted_at)} →{' '}
                    {instance.completed_at ? formatDateTime(instance.completed_at) : 'not completed'}
                  </p>
                </div>
                <StatusChip status={instance.status} size="sm" />
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      {pendingAction ? (
        <ActionModal
          instance={pendingAction.instance}
          action={pendingAction.action}
          onClose={() => setPendingAction(null)}
          onDone={() => {
            setPendingAction(null)
            refresh()
          }}
        />
      ) : null}

      {cancelling ? (
        <CancelModal
          instance={cancelling}
          onClose={() => setCancelling(null)}
          onDone={() => {
            setCancelling(null)
            refresh()
          }}
        />
      ) : null}
    </div>
  )
}

function StepList({ instance, meUuid }: { instance: ApprovalInstance; meUuid: string | null }) {
  const steps = useMemo(() => {
    const numbers = new Set<number>()
    for (const step of instance.steps_snapshot) numbers.add(Number(step.step_no))
    for (const assignment of instance.assignments) numbers.add(assignment.step_no)

    return [...numbers]
      .sort((a, b) => a - b)
      .map((stepNo) => ({
        stepNo,
        snapshot: instance.steps_snapshot.find((step) => Number(step.step_no) === stepNo) ?? null,
        assignments: instance.assignments.filter((assignment) => assignment.step_no === stepNo),
      }))
  }, [instance])

  return (
    <ol style={{ listStyle: 'none', display: 'grid', gap: 10 }}>
      {steps.map(({ stepNo, snapshot, assignments }) => {
        const isCurrent = instance.is_open && stepNo === instance.current_step
        const settled = assignments.length > 0 && assignments.every((a) => a.status !== 'pending')

        return (
          <li
            key={stepNo}
            style={{
              padding: 13,
              borderRadius: 'var(--radius-md)',
              border: `1px solid ${isCurrent ? 'var(--color-primary-border)' : 'rgb(var(--color-border))'}`,
              background: isCurrent ? 'var(--color-primary-muted)' : 'var(--color-bg-card)',
            }}
          >
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
              <p style={{ fontSize: 13.5, fontWeight: 700 }}>
                <span style={{ color: 'var(--color-text-muted)', marginRight: 7 }}>Step {stepNo}</span>
                {snapshot?.name?.trim() || assignments[0]?.step_name || 'Approval step'}
              </p>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                {snapshot?.execution === 'parallel' ? (
                  <Chip tone="info" size="sm">
                    Parallel · {snapshot.min_approvals ?? 1} of {assignments.length || 1} must approve
                  </Chip>
                ) : null}
                <Chip tone={isCurrent ? 'primary' : settled ? 'success' : 'neutral'} size="sm">
                  {isCurrent ? 'Waiting on this step' : settled ? 'Done' : 'Not started'}
                </Chip>
              </div>
            </div>

            {assignments.length === 0 ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)', marginTop: 8 }}>
                Approvers are chosen when this step opens
                {snapshot?.approver_type ? ` (${humanise(snapshot.approver_type).toLowerCase()})` : ''}.
              </p>
            ) : (
              <ul style={{ listStyle: 'none', display: 'grid', gap: 7, marginTop: 10 }}>
                {assignments.map((assignment) => (
                  <li
                    key={assignment.id}
                    style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}
                  >
                    <span style={{ fontSize: 12.5, display: 'flex', alignItems: 'center', gap: 6 }}>
                      <CircleUser size={13} aria-hidden style={{ color: 'var(--color-text-muted)' }} />
                      <span title={assignment.approver_uuid}>{personLabel(assignment.approver_uuid, meUuid)}</span>
                      {assignment.delegated_from ? (
                        <span style={{ color: 'var(--color-text-muted)' }}>
                          (from {personLabel(assignment.delegated_from, meUuid)})
                        </span>
                      ) : null}
                    </span>
                    <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      {assignment.acted_at ? (
                        <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                          {formatDateTime(assignment.acted_at)}
                        </span>
                      ) : assignment.due_at ? (
                        <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                          due {formatDateTime(assignment.due_at)}
                        </span>
                      ) : null}
                      <StatusChip status={assignment.status} size="sm" />
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </li>
        )
      })}
    </ol>
  )
}

const ACTION_COPY: Record<ApprovalAction, { title: string; description: string; confirm: string; commentRequired: boolean }> = {
  approve: {
    title: 'Approve this contract',
    description: 'Your approval moves it to the next step, or completes the run if this is the last one.',
    confirm: 'Approve',
    commentRequired: false,
  },
  reject: {
    title: 'Reject this contract',
    description: 'Rejection closes the run. Say why — the drafter only sees what you write here.',
    confirm: 'Reject',
    commentRequired: true,
  },
  send_back: {
    title: 'Send this back',
    description: 'The run closes and the contract returns to the submitter to be corrected and resubmitted.',
    confirm: 'Send back',
    commentRequired: true,
  },
  request_changes: {
    title: 'Request changes',
    description: 'Name the changes you need before you can approve.',
    confirm: 'Request changes',
    commentRequired: true,
  },
  comment: {
    title: 'Add a comment',
    description: 'Recorded against this step without deciding anything.',
    confirm: 'Post comment',
    commentRequired: true,
  },
  reassign: {
    title: 'Reassign this step',
    description: 'Hand your assignment to someone else. The action is recorded against both of you.',
    confirm: 'Reassign',
    commentRequired: false,
  },
  escalate: { title: 'Escalate', description: '', confirm: 'Escalate', commentRequired: false },
  cancel: { title: 'Cancel', description: '', confirm: 'Cancel', commentRequired: false },
}

function ActionModal({
  instance,
  action,
  onClose,
  onDone,
}: {
  instance: ApprovalInstance
  action: ApprovalAction
  onClose: () => void
  onDone: () => void
}) {
  const toast = useToast()
  const copy = ACTION_COPY[action]
  const [comment, setComment] = useState('')
  const [toUuid, setToUuid] = useState('')
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const submit = async () => {
    if (copy.commentRequired && comment.trim() === '') {
      setErrors({ comment: 'Say why — this is what the other party reads.' })
      return
    }
    if (action === 'reassign' && toUuid.trim() === '') {
      setErrors({ to_uuid: 'Name the person taking this over.' })
      return
    }

    setBusy(true)
    setErrors({})
    try {
      await api.post(`/approvals/${instance.id}/act`, {
        action,
        comment: comment.trim() || null,
        // The endpoint is documented as `reassign_to` and the approval service
        // reads `to_uuid`; sending both means a reassignment works either way.
        ...(action === 'reassign' ? { to_uuid: toUuid.trim(), reassign_to: toUuid.trim() } : {}),
      })
      toast.success(`${ACTION_LABEL[action]}`)
      onDone()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('That action did not go through', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={copy.title}
      description={copy.description}
      width={520}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            variant={action === 'reject' ? 'danger' : 'primary'}
            loading={busy}
            onClick={() => void submit()}
          >
            {copy.confirm}
          </Button>
        </>
      }
    >
      <div style={{ display: 'grid', gap: 14 }}>
        {action === 'reassign' ? (
          <Input
            label="Reassign to (user id)"
            required
            value={toUuid}
            error={errors.to_uuid ?? errors.reassign_to}
            onChange={(event) => setToUuid(event.target.value)}
          />
        ) : null}
        <Textarea
          label="Comment"
          rows={4}
          required={copy.commentRequired}
          value={comment}
          error={errors.comment}
          onChange={(event) => setComment(event.target.value)}
        />
      </div>
    </Modal>
  )
}

function CancelModal({
  instance,
  onClose,
  onDone,
}: {
  instance: ApprovalInstance
  onClose: () => void
  onDone: () => void
}) {
  const toast = useToast()
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})

  const submit = async () => {
    if (reason.trim() === '') {
      setErrors({ reason: 'Say why this run is being abandoned.' })
      return
    }
    setBusy(true)
    setErrors({})
    try {
      await api.post(`/approvals/${instance.id}/cancel`, { reason: reason.trim() })
      toast.success('Approval cancelled')
      onDone()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not cancel the approval', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title="Cancel this approval"
      description="The run is abandoned. Nobody is asked to act on it again, and the contract stays where it is."
      width={480}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Keep it open
          </Button>
          <Button variant="danger" loading={busy} onClick={() => void submit()}>
            Cancel approval
          </Button>
        </>
      }
    >
      <Textarea
        label="Why"
        rows={3}
        required
        value={reason}
        error={errors.reason}
        onChange={(event) => setReason(event.target.value)}
      />
    </Modal>
  )
}
