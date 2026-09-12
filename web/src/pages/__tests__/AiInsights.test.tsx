import { render, screen, within } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * This page turns four different reads into one list of things to do, and it
 * has to be honest about two of them: which findings came from a model, and
 * whether AI is configured at all. Both are asserted here, along with the
 * partial-failure case — one source refusing must not blank the screen.
 */

const apiGet = vi.fn()

vi.mock('../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../services/apiClient')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGet(...args) },
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

import AiInsights from '../AiInsights'
import { ToastProvider } from '../../context/ToastProvider'

function paged<T>(items: T[]) {
  return { items, total: items.length, page: 1, per_page: 50, total_pages: 1 }
}

const FINDING = {
  id: 1,
  contract_id: 42,
  rule_key: 'liability_uncapped',
  risk_category: 'legal',
  severity: 'critical',
  title: 'Unlimited liability clause detected',
  detail: 'Clause 14 caps neither direct nor indirect loss.',
  recommendation: 'Negotiate a cap at fees paid in the preceding twelve months.',
  detected_by: 'ai',
  ai_confidence: 0.88,
  review_status: 'open',
  created_at: '2026-03-01T09:00:00Z',
  contract_number: 'CTR-2026-0042',
  contract_title: 'Master services agreement — Acme',
  counterparty_name: 'Acme Pvt Ltd',
}

const RENEWAL = {
  id: 5,
  contract_id: 43,
  contract_number: 'CTR-2026-0043',
  contract_title: 'Support agreement — Northwind',
  counterparty_name: 'Northwind Ltd',
  status: 'review_due',
  current_expiry: '2026-06-30',
  notice_deadline: '2026-04-30',
  decision_due_date: null,
  auto_renewal: true,
  days_to_notice: 17,
  days_to_expiry: 78,
  recommendation: null,
  recommendation_reason: null,
  recommendation_source: 'rules',
}

let aiConfigured = false
let riskFails = false

function renderInsights() {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={['/insights']}>
        <AiInsights />
      </MemoryRouter>
    </ToastProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  aiConfigured = false
  riskFails = false

  apiGet.mockImplementation((path: string) => {
    if (path === '/ai/status') {
      return Promise.resolve({ configured: aiConfigured, provider: aiConfigured ? 'anthropic' : null })
    }
    if (path === '/risks') {
      return riskFails
        ? Promise.reject(new Error('Risk findings need the risk permission.'))
        : Promise.resolve(paged([FINDING]))
    }
    if (path === '/renewals') return Promise.resolve(paged([RENEWAL]))
    if (path === '/obligations') return Promise.resolve(paged([]))
    if (path === '/contracts') return Promise.resolve(paged([]))
    return Promise.resolve(null)
  })
})

describe('AiInsights', () => {
  it('states a renewal deadline as the action it implies, under the right urgency', async () => {
    renderInsights()

    expect(await screen.findByText('Renewal notice deadline is in 17 days')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /this month/i })).toBeInTheDocument()
    expect(
      screen.getByText(/renews automatically unless notice is given before the deadline/i),
    ).toBeInTheDocument()
  })

  it('marks a model-detected finding as such and puts a critical one first', async () => {
    renderInsights()

    const finding = await screen.findByText('Unlimited liability clause detected')
    const card = finding.closest('article')
    expect(card).not.toBeNull()
    expect(within(card as HTMLElement).getByText('Found by AI')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: /act now/i })).toBeInTheDocument()
  })

  it('says AI is not configured and where it is configured, without hiding the rest', async () => {
    renderInsights()

    expect(await screen.findByText('AI analysis is not configured')).toBeInTheDocument()
    expect(screen.getByText(/configured in Console by an administrator/i)).toBeInTheDocument()
    // The date-derived work is computed by Contracts itself and is still listed.
    expect(screen.getByText('Renewal notice deadline is in 17 days')).toBeInTheDocument()
  })

  it('names the connected provider when AI is configured', async () => {
    aiConfigured = true
    renderInsights()

    expect(await screen.findByText('AI analysis is connected')).toBeInTheDocument()
    expect(screen.getByText(/anthropic/i)).toBeInTheDocument()
  })

  it('keeps going when one source refuses, and says which one', async () => {
    riskFails = true
    renderInsights()

    expect(await screen.findByText('Renewal notice deadline is in 17 days')).toBeInTheDocument()
    expect(screen.getByText(/Risk findings — Risk findings need the risk permission\./)).toBeInTheDocument()
    expect(screen.queryByText('Unlimited liability clause detected')).not.toBeInTheDocument()
  })

  it('says the portfolio is clear rather than showing an empty list', async () => {
    apiGet.mockImplementation((path: string) => {
      if (path === '/ai/status') return Promise.resolve({ configured: true })
      return Promise.resolve(paged([]))
    })

    renderInsights()

    expect(await screen.findByText('Nothing needs your attention')).toBeInTheDocument()
  })
})
