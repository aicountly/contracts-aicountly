import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Contract } from '../../../../types/contracts'

/**
 * The effective position is the claim this tab makes: "the contract says X
 * today, and amendment N is why". It has to survive the two shapes the position
 * endpoint is described in, and it has to name the source for every row —
 * including the rows the master contract still decides.
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

import { AmendmentsTab } from '../AmendmentsTab'
import { ToastProvider } from '../../../../context/ToastProvider'

const contract = {
  id: 1,
  contract_number: 'CTR-2026-0001',
  title: 'Master services agreement',
  status: 'active',
  currency: 'INR',
  effective_date: '2026-01-01',
} as unknown as Contract

const amendment = {
  id: 5,
  contract_id: 1,
  amendment_no: 1,
  title: 'Extend the term',
  description: 'Term extended by twelve months.',
  effective_date: '2026-11-01',
  execution_date: '2026-10-20',
  status: 'executed',
  affected_fields: { expiry_date: { from: '2026-12-31', to: '2027-12-31' } },
  affected_clauses: [],
  affected_commercials: {},
  affected_obligations: [],
  applied_at: '2026-10-21T10:00:00Z',
  applied_by: 'user-1',
  created_at: '2026-10-01T10:00:00Z',
  updated_at: '2026-10-21T10:00:00Z',
}

const servicePosition = {
  contract_id: 1,
  base: { expiry_date: '2026-12-31', notice_period_days: 60, currency: 'INR' },
  current: { expiry_date: '2027-12-31', notice_period_days: 60, currency: 'INR' },
  overrides: {
    expiry_date: {
      amendment_id: 5,
      amendment_no: 1,
      title: 'Extend the term',
      effective_date: '2026-11-01',
      from: '2026-12-31',
      to: '2027-12-31',
    },
  },
  amendments: [],
}

function renderTab() {
  return render(
    <ToastProvider>
      <AmendmentsTab contractId={1} contract={contract} onChanged={() => {}} />
    </ToastProvider>,
  )
}

function mockWith(position: unknown, amendments: unknown[] = [amendment]) {
  apiGet.mockImplementation((path: string) => {
    if (path === '/contracts/1/amendments') return Promise.resolve(amendments)
    if (path === '/contracts/1/effective-position') return Promise.resolve(position)
    return Promise.resolve(null)
  })
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  mockWith(servicePosition)
})

describe('AmendmentsTab', () => {
  it('shows the chain under the master contract with what each amendment changed', async () => {
    renderTab()

    expect(await screen.findByText('Master contract · CTR-2026-0001')).toBeInTheDocument()
    expect(screen.getByText('Amendment 1 · Extend the term')).toBeInTheDocument()
    expect(screen.getByText('Expiry Date:')).toBeInTheDocument()
    expect(screen.getByText('31 Dec 2026')).toBeInTheDocument()
  })

  it('names the amendment behind an overridden field and the master contract behind the rest', async () => {
    renderTab()

    const expiryRow = (await screen.findByRole('rowheader', { name: 'Expiry Date' })).closest('tr')
    expect(expiryRow).not.toBeNull()
    expect(within(expiryRow as HTMLElement).getByText('Amendment 1')).toBeInTheDocument()
    expect(within(expiryRow as HTMLElement).getByText('was 31 Dec 2026')).toBeInTheDocument()

    const noticeRow = screen.getByRole('rowheader', { name: 'Notice Period Days' }).closest('tr')
    expect(within(noticeRow as HTMLElement).getByText('Master contract')).toBeInTheDocument()
    expect(within(noticeRow as HTMLElement).getByText('60 days')).toBeInTheDocument()
  })

  it('reads the position when the API answers in its documented fields/sources shape', async () => {
    mockWith({
      fields: { expiry_date: '2027-12-31', notice_period_days: 60 },
      sources: {
        expiry_date: {
          amendment_id: 5,
          amendment_no: 1,
          title: 'Extend the term',
          effective_date: '2026-11-01',
          from: '2026-12-31',
          to: '2027-12-31',
        },
      },
    })

    renderTab()

    const expiryRow = (await screen.findByRole('rowheader', { name: 'Expiry Date' })).closest('tr')
    expect(within(expiryRow as HTMLElement).getByText('Amendment 1')).toBeInTheDocument()
  })

  it('asks before writing an amendment onto the contract', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({ amendment, contract })
    mockWith(servicePosition, [{ ...amendment, status: 'awaiting_signature', applied_at: null }])

    renderTab()

    await user.click(await screen.findByRole('button', { name: /apply to contract/i }))

    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: /^apply$/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/amendments/5/apply'))
  })

  it('says what belongs here when nothing has been amended', async () => {
    mockWith({ current: {}, overrides: {} }, [])

    renderTab()

    expect(await screen.findByText('Nothing has been amended')).toBeInTheDocument()
  })
})
