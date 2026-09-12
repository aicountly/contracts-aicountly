import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * The two things this panel must never get wrong: an answer with a source has
 * to show the source, and an answer the contract does not support has to say so
 * rather than reading like a finding.
 */

const apiGet = vi.fn()
const apiPost = vi.fn()

vi.mock('../../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../services/apiClient')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGet(...args),
      post: (...args: unknown[]) => apiPost(...args),
    },
  }
})

import { AskContractPanel } from '../AskContractPanel'
import { ToastProvider } from '../../../context/ToastProvider'

function renderPanel(props: Partial<Parameters<typeof AskContractPanel>[0]> = {}) {
  return render(
    <ToastProvider>
      <AskContractPanel contractId={1} contractTitle="Master services agreement" {...props} />
    </ToastProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiGet.mockImplementation((path: string) => {
    if (path === '/ai/contracts/1/conversations') return Promise.resolve([])
    return Promise.resolve([])
  })
})

describe('AskContractPanel', () => {
  it('shows an answer with the clause and page it came from', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({
      answer: 'Liability is capped at the fees paid in the preceding twelve months.',
      citations: [{ clause_number: '11.2', heading: 'Limitation of liability', page: 14, excerpt: 'aggregate liability shall not exceed' }],
      grounded: true,
      conversation_id: 31,
    })

    renderPanel()

    await user.type(await screen.findByLabelText(/ask a question/i), 'What is our liability cap?')
    await user.click(screen.getByRole('button', { name: /^ask$/i }))

    expect(
      await screen.findByText('Liability is capped at the fees paid in the preceding twelve months.'),
    ).toBeInTheDocument()
    expect(screen.getByText('Clause 11.2 · Limitation of liability · page 14')).toBeInTheDocument()
    expect(apiPost).toHaveBeenCalledWith('/ai/contracts/1/ask', {
      question: 'What is our liability cap?',
      conversation_id: undefined,
    })
  })

  it('says plainly when the contract does not answer the question', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({
      answer: 'Contracts of this kind often include a service credit regime.',
      citations: [],
      grounded: false,
      conversation_id: 32,
    })

    renderPanel()

    await user.type(await screen.findByLabelText(/ask a question/i), 'What are the service credits?')
    await user.click(screen.getByRole('button', { name: /^ask$/i }))

    expect(await screen.findByText(/this contract does not answer that question/i)).toBeInTheDocument()
  })

  it('continues the most recent conversation it finds', async () => {
    apiGet.mockImplementation((path: string) => {
      if (path === '/ai/contracts/1/conversations') {
        return Promise.resolve([{ id: 7, title: null, created_at: '2026-09-01T09:00:00Z', updated_at: '2026-09-01T09:00:00Z' }])
      }
      if (path === '/ai/conversations/7/messages') {
        return Promise.resolve([
          { id: 1, role: 'user', content: 'Does it auto-renew?', citations: [], grounded: true, created_at: '2026-09-01T09:00:00Z' },
          {
            id: 2,
            role: 'assistant',
            content: 'Yes, for successive twelve month terms.',
            citations: [{ page: 3 }],
            grounded: true,
            created_at: '2026-09-01T09:00:30Z',
          },
        ])
      }
      return Promise.resolve([])
    })

    const user = userEvent.setup()
    apiPost.mockResolvedValue({ answer: 'Ninety days.', citations: [{ page: 3 }], grounded: true, conversation_id: 7 })

    renderPanel()

    expect(await screen.findByText('Yes, for successive twelve month terms.')).toBeInTheDocument()

    await user.type(screen.getByLabelText(/ask a question/i), 'How much notice?')
    await user.click(screen.getByRole('button', { name: /^ask$/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalled())
    expect(apiPost).toHaveBeenCalledWith('/ai/contracts/1/ask', {
      question: 'How much notice?',
      conversation_id: 7,
    })
  })

  it('explains itself instead of offering a box that cannot be used', async () => {
    renderPanel({ enabled: false })

    expect(await screen.findByText(/AI is not available on this company/i)).toBeInTheDocument()
    expect(screen.queryByLabelText(/ask a question/i)).not.toBeInTheDocument()
  })
})
