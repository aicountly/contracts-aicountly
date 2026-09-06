import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  ArrowLeft,
  Braces,
  CheckCircle2,
  Copy,
  Eye,
  FilePlus2,
  History,
  Save,
  Search,
  Trash2,
  TriangleAlert,
} from 'lucide-react'

import {
  Button,
  Card,
  Chip,
  ConfirmDialog,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Modal,
  Select,
  Skeleton,
  StatusChip,
  Tabs,
} from '../ui'
import { useSession } from '../../context/SessionProvider'
import { useToast } from '../../context/ToastProvider'
import { useApiResource } from '../../hooks/useApiResource'
import { ApiError, api } from '../../services/apiClient'
import { PERMISSION } from '../../types/permissions'
import type {
  Contract,
  ContractListItem,
  ContractTypeSummary,
  Paged,
  PreviewVariable,
  TemplateDetail,
  TemplateInput,
  TemplatePreview,
  TemplateStatus,
  TemplateSummary,
  TemplateVariable,
  TemplateVersion,
} from '../../types/contracts'
import { TEMPLATE_STATUSES } from '../../types/contracts'
import { formatDateTime, humanise, truncate } from '../../utils/format'

/**
 * Write a template, see what it produces, and keep what it used to say.
 *
 * The rule the whole editor is built around is that a template may only
 * reference a merge key the server knows about. The palette is the way to put
 * one in, and anything typed by hand that is not in the registry is reported
 * before the save rather than discovered as a blank space in a contract three
 * weeks later — the renderer would silently drop it.
 */

/** Every `{{ … }}` token in the body, trimmed, in the order it first appears. */
function bodyTokens(body: string): string[] {
  const seen: string[] = []
  for (const match of body.matchAll(/\{\{([^{}]*)\}\}/g)) {
    const token = match[1].trim()
    if (token !== '' && !seen.includes(token)) seen.push(token)
  }
  return seen
}

/** A `used` / `missing` entry is either a bare key or an object carrying one. */
function previewKey(entry: string | PreviewVariable): string {
  if (typeof entry === 'string') return entry
  return entry.var_key ?? entry.key ?? entry.label ?? 'unknown'
}

function previewLabel(entry: string | PreviewVariable): string | null {
  return typeof entry === 'string' ? null : (entry.label ?? null)
}

const SOURCE_ORDER = ['contract', 'counterparty', 'company', 'commercial', 'custom', 'system']

