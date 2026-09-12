import { useState } from 'react'
import { Camera, History, Mail, Pencil, Phone, Plus, Trash2, Users } from 'lucide-react'

import {
  Button,
  Card,
  Checkbox,
  Chip,
  ConfirmDialog,
  DataTable,
  Drawer,
  EmptyState,
  ErrorState,
  Input,
  Modal,
  Select,
  Textarea,
} from '../../ui'
import type { Column } from '../../ui'
import { CounterpartyPicker } from '../CounterpartyPicker'
import { useSession } from '../../../context/SessionProvider'
import { useToast } from '../../../context/ToastProvider'
import { useApiResource } from '../../../hooks/useApiResource'
import { ApiError, api } from '../../../services/apiClient'
import type { FieldErrors } from '../../../services/apiClient'
import { PERMISSION } from '../../../types/permissions'
import type { Contract, ContractParty, PartySnapshot } from '../../../types/contracts'
import { formatDateTime, humanise } from '../../../utils/format'

/**
 * Who is bound by this contract.
 *
 * A party is not the same thing as the counterparty name on the contract row:
 * an agreement can have a guarantor, an internal entity and two signatories,
 * and the name printed on a list view is only the headline. Each party can also
 * be snapshotted — Contacts keeps changing after signature, and what matters in
 * a dispute is the address the notice clause pointed at on the day.
 */

interface Props {
  contractId: number
  contract: Contract
  onChanged: () => void
}

const PARTY_ROLES = [
  { value: 'customer', label: 'Customer' },
  { value: 'vendor', label: 'Vendor or supplier' },
  { value: 'internal', label: 'Our entity' },
  { value: 'partner', label: 'Partner' },
  { value: 'guarantor', label: 'Guarantor' },
  { value: 'other', label: 'Other' },
]

interface PartyForm {
  party_role: string
  name: string
  legal_name: string
  email: string
  phone: string
  address: string
  registration_number: string
  signatory_name: string
  signatory_email: string
  signatory_designation: string
  is_primary: boolean
  contact_uuid: string | null
}

const EMPTY_FORM: PartyForm = {
  party_role: 'customer',
  name: '',
  legal_name: '',
  email: '',
  phone: '',
  address: '',
  registration_number: '',
  signatory_name: '',
  signatory_email: '',
  signatory_designation: '',
  is_primary: false,
  contact_uuid: null,
}

function toForm(party: ContractParty): PartyForm {
  return {
    party_role: party.party_role ?? 'customer',
    name: party.name ?? '',
    legal_name: party.legal_name ?? '',
    email: party.email ?? '',
    phone: party.phone ?? '',
    address: party.address ?? '',
    registration_number: party.registration_number ?? '',
    signatory_name: party.signatory_name ?? '',
    signatory_email: party.signatory_email ?? '',
    signatory_designation: party.signatory_designation ?? '',
    is_primary: party.is_primary,
    contact_uuid: party.contact_uuid,
  }
}

