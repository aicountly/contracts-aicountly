import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Contract } from '../../../../types/contracts'

/**
 * What is worth protecting here is the phrasing and the write.
 *
 * A recurrence rendered as "quarterly" instead of "Quarterly, next due 31 Mar
 * 2027" is the difference between a code and a sentence, and completing an
 * occurrence has to send the field names the endpoint actually reads — a body
 * with the wrong key fails silently as "no evidence supplied".
 */

const apiGet = vi.fn()
const apiPost = vi.fn()

vi.mock('../../../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../../services/apiClient')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGet(...args),
      post: (...args: unknown[]) => apiPost(...args),
    },
  }
})

vi.mock('../../../../context/SessionProvider', () => ({
  useSession: () => ({
    status: 'ready' as const,
    session: null,
    error: null,
    can: () => true,
    canAny: () => true,
    refresh: async () => {},
  }),
}))

import { ObligationsTab, describeRecurrence } from '../ObligationsTab'
import { ToastProvider } from '../../../../context/ToastProvider'

const contract = {
  id: 1,
  uuid: 'ctr-1',
  contract_number: 'CTR-2026-0001',
  title: 'Master services agreement',
  status: 'active',
  currency: 'INR',
  tabs: {},
} as unknown as Contract

const obligation = {
  id: 4,
  contract_id: 1,
  clause_id: null,
  obligation_type: 'reporting',
  title: 'Submit the quarterly SLA report',
  description: null,
  responsible_party: 'counterparty',
  owner_uuid: null,
  frequency: 'quarterly' as const,
  custom_interval_days: null,
  start_date: '2026-01-01',
  end_date: null,
  first_due_date: '2026-03-31',
  grace_period_days: 5,
  amount: null,
  currency: null,
  evidence_required: true,
  reminder_days: '14,7,1',
  status: 'due',
  is_ai_extracted: false,
  is_active: true,
  next_due_date: '2027-03-31',
  days_to_next_due: 40,
  occurrence_count: 4,
  overdue_count: 0,
  completed_count: 3,
}

const occurrence = {
  id: 9,
  obligation_id: 4,
  contract_id: 1,
  sequence_no: 4,
  due_date: '2027-03-31',
  grace_until: null,
  status: 'due',
  completed_at: null,
  completion_note: null,
  amount: null,
  days_to_due: 40,
  is_overdue: false,
  evidence_required: true,
  currency: 'INR',
}

function renderTab() {
  return render(
    <ToastProvider>
      <ObligationsTab contractId={1} contract={contract} onChanged={() => {}} />
    </ToastProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation((path: string) => {
    if (path === '/contracts/1/obligations') return Promise.resolve([obligation])
    if (path === '/obligations') return Promise.resolve({ items: [occurrence], total: 1, page: 1, per_page: 100, total_pages: 1 })
    if (path === '/contracts/1/documents') return Promise.resolve([])
    return Promise.resolve(null)
  })
})

describe('describeRecurrence', () => {
  it('names the frequency and the next due date', () => {
    expect(describeRecurrence(obligation)).toBe('Quarterly, next due 31 Mar 2027')
  })

  it('falls back to the first due date for a one-time obligation', () => {
    expect(
      describeRecurrence({
        frequency: 'one_time',
        custom_interval_days: null,
        next_due_date: null,
        first_due_date: '2026-06-30',
        end_date: null,
      }),
    ).toBe('One time, due 30 Jun 2026')
  })

  it('spells out a custom interval in days', () => {
    expect(
      describeRecurrence({
        frequency: 'custom',
        custom_interval_days: 45,
        next_due_date: null,
        first_due_date: null,
        end_date: null,
      }),
    ).toBe('Every 45 days, nothing outstanding')
  })
})

describe('ObligationsTab', () => {
  it('lists obligations with the recurrence in words and the next due date', async () => {
    renderTab()

    expect(await screen.findByText('Submit the quarterly SLA report')).toBeInTheDocument()
    expect(screen.getByText('Quarterly, next due 31 Mar 2027')).toBeInTheDocument()
    expect(screen.getByText(/Due 31 Mar 2027/)).toBeInTheDocument()
    expect(screen.getByText('3 of 4 completed')).toBeInTheDocument()
  })

  it('offers the first step when nothing has been recorded', async () => {
    apiGet.mockImplementation((path: string) => {
      if (path === '/contracts/1/obligations') return Promise.resolve([])
      return Promise.resolve({ items: [], total: 0, page: 1, per_page: 100, total_pages: 1 })
    })

    renderTab()

    expect(await screen.findByText('No obligations recorded')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /add the first obligation/i })).toBeInTheDocument()
  })

  it("shows the API's own message and retries on failure", async () => {
    const user = userEvent.setup()
    apiGet.mockRejectedValue(new Error('Obligations are temporarily unavailable.'))

    renderTab()

    expect(await screen.findByText('Obligations are temporarily unavailable.')).toBeInTheDocument()

    apiGet.mockImplementation((path: string) =>
      path === '/contracts/1/obligations'
        ? Promise.resolve([obligation])
        : Promise.resolve({ items: [], total: 0, page: 1, per_page: 100, total_pages: 1 }),
    )
    await user.click(screen.getByRole('button', { name: /try again/i }))

    expect(await screen.findByText('Submit the quarterly SLA report')).toBeInTheDocument()
  })

  it('completes an occurrence with the field names the endpoint reads', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({ ...occurrence, status: 'completed' })

    renderTab()

    await user.click(await screen.findByRole('button', { name: /mark complete/i }))

    const dialog = await screen.findByRole('dialog')
    await user.type(within(dialog).getByLabelText(/what was done/i), 'Report filed with the client')
    await user.type(within(dialog).getByLabelText(/external reference/i), 'TICKET-4471')
    await user.click(within(dialog).getByRole('button', { name: /mark complete/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/occurrences/9/complete', {
      completion_note: 'Report filed with the client',
      amount: null,
      document_id: null,
      evidence_note: null,
      external_ref: 'TICKET-4471',
    })
  })
})