export function TemplateEditor({
  templateId,
  contractTypes,
  onClose,
  onSaved,
  onDeleted,
}: {
  templateId: number | 'new'
  contractTypes: ContractTypeSummary[]
  onClose: () => void
  onSaved: (template: TemplateSummary) => void
  onDeleted: () => void
}) {
  const { can } = useSession()
  const toast = useToast()
  const navigate = useNavigate()

  const canManage = can(PERMISSION.TEMPLATE_MANAGE)
  const canCreateContract = can(PERMISSION.CONTRACT_CREATE)

  const resource = useApiResource<{ template: TemplateDetail | null; variables: TemplateVariable[] }>(
    async (signal) => {
      const [template, variables] = await Promise.all([
        templateId === 'new'
          ? Promise.resolve(null)
          : api.get<TemplateDetail>(`/templates/${templateId}`, undefined, signal),
        api.get<TemplateVariable[]>('/template-variables', undefined, signal),
      ])
      return { template, variables: variables ?? [] }
    },
    [templateId],
  )

  const template = resource.data?.template ?? null
  const variables = useMemo(() => resource.data?.variables ?? [], [resource.data])

  const [tab, setTab] = useState<'body' | 'preview' | 'history'>('body')
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [contractTypeId, setContractTypeId] = useState('')
  const [status, setStatus] = useState<TemplateStatus>('draft')
  const [body, setBody] = useState('')
  const [changeNote, setChangeNote] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [dirty, setDirty] = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [createOpen, setCreateOpen] = useState(false)

  const bodyRef = useRef<HTMLTextAreaElement>(null)
  const bodyFieldId = useId()

  // The form is seeded once per template load; re-running it on every render of
  // the resource would throw away whatever the user has typed since.
  useEffect(() => {
    if (!resource.data) return
    const loaded = resource.data.template
    setName(loaded?.name ?? '')
    setDescription(loaded?.description ?? '')
    setContractTypeId(loaded?.contract_type_id ? String(loaded.contract_type_id) : '')
    setStatus(loaded?.status ?? 'draft')
    setBody(loaded?.body ?? '')
    setChangeNote('')
    setDirty(false)
    setFieldErrors({})
  }, [resource.data])

  const registry = useMemo(() => new Set(variables.map((variable) => variable.var_key)), [variables])
  const usedTokens = useMemo(() => bodyTokens(body), [body])
  const unregistered = useMemo(
    () => usedTokens.filter((token) => !registry.has(token)),
    [usedTokens, registry],
  )

  const insertVariable = useCallback(
    (key: string) => {
      const field = bodyRef.current
      const token = `{{${key}}}`

      if (!field) {
        setBody((current) => current + token)
        setDirty(true)
        return
      }

      const start = field.selectionStart ?? field.value.length
      const end = field.selectionEnd ?? start
      const next = `${field.value.slice(0, start)}${token}${field.value.slice(end)}`

      setBody(next)
      setDirty(true)

      // The caret has to be restored after React re-renders the textarea,
      // otherwise it jumps to the end and the next insert lands in the wrong
      // place.
      window.requestAnimationFrame(() => {
        field.focus()
        field.setSelectionRange(start + token.length, start + token.length)
      })
    },
    [],
  )

  const save = async () => {
    if (!canManage) return

    const errors: Record<string, string> = {}
    if (name.trim() === '') errors.name = 'Give the template a name.'
    if (body.trim() === '') errors.body = 'A template needs a body to render.'
    if (unregistered.length > 0) {
      errors.body = `${unregistered.length} merge ${unregistered.length === 1 ? 'variable is' : 'variables are'} not in the registry: ${unregistered.join(', ')}. Insert variables from the palette so the renderer can resolve them.`
    }

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      return
    }

    setSaving(true)
    setFieldErrors({})

    const payload: TemplateInput = {
      name: name.trim(),
      description: description.trim() === '' ? null : description.trim(),
      contract_type_id: contractTypeId === '' ? null : Number(contractTypeId),
      status,
      body,
      change_note: changeNote.trim() === '' ? null : changeNote.trim(),
    }

    try {
      const saved =
        templateId === 'new'
          ? await api.post<TemplateSummary>('/templates', payload)
          : await api.put<TemplateSummary>(`/templates/${templateId}`, payload)

      toast.success(templateId === 'new' ? 'Template created' : 'Template saved', saved?.name ?? name)
      setDirty(false)
      onSaved(saved)
      if (templateId !== 'new') resource.reload()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setFieldErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the template', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (templateId === 'new') return
    setDeleting(true)
    try {
      await api.delete(`/templates/${templateId}`)
      toast.success('Template deleted', name)
      setConfirmDelete(false)
      onDeleted()
    } catch (err) {
      toast.error('Could not delete the template', err instanceof Error ? err.message : undefined)
    } finally {
      setDeleting(false)
    }
  }

  if (resource.loading) {
    return (
      <div style={{ display: 'grid', gap: 16 }}>
        <Skeleton height={30} width="40%" />
        <Card>
          <div style={{ display: 'grid', gap: 12 }}>
            <Skeleton height={36} />
            <Skeleton height={36} width="60%" />
            <Skeleton height={220} radius={10} />
          </div>
        </Card>
      </div>
    )
  }

  if (resource.error) {
    return (
      <ErrorState
        title="Could not open that template"
        detail={resource.error.message}
        onRetry={resource.reload}
      />
    )
  }

  const versions = template?.versions ?? []

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <style>{`
        @media (max-width: 1040px) {
          .ct-template-grid { grid-template-columns: minmax(0, 1fr) !important; }
        }
      `}</style>

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
            All templates
          </Button>
          <h1 style={{ fontSize: 20, fontWeight: 800, marginTop: 6, letterSpacing: '-.01em' }}>
            {templateId === 'new' ? 'New template' : (name || 'Untitled template')}
          </h1>
          <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', marginTop: 8, alignItems: 'center' }}>
            <StatusChip status={status} size="sm" />
            {template ? (
              <>
                <Chip tone="neutral" size="sm">Version {template.version}</Chip>
                <span style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                  Updated {formatDateTime(template.updated_at)}
                </span>
              </>
            ) : null}
            {dirty ? (
              <Chip tone="warning" size="sm">Unsaved changes</Chip>
            ) : null}
          </div>
        </div>

        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          {template && canCreateContract ? (
            <Button variant="secondary" icon={<FilePlus2 size={14} />} onClick={() => setCreateOpen(true)}>
              Create contract
            </Button>
          ) : null}
          {template && canManage ? (
            <Button
              variant="ghost"
              icon={<Trash2 size={14} />}
              onClick={() => setConfirmDelete(true)}
              aria-label="Delete this template"
            >
              Delete
            </Button>
          ) : null}
          {canManage ? (
            <Button variant="primary" icon={<Save size={14} />} loading={saving} onClick={() => void save()}>
              {templateId === 'new' ? 'Create template' : 'Save template'}
            </Button>
          ) : null}
        </div>
      </header>

      {!canManage ? (
        <Card style={{ background: 'var(--color-bg-subtle)' }}>
          <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }}>
            You can read this template and draft contracts from it. Changing the wording needs the
            template management permission.
          </p>
        </Card>
      ) : null}

      <Tabs
        ariaLabel="Template editor sections"
        active={tab}
        onChange={(next) => setTab(next as typeof tab)}
        items={[
          { id: 'body', label: 'Body', icon: <Braces size={13} aria-hidden /> },
          { id: 'preview', label: 'Preview', icon: <Eye size={13} aria-hidden /> },
          {
            id: 'history',
            label: 'History',
            icon: <History size={13} aria-hidden />,
            badge: versions.length || undefined,
          },
        ]}
      />

      <div id={`panel-${tab}`} role="tabpanel" aria-labelledby={`tab-${tab}`}>
        {tab === 'body' ? (
          <div
            className="ct-template-grid"
            style={{ display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) 300px', gap: 16, alignItems: 'start' }}
          >
            <div style={{ display: 'grid', gap: 16 }}>
              <Card>
                <div style={{ display: 'grid', gap: 14 }}>
                  <Input
                    label="Name"
                    required
                    value={name}
                    error={fieldErrors.name}
                    disabled={!canManage}
                    placeholder="Master services agreement — standard"
                    onChange={(event) => {
                      setName(event.target.value)
                      setDirty(true)
                    }}
                  />
                  <Input
                    label="Description"
                    value={description}
                    error={fieldErrors.description}
                    disabled={!canManage}
                    hint="What this template is for, so the next drafter picks the right one."
                    onChange={(event) => {
                      setDescription(event.target.value)
                      setDirty(true)
                    }}
                  />
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(190px, 1fr))', gap: 14 }}>
                    <Select
                      label="Contract type"
                      value={contractTypeId}
                      placeholder="Any type"
                      error={fieldErrors.contract_type_id}
                      disabled={!canManage}
                      options={contractTypes.map((type) => ({ value: String(type.id), label: type.name }))}
                      onChange={(event) => {
                        setContractTypeId(event.target.value)
                        setDirty(true)
                      }}
                    />
                    <Select
                      label="Status"
                      value={status}
                      error={fieldErrors.status}
                      disabled={!canManage}
                      hint="Only an active template is offered when drafting."
                      options={TEMPLATE_STATUSES.map((value) => ({ value, label: humanise(value) }))}
                      onChange={(event) => {
                        setStatus(event.target.value as TemplateStatus)
                        setDirty(true)
                      }}
                    />
                  </div>
                </div>
              </Card>

              <Card>
                {/* Not the kit's Textarea: inserting at the caret needs the
                    element itself, and the kit's control does not forward a
                    ref. Field keeps the label, hint and error wiring identical. */}
                <Field
                  label="Template body"
                  htmlFor={bodyFieldId}
                  required
                  error={fieldErrors.body}
                  hint="Insert merge variables from the palette. They are replaced with the contract's own values when the document is produced."
                  describedById={`${bodyFieldId}-desc`}
                >
                  <textarea
                    id={bodyFieldId}
                    ref={bodyRef}
                    rows={20}
                    value={body}
                    disabled={!canManage}
                    aria-invalid={fieldErrors.body ? true : undefined}
                    aria-describedby={`${bodyFieldId}-desc`}
                    onChange={(event) => {
                      setBody(event.target.value)
                      setDirty(true)
                    }}
                    style={{
                      width: '100%',
                      padding: '10px 12px',
                      borderRadius: 'var(--radius-md)',
                      border: `1px solid ${fieldErrors.body ? 'var(--color-danger)' : 'rgb(var(--color-border-strong))'}`,
                      background: 'var(--color-bg-card)',
                      color: 'var(--color-text)',
                      fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                      fontSize: 13,
                      lineHeight: 1.7,
                      resize: 'vertical',
                    }}
                  />
                </Field>

                <div aria-live="polite" style={{ marginTop: 12 }}>
                  {unregistered.length > 0 ? (
                    <div
                      role="alert"
                      style={{
                        display: 'flex',
                        gap: 9,
                        padding: '11px 13px',
                        background: 'var(--color-danger-bg)',
                        border: '1px solid var(--color-danger-border)',
                        borderRadius: 'var(--radius-md)',
                      }}
                    >
                      <TriangleAlert size={16} aria-hidden style={{ color: 'var(--color-danger)', flexShrink: 0, marginTop: 1 }} />
                      <div style={{ fontSize: 12.5, lineHeight: 1.6 }}>
                        <strong>
                          {unregistered.length} merge {unregistered.length === 1 ? 'variable is' : 'variables are'} not
                          registered
                        </strong>
                        <p style={{ color: 'var(--color-text-secondary)', marginTop: 3 }}>
                          The renderer resolves registry keys only, so {unregistered.length === 1 ? 'this one' : 'these'}{' '}
                          would come out blank: {unregistered.map((token) => `{{${token}}}`).join(', ')}
                        </p>
                      </div>
                    </div>
                  ) : usedTokens.length > 0 ? (
                    <p
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 7,
                        fontSize: 12.5,
                        color: 'var(--color-text-secondary)',
                      }}
                    >
                      <CheckCircle2 size={14} aria-hidden style={{ color: 'var(--color-success)' }} />
                      {usedTokens.length} merge {usedTokens.length === 1 ? 'variable' : 'variables'} in use, all
                      registered.
                    </p>
                  ) : (
                    <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
                      No merge variables yet — this template would produce the same words for every contract.
                    </p>
                  )}
                </div>

                {canManage ? (
                  <div style={{ marginTop: 14 }}>
                    <Input
                      label="Change note"
                      value={changeNote}
                      placeholder="What changed and why"
                      hint="Stored against this version, so the history explains itself later."
                      onChange={(event) => setChangeNote(event.target.value)}
                    />
                  </div>
                ) : null}
              </Card>
            </div>

            <VariablePalette
              variables={variables}
              used={usedTokens}
              disabled={!canManage}
              onInsert={insertVariable}
            />
          </div>
        ) : null}

        {tab === 'preview' ? (
          <PreviewPanel templateId={templateId} dirty={dirty} />
        ) : null}

        {tab === 'history' ? (
          <VersionHistory
            versions={versions}
            currentVersion={template?.version ?? 1}
            canRestore={canManage}
            onRestore={(version) => {
              setBody(version.body)
              setChangeNote(`Restored the wording of version ${version.version}`)
              setDirty(true)
              setTab('body')
              toast.info('Wording copied into the editor', 'Nothing is stored until you save.')
            }}
          />
        ) : null}
      </div>

      {createOpen && template ? (
        <CreateContractDialog
          template={template}
          onClose={() => setCreateOpen(false)}
          onCreated={(contract) => navigate(`/contracts/${contract.id}`)}
        />
      ) : null}

      <ConfirmDialog
        open={confirmDelete}
        busy={deleting}
        tone="danger"
        title="Delete this template?"
        confirmLabel="Delete template"
        message={
          <>
            <strong>{name}</strong> will no longer be available for drafting. Contracts already
            created from it are not affected.
          </>
        }
        onClose={() => setConfirmDelete(false)}
        onConfirm={() => void remove()}
      />
    </div>
  )
}

