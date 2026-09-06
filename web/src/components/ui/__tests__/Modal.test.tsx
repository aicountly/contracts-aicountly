import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { ConfirmDialog, Modal } from '../Modal'

describe('Modal', () => {
  it('renders nothing when closed', () => {
    render(
      <Modal open={false} onClose={() => {}} title="Archive contract">
        body
      </Modal>,
    )

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('is a labelled modal dialog', () => {
    render(
      <Modal open onClose={() => {}} title="Archive contract" description="It can be restored later.">
        body
      </Modal>,
    )

    const dialog = screen.getByRole('dialog', { name: 'Archive contract' })
    expect(dialog).toHaveAttribute('aria-modal', 'true')
    expect(screen.getByText('It can be restored later.')).toBeInTheDocument()
  })

  it('closes on Escape', async () => {
    const onClose = vi.fn()
    const user = userEvent.setup()

    render(
      <Modal open onClose={onClose} title="Archive contract">
        body
      </Modal>,
    )

    await user.keyboard('{Escape}')
    expect(onClose).toHaveBeenCalled()
  })

  it('traps Tab inside the dialog', async () => {
    const user = userEvent.setup()

    render(
      <>
        <button type="button">outside</button>
        <Modal open onClose={() => {}} title="Archive contract" footer={<button type="button">Confirm</button>}>
          <button type="button">Inside</button>
        </Modal>
      </>,
    )

    // Without the trap a keyboard user tabs straight out into a page that is
    // still there, still interactive, and now invisible to them.
    const inside = screen.getByRole('button', { name: 'Inside' })
    const confirm = screen.getByRole('button', { name: 'Confirm' })

    inside.focus()
    await user.tab()
    expect(document.activeElement).toBe(confirm)

    await user.tab()
    expect(screen.getByRole('button', { name: 'outside' })).not.toBe(document.activeElement)
  })
})

describe('ConfirmDialog', () => {
  it('runs the action only when confirmed', async () => {
    const onConfirm = vi.fn()
    const onClose = vi.fn()
    const user = userEvent.setup()

    render(
      <ConfirmDialog
        open
        onClose={onClose}
        onConfirm={onConfirm}
        title="Delete draft"
        message="This cannot be undone."
        confirmLabel="Delete"
        tone="danger"
      />,
    )

    await user.click(screen.getByRole('button', { name: 'Cancel' }))
    expect(onClose).toHaveBeenCalled()
    expect(onConfirm).not.toHaveBeenCalled()

    await user.click(screen.getByRole('button', { name: 'Delete' }))
    expect(onConfirm).toHaveBeenCalledTimes(1)
  })

  it('disables both buttons while the action is in flight', () => {
    render(
      <ConfirmDialog
        open
        busy
        onClose={() => {}}
        onConfirm={() => {}}
        title="Delete draft"
        message="This cannot be undone."
      />,
    )

    // A confirm pressed twice deletes twice, or creates two contracts.
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Confirm' })).toBeDisabled()
  })
})
