import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { ContractListItem, Paged } from '../../types/contracts'

/**
 * The repository's behaviour that is worth protecting is all about the
 * conversation with the server: the search is debounced and sent, sorting is
 * server-side, and an empty table means two different things depending on
 * whether a filter is set. Those are the things asserted here.
 */

const apiGet = vi.fn()
const apiPost = vi.fn()

vi.mock('../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../services/apiClient')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGet(...args),
      post: (...args: unknown[]) => apiPost(...args),
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

import Repository from '../Repository'
import { ToastProvider } from '../../context/ToastProvider'

function contract(overrides: Partial<ContractListItem> = {}): ContractListItem {
  return {
    id: 1,
    uuid: 'ctr-1',
    contract_number: 'CTR-2026-0001',
    title: 'Master services agreement — Acme',
    status: 'active',
    lifecycle_stage: null,
    counterparty_name: 'Acme Pvt Ltd',
    effective_date: '2026-01-01',
    expiry_date: '2026-12-31',
    notice_deadline: null,
    renewal_type: null,
    auto_renewal: false,
    currency: 'INR',
    total_value: '250000.00',
    risk_level: 'medium',
    ai_risk_score: 44,
    health_score: 81,
    owner_uuid: null,
    approval_status: null,
    signing_status: null,
    archived_at: null,
    contract_type_id: 3,
    contract_type_name: 'MSA',
    department_id: null,
    department_name: null,
    is_favourite: false,
    days_to_expiry: 200,
    days_to_notice: null,
    created_at: '2026-01-01T09:00:00Z',
    updated_at: '2026-02-01T09:00:00Z',
    ...overrides,
  }
}

function page(items: ContractListItem[]): Paged<ContractListItem> {
  return { items, total: items.length, page: 1, per_page: 25, total_pages: 1 }
}

let contractsResponse: Paged<ContractListItem> = page([contract()])

function renderRepository() {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={['/contracts']}>
        <Repository />
      </MemoryRouter>
    </ToastProvider>,
  )
}

function contractsCalls(): Record<string, unknown>[] {
  return apiGet.mock.calls
    .filter((call) => call[0] === '/contracts')
    .map((call) => (call[1] ?? {}) as Record<string, unknown>)
}

beforeEach(() => {
  window.localStorage.clear()
  apiGet.mockReset()
  apiPost.mockReset()
  contractsResponse = page([contract()])

  apiGet.mockImplementation((path: string) => {
    if (path === '/contracts') return Promise.resolve(contractsResponse)
    if (path.startsWith('/settings/')) return Promise.resolve([])
    return Promise.resolve(null)
  })
  apiPost.mockResolvedValue({ favourite: true })
})

describe('Repository', () => {
  it('loads the first page and renders the contracts it gets back', async () => {
    renderRepository()

    expect(await screen.findByText('Master services agreement — Acme')).toBeInTheDocument()
    expect(contractsCalls()[0]).toMatchObject({ page: 1, per_page: 25, sort: 'updated_at', dir: 'desc' })
  })

  it('sends the search term to the server after the user stops typing', async () => {
    const user = userEvent.setup()
    renderRepository()
    await screen.findByText('Master services agreement — Acme')

    await user.type(screen.getByLabelText('Search contracts'), 'acme')

    await waitFor(
      () => {
        const queries = contractsCalls()
        expect(queries[queries.length - 1]).toMatchObject({ q: 'acme', page: 1 })
      },
      { timeout: 3000 },
    )

    // Debounced: four keystrokes must not be four round trips.
    expect(contractsCalls().filter((query) => typeof query.q === 'string').length).toBeLessThan(4)
  })

  it('asks the server to re-sort rather than reordering the page it holds', async () => {
    const user = userEvent.setup()
    renderRepository()
    await screen.findByText('Master services agreement — Acme')

    const header = screen.getByRole('columnheader', { name: /contract/i })
    await user.click(within(header).getByRole('button'))

    await waitFor(() => {
      const queries = contractsCalls()
      expect(queries[queries.length - 1]).toMatchObject({ sort: 'title', dir: 'desc' })
    })
  })

  it('offers a first step when the company has no contracts at all', async () => {
    contractsResponse = page([])
    renderRepository()

    expect(await screen.findByText('No contracts yet')).toBeInTheDocument()
    // The page header carries one "New contract" too, hence getAll.
    expect(screen.getAllByRole('button', { name: /new contract/i }).length).toBeGreaterThan(0)
    expect(screen.getByRole('button', { name: /upload documents/i })).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /clear filters/i })).not.toBeInTheDocument()
  })

  it('offers to clear the filters when a search matches nothing', async () => {
    const user = userEvent.setup()
    renderRepository()
    await screen.findByText('Master services agreement — Acme')

    contractsResponse = page([])
    await user.type(screen.getByLabelText('Search contracts'), 'nothing-matches-this')

    expect(
      await screen.findByText('No contracts match these filters', undefined, { timeout: 3000 }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /clear filters/i })).toBeInTheDocument()
    expect(screen.queryByText('No contracts yet')).not.toBeInTheDocument()
  })

  it('remembers the column choice in this browser', async () => {
    const user = userEvent.setup()
    renderRepository()
    await screen.findByText('Master services agreement — Acme')

    await user.click(screen.getByRole('button', { name: /columns/i }))
    await user.click(screen.getByRole('checkbox', { name: 'Department' }))

    await waitFor(() => {
      const stored = window.localStorage.getItem('aic.contracts.columns')
      expect(stored).toContain('department')
    })
  })

  it('stars a contract optimistically and tells the server', async () => {
    const user = userEvent.setup()
    renderRepository()
    await screen.findByText('Master services agreement — Acme')

    await user.click(screen.getByRole('button', { name: /^star /i }))

    expect(apiPost).toHaveBeenCalledWith('/contracts/1/favourite', { favourite: true })
    await waitFor(() => {
      expect(screen.getByRole('button', { name: /^unstar /i })).toBeInTheDocument()
    })
  })
})
