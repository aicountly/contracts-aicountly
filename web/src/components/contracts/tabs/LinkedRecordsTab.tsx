import { useEffect, useState } from 'react'
import { Link as RouterLink } from 'react-router-dom'
import { ExternalLink, Link2, Plus, Search, Trash2 } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Chip,
  ConfirmDialog,
  DataTable,
  ErrorState,
  Field,
  Modal,
  Select,
  Textarea,
} from '../../ui'
import type { Column } from '../../ui'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract, ContractLink, ContractListItem, Paged } from '../../../types/contracts'
import { formatDate, humanise, truncate } from '../../../utils/format'

/**
 * How this contract relates to everything else.
 *
 * Two kinds of relationship live here and they are not the same thing. The ones
 * at the top are structural — the parent this was amended from, the request it
 * came out of — and the contract row itself carries them, so they cannot be
 * deleted from this screen. The table below holds the links a person drew, and
 * those are theirs to add and remove.
 */

interface Props {
  contractId: number
  contract: Contract
  onChanged: () => void
}

const LINK_TYPES = [
  { value: 'related', label: 'Related to' },
  { value: 'parent', label: 'Parent of' },
  { value: 'child', label: 'Child of' },
  { value: 'supersedes', label: 'Supersedes' },
  { value: 'superseded_by', label: 'Superseded by' },
  { value: 'amends', label: 'Amends' },
  { value: 'renewal_of', label: 'Renewal of' },
  { value: 'framework', label: 'Under framework' },
]

const SEARCH_DEBOUNCE_MS = 300

