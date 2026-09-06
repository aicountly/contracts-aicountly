import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { CompareResult } from '../../../types/contracts'

/**
 * The redline is the one place in the product where getting the presentation
 * wrong changes what a lawyer believes the document says, so the assertions
 * here are about meaning: a removal reads as removed without relying on colour,
 * unchanged text stays out of the way until asked for, and anything the model
 * wrote is labelled as such.
 */

const apiGet = vi.fn()

vi.mock('../../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../services/apiClient')>()
  return {
    ...actual,
    api: { ...actual.api, get: (...args: unknown[]) => apiGet(...args) },
  }
})

import { VersionDiffViewer } from '../VersionDiffViewer'
import { ApiError } from '../../../services/apiClient'

const comparison: CompareResult = {
  segments: [
    { type: 'unchanged', text: 'This agreement is governed by the laws of India.' },
    { type: 'removed', text: 'Liability is capped at the fees paid in the preceding 12 months.' },
    { type: 'added', text: 'Liability is capped at three times the fees paid in the contract year.' },
    { type: 'changed', base_text: 'Payment within 30 days', target_text: 'Payment within 60 days' },
  ],
  stats: { added: 1, removed: 1, changed: 1, unchanged: 1, similarity: 92 },
  classified: [
    {
      category: 'liability',
      severity: 'high',
      summary: 'Liability cap raised',
      base_value: '1x annual fees',
      target_value: '3x annual fees',
      section: 'Clause 11.2',
    },
  ],
  ai_explanation: 'The liability cap and the payment window both moved in the counterparty’s favour.',
}

beforeEach(() => {
  apiGet.mockReset()
  apiGet.mockResolvedValue(comparison)
})

describe('VersionDiffViewer', () => {
  it('asks the server for the pair it was given', async () => {
    render(<VersionDiffViewer contractId={42} base={7} target={9} />)

    expect(await screen.findByText(/Liability cap raised/)).toBeInTheDocument()
    expect(apiGet).toHaveBeenCalledWith('/contracts/42/compare', { base: 7, target: 9 }, expect.anything())
  })

  it('names every kind of change in words, not only in colour', async () => {
    render(<VersionDiffViewer contractId={42} base={7} target={9} />)

    expect(await screen.findByText(/three times the fees/)).toBeInTheDocument()
    expect(screen.getAllByText('Added').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Removed').length).toBeGreaterThan(0)
    expect(screen.getByText('1 added')).toBeInTheDocument()
    expect(screen.getByText('92% similar')).toBeInTheDocument()
  })

  it('calls out the changes that matter with their category and both values', async () => {
    render(<VersionDiffViewer contractId={42} base={7} target={9} />)

    expect(await screen.findByText('Changes that matter')).toBeInTheDocument()
    expect(screen.getByText('Liability')).toBeInTheDocument()
    expect(screen.getByText('1x annual fees')).toBeInTheDocument()
    expect(screen.getByText('3x annual fees')).toBeInTheDocument()
    expect(screen.getByText('Clause 11.2')).toBeInTheDocument()
  })

  it('labels the model’s explanation as AI-generated and carries the disclaimer', async () => {
    render(<VersionDiffViewer contractId={42} base={7} target={9} />)

    expect(await screen.findByText('AI-generated')).toBeInTheDocument()
    expect(screen.getByText(/does not constitute legal advice/i)).toBeInTheDocument()
  })

  it('keeps unchanged text out of the way until it is asked for', async () => {
    const user = userEvent.setup()
    render(<VersionDiffViewer contractId={42} base={7} target={9} />)

    await screen.findByText(/three times the fees/)
    expect(screen.queryByText(/governed by the laws of India/)).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /show unchanged text/i }))

    expect(screen.getByText(/governed by the laws of India/)).toBeInTheDocument()
  })

  it('shows the API message and a retry when the comparison fails', async () => {
    apiGet.mockRejectedValue(new ApiError('Version 9 has no extracted text.', 409, 'NO_TEXT'))
    render(<VersionDiffViewer contractId={42} base={7} target={9} />)

    expect(await screen.findByText('Could not compare these versions')).toBeInTheDocument()
    expect(screen.getByText('Version 9 has no extracted text.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /try again/i })).toBeInTheDocument()
  })
})
