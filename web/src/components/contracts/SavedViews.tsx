import { useState } from 'react'
import { Bookmark, Trash2 } from 'lucide-react'

import { Button, Input, Modal } from '../ui'
import type { ContractFilters, ContractSort } from '../../types/contracts'

/**
 * Named filter sets, remembered between visits.
 *
 * The API contract has no saved-views endpoint, so these live in this browser's
 * localStorage. That is a real limitation and worth stating plainly in the UI:
 * a view saved on a laptop is not there on a phone, and clearing site data
 * loses it. When the server grows `GET/POST /settings/saved-views` the storage
 * functions below are the only thing that has to change.
 */

const STORAGE_KEY = 'aic.contracts.savedViews'

export interface SavedViewPayload {
  filters: ContractFilters
  sort: ContractSort
  columns: string[]
  perPage: number
}

export interface SavedView extends SavedViewPayload {
  id: string
  name: string
}

function isSavedView(value: unknown): value is SavedView {
  if (typeof value !== 'object' || value === null) return false
  const candidate = value as Partial<SavedView>
  return (
    typeof candidate.id === 'string' &&
    typeof candidate.name === 'string' &&
    typeof candidate.filters === 'object' &&
    candidate.filters !== null
  )
}

export function readSavedViews(): SavedView[] {
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (!raw) return []
    const parsed: unknown = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed.filter(isSavedView) : []
  } catch {
    return []
  }
}

function writeSavedViews(views: SavedView[]): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(views))
  } catch {
    /* storage can be full or blocked; the view simply is not remembered */
  }
}

function newId(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto
    ? crypto.randomUUID()
    : `view-${Date.now()}-${Math.round(Math.random() * 1e6)}`
}

export function SavedViews({
  current,
  activeId,
  onApply,
  onClear,
}: {
  /** What "save" would capture — the filters, sort, columns and page size in use. */
  current: SavedViewPayload
  activeId: string | null
  onApply: (view: SavedView) => void
  onClear: () => void
}) {
  const [views, setViews] = useState<SavedView[]>(() => readSavedViews())
  const [managing, setManaging] = useState(false)
  const [name, setName] = useState('')
  const [nameError, setNameError] = useState<string | undefined>(undefined)

  const persist = (next: SavedView[]) => {
    setViews(next)
    writeSavedViews(next)
  }

  const active = views.find((view) => view.id === activeId) ?? null

  const openManager = () => {
    setName(active?.name ?? '')
    setNameError(undefined)
    setManaging(true)
  }

  const saveAsNew = () => {
    const trimmed = name.trim()
    if (!trimmed) {
      setNameError('Give the view a name.')
      return
    }
    if (views.some((view) => view.name.toLowerCase() === trimmed.toLowerCase())) {
      setNameError('A view with that name already exists.')
      return
    }
    const view: SavedView = { id: newId(), name: trimmed, ...current }
    persist([...views, view])
    onApply(view)
    setManaging(false)
  }

  const updateActive = () => {
    if (!active) return
    const next = views.map((view) =>
      view.id === active.id ? { ...view, ...current, name: name.trim() || view.name } : view,
    )
    persist(next)
    const updated = next.find((view) => view.id === active.id)
    if (updated) onApply(updated)
    setManaging(false)
  }

  const remove = (id: string) => {
    persist(views.filter((view) => view.id !== id))
    if (id === activeId) onClear()
  }

  return (
    <>
      <label style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
        <span className="ct-sr-only">Saved view</span>
        <select
          value={activeId ?? ''}
          onChange={(event) => {
            const view = views.find((item) => item.id === event.target.value)
            if (view) onApply(view)
            else onClear()
          }}
          style={{
            height: 30,
            maxWidth: 190,
            padding: '0 8px',
            borderRadius: 'var(--radius-md)',
            border: '1px solid rgb(var(--color-border-strong))',
            background: 'var(--color-bg-card)',
            color: 'var(--color-text)',
            fontSize: 12.5,
            fontWeight: 600,
          }}
        >
          <option value="">All contracts</option>
          {views.map((view) => (
            <option key={view.id} value={view.id}>
              {view.name}
            </option>
          ))}
        </select>
      </label>

      <Button size="sm" variant="secondary" icon={<Bookmark size={14} />} onClick={openManager}>
        Save view
      </Button>

      <Modal
        open={managing}
        onClose={() => setManaging(false)}
        title="Saved views"
        description="Views are kept in this browser only — the API has no place to store them yet."
        width={480}
        footer={
          <>
            <Button variant="ghost" onClick={() => setManaging(false)}>
              Close
            </Button>
            {active ? (
              <Button variant="secondary" onClick={updateActive}>
                Update “{active.name}”
              </Button>
            ) : null}
            <Button variant="primary" onClick={saveAsNew}>
              Save as new
            </Button>
          </>
        }
      >
        <Input
          label="View name"
          value={name}
          error={nameError}
          placeholder="Expiring vendor contracts"
          onChange={(event) => {
            setName(event.target.value)
            setNameError(undefined)
          }}
        />

        {views.length > 0 ? (
          <div style={{ marginTop: 18 }}>
            <h3
              style={{
                fontSize: 12.5,
                fontWeight: 700,
                color: 'var(--color-text-secondary)',
                marginBottom: 8,
              }}
            >
              Your views
            </h3>
            <ul style={{ listStyle: 'none', display: 'grid', gap: 4 }}>
              {views.map((view) => (
                <li
                  key={view.id}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 10,
                    padding: '7px 10px',
                    borderRadius: 'var(--radius-sm)',
                    background:
                      view.id === activeId ? 'var(--color-primary-muted)' : 'var(--color-bg-subtle)',
                  }}
                >
                  <button
                    type="button"
                    onClick={() => {
                      onApply(view)
                      setManaging(false)
                    }}
                    style={{
                      background: 'none',
                      border: 'none',
                      padding: 0,
                      cursor: 'pointer',
                      font: 'inherit',
                      fontSize: 13,
                      fontWeight: 600,
                      textAlign: 'left',
                      color: 'var(--color-text)',
                    }}
                  >
                    {view.name}
                  </button>
                  <button
                    type="button"
                    onClick={() => remove(view.id)}
                    aria-label={`Delete view ${view.name}`}
                    style={{
                      background: 'none',
                      border: 'none',
                      cursor: 'pointer',
                      color: 'var(--color-text-muted)',
                      lineHeight: 0,
                      padding: 4,
                    }}
                  >
                    <Trash2 size={14} aria-hidden />
                  </button>
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <p style={{ marginTop: 16, fontSize: 12.5, color: 'var(--color-text-muted)' }}>
            No views yet. Set the filters you want, then save them here.
          </p>
        )}
      </Modal>
    </>
  )
}