export function LinkedRecordsTab({ contractId, contract, onChanged }: Props) {
  const toast = useToast()
  const { can } = useSession()
  const canEdit = can(PERMISSION.CONTRACT_EDIT)

  const [adding, setAdding] = useState(false)
  const [linkType, setLinkType] = useState('related')
  const [note, setNote] = useState('')
  const [chosen, setChosen] = useState<ContractListItem | null>(null)
  const [query, setQuery] = useState('')
  const [debounced, setDebounced] = useState('')
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [removing, setRemoving] = useState<ContractLink | null>(null)
  const [deleting, setDeleting] = useState(false)

  const links = useApiResource<ContractLink[]>(
    (signal) => api.get<ContractLink[]>(`/contracts/${contractId}/links`, undefined, signal),
    [contractId],
  )

  useEffect(() => {
    const timer = window.setTimeout(() => setDebounced(query.trim()), SEARCH_DEBOUNCE_MS)
    return () => window.clearTimeout(timer)
  }, [query])

  const search = useApiResource<Paged<ContractListItem>>(
    (signal) =>
      api.get<Paged<ContractListItem>>('/contracts', { q: debounced, per_page: 8 }, signal),
    [debounced],
    { enabled: adding && debounced.length >= 2 },
  )

  const candidates = (search.data?.items ?? []).filter((item) => item.id !== contractId)

  const reset = () => {
    setAdding(false)
    setChosen(null)
    setQuery('')
    setDebounced('')
    setNote('')
    setLinkType('related')
    setErrors({})
  }

  const create = async () => {
    if (!chosen) {
      setErrors({ related_contract_id: 'Choose the contract to link to.' })
      return
    }

    setSaving(true)
    setErrors({})
    try {
      await api.post<ContractLink>(`/contracts/${contractId}/links`, {
        link_type: linkType,
        related_contract_id: chosen.id,
        note: note.trim() || null,
      })
      toast.success('Link added', `${chosen.title} is now linked to this contract.`)
      reset()
      links.reload()
      onChanged()
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not add the link', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (!removing) return
    setDeleting(true)
    try {
      await api.delete(`/links/${removing.id}`)
      toast.success('Link removed')
      setRemoving(null)
      links.reload()
      onChanged()
    } catch (err) {
      toast.error('Could not remove the link', err instanceof Error ? err.message : undefined)
    } finally {
      setDeleting(false)
    }
  }

  const columns: Column<ContractLink>[] = [
    {
      key: 'type',
      header: 'Relationship',
      render: (link) => <Chip size="sm">{humanise(link.link_type ?? 'related')}</Chip>,
    },
    {
      key: 'record',
      header: 'Record',
      render: (link) => {
        const title = link.related_contract_title ?? link.label ?? 'Linked record'
        return (
          <div style={{ minWidth: 180 }}>
            {link.related_contract_id ? (
              <RouterLink to={`/contracts/${link.related_contract_id}`} className="ct-link">
                {title}
              </RouterLink>
            ) : (
              <span style={{ fontWeight: 600 }}>{title}</span>
            )}
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {link.related_contract_number ??
                (link.related_type ? `${humanise(link.related_type)} ${link.related_id ?? ''}` : '—')}
            </div>
          </div>
        )
      },
    },
    {
      key: 'status',
      header: 'Status',
      hideBelow: 'sm',
      render: (link) =>
        link.related_contract_status ? (
          <Chip size="sm">{humanise(link.related_contract_status)}</Chip>
        ) : (
          '—'
        ),
    },
    {
      key: 'note',
      header: 'Note',
      hideBelow: 'md',
      render: (link) => (link.note ? truncate(link.note, 60) : '—'),
    },
    {
      key: 'created',
      header: 'Added',
      hideBelow: 'lg',
      render: (link) => formatDate(link.created_at),
    },
    {
      key: 'actions',
      header: '',
      srLabel: 'Actions',
      align: 'right',
      width: 60,
      render: (link) =>
        canEdit ? (
          <Button
            size="sm"
            variant="ghost"
            aria-label={`Remove the link to ${link.related_contract_title ?? 'this record'}`}
            icon={<Trash2 size={14} />}
            onClick={() => setRemoving(link)}
          />
        ) : null,
    },
  ]

  const structural = [
    contract.parent_contract_id
      ? {
          key: 'parent',
          label: 'Amended from',
          to: `/contracts/${contract.parent_contract_id}`,
          value: `Contract #${contract.parent_contract_id}`,
        }
      : null,
    contract.request_id
      ? {
          key: 'request',
          label: 'Raised from request',
          to: `/requests/${contract.request_id}`,
          value: `Request #${contract.request_id}`,
        }
      : null,
    contract.template_id
      ? {
          key: 'template',
          label: 'Drafted from template',
          to: '/templates',
          value: `Template #${contract.template_id}`,
        }
      : null,
  ].filter((item): item is { key: string; label: string; to: string; value: string } => item !== null)

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      {structural.length > 0 ? (
        <Card>
          <CardHeader
            level={3}
            title="Where this contract came from"
            description="Relationships the record itself carries."
          />
          <ul style={{ listStyle: 'none', display: 'grid', gap: 8 }}>
            {structural.map((item) => (
              <li key={item.key}>
                <RouterLink
                  to={item.to}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    padding: '9px 11px',
                    borderRadius: 'var(--radius-md)',
                    border: '1px solid rgb(var(--color-border))',
                    color: 'var(--color-text)',
                  }}
                >
                  <span style={{ fontSize: 12, color: 'var(--color-text-muted)', minWidth: 150 }}>
                    {item.label}
                  </span>
                  <span style={{ fontSize: 13, fontWeight: 600 }}>{item.value}</span>
                  <ExternalLink size={13} aria-hidden style={{ marginLeft: 'auto', color: 'var(--color-text-subtle)' }} />
                </RouterLink>
              </li>
            ))}
          </ul>
        </Card>
      ) : null}

      <Card padded={false}>
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: 12,
            flexWrap: 'wrap',
            padding: '12px 14px',
            borderBottom: '1px solid rgb(var(--color-border))',
          }}
        >
          <div>
            <h3 style={{ fontSize: 14, fontWeight: 700 }}>Linked records</h3>
            <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
              Contracts that have to be read alongside this one.
            </p>
          </div>
          {canEdit ? (
            <Button size="sm" variant="primary" icon={<Plus size={14} />} onClick={() => setAdding(true)}>
              Link a contract
            </Button>
          ) : null}
        </div>

        {links.error ? (
          <ErrorState
            title="Could not load the linked records"
            detail={links.error.message}
            onRetry={links.reload}
          />
        ) : (
          <DataTable
            columns={columns}
            rows={links.data ?? []}
            rowKey={(link) => link.id}
            loading={links.loading}
            caption="Records linked to this contract"
            emptyTitle="Nothing linked yet"
            emptyDescription="Link the framework agreement, the NDA that came first or the contract this one replaces, so the next person reading it has the whole picture."
            emptyAction={
              canEdit ? (
                <Button variant="primary" icon={<Link2 size={15} />} onClick={() => setAdding(true)}>
                  Link a contract
                </Button>
              ) : undefined
            }
          />
        )}
      </Card>

      <Modal
        open={adding}
        onClose={reset}
        title="Link a contract"
        description="Search the repository, then say how the two relate."
        width={560}
        footer={
          <>
            <Button variant="secondary" onClick={reset} disabled={saving}>
              Cancel
            </Button>
            <Button variant="primary" loading={saving} onClick={() => void create()}>
              Add link
            </Button>
          </>
        }
      >
        <div style={{ display: 'grid', gap: 14 }}>
          <Select
            label="Relationship"
            value={linkType}
            error={errors.link_type}
            onChange={(event) => setLinkType(event.target.value)}
            options={LINK_TYPES}
          />

          <Field
            label="Contract"
            htmlFor="link-search"
            required
            error={errors.related_contract_id}
            hint={chosen ? undefined : 'Type at least two characters of a title, number or counterparty.'}
            describedById="link-search-desc"
          >
            <div style={{ position: 'relative' }}>
              <Search
                size={14}
                aria-hidden
                style={{
                  position: 'absolute',
                  left: 10,
                  top: '50%',
                  transform: 'translateY(-50%)',
                  color: 'var(--color-text-subtle)',
                }}
              />
              <input
                id="link-search"
                type="search"
                value={chosen ? chosen.title : query}
                autoComplete="off"
                aria-describedby="link-search-desc"
                onChange={(event) => {
                  setChosen(null)
                  setQuery(event.target.value)
                }}
                placeholder="Search contracts"
                style={{
                  width: '100%',
                  height: 36,
                  padding: '0 10px 0 30px',
                  borderRadius: 'var(--radius-md)',
                  border: `1px solid ${
                    errors.related_contract_id ? 'var(--color-danger)' : 'rgb(var(--color-border-strong))'
                  }`,
                  background: 'var(--color-bg-card)',
                  color: 'var(--color-text)',
                  fontSize: 13.5,
                }}
              />
            </div>
          </Field>

          <div aria-live="polite">
            {chosen ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)' }}>
                Linking to <strong>{chosen.title}</strong> ({chosen.contract_number}).{' '}
                <button
                  type="button"
                  onClick={() => setChosen(null)}
                  style={{
                    background: 'none',
                    border: 'none',
                    padding: 0,
                    cursor: 'pointer',
                    color: 'rgb(var(--color-primary))',
                    fontWeight: 700,
                    fontSize: 12.5,
                  }}
                >
                  Change
                </button>
              </p>
            ) : search.loading ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>Searching…</p>
            ) : search.error ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-danger)' }}>{search.error.message}</p>
            ) : debounced.length >= 2 && candidates.length === 0 ? (
              <p style={{ fontSize: 12.5, color: 'var(--color-text-muted)' }}>
                No contract matches “{debounced}”.
              </p>
            ) : candidates.length > 0 ? (
              <ul
                style={{
                  listStyle: 'none',
                  border: '1px solid rgb(var(--color-border))',
                  borderRadius: 'var(--radius-md)',
                  maxHeight: 220,
                  overflowY: 'auto',
                }}
              >
                {candidates.map((item) => (
                  <li key={item.id}>
                    <button
                      type="button"
                      onClick={() => setChosen(item)}
                      style={{
                        display: 'block',
                        width: '100%',
                        textAlign: 'left',
                        padding: '8px 11px',
                        background: 'none',
                        border: 'none',
                        borderBottom: '1px solid var(--color-border-light)',
                        cursor: 'pointer',
                      }}
                    >
                      <span style={{ display: 'block', fontSize: 13, fontWeight: 600 }}>{item.title}</span>
                      <span style={{ display: 'block', fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                        {item.contract_number} · {item.counterparty_name ?? 'No counterparty'}
                      </span>
                    </button>
                  </li>
                ))}
              </ul>
            ) : null}
          </div>

          <Textarea
            label="Note"
            rows={2}
            value={note}
            error={errors.note}
            hint="Why these two belong together, for whoever reads this next."
            onChange={(event) => setNote(event.target.value)}
          />
        </div>
      </Modal>

      <ConfirmDialog
        open={removing !== null}
        busy={deleting}
        tone="danger"
        confirmLabel="Remove link"
        title="Remove this link"
        message="The link is removed from both contracts. Neither contract itself is changed."
        onClose={() => setRemoving(null)}
        onConfirm={() => void remove()}
      />
    </div>
  )
}