export function PartiesTab({ contractId, contract, onChanged }: Props) {
  const toast = useToast()
  const { can } = useSession()
  const canEdit = can(PERMISSION.CONTRACT_EDIT)

  const [editing, setEditing] = useState<ContractParty | 'new' | null>(null)
  const [form, setForm] = useState<PartyForm>(EMPTY_FORM)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState<ContractParty | null>(null)
  const [removing, setRemoving] = useState(false)
  const [snapshotting, setSnapshotting] = useState<number | null>(null)
  const [historyFor, setHistoryFor] = useState<ContractParty | null>(null)

  const parties = useApiResource<ContractParty[]>(
    (signal) => api.get<ContractParty[]>(`/contracts/${contractId}/parties`, undefined, signal),
    [contractId],
  )

  const rows = parties.data ?? []

  const openNew = () => {
    setForm({ ...EMPTY_FORM, name: rows.length === 0 ? (contract.counterparty_name ?? '') : '' })
    setErrors({})
    setEditing('new')
  }

  const openEdit = (party: ContractParty) => {
    setForm(toForm(party))
    setErrors({})
    setEditing(party)
  }

  const save = async () => {
    if (!editing) return

    setSaving(true)
    setErrors({})

    const body = {
      ...form,
      legal_name: form.legal_name || null,
      email: form.email || null,
      phone: form.phone || null,
      address: form.address || null,
      registration_number: form.registration_number || null,
      signatory_name: form.signatory_name || null,
      signatory_email: form.signatory_email || null,
      signatory_designation: form.signatory_designation || null,
    }

    try {
      if (editing === 'new') {
        await api.post<ContractParty>(`/contracts/${contractId}/parties`, body)
        toast.success('Party added')
      } else {
        await api.put<ContractParty>(`/parties/${editing.id}`, body)
        toast.success('Party updated')
      }
      setEditing(null)
      parties.reload()
      onChanged()
    } catch (err) {
      // A 422 names the fields it refused; putting that in a toast would make
      // the user guess which box the server meant.
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
      } else {
        toast.error('Could not save the party', err instanceof Error ? err.message : undefined)
      }
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (!deleting) return
    setRemoving(true)
    try {
      await api.delete(`/parties/${deleting.id}`)
      toast.success('Party removed')
      setDeleting(null)
      parties.reload()
      onChanged()
    } catch (err) {
      toast.error('Could not remove the party', err instanceof Error ? err.message : undefined)
    } finally {
      setRemoving(false)
    }
  }

  const snapshot = async (party: ContractParty) => {
    setSnapshotting(party.id)
    try {
      await api.post<PartySnapshot>(`/parties/${party.id}/snapshot`)
      toast.success('Snapshot captured', `${party.name} as they stand today has been recorded.`)
      parties.reload()
    } catch (err) {
      toast.error('Could not capture the snapshot', err instanceof Error ? err.message : undefined)
    } finally {
      setSnapshotting(null)
    }
  }

  const columns: Column<ContractParty>[] = [
    {
      key: 'name',
      header: 'Party',
      render: (party) => (
        <div style={{ minWidth: 180 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
            <span style={{ fontWeight: 600, color: 'var(--color-text)' }}>{party.name}</span>
            {party.is_primary ? (
              <Chip size="sm" tone="primary">
                Primary
              </Chip>
            ) : null}
          </div>
          {party.legal_name && party.legal_name !== party.name ? (
            <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)', marginTop: 2 }}>
              {party.legal_name}
            </div>
          ) : null}
        </div>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      render: (party) => (party.party_role ? <Chip size="sm">{humanise(party.party_role)}</Chip> : '—'),
    },
    {
      key: 'contact',
      header: 'Contact',
      hideBelow: 'sm',
      render: (party) => (
        <div style={{ display: 'grid', gap: 2, fontSize: 12.5 }}>
          {party.email ? (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
              <Mail size={12} aria-hidden style={{ color: 'var(--color-text-subtle)' }} />
              {party.email}
            </span>
          ) : null}
          {party.phone ? (
            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
              <Phone size={12} aria-hidden style={{ color: 'var(--color-text-subtle)' }} />
              {party.phone}
            </span>
          ) : null}
          {!party.email && !party.phone ? <span style={{ color: 'var(--color-text-subtle)' }}>—</span> : null}
        </div>
      ),
    },
    {
      key: 'signatory',
      header: 'Signatory',
      hideBelow: 'md',
      render: (party) =>
        party.signatory_name ? (
          <div style={{ fontSize: 12.5 }}>
            <div>{party.signatory_name}</div>
            {party.signatory_designation ? (
              <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                {party.signatory_designation}
              </div>
            ) : null}
          </div>
        ) : (
          <span style={{ color: 'var(--color-text-subtle)' }}>Not named</span>
        ),
    },
    {
      key: 'snapshot',
      header: 'Snapshot',
      hideBelow: 'lg',
      render: (party) =>
        party.snapshot_at ? (
          <span style={{ fontSize: 12 }}>{formatDateTime(party.snapshot_at)}</span>
        ) : (
          <span style={{ color: 'var(--color-text-subtle)', fontSize: 12 }}>None</span>
        ),
    },
    {
      key: 'actions',
      header: '',
      srLabel: 'Actions',
      align: 'right',
      width: 150,
      render: (party) => (
        <div style={{ display: 'inline-flex', gap: 4 }}>
          <Button
            size="sm"
            variant="ghost"
            aria-label={`Snapshot history for ${party.name}`}
            icon={<History size={14} />}
            onClick={() => setHistoryFor(party)}
          />
          {canEdit ? (
            <>
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Capture a snapshot of ${party.name}`}
                icon={<Camera size={14} />}
                loading={snapshotting === party.id}
                onClick={() => void snapshot(party)}
              />
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Edit ${party.name}`}
                icon={<Pencil size={14} />}
                onClick={() => openEdit(party)}
              />
              <Button
                size="sm"
                variant="ghost"
                aria-label={`Remove ${party.name}`}
                icon={<Trash2 size={14} />}
                onClick={() => setDeleting(party)}
              />
            </>
          ) : null}
        </div>
      ),
    },
  ]

  if (parties.error) {
    return (
      <Card>
        <ErrorState
          title="Could not load the parties"
          detail={parties.error.message}
          onRetry={parties.reload}
        />
      </Card>
    )
  }

  return (
    <>
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
            <h3 style={{ fontSize: 14, fontWeight: 700 }}>Parties</h3>
            <p style={{ fontSize: 12.5, color: 'var(--color-text-secondary)', marginTop: 2 }}>
              Everyone bound by this agreement, and who signs for them.
            </p>
          </div>
          {canEdit ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={openNew}>
              Add party
            </Button>
          ) : null}
        </div>

        <p aria-live="polite" className="ct-sr-only">
          {parties.loading ? 'Loading parties' : `${rows.length} parties on this contract`}
        </p>

        <DataTable
          columns={columns}
          rows={rows}
          rowKey={(party) => party.id}
          loading={parties.loading}
          caption="Parties to this contract"
          emptyTitle="No parties recorded"
          emptyDescription="Add the entities that sign this agreement — their legal names, notice addresses and signatories are what a dispute turns on."
          emptyAction={
            canEdit ? (
              <Button variant="primary" icon={<Plus size={15} />} onClick={openNew}>
                Add the first party
              </Button>
            ) : undefined
          }
        />
      </Card>

      <Modal
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={editing === 'new' ? 'Add a party' : 'Edit party'}
        description="Name the entity as it appears in the agreement, not as it is known internally."
        width={640}
        footer={
          <>
            <Button variant="secondary" onClick={() => setEditing(null)} disabled={saving}>
              Cancel
            </Button>
            <Button variant="primary" loading={saving} onClick={() => void save()}>
              {editing === 'new' ? 'Add party' : 'Save changes'}
            </Button>
          </>
        }
      >
        <div style={{ display: 'grid', gap: 14 }}>
          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))' }}>
            <Select
              label="Role"
              required
              value={form.party_role}
              error={errors.party_role}
              onChange={(event) => setForm({ ...form, party_role: event.target.value })}
              options={PARTY_ROLES}
            />
            <Input
              label="Legal name"
              value={form.legal_name}
              error={errors.legal_name}
              hint="As registered, if it differs from the trading name."
              onChange={(event) => setForm({ ...form, legal_name: event.target.value })}
            />
          </div>

          <CounterpartyPicker
            label="Name"
            required
            value={form.name}
            error={errors.name}
            onChange={(name, contact) =>
              setForm((current) => ({
                ...current,
                name,
                contact_uuid: contact?.uuid ?? null,
                email: contact?.email ?? current.email,
                phone: contact?.phone ?? current.phone,
                legal_name:
                  contact?.organisation ?? contact?.organization ?? contact?.company_name ?? current.legal_name,
              }))
            }
          />

          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))' }}>
            <Input
              label="Email"
              type="email"
              value={form.email}
              error={errors.email}
              onChange={(event) => setForm({ ...form, email: event.target.value })}
            />
            <Input
              label="Phone"
              value={form.phone}
              error={errors.phone}
              onChange={(event) => setForm({ ...form, phone: event.target.value })}
            />
            <Input
              label="Registration number"
              value={form.registration_number}
              error={errors.registration_number}
              onChange={(event) => setForm({ ...form, registration_number: event.target.value })}
            />
          </div>

          <Textarea
            label="Notice address"
            rows={2}
            value={form.address}
            error={errors.address}
            hint="Where formal notices under this contract must be sent."
            onChange={(event) => setForm({ ...form, address: event.target.value })}
          />

          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(210px, 1fr))' }}>
            <Input
              label="Signatory"
              value={form.signatory_name}
              error={errors.signatory_name}
              onChange={(event) => setForm({ ...form, signatory_name: event.target.value })}
            />
            <Input
              label="Signatory designation"
              value={form.signatory_designation}
              error={errors.signatory_designation}
              onChange={(event) => setForm({ ...form, signatory_designation: event.target.value })}
            />
            <Input
              label="Signatory email"
              type="email"
              value={form.signatory_email}
              error={errors.signatory_email}
              onChange={(event) => setForm({ ...form, signatory_email: event.target.value })}
            />
          </div>

          <Checkbox
            label="Primary party"
            hint="The counterparty this contract is listed under."
            checked={form.is_primary}
            onChange={(event) => setForm({ ...form, is_primary: event.target.checked })}
          />
        </div>
      </Modal>

      <ConfirmDialog
        open={deleting !== null}
        busy={removing}
        tone="danger"
        confirmLabel="Remove party"
        title="Remove this party"
        message={`${deleting?.name ?? 'This party'} will be removed from the contract. Snapshots already captured are kept.`}
        onClose={() => setDeleting(null)}
        onConfirm={() => void remove()}
      />

      <SnapshotDrawer party={historyFor} onClose={() => setHistoryFor(null)} />
    </>
  )
}

