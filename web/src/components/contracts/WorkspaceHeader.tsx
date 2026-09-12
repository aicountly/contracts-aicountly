import { useEffect, useId, useRef, useState } from 'react'
import type { ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import {
  Archive,
  ArchiveRestore,
  Ban,
  ChevronLeft,
  FilePlus2,
  FileSignature,
  MoreHorizontal,
  Pencil,
  RefreshCw,
  Send,
  Star,
  Upload,
} from 'lucide-react'

import { Button, Chip, ConfirmDialog, DateInput, Modal, RiskChip, Select, Textarea } from '../ui'
import { ContractStatusBadge } from './ContractStatusBadge'
import { useSession } from '../../context/SessionProvider'
import { useToast } from '../../context/ToastProvider'
import { ApiError, api } from '../../services/apiClient'
import type { FieldErrors } from '../../services/apiClient'
import { PERMISSION } from '../../types/permissions'
import type { Contract } from '../../types/contracts'
import { formatDate, formatMoney, humanise, truncate } from '../../utils/format'

/**
 * The identity and the controls of a contract workspace.
 *
 * Which actions appear is decided by two things at once: what the user may do,
 * and what the contract's current status allows. Both matter — showing "send
 * for signature" on a draft that has not been approved teaches people to click
 * buttons that fail, and showing it to someone without the permission produces
 * a 403 they cannot act on. The server enforces the same pair; this is about
 * not offering the dead end.
 *
 * One action is promoted to a button and the rest live in a menu, because a row
 * of eight equally weighted buttons is a row nobody reads.
 */

interface Props {
  contract: Contract
  onChanged: () => void
  onOpenTab: (tabId: string) => void
  /** Sends the workspace to the Document tab with the uploader open. */
  onUploadExecuted: () => void
}

interface WorkspaceAction {
  id: string
  label: string
  icon: ReactNode
  run: () => void
}

const TERMINATION_TYPES = [
  { value: 'convenience', label: 'For convenience' },
  { value: 'cause', label: 'For cause' },
  { value: 'breach', label: 'For breach' },
  { value: 'mutual', label: 'By mutual agreement' },
  { value: 'non_renewal', label: 'Notice of non-renewal' },
]

const SETTLED = new Set(['expired', 'terminated', 'cancelled'])

export function WorkspaceHeader({ contract, onChanged, onOpenTab, onUploadExecuted }: Props) {
  const navigate = useNavigate()
  const toast = useToast()
  const { can } = useSession()

  const [favourite, setFavourite] = useState(contract.is_favourite)
  const [busy, setBusy] = useState<string | null>(null)
  const [confirming, setConfirming] = useState<'approval' | 'archive' | 'restore' | null>(null)
  const [signatureOpen, setSignatureOpen] = useState(false)
  const [signatureNote, setSignatureNote] = useState('')
  const [terminating, setTerminating] = useState(false)
  const [termination, setTermination] = useState({
    termination_type: 'convenience',
    effective_date: '',
    notice_date: '',
    reason: '',
  })
  const [errors, setErrors] = useState<FieldErrors>({})

  useEffect(() => {
    setFavourite(contract.is_favourite)
  }, [contract.is_favourite])

  const status = contract.status
  const archived = contract.archived_at !== null
  const settled = SETTLED.has(status)

  const canEdit = can(PERMISSION.CONTRACT_EDIT) && !archived && !settled
  const canSubmitForApproval =
    can(PERMISSION.CONTRACT_EDIT) &&
    !archived &&
    ['draft', 'under_review', 'negotiation'].includes(status) &&
    contract.approval_status !== 'pending' &&
    contract.approval_status !== 'in_progress'
  const canSendForSignature =
    can(PERMISSION.SIGNATURE_ACT) &&
    !archived &&
    ['approved', 'negotiation', 'under_review'].includes(status) &&
    contract.signing_status !== 'signed'
  const canUploadExecuted =
    can(PERMISSION.DOCUMENT_UPLOAD) &&
    !archived &&
    ['awaiting_signature', 'approved', 'active'].includes(status)
  const canStartRenewal =
    can(PERMISSION.RENEWAL_MANAGE) && !archived && ['active', 'renewal_review', 'expired'].includes(status)
  const canAmend =
    can(PERMISSION.AMENDMENT_MANAGE) && !archived && ['active', 'renewal_review'].includes(status)
  const canTerminate =
    can(PERMISSION.CONTRACT_TERMINATE) &&
    !archived &&
    ['active', 'renewal_review', 'awaiting_signature'].includes(status)
  const canArchive = can(PERMISSION.CONTRACT_ARCHIVE)

  const toggleFavourite = async () => {
    const next = !favourite
    // Optimistic: a star costs nothing to put back if the write fails.
    setFavourite(next)
    try {
      await api.post<{ favourite: boolean }>(`/contracts/${contract.id}/favourite`, { favourite: next })
    } catch (err) {
      setFavourite(!next)
      toast.error('Could not update the star', err instanceof Error ? err.message : undefined)
    }
  }

  const submitForApproval = async () => {
    setBusy('approval')
    try {
      await api.post('/approvals/submit', { subject_type: 'contract', subject_id: contract.id })
      toast.success('Sent for approval', 'The first approver has been notified.')
      setConfirming(null)
      onChanged()
      onOpenTab('approvals')
    } catch (err) {
      // A 409 here is a business rule — no workflow configured, or one already
      // running — and the server's sentence says which.
      toast.error('Could not submit for approval', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(null)
    }
  }

  const sendForSignature = async () => {
    setBusy('signature')
    setErrors({})
    try {
      await api.post<Contract>(`/contracts/${contract.id}/status`, {
        status: 'awaiting_signature',
        note: signatureNote.trim() || null,
      })
      toast.success('Ready for signature', 'The contract is now awaiting signature.')
      setSignatureOpen(false)
      setSignatureNote('')
      onChanged()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not move to signature', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(null)
    }
  }

  const startRenewal = async () => {
    setBusy('renewal')
    try {
      await api.post(`/contracts/${contract.id}/renewals/ensure`)
      toast.success('Renewal opened', 'The renewal decision is now tracked on this contract.')
      onChanged()
      onOpenTab('renewal')
    } catch (err) {
      toast.error('Could not start a renewal', err instanceof Error ? err.message : undefined)
    } finally {
      setBusy(null)
    }
  }

  const recordTermination = async () => {
    setBusy('terminate')
    setErrors({})
    try {
      await api.post(`/contracts/${contract.id}/terminations`, {
        termination_type: termination.termination_type,
        effective_date: termination.effective_date || null,
        notice_date: termination.notice_date || null,
        reason: termination.reason.trim() || null,
      })
      toast.success('Termination recorded', 'It still has to be approved and noticed before it takes effect.')
      setTerminating(false)
      setTermination({ termination_type: 'convenience', effective_date: '', notice_date: '', reason: '' })
      onChanged()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not record the termination', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setBusy(null)
    }
  }

  const setArchived = async (next: boolean) => {
    setBusy('archive')
    try {
      await api.post(`/contracts/${contract.id}/archive`, { archived: next })
      toast.success(next ? 'Contract archived' : 'Contract restored')
      setConfirming(null)
      onChanged()
    } catch (err) {
      toast.error(
        next ? 'Could not archive the contract' : 'Could not restore the contract',
        err instanceof Error ? err.message : undefined,
      )
    } finally {
      setBusy(null)
    }
  }

  const actions: WorkspaceAction[] = []

  if (canSubmitForApproval) {
    actions.push({
      id: 'approval',
      label: 'Submit for approval',
      icon: <Send size={14} />,
      run: () => setConfirming('approval'),
    })
  }
  if (canSendForSignature) {
    actions.push({
      id: 'signature',
      label: 'Send for signature',
      icon: <FileSignature size={14} />,
      run: () => setSignatureOpen(true),
    })
  }
  if (canUploadExecuted) {
    actions.push({
      id: 'executed',
      label: 'Upload executed copy',
      icon: <Upload size={14} />,
      run: onUploadExecuted,
    })
  }
  if (canStartRenewal) {
    actions.push({
      id: 'renewal',
      label: 'Start renewal',
      icon: <RefreshCw size={14} />,
      run: () => void startRenewal(),
    })
  }
  if (canAmend) {
    actions.push({
      id: 'amend',
      label: 'Amend',
      icon: <FilePlus2 size={14} />,
      run: () => onOpenTab('amendments'),
    })
  }
  if (canTerminate) {
    actions.push({
      id: 'terminate',
      label: 'Terminate',
      icon: <Ban size={14} />,
      run: () => setTerminating(true),
    })
  }
  if (canArchive) {
    actions.push(
      archived
        ? {
            id: 'restore',
            label: 'Restore from archive',
            icon: <ArchiveRestore size={14} />,
            run: () => setConfirming('restore'),
          }
        : {
            id: 'archive',
            label: 'Archive',
            icon: <Archive size={14} />,
            run: () => setConfirming('archive'),
          },
    )
  }

  const [primary, ...rest] = actions

  return (
    <header style={{ marginBottom: 16 }}>
      <style>{`
        .ct-wsh-meta { display: grid; gap: 10px 22px; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); margin-top: 14px; padding-top: 14px; border-top: 1px solid rgb(var(--color-border)); }
      `}</style>

      <Link
        to="/contracts"
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: 4,
          fontSize: 12.5,
          fontWeight: 600,
          color: 'var(--color-text-secondary)',
          marginBottom: 8,
        }}
      >
        <ChevronLeft size={14} aria-hidden />
        Contract repository
      </Link>

      <div style={{ display: 'flex', gap: 14, alignItems: 'flex-start', flexWrap: 'wrap' }}>
        <div style={{ minWidth: 0, flex: '1 1 380px' }}>
          <h1
            style={{
              fontSize: 21,
              fontWeight: 800,
              letterSpacing: '-.01em',
              color: 'var(--color-text)',
              lineHeight: 1.3,
            }}
          >
            {contract.title}
          </h1>

          <p style={{ fontSize: 13, color: 'var(--color-text-secondary)', marginTop: 4 }}>
            <span style={{ fontWeight: 700 }}>{contract.contract_number || 'Not numbered yet'}</span>
            {contract.counterparty_name ? ` · ${contract.counterparty_name}` : ''}
            {contract.contract_type_name ? ` · ${contract.contract_type_name}` : ''}
          </p>

          <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', alignItems: 'center', marginTop: 10 }}>
            <ContractStatusBadge
              status={status}
              archivedAt={contract.archived_at}
              daysToExpiry={contract.days_to_expiry}
            />
            <RiskChip level={contract.risk_level} score={contract.ai_risk_score} />
            {contract.approval_status && contract.approval_status !== 'not_required' ? (
              <Chip tone={contract.approval_status === 'rejected' ? 'danger' : 'neutral'}>
                Approval: {humanise(contract.approval_status)}
              </Chip>
            ) : null}
            {contract.signing_status && contract.signing_status !== 'not_started' ? (
              <Chip tone={contract.signing_status === 'signed' ? 'success' : 'neutral'}>
                Signature: {humanise(contract.signing_status)}
              </Chip>
            ) : null}
          </div>
        </div>

        <div
          className="ct-no-print"
          style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}
        >
          <Button
            variant="ghost"
            aria-label={favourite ? `Unstar ${contract.title}` : `Star ${contract.title}`}
            aria-pressed={favourite}
            onClick={() => void toggleFavourite()}
            icon={
              <Star
                size={16}
                fill={favourite ? 'currentColor' : 'none'}
                style={{ color: favourite ? 'var(--color-warning)' : undefined }}
              />
            }
          />

          {canEdit ? (
            <Button
              variant="secondary"
              icon={<Pencil size={14} />}
              onClick={() => navigate(`/contracts/${contract.id}/edit`)}
            >
              Edit
            </Button>
          ) : null}

          {primary ? (
            <Button
              variant="primary"
              icon={primary.icon}
              loading={busy === primary.id}
              onClick={primary.run}
            >
              {primary.label}
            </Button>
          ) : null}

          {rest.length > 0 ? <ActionMenu actions={rest} busy={busy} /> : null}
        </div>
      </div>

      <dl className="ct-wsh-meta">
        <Meta label="Owner" value={contract.owner_uuid ? truncate(contract.owner_uuid, 16) : 'Unassigned'} title={contract.owner_uuid ?? undefined} />
        <Meta label="Effective" value={formatDate(contract.effective_date)} />
        <Meta label="Expiry" value={formatDate(contract.expiry_date)} />
        <Meta label="Notice by" value={formatDate(contract.notice_deadline)} />
        {can(PERMISSION.COMMERCIALS_VIEW) ? (
          <Meta
            label="Total value"
            value={formatMoney(contract.total_value, contract.currency || 'INR')}
          />
        ) : null}
        <Meta label="Department" value={contract.department_name ?? '—'} />
      </dl>

      <ConfirmDialog
        open={confirming === 'approval'}
        busy={busy === 'approval'}
        title="Submit for approval"
        confirmLabel="Submit"
        message="The approval workflow for this contract type will start and the first approver will be notified. You can still edit the contract while it is with them, but they will see what you change."
        onClose={() => setConfirming(null)}
        onConfirm={() => void submitForApproval()}
      />

      <ConfirmDialog
        open={confirming === 'archive'}
        busy={busy === 'archive'}
        title="Archive this contract"
        confirmLabel="Archive"
        message="It will be hidden from the default repository view. Nothing is deleted, obligations already recorded stay in place, and you can restore it at any time."
        onClose={() => setConfirming(null)}
        onConfirm={() => void setArchived(true)}
      />

      <ConfirmDialog
        open={confirming === 'restore'}
        busy={busy === 'archive'}
        title="Restore this contract"
        confirmLabel="Restore"
        message="The contract returns to the active repository and starts appearing in lists and reports again."
        onClose={() => setConfirming(null)}
        onConfirm={() => void setArchived(false)}
      />

      <Modal
        open={signatureOpen}
        onClose={() => setSignatureOpen(false)}
        title="Send for signature"
        description="The contract moves to awaiting signature. Signers are managed from the Document tab."
        width={480}
        footer={
          <>
            <Button variant="secondary" onClick={() => setSignatureOpen(false)} disabled={busy === 'signature'}>
              Cancel
            </Button>
            <Button variant="primary" loading={busy === 'signature'} onClick={() => void sendForSignature()}>
              Send for signature
            </Button>
          </>
        }
      >
        <Textarea
          label="Note"
          rows={3}
          value={signatureNote}
          error={errors.note}
          hint="Recorded on the contract's activity, so the next person knows what was sent and to whom."
          onChange={(event) => setSignatureNote(event.target.value)}
        />
      </Modal>

      <Modal
        open={terminating}
        onClose={() => setTerminating(false)}
        title="Terminate this contract"
        description="Records a termination against the contract. It takes effect once it has been approved and notice has been served."
        width={560}
        footer={
          <>
            <Button variant="secondary" onClick={() => setTerminating(false)} disabled={busy === 'terminate'}>
              Cancel
            </Button>
            <Button variant="danger" loading={busy === 'terminate'} onClick={() => void recordTermination()}>
              Record termination
            </Button>
          </>
        }
      >
        <div style={{ display: 'grid', gap: 14 }}>
          <Select
            label="Grounds"
            required
            value={termination.termination_type}
            error={errors.termination_type}
            onChange={(event) => setTermination({ ...termination, termination_type: event.target.value })}
            options={TERMINATION_TYPES}
          />
          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
            <DateInput
              label="Notice date"
              value={termination.notice_date}
              error={errors.notice_date}
              hint="When notice is or was served."
              onChange={(event) => setTermination({ ...termination, notice_date: event.target.value })}
            />
            <DateInput
              label="Effective date"
              required
              value={termination.effective_date}
              error={errors.effective_date}
              hint="When the contract ends."
              onChange={(event) => setTermination({ ...termination, effective_date: event.target.value })}
            />
          </div>
          <Textarea
            label="Reason"
            rows={3}
            required
            value={termination.reason}
            error={errors.reason}
            hint="The grounds as they will be stated in the notice."
            onChange={(event) => setTermination({ ...termination, reason: event.target.value })}
          />
        </div>
      </Modal>
    </header>
  )
}

