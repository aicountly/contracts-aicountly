import { useMemo, useState } from 'react'
import type { ReactNode } from 'react'
import { ArrowLeft, Ban, BookOpen, Copy, History, Save, Scale, Trash2 } from 'lucide-react'

import {
  Button,
  Card,
  Checkbox,
  Chip,
  ConfirmDialog,
  DateInput,
  ErrorState,
  Input,
  RiskChip,
  Select,
  Skeleton,
  StatusChip,
  Textarea,
} from '../ui'
import { useSession } from '../../context/SessionProvider'
import { useToast } from '../../context/ToastProvider'
import { useApiResource } from '../../hooks/useApiResource'
import type { Resource } from '../../hooks/useApiResource'
import { ApiError, api } from '../../services/apiClient'
import { PERMISSION } from '../../types/permissions'
import {
  CLAUSE_APPROVAL_STATUSES,
  RISK_LEVELS,
  type ClauseApprovalStatus,
  type ClauseCategory,
  type ContractTypeSummary,
  type LibraryClauseInput,
  type LibraryClauseItem,
  type LibraryClauseVersion,
  type RiskLevel,
} from '../../types/contracts'
import { formatDate, formatDateTime, humanise } from '../../utils/format'

/**
 * One clause of the library: the wording the company wants, what it will accept
 * instead, and what it will not accept at all.
 *
 * The three texts are the point of the screen. A library that holds only the
 * preferred wording tells a negotiator nothing about the room they have, which
 * is the question they are actually asking at the table.
 */

function toNumberIds(values: (number | string)[] | null | undefined): number[] {
  if (!Array.isArray(values)) return []
  return values
    .map((value) => (typeof value === 'number' ? value : Number(value)))
    .filter((value) => Number.isInteger(value) && value > 0)
}

