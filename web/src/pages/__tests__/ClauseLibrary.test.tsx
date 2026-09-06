import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { ClauseCategory, LibraryClauseItem, LibraryClauseVersion, Paged } from '../../types/contracts'

/**
 * The library's job is to hold three positions per clause — what we ask for,
 * what we will accept, what we will not — and to keep the wording it used to
 * have. Reading an old version must never quietly become the current one.
 */

const apiGet = vi.fn()
const apiPut = vi.fn()

vi.mock('../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../services/apiClient')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGet(...args),
      put: (...args: unknown[]) => apiPut(...args),
    },
  }
})

vi.mock('../../context/SessionProvider', () => ({
  useSession: () => ({
    status: 'ready' as const,
    session: null,
    error: null,
    can: () => true,
    canAny: () => true,
    refresh: async () => {},
  }),
}))

import ClauseLibrary from '../ClauseLibrary'
import { ToastProvider } from '../../context/ToastProvider'

const CATEGORIES: ClauseCategory[] = [
  {
    id: 1,
    code: 'liability',
    name: 'Liability',
    description: null,
    risk_weight: 9,
    is_system: true,
    sort_order: 10,
    clause_count: 4,
  },
  {
    id: 2,
    code: 'confidentiality',
    name: 'Confidentiality',
    description: null,
    risk_weight: 6,
    is_system: true,
    sort_order: 20,
    clause_count: 2,
  },
]

function clause(overrides: Partial<LibraryClauseItem> = {}): LibraryClauseItem {
  return {
    id: 12,
    uuid: 'cls-12',
    category_id: 1,
    category_name: 'Liability',
    name: 'Limitation of liability — capped at fees paid',
    description: 'Our standard cap for services agreements.',
    standard_text: 'Neither party shall be liable for indirect loss; liability is capped at fees paid.',
    fallback_text: 'Liability is capped at twice the fees paid in the preceding twelve months.',
    prohibited_wording: 'Unlimited liability for any breach whatsoever.',
    risk_classification: 'high',
    applicable_types: [3],
    jurisdiction: 'India',
    version: 3,
    approval_status: 'approved',
    effective_from: '2026-01-01',
    effective_to: null,
    author_uuid: null,
    approver_uuid: null,
    approved_at: '2026-01-05T09:00:00Z',
    is_system: false,
    archived_at: null,
    created_at: '2025-12-01T09:00:00Z',
    updated_at: '2026-02-01T09:00:00Z',
    ...overrides,
  }
}

const VERSIONS: LibraryClauseVersion[] = [
  {
    id: 30,
    clause_id: 12,
    version: 2,
    standard_text: 'Liability is capped at the total contract value.',
    fallback_text: null,
    change_note: 'Tightened the cap from contract value to fees paid',
    author_uuid: null,
    author_name: 'R. Mehta',
    created_at: '2026-01-20T09:00:00Z',
  },
]

function paged(items: LibraryClauseItem[]): Paged<LibraryClauseItem> {
  return { items, total: items.length, page: 1, per_page: 25, total_pages: 1 }
}

let listResponse = paged([clause()])

function renderLibrary() {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={['/clauses']}>
        <ClauseLibrary />
      </MemoryRouter>
    </ToastProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPut.mockReset()
  listResponse = paged([clause()])

  apiGet.mockImplementation((path: string) => {
    if (path === '/clauses') return Promise.resolve(listResponse)
    if (path === '/clause-categories') return Promise.resolve(CATEGORIES)
    if (path === '/clauses/12/versions') return Promise.resolve(VERSIONS)
    if (path === '/settings/contract-types') return Promise.resolve([{ id: 3, name: 'MSA' }])
    return Promise.resolve(null)
  })
})

describe('ClauseLibrary', () => {
  it('lists the categories alongside the clauses they hold', async () => {
    renderLibrary()

    expect(await screen.findByRole('button', { name: /Liability\s*4/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Confidentiality\s*2/ })).toBeInTheDocument()
    expect(screen.getByText('Limitation of liability — capped at fees paid')).toBeInTheDocument()
  })

  it('narrows the server query to the chosen category', async () => {
    const user = userEvent.setup()
    renderLibrary()

    await user.click(await screen.findByRole('button', { name: /Confidentiality\s*2/ }))

    await waitFor(() => {
      const queries = apiGet.mock.calls.filter((call) => call[0] === '/clauses')
      expect(queries[queries.length - 1][1]).toMatchObject({ category_id: 2, page: 1 })
    })
  })

  it('opens a clause with all three positions filled in', async () => {
    const user = userEvent.setup()
    renderLibrary()

    await user.click(await screen.findByRole('button', { name: 'Limitation of liability — capped at fees paid' }))

    expect(screen.getByLabelText(/standard wording/i)).toHaveValue(
      'Neither party shall be liable for indirect loss; liability is capped at fees paid.',
    )
    expect(screen.getByLabelText(/fallback wording/i)).toHaveValue(
      'Liability is capped at twice the fees paid in the preceding twelve months.',
    )
    expect(screen.getByLabelText(/prohibited wording/i)).toHaveValue(
      'Unlimited liability for any breach whatsoever.',
    )
  })

  it('shows an earlier wording without replacing the current one', async () => {
    const user = userEvent.setup()
    renderLibrary()

    await user.click(await screen.findByRole('button', { name: 'Limitation of liability — capped at fees paid' }))
    await user.click(await screen.findByRole('button', { name: /show wording/i }))

    expect(screen.getByText('Liability is capped at the total contract value.')).toBeInTheDocument()
    expect(screen.getByText('Tightened the cap from contract value to fees paid')).toBeInTheDocument()
    // The live field is untouched by reading the history.
    expect(screen.getByLabelText(/standard wording/i)).toHaveValue(
      'Neither party shall be liable for indirect loss; liability is capped at fees paid.',
    )
  })

  it('sends every position, the risk class and the applicable types on save', async () => {
    const user = userEvent.setup()
    apiPut.mockResolvedValue(clause({ version: 4 }))
    renderLibrary()

    await user.click(await screen.findByRole('button', { name: 'Limitation of liability — capped at fees paid' }))
    await user.click(screen.getByRole('button', { name: /save clause/i }))

    await waitFor(() =>
      expect(apiPut).toHaveBeenCalledWith(
        '/clauses/12',
        expect.objectContaining({
          risk_classification: 'high',
          approval_status: 'approved',
          applicable_types: [3],
          jurisdiction: 'India',
          prohibited_wording: 'Unlimited liability for any breach whatsoever.',
        }),
      ),
    )
  })

  it('refuses a save with no standard wording and says so on the field', async () => {
    const user = userEvent.setup()
    renderLibrary()

    await user.click(await screen.findByRole('button', { name: 'Limitation of liability — capped at fees paid' }))
    await user.clear(screen.getByLabelText(/standard wording/i))
    await user.click(screen.getByRole('button', { name: /save clause/i }))

    expect(await screen.findByText('The standard wording is what this clause is.')).toBeInTheDocument()
    expect(apiPut).not.toHaveBeenCalled()
  })
})
