import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'

import { Checkbox, Input, MoneyInput, Select, Textarea } from '../Form'

describe('Form controls', () => {
  it('associates every control with a real label', () => {
    // Wiring htmlFor/id by hand at forty call sites is how half of them end up
    // without it, which is why the kit does it.
    render(
      <>
        <Input label="Contract title" />
        <Textarea label="Description" />
        <Select label="Contract type" options={[{ value: 'nda', label: 'NDA' }]} />
        <Checkbox label="Renews automatically" />
        <MoneyInput label="Total value" currency="INR" />
      </>,
    )

    expect(screen.getByLabelText('Contract title')).toBeInTheDocument()
    expect(screen.getByLabelText('Description')).toBeInTheDocument()
    expect(screen.getByLabelText('Contract type')).toBeInTheDocument()
    expect(screen.getByLabelText('Renews automatically')).toBeInTheDocument()
    expect(screen.getByLabelText('Total value')).toBeInTheDocument()
  })

  it('marks an invalid field and points at its message', () => {
    render(<Input label="Expiry date" error="The expiry date cannot be before the effective date." />)

    const field = screen.getByLabelText('Expiry date')
    expect(field).toHaveAttribute('aria-invalid', 'true')

    const describedBy = field.getAttribute('aria-describedby')
    expect(describedBy).toBeTruthy()

    // The message a 422 returned has to reach the person, not just the DOM.
    expect(screen.getByRole('alert')).toHaveTextContent('cannot be before the effective date')
    expect(document.getElementById(describedBy!)).toHaveTextContent('cannot be before')
  })

  it('describes a hint without claiming the field is invalid', () => {
    render(<Input label="Notice period" hint="Days of notice required to stop a renewal." />)

    const field = screen.getByLabelText('Notice period')
    expect(field).not.toHaveAttribute('aria-invalid')
    expect(document.getElementById(field.getAttribute('aria-describedby')!)).toHaveTextContent('Days of notice')
  })

  it('announces a required field to a screen reader, not only with an asterisk', () => {
    render(<Input label="Contract title" required />)

    // An asterisk alone is invisible to anyone not looking at the screen.
    expect(screen.getByLabelText(/Contract title/)).toBeRequired()
    expect(screen.getByText('(required)')).toBeInTheDocument()
  })

  it('gives a money field a decimal keyboard and keeps the value a string', () => {
    render(<MoneyInput label="Total value" currency="INR" defaultValue="1200000.00" />)

    const field = screen.getByLabelText('Total value')
    expect(field).toHaveAttribute('inputmode', 'decimal')

    // Not type="number": a contract value that has been through a JavaScript
    // float is a value you cannot reconcile.
    expect(field).toHaveAttribute('type', 'text')
    expect(field).toHaveValue('1200000.00')
  })

  it('renders a placeholder option only when asked', () => {
    const { rerender } = render(
      <Select label="Owner" options={[{ value: 'a', label: 'Alice' }]} />,
    )
    expect(screen.getAllByRole('option')).toHaveLength(1)

    rerender(
      <Select label="Owner" placeholder="Anyone" options={[{ value: 'a', label: 'Alice' }]} />,
    )
    expect(screen.getAllByRole('option')).toHaveLength(2)
  })
})
