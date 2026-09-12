import { describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'

import { DataTable } from '../DataTable'
import type { Column } from '../DataTable'

interface Row {
  id: number
  title: string
  value: number
}

const rows: Row[] = [
  { id: 1, title: 'Vendor Services Agreement', value: 1200000 },
  { id: 2, title: 'Office Lease', value: 7200000 },
]

const columns: Column<Row>[] = [
  { key: 'title', header: 'Title', sortKey: 'title', render: (r) => r.title },
  { key: 'value', header: 'Value', sortKey: 'total_value', align: 'right', render: (r) => r.value },
  { key: 'note', header: 'Note', render: () => '—', hideBelow: 'md' },
]

describe('DataTable', () => {
  it('renders a real table with a caption and column headers', () => {
    render(
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        caption="Contracts"
      />,
    )

    // A grid of divs would satisfy a snapshot and be unreadable to a screen
    // reader, which is how most people work through a contract portfolio.
    const table = screen.getByRole('table', { name: 'Contracts' })
    expect(table).toBeInTheDocument()

    expect(within(table).getAllByRole('columnheader')).toHaveLength(3)
    expect(within(table).getAllByRole('row')).toHaveLength(3) // header + 2
  })

  it('shows a skeleton while loading and no rows', () => {
    render(<DataTable columns={columns} rows={[]} rowKey={(r) => r.id} loading />)

    expect(screen.getByRole('status', { name: 'Loading' })).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('shows the caller’s empty state rather than an empty table', () => {
    render(
      <DataTable
        columns={columns}
        rows={[]}
        rowKey={(r) => r.id}
        emptyTitle="No contracts yet"
        emptyDescription="Create one, or upload an existing agreement."
      />,
    )

    expect(screen.getByText('No contracts yet')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('reports the sorted column to assistive technology', () => {
    render(
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        sort={{ key: 'title', dir: 'asc' }}
        onSortChange={() => {}}
      />,
    )

    expect(screen.getByRole('columnheader', { name: /Title/ })).toHaveAttribute('aria-sort', 'ascending')
    expect(screen.getByRole('columnheader', { name: /Value/ })).not.toHaveAttribute('aria-sort')
  })

  it('toggles direction on the active column and defaults to desc on a new one', async () => {
    const onSortChange = vi.fn()
    const user = userEvent.setup()

    render(
      <DataTable
        columns={columns}
        rows={rows}
        rowKey={(r) => r.id}
        sort={{ key: 'title', dir: 'desc' }}
        onSortChange={onSortChange}
      />,
    )

    await user.click(screen.getByRole('button', { name: /Title/ }))
    expect(onSortChange).toHaveBeenLastCalledWith('title', 'asc')

    await user.click(screen.getByRole('button', { name: /Value/ }))
    expect(onSortChange).toHaveBeenLastCalledWith('total_value', 'desc')
  })

  it('does not offer sorting on a column the server cannot sort by', () => {
    render(
      <DataTable columns={columns} rows={rows} rowKey={(r) => r.id} onSortChange={() => {}} />,
    )

    expect(screen.queryByRole('button', { name: /Note/ })).not.toBeInTheDocument()
  })
})
