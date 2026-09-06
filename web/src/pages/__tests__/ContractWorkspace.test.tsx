import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Contract } from '../../types/contracts'

/**
 * What is worth protecting in the workspace is the wiring, not the markup:
 * sixteen tabs must not turn into sixteen requests, an action the status or the
 * permission set forbids must not be offered, and a contract that is not there
 * must say so rather than spinning.
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

let permissions: string[] = []

vi.mock('../../context/SessionProvider', () => ({
  useSession: () => ({
    status: 'ready' as const,
    session: null,
    error: null,
    can: (permission: string) => permissions.includes(permission),
    canAny: (list: string[]) => list.some((permission) => permissions.includes(permission)),
    refresh: async () => {},
  }),
}))

import ContractWorkspace from '../ContractWorkspace'
import { ToastProvider } from '../../context/ToastProvider'
import { ApiError } from '../../services/apiClient'
import { PERMISSION } from '../../types/permissions'

function contract(overrides: Partial<Contract> = {}): Contract {
  return {
    id: 42,
    uuid: 'ctr-42',
    contract_number: 'CTR-2026-0042',
    title: 'Master services agreement — Acme',
    status: 'draft',
    lifecycle_stage: null,
    counterparty_name: 'Acme Pvt Ltd',
    effective_date: '2026-01-01',
    expiry_date: '2026-12-31',
    notice_deadline: '2026-10-02',
    renewal_type: 'manual',
    auto_renewal: false,
    currency: 'INR',
    total_value: '250000.00',
    risk_level: 'medium',
    ai_risk_score: 44,
    health_score: 81,
    owner_uuid: 'usr-9',
    approval_status: null,
    signing_status: null,
    archived_at: null,
    contract_type_id: 3,
    contract_type_name: 'MSA',
    department_id: null,
    department_name: 'Legal',
    is_favourite: false,
    days_to_expiry: 200,
    days_to_notice: 120,
    created_at: '2026-01-01T09:00:00Z',
    updated_at: '2026-02-01T09:00:00Z',
    description: 'Services across all Acme entities.',
    commencement_date: '2026-01-01',
    execution_date: null,
    renewal_frequency: 'annual',
    notice_period_days: 90,
    governing_law: 'India',
    jurisdiction: 'Mumbai',
    recurring_value: null,
    payment_frequency: 'monthly',
    billing_frequency: 'monthly',
    commercial_summary: null,
    notes: null,
    custom_fields: null,
    verification_state: null,
    parent_contract_id: null,
    request_id: null,
    template_id: null,
    created_by: null,
    updated_by: null,
    tabs: {
      parties: 2,
      documents: 1,
      clauses: 0,
      obligations: 3,
      milestones: 0,
      approvals: 0,
      versions: 2,
      amendments: 0,
      risks: 1,
      comments: 0,
      links: 0,
    },
    ...overrides,
  }
}

let contractResponse: Contract = contract()
let contractFailure: unknown = null

function renderWorkspace() {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={['/contracts/42']}>
        <Routes>
          <Route path="/contracts/:id" element={<ContractWorkspace />} />
        </Routes>
      </MemoryRouter>
    </ToastProvider>,
  )
}

function pathsRequested(): string[] {
  return apiGet.mock.calls.map((call) => String(call[0]))
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  permissions = []
  contractResponse = contract()
  contractFailure = null

  apiGet.mockImplementation((path: string) => {
    if (path === '/contracts/42') {
      return contractFailure ? Promise.reject(contractFailure) : Promise.resolve(contractResponse)
    }
    if (path === '/contracts/42/health') {
      return Promise.resolve({ overall: 81, explanations: ['Notice window is comfortable.'] })
    }
    if (path === '/contracts/42/obligations') return Promise.resolve([])
    if (path === '/contracts/42/activity') {
      return Promise.resolve({ items: [], total: 0, page: 1, per_page: 20, total_pages: 0 })
    }
    return Promise.resolve(null)
  })
})

describe('ContractWorkspace', () => {
  it('shows who the contract is with and what state it is in', async () => {
    renderWorkspace()

    expect(await screen.findByRole('heading', { name: 'Master services agreement — Acme' })).toBeInTheDocument()
    expect(screen.getByText(/CTR-2026-0042/)).toBeInTheDocument()
    expect(screen.getByText(/Acme Pvt Ltd/)).toBeInTheDocument()
    expect(screen.getByText('Draft')).toBeInTheDocument()
    expect(screen.getByText('Medium')).toBeInTheDocument()
  })

  it('fetches only the tab that is open, then the next one when it is opened', async () => {
    const user = userEvent.setup()
    renderWorkspace()

    // Overview is the landing tab, so its two reads are expected and no others.
    await screen.findByText('Key dates')
    await waitFor(() => expect(pathsRequested()).toContain('/contracts/42/health'))
    expect(pathsRequested()).not.toContain('/contracts/42/activity')

    await user.click(screen.getByRole('tab', { name: /^Activity/ }))

    await waitFor(() => expect(pathsRequested()).toContain('/contracts/42/activity'))
    expect(apiGet.mock.calls.filter((call) => call[0] === '/contracts/42/health')).toHaveLength(1)
  })

  it('offers no actions the permissions do not allow', async () => {
    renderWorkspace()
    await screen.findByRole('heading', { name: 'Master services agreement — Acme' })

    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /submit for approval/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: /commercials/i })).not.toBeInTheDocument()
  })

  it('offers editing and approval on a draft when the user may do both', async () => {
    permissions = [PERMISSION.CONTRACT_EDIT, PERMISSION.COMMERCIALS_VIEW]
    renderWorkspace()
    await screen.findByRole('heading', { name: 'Master services agreement — Acme' })

    expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /submit for approval/i })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /commercials/i })).toBeInTheDocument()
    // Signature is an approved-contract action; this one is a draft.
    expect(screen.queryByRole('button', { name: /send for signature/i })).not.toBeInTheDocument()
  })

  it('does not offer to edit a terminated contract', async () => {
    permissions = [PERMISSION.CONTRACT_EDIT]
    contractResponse = contract({ status: 'terminated' })
    renderWorkspace()
    await screen.findByRole('heading', { name: 'Master services agreement — Acme' })

    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
  })

  it('says so when the contract is not there', async () => {
    contractFailure = new ApiError('No such contract.', 404, 'NOT_FOUND')
    renderWorkspace()

    expect(await screen.findByText('Contract not found')).toBeInTheDocument()
  })

  it('shows the API message and a retry when the read fails', async () => {
    contractFailure = new ApiError('The server had a problem with that request.', 503, 'UPSTREAM')
    renderWorkspace()

    expect(await screen.findByText('Could not open this contract')).toBeInTheDocument()
    expect(screen.getByText('The server had a problem with that request.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
  })
})