/**
 * The registry, grouped by where each value comes from.
 *
 * Grouping is not decoration: a drafter looking for the counterparty's
 * registered address needs to know it comes from the counterparty record rather
 * than hunting an alphabetical list of eighty keys.
 */
function VariablePalette({
  variables,
  used,
  disabled,
  onInsert,
}: {
  variables: TemplateVariable[]
  used: string[]
  disabled: boolean
  onInsert: (key: string) => void
}) {
  const [query, setQuery] = useState('')

  const groups = useMemo(() => {
    const term = query.trim().toLowerCase()
    const matched = term
      ? variables.filter(
          (variable) =>
            variable.var_key.toLowerCase().includes(term) ||
            variable.label.toLowerCase().includes(term),
        )
      : variables

    const map = new Map<string, TemplateVariable[]>()
    for (const variable of matched) {
      const bucket = map.get(variable.source) ?? []
      bucket.push(variable)
      map.set(variable.source, bucket)
    }

    return [...map.entries()].sort(
      ([a], [b]) => SOURCE_ORDER.indexOf(a) - SOURCE_ORDER.indexOf(b),
    )
  }, [variables, query])

  return (
    <Card style={{ position: 'sticky', top: 16 }}>
      <h2 style={{ fontSize: 14, fontWeight: 700 }}>Merge variables</h2>
      <p style={{ fontSize: 12, color: 'var(--color-text-secondary)', marginTop: 3, lineHeight: 1.55 }}>
        Click one to drop it in at the cursor.
      </p>

      <div style={{ marginTop: 12 }}>
        <Input
          label="Search variables"
          value={query}
          placeholder="counterparty, value, date…"
          onChange={(event) => setQuery(event.target.value)}
        />
      </div>

      <div style={{ marginTop: 12, maxHeight: 460, overflowY: 'auto', display: 'grid', gap: 14 }}>
        {variables.length === 0 ? (
          <EmptyState
            compact
            icon={<Braces size={18} />}
            title="No variables registered"
            description="The merge registry is empty for this company, so a template can only contain fixed wording."
          />
        ) : groups.length === 0 ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
            Nothing matches “{query}”.
          </p>
        ) : (
          groups.map(([source, items]) => (
            <section key={source}>
              <h3
                style={{
                  fontSize: 11,
                  fontWeight: 700,
                  textTransform: 'uppercase',
                  letterSpacing: '.03em',
                  color: 'var(--color-text-muted)',
                  marginBottom: 7,
                }}
              >
                {humanise(source)}
              </h3>
              <div style={{ display: 'grid', gap: 6 }}>
                {items.map((variable) => {
                  const inUse = used.includes(variable.var_key)
                  return (
                    <button
                      key={variable.id}
                      type="button"
                      disabled={disabled}
                      onClick={() => onInsert(variable.var_key)}
                      title={variable.example ? `Example: ${variable.example}` : undefined}
                      style={{
                        display: 'grid',
                        gap: 2,
                        textAlign: 'left',
                        padding: '7px 9px',
                        borderRadius: 'var(--radius-sm)',
                        border: '1px solid rgb(var(--color-border))',
                        background: inUse ? 'var(--color-primary-muted)' : 'var(--color-bg-card)',
                        cursor: disabled ? 'not-allowed' : 'pointer',
                        opacity: disabled ? 0.6 : 1,
                      }}
                    >
                      <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--color-text)' }}>
                        {variable.label}
                        {inUse ? (
                          <span style={{ fontWeight: 600, color: 'rgb(var(--color-primary-active))', marginLeft: 6, fontSize: 11 }}>
                            in use
                          </span>
                        ) : null}
                      </span>
                      <code style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                        {`{{${variable.var_key}}}`}
                      </code>
                    </button>
                  )
                })}
              </div>
            </section>
          ))
        )}
      </div>
    </Card>
  )
}