/**
 * The actions that did not fit on the bar.
 *
 * A real `role="menu"` with Escape returning focus to the trigger: this is the
 * only route to terminate and archive, and a menu a keyboard user cannot leave
 * is a menu they cannot use.
 */
function ActionMenu({ actions, busy }: { actions: WorkspaceAction[]; busy: string | null }) {
  const [open, setOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const menuId = useId()

  useEffect(() => {
    if (!open) return

    const onPointerDown = (event: MouseEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false)
    }
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setOpen(false)
      containerRef.current?.querySelector('button')?.focus()
    }

    document.addEventListener('mousedown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('mousedown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  return (
    <div ref={containerRef} style={{ position: 'relative' }}>
      <Button
        variant="secondary"
        aria-label="More actions"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={menuId}
        icon={<MoreHorizontal size={16} />}
        onClick={() => setOpen((current) => !current)}
      />

      {open ? (
        <div
          id={menuId}
          role="menu"
          aria-label="More actions"
          style={{
            position: 'absolute',
            zIndex: 25,
            top: 'calc(100% + 6px)',
            right: 0,
            minWidth: 232,
            padding: 6,
            background: 'var(--color-bg-card)',
            border: '1px solid rgb(var(--color-border))',
            borderRadius: 'var(--radius-md)',
            boxShadow: 'var(--shadow-lg)',
          }}
        >
          {actions.map((action) => (
            <button
              key={action.id}
              type="button"
              role="menuitem"
              disabled={busy === action.id}
              onClick={() => {
                setOpen(false)
                action.run()
              }}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 9,
                width: '100%',
                padding: '8px 9px',
                background: 'none',
                border: 'none',
                borderRadius: 'var(--radius-sm)',
                cursor: busy === action.id ? 'wait' : 'pointer',
                fontSize: 13,
                fontWeight: 600,
                color:
                  action.id === 'terminate' ? 'var(--color-danger)' : 'var(--color-text)',
                textAlign: 'left',
              }}
            >
              <span style={{ lineHeight: 0, color: 'var(--color-text-muted)' }} aria-hidden>
                {action.icon}
              </span>
              {action.label}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}

function Meta({ label, value, title }: { label: string; value: string; title?: string }) {
  return (
    <div style={{ minWidth: 0 }}>
      <dt
        style={{
          fontSize: 10.5,
          fontWeight: 700,
          textTransform: 'uppercase',
          letterSpacing: '.04em',
          color: 'var(--color-text-muted)',
        }}
      >
        {label}
      </dt>
      <dd
        title={title}
        style={{
          fontSize: 13,
          fontWeight: 600,
          color: 'var(--color-text)',
          marginTop: 2,
          overflow: 'hidden',
          textOverflow: 'ellipsis',
          whiteSpace: 'nowrap',
        }}
      >
        {value}
      </dd>
    </div>
  )
}