export function ClauseEditor({
  clause,
  categories,
  contractTypes,
  defaultCategoryId,
  onClose,
  onSaved,
  onDeleted,
}: {
  /** `null` starts a new clause; the row itself is passed in from the list. */
  clause: LibraryClauseItem | null
  categories: ClauseCategory[]
  contractTypes: ContractTypeSummary[]
  defaultCategoryId: number | null
  onClose: () => void
  onSaved: (clause: LibraryClauseItem) => void
  onDeleted: () => void
}) {
  const { can } = useSession()
  const toast = useToast()
  const canManage = can(PERMISSION.CLAUSE_MANAGE)

  const [name, setName] = useState(clause?.name ?? '')
  const [description, setDescription] = useState(clause?.description ?? '')
  const [categoryId, setCategoryId] = useState(
    clause?.category_id != null ? String(clause.category_id) : defaultCategoryId != null ? String(defaultCategoryId) : '',
  )
  const [standardText, setStandardText] = useState(clause?.standard_text ?? '')
  const [fallbackText, setFallbackText] = useState(clause?.fallback_text ?? '')
  const [prohibited, setProhibited] = useState(clause?.prohibited_wording ?? '')
  const [risk, setRisk] = useState<RiskLevel>(clause?.risk_classification ?? 'medium')
  const [applicableTypes, setApplicableTypes] = useState<number[]>(toNumberIds(clause?.applicable_types))
  const [jurisdiction, setJurisdiction] = useState(clause?.jurisdiction ?? '')
  const [approvalStatus, setApprovalStatus] = useState<ClauseApprovalStatus>(
    clause?.approval_status ?? 'draft',
  )
  const [effectiveFrom, setEffectiveFrom] = useState(clause?.effective_from ?? '')
  const [effectiveTo, setEffectiveTo] = useState(clause?.effective_to ?? '')
  const [changeNote, setChangeNote] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [deleting, setDeleting] = useState(false)

  const clauseId = clause?.id ?? null

  const versions = useApiResource<LibraryClauseVersion[]>(
    (signal) =>
      clauseId === null
        ? Promise.resolve([])
        : api.get<LibraryClauseVersion[]>(`/clauses/${clauseId}/versions`, undefined, signal),
    [clauseId],
    { enabled: clauseId !== null },
  )

  const categoryOptions = useMemo(
    () => categories.map((category) => ({ value: String(category.id), label: category.name })),
    [categories],
  )

  const save = async () => {
    if (!canManage) return

    const next: Record<string, string> = {}
    if (name.trim() === '') next.name = 'Give the clause a name a drafter would search for.'
    if (standardText.trim() === '') next.standard_text = 'The standard wording is what this clause is.'
    if (effectiveFrom !== '' && effectiveTo !== '' && effectiveTo < effectiveFrom) {
      next.effective_to = 'The end date cannot be before the start date.'
    }

    if (Object.keys(next).length > 0) {
      setErrors(next)
      return
    }

    setSaving(true)
    setErrors({})

    const payload: LibraryClauseInput = {
      name: name.trim(),
      description: description.trim() === '' ? null : description.trim(),
      category_id: categoryId === '' ? null : Number(categoryId),
      standard_text: standardText,
      fallback_text: fallbackText.trim() === '' ? null : fallbackText,
      prohibited_wording: prohibited.trim() === '' ? null : prohibited,
      risk_classification: risk,
      applicable_types: applicableTypes,
      jurisdiction: jurisdiction.trim() === '' ? null : jurisdiction.trim(),
      approval_status: approvalStatus,
      effective_from: effectiveFrom === '' ? null : effectiveFrom,
      effective_to: effectiveTo === '' ? null : effectiveTo,
      change_note: changeNote.trim() === '' ? null : changeNote.trim(),
    }

    try {
      const saved =
        clauseId === null
          ? await api.post<LibraryClauseItem>('/clauses', payload)
          : await api.put<LibraryClauseItem>(`/clauses/${clauseId}`, payload)

      toast.success(clauseId === null ? 'Clause added to the library' : 'Clause saved', saved?.name ?? name)
      setChangeNote('')
      onSaved(saved)
      if (clauseId !== null) versions.reload()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the clause', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (clauseId === null) return
    setDeleting(true)
    try {
      await api.delete(`/clauses/${clauseId}`)
      toast.success('Clause removed from the library', name)
      setConfirmDelete(false)
      onDeleted()
    } catch (err) {
      toast.error('Could not remove the clause', err instanceof Error ? err.message : undefined)
    } finally {
      setDeleting(false)
    }
  }

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <header
        style={{
          display: 'flex',
          alignItems: 'flex-start',
          justifyContent: 'space-between',
          gap: 14,
          flexWrap: 'wrap',
        }}
      >
        <div style={{ minWidth: 0 }}>
          <Button variant="ghost" size="sm" icon={<ArrowLeft size={14} />} onClick={onClose}>
            Clause library
          </Button>
          <h1 style={{ fontSize: 20, fontWeight: 800, marginTop: 6, letterSpacing: '-.01em' }}>
            {clauseId === null ? 'New clause' : (name || 'Untitled clause')}
          </h1>
          <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', marginTop: 8, alignItems: 'center' }}>
            <StatusChip status={approvalStatus} size="sm" />
            <RiskChip level={risk} />
            {clause ? (
              <>
                <Chip tone="neutral" size="sm">Version {clause.version}</Chip>
                <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                  Updated {formatDateTime(clause.updated_at)}
                </span>
              </>
            ) : null}
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {clauseId !== null && canManage ? (
            <Button variant="ghost" icon={<Trash2 size={14} />} onClick={() => setConfirmDelete(true)}>
              Delete
            </Button>
          ) : null}
          {canManage ? (
            <Button variant="primary" icon={<Save size={14} />} loading={saving} onClick={() => void save()}>
              {clauseId === null ? 'Add to library' : 'Save clause'}
            </Button>
          ) : null}
        </div>
      </header>

      {!canManage ? (
        <Card style={{ background: 'var(--color-bg-subtle)' }}>
          <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
            You are reading this clause. Changing approved wording needs the clause management
            permission, because contracts are measured against what is stored here.
          </p>
        </Card>
      ) : null}

      <Card>
        <h2 style={{ fontSize: 14, fontWeight: 700, marginBottom: 14 }}>Identity</h2>
        <div style={{ display: 'grid', gap: 14 }}>
          <Input
            label="Name"
            required
            value={name}
            error={errors.name}
            disabled={!canManage}
            placeholder="Limitation of liability — capped at fees paid"
            onChange={(event) => setName(event.target.value)}
          />
          <Input
            label="Description"
            value={description}
            error={errors.description}
            disabled={!canManage}
            hint="When to reach for this clause."
            onChange={(event) => setDescription(event.target.value)}
          />
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14 }}>
            <Select
              label="Category"
              value={categoryId}
              placeholder="Uncategorised"
              error={errors.category_id}
              disabled={!canManage}
              options={categoryOptions}
              onChange={(event) => setCategoryId(event.target.value)}
            />
            <Select
              label="Risk classification"
              value={risk}
              error={errors.risk_classification}
              disabled={!canManage}
              hint="How much exposure this subject carries when it goes wrong."
              options={RISK_LEVELS.map((level) => ({ value: level, label: humanise(level) }))}
              onChange={(event) => setRisk(event.target.value as RiskLevel)}
            />
            <Select
              label="Approval status"
              value={approvalStatus}
              error={errors.approval_status}
              disabled={!canManage}
              hint="Only approved wording should be offered as standard."
              options={CLAUSE_APPROVAL_STATUSES.map((value) => ({ value, label: humanise(value) }))}
              onChange={(event) => setApprovalStatus(event.target.value as ClauseApprovalStatus)}
            />
          </div>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14 }}>
            <Input
              label="Jurisdiction"
              value={jurisdiction}
              error={errors.jurisdiction}
              disabled={!canManage}
              placeholder="India, England and Wales, Singapore…"
              hint="Leave blank when the wording travels."
              onChange={(event) => setJurisdiction(event.target.value)}
            />
            <DateInput
              label="Effective from"
              value={effectiveFrom}
              error={errors.effective_from}
              disabled={!canManage}
              onChange={(event) => setEffectiveFrom(event.target.value)}
            />
            <DateInput
              label="Effective to"
              value={effectiveTo}
              error={errors.effective_to}
              disabled={!canManage}
              hint="Blank means it stays current."
              onChange={(event) => setEffectiveTo(event.target.value)}
            />
          </div>
        </div>
      </Card>

      <Card>
        <h2 style={{ fontSize: 14, fontWeight: 700 }}>Applicable contract types</h2>
        <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3, marginBottom: 12 }}>
          {applicableTypes.length === 0
            ? 'None selected — this clause is offered for every contract type.'
            : `Offered for ${applicableTypes.length} of ${contractTypes.length} types.`}
        </p>
        {contractTypes.length === 0 ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
            No contract types are configured yet, so this clause applies everywhere.
          </p>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: 10 }}>
            {contractTypes.map((type) => (
              <Checkbox
                key={type.id}
                label={type.name}
                disabled={!canManage}
                checked={applicableTypes.includes(type.id)}
                onChange={(event) =>
                  setApplicableTypes((current) =>
                    event.target.checked
                      ? [...current, type.id]
                      : current.filter((id) => id !== type.id),
                  )
                }
              />
            ))}
          </div>
        )}
      </Card>

      <Card>
        <h2 style={{ fontSize: 14, fontWeight: 700, marginBottom: 4 }}>Wording</h2>
        <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginBottom: 14, lineHeight: 1.6 }}>
          The standard text is what we ask for, the fallback is what we will accept, and the
          prohibited wording is what a reviewer must push back on.
        </p>

        <div style={{ display: 'grid', gap: 16 }}>
          <Textarea
            label="Standard wording"
            required
            rows={8}
            value={standardText}
            error={errors.standard_text}
            disabled={!canManage}
            hint="Copied onto a contract when this clause is attached."
            onChange={(event) => setStandardText(event.target.value)}
          />

          <div
            style={{
              borderLeft: '3px solid var(--color-warning-border)',
              paddingLeft: 12,
            }}
          >
            <Textarea
              label="Fallback wording"
              rows={6}
              value={fallbackText}
              error={errors.fallback_text}
              disabled={!canManage}
              hint="The position to concede to when the counterparty will not take the standard text."
              onChange={(event) => setFallbackText(event.target.value)}
            />
          </div>

          <div
            style={{
              borderLeft: '3px solid var(--color-danger-border)',
              paddingLeft: 12,
            }}
          >
            <Textarea
              label="Prohibited wording"
              rows={5}
              value={prohibited}
              error={errors.prohibited_wording}
              disabled={!canManage}
              hint="Language that must not be agreed. The playbook check reads this to raise a deviation."
              onChange={(event) => setProhibited(event.target.value)}
            />
            <p
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 6,
                fontSize: 11.5,
                color: 'var(--color-text-muted)',
                marginTop: 6,
              }}
            >
              <Ban size={12} aria-hidden />
              This text is never inserted into a contract.
            </p>
          </div>

          {canManage ? (
            <Input
              label="Change note"
              value={changeNote}
              placeholder="What changed and why"
              hint="Kept with the version this save creates."
              onChange={(event) => setChangeNote(event.target.value)}
            />
          ) : null}
        </div>
      </Card>

      {clauseId !== null ? (
        <VersionHistory
          resource={versions}
          currentVersion={clause?.version ?? 1}
          canRestore={canManage}
          onCopy={(version) => {
            setStandardText(version.standard_text)
            if (version.fallback_text !== null) setFallbackText(version.fallback_text)
            setChangeNote(`Reinstated the wording of version ${version.version}`)
            toast.info('Wording copied into the editor', 'The history keeps the version you copied.')
          }}
        />
      ) : null}

      <ConfirmDialog
        open={confirmDelete}
        busy={deleting}
        tone="danger"
        title="Remove this clause from the library?"
        confirmLabel="Remove clause"
        message={
          <>
            <strong>{name}</strong> will no longer be offered when drafting, and playbook checks that
            reference it stop matching. Clauses already copied onto contracts are untouched.
          </>
        }
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => void remove()}
      />
    </div>
  )
}