/**
 * Render the saved template against a real contract.
 *
 * The preview reflects what is stored, not what is in the editor — the server
 * renders from its own copy — so an unsaved edit is called out rather than
 * quietly previewed as if it counted.
 */
function PreviewPanel({ templateId, dirty }: { templateId: number | 'new'; dirty: boolean }) {
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [chosen, setChosen] = useState<ContractListItem | null>(null)
  const [preview, setPreview] = useState<TemplatePreview | null>(null)
  const [running, setRunning] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [query])

  const search = useApiResource<Paged<ContractListItem>>(
    (signal) => api.get<Paged<ContractListItem>>('/contracts', { q: debounced, per_page: 6 }, signal),
    [debounced],
    { enabled: debounced.length > 1 },
  )

  const run = async (contract: ContractListItem | null) => {
    if (templateId === 'new') return
    setRunning(true)
    setError(null)
    try {
      const result = await api.post<TemplatePreview>(`/templates/${templateId}/preview`, {
        contract_id: contract?.id ?? null,
      })
      setPreview(result)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'The preview did not render.')
    } finally {
      setRunning(false)
    }
  }

  if (templateId === 'new') {
    return (
      <EmptyState
        icon={<Eye size={22} />}
        title="Save the template first"
        description="A preview is rendered by the server from the stored template, so there is nothing to render until this one has been created."
      />
    )
  }

  const missing = preview?.missing ?? []
  const used = preview?.used ?? []

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <Card>
        <h2 style={{ fontSize: 14, fontWeight: 700 }}>Preview against a contract</h2>
        <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 3, lineHeight: 1.6 }}>
          Merging real data is the only way to find out which variables actually resolve. Nothing is
          written to the contract.
        </p>

        {dirty ? (
          <p
            role="status"
            style={{
              marginTop: 12,
              padding: '9px 12px',
              fontSize: 12.5,
              background: 'var(--color-warning-bg)',
              border: '1px solid var(--color-warning-border)',
              color: 'var(--color-warning-text)',
              borderRadius: 'var(--radius-md)',
            }}
          >
            You have unsaved changes. This preview renders the last saved version.
          </p>
        ) : null}

        <div style={{ marginTop: 14, display: 'grid', gap: 12 }}>
          <Input
            label="Find a contract"
            value={query}
            placeholder="Number, title or counterparty"
            onChange={(event) => setQuery(event.target.value)}
          />

          {chosen ? (
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 12,
                padding: '10px 12px',
                border: '1px solid var(--color-primary-border)',
                background: 'var(--color-primary-muted)',
                borderRadius: 'var(--radius-md)',
                flexWrap: 'wrap',
              }}
            >
              <div style={{ minWidth: 0 }}>
                <p style={{ fontSize: 13, fontWeight: 700 }}>{chosen.title}</p>
                <p style={{ fontSize: 12, color: 'var(--color-text-secondary)' }}>
                  {chosen.contract_number}
                  {chosen.counterparty_name ? ` · ${chosen.counterparty_name}` : ''}
                </p>
              </div>
              <Button size="sm" variant="ghost" onClick={() => setChosen(null)}>
                Choose another
              </Button>
            </div>
          ) : debounced.length > 1 ? (
            <div style={{ display: 'grid', gap: 6 }}>
              {search.loading ? (
                <>
                  <Skeleton height={40} radius={8} />
                  <Skeleton height={40} radius={8} />
                </>
              ) : search.error ? (
                <ErrorState compact title="Could not search contracts" detail={search.error.message} onRetry={search.reload} />
              ) : (search.data?.items ?? []).length === 0 ? (
                <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
                  No contract matches “{debounced}”.
                </p>
              ) : (
                (search.data?.items ?? []).map((contract) => (
                  <button
                    key={contract.id}
                    type="button"
                    onClick={() => setChosen(contract)}
                    style={{
                      display: 'grid',
                      gap: 2,
                      textAlign: 'left',
                      padding: '9px 11px',
                      border: '1px solid rgb(var(--color-border))',
                      borderRadius: 'var(--radius-md)',
                      background: 'var(--color-bg-card)',
                      cursor: 'pointer',
                    }}
                  >
                    <span style={{ fontSize: 13, fontWeight: 700 }}>{contract.title}</span>
                    <span style={{ fontSize: 12, color: 'var(--color-text-secondary)' }}>
                      {contract.contract_number}
                      {contract.counterparty_name ? ` · ${contract.counterparty_name}` : ''}
                    </span>
                  </button>
                ))
              )}
            </div>
          ) : (
            <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
              <Search size={12} aria-hidden style={{ verticalAlign: -1, marginRight: 5 }} />
              Type at least two characters, or render with sample values only.
            </p>
          )}

          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <Button variant="primary" loading={running} icon={<Eye size={14} />} onClick={() => void run(chosen)}>
              {chosen ? 'Render with this contract' : 'Render with sample values'}
            </Button>
          </div>
        </div>
      </Card>

      <div aria-live="polite">
        {error ? (
          <ErrorState title="The preview did not render" detail={error} onRetry={() => void run(chosen)} />
        ) : preview ? (
          <div style={{ display: 'grid', gap: 16 }}>
            <Card>
              <h3 style={{ fontSize: 14, fontWeight: 700 }}>What resolved</h3>
              <div style={{ display: 'grid', gap: 14, marginTop: 12 }}>
                <section>
                  <p style={{ fontSize: 12, fontWeight: 700, color: 'var(--color-text-muted)', marginBottom: 6 }}>
                    Resolved · {used.length}
                  </p>
                  {used.length === 0 ? (
                    <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>No variables resolved.</p>
                  ) : (
                    <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                      {used.map((entry) => (
                        <Chip key={previewKey(entry)} tone="success" size="sm">
                          {previewLabel(entry) ?? previewKey(entry)}
                        </Chip>
                      ))}
                    </div>
                  )}
                </section>

                <section>
                  <p style={{ fontSize: 12, fontWeight: 700, color: 'var(--color-text-muted)', marginBottom: 6 }}>
                    Missing · {missing.length}
                  </p>
                  {missing.length === 0 ? (
                    <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)' }}>
                      Every variable in the body had a value.
                    </p>
                  ) : (
                    <>
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        {missing.map((entry) => (
                          <Chip key={previewKey(entry)} tone="danger" size="sm">
                            {previewLabel(entry) ?? previewKey(entry)}
                          </Chip>
                        ))}
                      </div>
                      <p style={{ fontSize: 12, color: 'var(--color-text-secondary)', marginTop: 8, lineHeight: 1.6 }}>
                        A missing variable renders as a blank in the finished document. Fill the value
                        on the contract, or take the variable out of the template.
                      </p>
                    </>
                  )}
                </section>
              </div>
            </Card>

            <Card padded={false}>
              <div style={{ padding: '14px 18px', borderBottom: '1px solid rgb(var(--color-border))' }}>
                <h3 style={{ fontSize: 14, fontWeight: 700 }}>Rendered document</h3>
              </div>
              {preview.html?.trim() ? (
                // Sandboxed and script-free: the body is company-authored HTML,
                // and rendering it into this document would give whoever edits a
                // template a foothold in everyone else's session. The white page
                // is deliberate in both themes — this is a document, not a panel.
                <iframe
                  title="Rendered template preview"
                  sandbox=""
                  srcDoc={preview.html}
                  style={{
                    width: '100%',
                    height: 560,
                    border: 'none',
                    background: '#fff',
                    borderBottomLeftRadius: 'var(--radius-lg)',
                    borderBottomRightRadius: 'var(--radius-lg)',
                  }}
                />
              ) : (
                <EmptyState compact title="The render came back empty" description="The template body produced no output for this contract." />
              )}
            </Card>
          </div>
        ) : null}
      </div>
    </div>
  )
}

