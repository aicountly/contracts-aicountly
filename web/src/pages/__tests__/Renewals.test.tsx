import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Paged, RenewalPipelineItem } from '../../types/contracts'

/**
 * What is worth protecting on this screen is the deadline.
 *
 * A renewal queue that shows urgency only as a colour, or that quietly drops a
 * deadline once it has passed, is how a company ends up renewed into a contract
 * it did not want. These tests assert the countdown is written in words, that a
 * passed deadline says so, that each bucket is a real question asked of the
 * server, and that a decision goes back with the fields the API expects.
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

import Renewals from '../Renewals'
import { ToastProvider } from '../../context/ToastProvider'

function renewal(overrides: Partial<RenewalPipelineItem> = {}): RenewalPipelineItem {
  return {
    id: 1,
    uuid: 'rnw-1',
    contract_id: 42,
    cycle_no: 1,
    current_expiry: '2026-12-31',
    notice_deadline: '2026-10-01',
    decision_due_date: '2026-09-15',
    proposed_start: null,
    proposed_expiry: null,
    renewal_term_months: 12,
    status: 'review_due',
    owner_uuid: null,
    recommendation: null,
    recommendation_reason: null,
    recommendation_source: null,
    decision: null,
    decision_by: null,
    decision_at: null,
    decision_notes: null,
    renegotiation_required: false,
    renewed_contract_id: null,
    notes: null,
    created_at: '2026-01-01T09:00:00Z',
    updated_at: '2026-01-01T09:00:00Z',
    contract_number: 'CTR-2026-0042',
    contract_title: 'Cloud hosting agreement — Northwind',
    contract_status: 'active',
    counterparty_name: 'Northwind Ltd',
    auto_renewal: true,
    currency: 'INR',
    total_value: '1200000.00',
    notice_period_days: 90,
    renewal_type: 'auto',
    renewal_frequency: 'annual',
    contract_type_id: 3,
    contract_type_name: 'Services',
    days_to_decision: 20,
    days_to_notice: 11,
    days_to_expiry: 101,
    ...overrides,
  }
}

function page(items: RenewalPipelineItem[]): Paged<RenewalPipelineItem> {
  return { items, total: items.length, page: 1, per_page: 25, total_pages: 1 }
}

let response: Paged<RenewalPipelineItem> = page([renewal()])

function renderRenewals() {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={['/renewals']}>
        <Renewals />
      </MemoryRouter>
    </ToastProvider>,
  )
}

function renewalQueries(): Record<string, unknown>[] {
  return apiGet.mock.calls
    .filter((call) => call[0] === '/renewals')
    .map((call) => (call[1] ?? {}) as Record<string, unknown>)
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  response = page([renewal()])

  apiGet.mockImplementation((path: string) => {
    if (path === '/renewals') return Promise.resolve(response)
    return Promise.resolve(null)
  })
  apiPost.mockResolvedValue({})
})

describe('Renewals', () => {
  it('opens on the notice-deadline bucket and counts down in words', async () => {
    renderRenewals()

    expect(await screen.findByText('Cloud hosting agreement — Northwind')).toBeInTheDocument()
    expect(screen.getByText('11 days left')).toBeInTheDocument()
    expect(screen.getByText('Serve notice by 1 Oct 2026')).toBeInTheDocument()
    expect(renewalQueries()[0]).toMatchObject({ bucket: 'notice_due', page: 1 })
  })

  it('says a deadline has passed rather than relying on the row colour', async () => {
    response = page([renewal({ days_to_notice: -3, notice_deadline: '2026-03-02' })])
    renderRenewals()

    expect(await screen.findByText('Deadline passed 3 days ago')).toBeInTheDocument()
    // The consequence, not just the date: this contract renews itself.
    expect(screen.getByText('It will roll forward on its own')).toBeInTheDocument()
  })

  it('asks the server for the bucket the user picked', async () => {
    const user = userEvent.setup()
    renderRenewals()
    await screen.findByText('Cloud hosting agreement — Northwind')

    await user.click(screen.getByRole('tab', { name: 'Auto-renewal risk' }))

    await waitFor(() => {
      const queries = renewalQueries()
      expect(queries[queries.length - 1]).toMatchObject({ bucket: 'auto_renewal_risk' })
    })
  })

  it('explains an empty bucket instead of showing a blank table', async () => {
    response = page([])
    renderRenewals()

    expect(await screen.findByText('No notice deadline is close')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /see every cycle/i })).toBeInTheDocument()
  })

  it('offers a retry when the pipeline cannot be loaded', async () => {
    apiGet.mockImplementation(() => Promise.reject(new Error('The renewal service is unavailable.')))
    renderRenewals()

    expect(await screen.findByText('The renewal service is unavailable.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
  })

  it('records a decision with the fields the API expects', async () => {
    const user = userEvent.setup()
    renderRenewals()
    await screen.findByText('Cloud hosting agreement — Northwind')

    await user.click(screen.getByRole('button', { name: /decide the renewal of/i }))

    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: 'Record decision' }))

    await waitFor(() => {
      expect(apiPost).toHaveBeenCalledWith(
        '/renewals/1/decision',
        expect.objectContaining({ decision: 'renew', renewal_term_months: 12 }),
      )
    })
  })

  it('requires a new decision date before deferring', async () => {
    const user = userEvent.setup()
    renderRenewals()
    await screen.findByText('Cloud hosting agreement — Northwind')

    await user.click(screen.getByRole('button', { name: /decide the renewal of/i }))
    const dialog = await screen.findByRole('dialog')

    await user.click(within(dialog).getByRole('radio', { name: /defer the decision/i }))
    await user.click(within(dialog).getByRole('button', { name: 'Record decision' }))

    expect(await screen.findByText('Say when the decision will be made instead.')).toBeInTheDocument()
    expect(apiPost).not.toHaveBeenCalled()
  })
})
