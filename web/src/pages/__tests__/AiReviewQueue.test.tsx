import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { AiExtractionRow, AiJobRow, Paged } from '../../types/contracts'

/**
 * The promise this screen makes is that no AI output is presented as verified
 * until a person has verified it, and that a bulk action never quietly accepts
 * something the model was unsure of. Those are the things asserted here.
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
    session: {
      uuid: 'user-1',
      roles: [],
      permissions: [],
      counts: { approvals: 0, obligations: 0, renewals: 0, review_queue: 3, notifications: 0 },
      ai: { configured: true },
      integrations: {},
    },
    error: null,
    can: () => true,
    canAny: () => true,
    refresh: async () => {},
  }),
}))

import AiReviewQueue from '../AiReviewQueue'
import { ToastProvider } from '../../context/ToastProvider'

function extraction(overrides: Partial<AiExtractionRow> = {}): AiExtractionRow {
  return {
    id: 1,
    job_id: 9,
    contract_id: 42,
    contract_number: 'CTR-2026-0042',
    contract_title: 'Master services agreement — Acme',
    version_id: 5,
    field_key: 'total_value',
    field_label: 'Total value',
    extracted_value: '250000',
    normalised_value: '250000.00',
    value_type: 'currency',
    confidence: 0.96,
    source_page: 4,
    source_excerpt: 'The total consideration payable under this Agreement is INR 250,000.',
    review_state: 'pending',
    accepted_value: null,
    reviewed_by: null,
    reviewed_at: null,
    created_at: '2026-03-01T09:00:00Z',
    ...overrides,
  }
}

function job(overrides: Partial<AiJobRow> = {}): AiJobRow {
  return {
    id: 9,
    kind: 'extract',
    contract_id: 42,
    contract_number: 'CTR-2026-0042',
    contract_title: 'Master services agreement — Acme',
    version_id: 5,
    status: 'succeeded',
    attempts: 1,
    max_attempts: 3,
    provider: 'anthropic',
    model: 'claude-sonnet',
    error_code: null,
    error_message: null,
    requested_by: 'user-1',
    started_at: '2026-03-01T09:00:00Z',
    completed_at: '2026-03-01T09:01:00Z',
    created_at: '2026-03-01T08:59:00Z',
    ...overrides,
  }
}

function paged<T>(items: T[]): Paged<T> {
  return { items, total: items.length, page: 1, per_page: 50, total_pages: 1 }
}

let queueRows: AiExtractionRow[] = []
let jobRows: AiJobRow[] = []
let aiConfigured = true

function renderQueue() {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={['/ai/review']}>
        <AiReviewQueue />
      </MemoryRouter>
    </ToastProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()

  queueRows = [
    extraction(),
    extraction({
      id: 2,
      field_key: 'expiry_date',
      field_label: 'Expiry date',
      extracted_value: '2027-03-31',
      normalised_value: '2027-03-31',
      value_type: 'date',
      confidence: 0.41,
      source_page: 7,
      source_excerpt: 'This Agreement shall continue until 31 March 2027 unless terminated earlier.',
    }),
  ]
  jobRows = [job()]
  aiConfigured = true

  apiGet.mockImplementation((path: string) => {
    if (path === '/ai/status') {
      return Promise.resolve({ configured: aiConfigured, provider: 'anthropic', model: 'claude-sonnet' })
    }
    if (path === '/ai/jobs') return Promise.resolve(paged(jobRows))
    if (path === '/ai/review-queue') return Promise.resolve(paged(queueRows))
    return Promise.resolve(null)
  })
})

describe('AiReviewQueue', () => {
  it('presents every extracted field as unverified until a person acts', async () => {
    renderQueue()

    expect(await screen.findByText('Total value')).toBeInTheDocument()
    expect(screen.getAllByText('Not yet verified')).toHaveLength(2)
    expect(screen.queryByText('Verified by a person')).not.toBeInTheDocument()
    expect(screen.getByText('2 awaiting verification')).toBeInTheDocument()
  })

  it('marks a low-confidence value as low, in words as well as colour', async () => {
    renderQueue()

    await screen.findByText('Expiry date')
    expect(screen.getByText('41% confident · low')).toBeInTheDocument()
    expect(screen.getByText('96% confident')).toBeInTheDocument()
    expect(screen.getByText('1 low confidence')).toBeInTheDocument()
  })

  it('shows the passage each value was read from', async () => {
    renderQueue()

    await screen.findByText('Total value')
    expect(
      screen.getByText(/The total consideration payable under this Agreement is INR 250,000\./),
    ).toBeInTheDocument()
    expect(screen.getByText(/page 4/)).toBeInTheDocument()
    expect(screen.getByText(/page 7/)).toBeInTheDocument()
  })

  it('accepts one field and records it as verified by a person', async () => {
    const user = userEvent.setup()
    apiPost.mockImplementation((path: string) =>
      Promise.resolve(
        path === '/ai/extractions/1/accept'
          ? extraction({ review_state: 'accepted', accepted_value: '250000.00', reviewed_at: '2026-03-02T10:00:00Z' })
          : null,
      ),
    )

    renderQueue()
    await screen.findByText('Total value')

    await user.click(screen.getAllByRole('button', { name: 'Accept' })[0])

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/ai/extractions/1/accept', {}))
    expect(await screen.findByText('Verified by a person')).toBeInTheDocument()
  })

  it('sends a corrected value as an edit rather than an acceptance of what the model said', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue(
      extraction({ review_state: 'edited', accepted_value: '260000.00', reviewed_at: '2026-03-02T10:00:00Z' }),
    )

    renderQueue()
    await screen.findByText('Total value')

    await user.click(screen.getAllByRole('button', { name: /edit/i })[0])
    const field = screen.getByLabelText('Corrected total value')
    await user.clear(field)
    await user.type(field, '260000.00')
    await user.click(screen.getByRole('button', { name: /save and verify/i }))

    await waitFor(() =>
      expect(apiPost).toHaveBeenCalledWith('/ai/extractions/1/accept', { value: '260000.00' }),
    )
  })

  it('offers bulk accept for high-confidence fields only, and names what it would accept', async () => {
    const user = userEvent.setup()
    renderQueue()
    await screen.findByText('Total value')

    const bulk = screen.getByRole('button', { name: /accept 1 high-confidence/i })
    await user.click(bulk)

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByText('Total value')).toBeInTheDocument()
    // The 41%-confident field is not in the list of things being accepted.
    expect(within(dialog).queryByText('Expiry date')).not.toBeInTheDocument()
  })

  it('bulk-accepts only the fields the dialog listed', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue(extraction({ review_state: 'accepted' }))

    renderQueue()
    await screen.findByText('Total value')

    await user.click(screen.getByRole('button', { name: /accept 1 high-confidence/i }))
    await user.click(await screen.findByRole('button', { name: /verify these fields/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledTimes(1))
    expect(apiPost).toHaveBeenCalledWith('/ai/extractions/1/accept', {})
  })

  it('says so when no AI provider is configured, rather than showing an empty screen', async () => {
    aiConfigured = false
    queueRows = []
    renderQueue()

    expect(await screen.findByText('No AI provider is connected')).toBeInTheDocument()
    expect(screen.getByText(/configures a provider in Console/i)).toBeInTheDocument()
    expect(screen.getByText('Nothing is waiting for review')).toBeInTheDocument()
  })

  it("surfaces the API's message and a retry when the queue cannot be read", async () => {
    apiGet.mockImplementation((path: string) => {
      if (path === '/ai/status') return Promise.resolve({ configured: true })
      return Promise.reject(new Error('The AI service is unavailable right now.'))
    })

    renderQueue()

    expect(await screen.findByText('The AI service is unavailable right now.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
  })
})