function VersionHistory({
  versions,
  currentVersion,
  canRestore,
  onRestore,
}: {
  versions: TemplateVersion[]
  currentVersion: number
  canRestore: boolean
  onRestore: (version: TemplateVersion) => void
}) {
  const [openId, setOpenId] = useState<number | null>(null)

  if (versions.length === 0) {
    return (
      <EmptyState
        icon={<History size={22} />}
        title="No earlier versions yet"
        description="Each save keeps the wording it replaced, so you can always see what a contract drafted last quarter was drafted from."
      />
    )
  }

  const ordered = [...versions].sort((a, b) => b.version - a.version)

  return (
    <div style={{ display: 'grid', gap: 12 }}>
      {ordered.map((version) => {
        const open = openId === version.id
        return (
          <Card key={version.id}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
              <div style={{ minWidth: 0 }}>
                <h3 style={{ fontSize: 14, fontWeight: 700 }}>
                  Version {version.version}
                  {version.version === currentVersion ? (
                    <span style={{ marginLeft: 8 }}>
                      <Chip tone="primary" size="sm">
                        Current
                      </Chip>
                    </span>
                  ) : null}
                </h3>
                <p style={{ fontSize: 12, color: 'var(--color-text-muted)', marginTop: 4 }}>
                  {formatDateTime(version.created_at)}
                  {version.author_name ? ` · ${version.author_name}` : ''}
                  {version.variables?.length ? ` · ${version.variables.length} variables` : ''}
                </p>
                {version.change_note ? (
                  <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 7, lineHeight: 1.6 }}>
                    {version.change_note}
                  </p>
                ) : null}
              </div>
              <div style={{ display: 'flex', gap: 7, flexShrink: 0, alignItems: 'flex-start' }}>
                <Button size="sm" variant="secondary" onClick={() => setOpenId(open ? null : version.id)}>
                  {open ? 'Hide wording' : 'Show wording'}
                </Button>
                {canRestore && version.version !== currentVersion ? (
                  <Button size="sm" variant="ghost" icon={<Copy size={13} />} onClick={() => onRestore(version)}>
                    Copy into editor
                  </Button>
                ) : null}
              </div>
            </div>

            {open ? (
              <pre
                style={{
                  marginTop: 12,
                  padding: 13,
                  maxHeight: 340,
                  overflow: 'auto',
                  background: 'var(--color-bg-subtle)',
                  border: '1px solid rgb(var(--color-border))',
                  borderRadius: 'var(--radius-md)',
                  fontSize: 12.5,
                  lineHeight: 1.7,
                  whiteSpace: 'pre-wrap',
                  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
                }}
              >
                {version.body}
              </pre>
            ) : null}
          </Card>
        )
      })}
    </div>
  )
}

