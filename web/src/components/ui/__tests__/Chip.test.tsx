import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import { RiskChip, StatusChip, humanise } from '../Chip'

describe('StatusChip', () => {
  it('spells the status out rather than relying on colour', () => {
    // Colour alone fails for anyone who cannot distinguish these hues, and
    // status here decides whether someone acts.
    render(<StatusChip status="awaiting_approval" />)
    expect(screen.getByText('Awaiting Approval')).toBeInTheDocument()
  })

  it('renders an unknown status legibly instead of blank', () => {
    render(<StatusChip status="some_future_status" />)
    expect(screen.getByText('Some Future Status')).toBeInTheDocument()
  })
})

describe('RiskChip', () => {
  it('shows a dash when a contract has never been assessed', () => {
    // Not "low". An unassessed contract is unknown, and showing it as low risk
    // is the most misleading thing this component could do.
    render(<RiskChip level={null} />)
    expect(screen.getByText('—')).toBeInTheDocument()
  })

  it('shows the level and the score together', () => {
    render(<RiskChip level="high" score={72} />)
    expect(screen.getByText('High')).toBeInTheDocument()
    expect(screen.getByText('72')).toBeInTheDocument()
    expect(screen.getByTitle('Risk score 72 of 100')).toBeInTheDocument()
  })

  it('shows a zero score rather than treating it as absent', () => {
    render(<RiskChip level="low" score={0} />)
    expect(screen.getByText('0')).toBeInTheDocument()
  })
})

describe('humanise', () => {
  it('turns a snake_case enum into words', () => {
    expect(humanise('awaiting_signature')).toBe('Awaiting Signature')
    expect(humanise('active')).toBe('Active')
  })
})