/**
 * What a party looked like when the snapshot was taken.
 *
 * The stored payload is rendered as it arrives rather than mapped onto today's
 * field names: the point of a snapshot is that it is not reinterpreted later.
 */
function SnapshotDrawer({ party, onClose }: { party: ContractParty | null; onClose: () => void }) {
  const snapshots = useApiResource<PartySnapshot[]>(
    (signal) => api.get<PartySnapshot[]>(`/parties/${party?.id}/snapshots`, undefined, signal),
    [party?.id],
    { enabled: party !== null },
  )

  return (
    <Drawer open={party !== null} onClose={onClose} title={`Snapshots — ${party?.name ?? ''}`} width={460}>
      {snapshots.loading ? (
        <p style={{ fontSize: 13, color: 'var(--color-text-secondary)' }} role="status">
          Loading snapshots…
        </p>
      ) : snapshots.error ? (
        <ErrorState
          compact
          title="Could not load snapshots"
          detail={snapshots.error.message}
          onRetry={snapshots.reload}
        />
      ) : (snapshots.data ?? []).length === 0 ? (
        <EmptyState
          compact
          icon={<Users size={19} />}
          title="No snapshots yet"
          description="Capture one to freeze this party's details as they stand today, so a later edit in Contacts cannot rewrite what was agreed."
        />
      ) : (
        <ol style={{ listStyle: 'none', display: 'grid', gap: 12 }}>
          {(snapshots.data ?? []).map((item) => (
            <li
              key={item.id}
              style={{
                border: '1px solid rgb(var(--color-border))',
                borderRadius: 'var(--radius-md)',
                padding: 12,
              }}
            >
              <div style={{ fontSize: 12.5, fontWeight: 700 }}>{formatDateTime(item.created_at)}</div>
              {item.captured_by_name ? (
                <div style={{ fontSize: 11.5, color: 'var(--color-text-muted)' }}>
                  Captured by {item.captured_by_name}
                </div>
              ) : null}
              <dl style={{ display: 'grid', gap: 4, marginTop: 8 }}>
                {Object.entries(item.data ?? {}).map(([key, value]) => (
                  <div key={key} style={{ display: 'flex', gap: 8, fontSize: 12 }}>
                    <dt style={{ minWidth: 110, color: 'var(--color-text-muted)' }}>{humanise(key)}</dt>
                    <dd style={{ minWidth: 0, wordBreak: 'break-word' }}>
                      {value === null || value === undefined || value === '' ? '—' : String(value)}
                    </dd>
                  </div>
                ))}
              </dl>
            </li>
          ))}
        </ol>
      )}
    </Drawer>
  )
}