function CreateContractDialog({
  template,
  onClose,
  onCreated,
}: {
  template: TemplateDetail
  onClose: () => void
  onCreated: (contract: Contract) => void
}) {
  const toast = useToast()
  const [title, setTitle] = useState(`${template.name} — `)
  const [counterparty, setCounterparty] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (title.trim() === '') {
      setErrors({ title: 'Give the contract a title.' })
      return
    }

    setBusy(true)
    setErrors({})
    try {
      const result = await api.post<{ contract: Contract } | Contract>(
        `/templates/${template.id}/create-contract`,
        {
          title: title.trim(),
          counterparty_name: counterparty.trim() === '' ? null : counterparty.trim(),
        },
      )
      const contract = 'contract' in result ? result.contract : result
      toast.success('Contract created', contract.contract_number ?? title.trim())
      onCreated(contract)
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
      title="Create a contract from this template"
      description={`The body of ${template.name} is copied onto a new draft. Editing the draft afterwards does not change the template.`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button variant="primary" loading={busy} onClick={() => void submit()}>
            Create draft
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
          onChange={(event) => setTitle(event.target.value)}
        />
        <Input
          label="Counterparty"
          value={counterparty}
          error={errors.counterparty_name}
          hint="Optional now — you can add parties on the contract itself."
          onChange={(event) => setCounterparty(event.target.value)}
        />
        {template.variables.length > 0 ? (
          <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', lineHeight: 1.6 }}>
            This template references {template.variables.length} merge{' '}
            {template.variables.length === 1 ? 'variable' : 'variables'}, including{' '}
            {truncate(template.variables.slice(0, 3).join(', '), 90)}. Anything the new draft has no
            value for renders blank until you fill it in.
          </p>
        ) : null}
      </div>
    </Modal>
  )
}