/**
 * Superseded wording, shown rather than replaced.
 *
 * A contract signed two years ago was reviewed against the clause as it read
 * then. Overwriting the record would make that impossible to reconstruct, so
 * every version stays readable here, and copying one into the editor is an
 * explicit act that leaves the history alone.
 */
function VersionHistory({
  resource,
  currentVersion,
  canRestore,
  onCopy,
}: {
  resource: Resource<LibraryClauseVersion[]>
  currentVersion: number
  canRestore: boolean
  onCopy: (version: LibraryClauseVersion) => void
}) {
  const [openId, setOpenId] = useState<number | null>(null)

  const rows = resource.data ?? []

  return (
    <Card>
      <h2 style={{ fontSize: 14, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 7 }}>
        <History size={15} aria-hidden />
        Version history
      </h2>
      <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3 }}>
        Every earlier wording is kept. Nothing here changes what the clause says today.
      </p>

      <div style={{ marginTop: 14 }}>
        {resource.loading ? (
          <div style={{ display: 'grid', gap: 10 }}>
            <Skeleton height={54} radius={10} />
            <Skeleton height={54} radius={10} />
          </div>
        ) : resource.error ? (
          <ErrorState
            compact
            title="Could not load the history"
            detail={resource.error.message}
            onRetry={resource.reload}
          />
        ) : rows.length === 0 ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
            No earlier versions yet — this is the wording as first written.
          </p>
        ) : (
          <ol style={{ display: 'grid', gap: 10, listStyle: 'none' }}>
            {[...rows]
              .sort((a, b) => b.version - a.version)
              .map((version) => {
                const open = openId === version.id
                return (
                  <li
                    key={version.id}
                    style={{
                      border: '1px solid rgb(var(--color-border))',
                      borderRadius: 'var(--radius-md)',
                      padding: 13,
                    }}
                  >
                    <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                      <div style={{ minWidth: 0 }}>
                        <p style={{ fontSize: 13.5, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 8 }}>
                          Version {version.version}
                          {version.version === currentVersion ? (
                            <Chip tone="primary" size="sm">
                              Current
                            </Chip>
                          ) : null}
                        </p>
                        <p style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 3 }}>
                          {formatDate(version.created_at)}
                          {version.author_name ? ` · ${version.author_name}` : ''}
                        </p>
                        {version.change_note ? (
                          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 6, lineHeight: 1.6 }}>
                            {version.change_note}
                          </p>
                        ) : null}
                      </div>
                      <div style={{ display: 'flex', gap: 7, flexShrink: 0, alignItems: 'flex-start' }}>
                        <Button size="sm" variant="secondary" onClick={() => setOpenId(open ? null : version.id)}>
                          {open ? 'Hide wording' : 'Show wording'}
                        </Button>
                        {canRestore && version.version !== currentVersion ? (
                          <Button size="sm" variant="ghost" icon={<Copy size={13} />} onClick={() => onCopy(version)}>
                            Copy into editor
                          </Button>
                        ) : null}
                      </div>
                    </div>

                    {open ? (
                      <div style={{ marginTop: 12, display: 'grid', gap: 12 }}>
                        <WordingBlock
                          icon={<BookOpen size={12} aria-hidden />}
                          title="Standard wording, as it stood"
                          text={version.standard_text}
                        />
                        {version.fallback_text ? (
                          <WordingBlock
                            icon={<Scale size={12} aria-hidden />}
                            title="Fallback wording, as it stood"
                            text={version.fallback_text}
                          />
                        ) : null}
                      </div>
                    ) : null}
                  </li>
                )
              })}
          </ol>
        )}
      </div>
    </Card>
  )
}

function WordingBlock({ icon, title, text }: { icon: ReactNode; title: string; text: string }) {
  return (
    <div
      style={{
        padding: '10px 12px',
        background: 'var(--color-bg-subtle)',
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
        {icon}
        {title}
      </p>
      <p style={{ fontSize: 12.5, lineHeight: 1.7, marginTop: 6, whiteSpace: 'pre-wrap' }}>{text}</p>
    </div>
  )
}
