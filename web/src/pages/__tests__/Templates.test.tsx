import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { Paged, TemplateDetail, TemplateSummary, TemplateVariable } from '../../types/contracts'

/**
 * The rule worth protecting is the merge registry: a template may only use a
 * variable the server can resolve, and the palette is how one gets in. A body
 * with an unregistered key must not reach the API, because the renderer drops
 * it silently and the contract comes out with a hole in it.
 */

const apiGet = vi.fn()
const apiPost = vi.fn()
const apiPut = vi.fn()

vi.mock('../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../services/apiClient')>()
  return {
    ...actual,
    api: {
      ...actual.api,
      get: (...args: unknown[]) => apiGet(...args),
      post: (...args: unknown[]) => apiPost(...args),
      put: (...args: unknown[]) => apiPut(...args),
    },
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

import Templates from '../Templates'
import { ToastProvider } from '../../context/ToastProvider'

const VARIABLES: TemplateVariable[] = [
  {
    id: 1,
    var_key: 'contract.title',
    label: 'Contract title',
    source: 'contract',
    source_path: 'title',
    data_type: 'text',
    example: 'Master services agreement',
    is_system: true,
  },
  {
    id: 2,
    var_key: 'counterparty.name',
    label: 'Counterparty name',
    source: 'counterparty',
    source_path: 'name',
    data_type: 'text',
    example: 'Acme Pvt Ltd',
    is_system: true,
  },
]

function summary(overrides: Partial<TemplateSummary> = {}): TemplateSummary {
  return {
    id: 7,
    uuid: 'tpl-7',
    name: 'Master services agreement — standard',
    description: 'The default MSA wording.',
    contract_type_id: 3,
    contract_type_name: 'MSA',
    status: 'active',
    version: 4,
    approval_status: 'approved',
    owner_uuid: null,
    variables: ['contract.title'],
    tags: [],
    archived_at: null,
    created_at: '2026-01-01T09:00:00Z',
    updated_at: '2026-02-01T09:00:00Z',
    ...overrides,
  }
}

function detail(overrides: Partial<TemplateDetail> = {}): TemplateDetail {
  return {
    ...summary(),
    body: 'This agreement is made between the Company and {{counterparty.name}}.',
    header_html: null,
    footer_html: null,
    versions: [
      {
        id: 11,
        template_id: 7,
        version: 3,
        body: 'This agreement is made between the Company and the Counterparty.',
        variables: [],
        change_note: 'Replaced the fixed counterparty name with a merge variable',
        author_uuid: null,
        author_name: 'R. Mehta',
        created_at: '2026-01-15T09:00:00Z',
      },
    ],
    ...overrides,
  }
}

function paged(items: TemplateSummary[]): Paged<TemplateSummary> {
  return { items, total: items.length, page: 1, per_page: 25, total_pages: 1 }
}

let listResponse = paged([summary()])

/**
 * The editor seeds its form from the loaded template in an effect, so a test
 * that grabs the textarea the moment it exists can read it before it is filled.
 * Waiting for the heading — which is seeded by the same effect — removes that
 * race from every test below.
 */
async function openEditor(): Promise<HTMLTextAreaElement> {
  renderTemplates('/templates?template=7')
  await screen.findByRole('heading', { name: 'Master services agreement — standard' })
  return screen.getByLabelText(/template body/i) as HTMLTextAreaElement
}

function renderTemplates(path = '/templates') {
  return render(
    <ToastProvider>
      <MemoryRouter initialEntries={[path]}>
        <Templates />
      </MemoryRouter>
    </ToastProvider>,
  )
}

beforeEach(() => {
  apiGet.mockReset()
  apiPost.mockReset()
  apiPut.mockReset()
  listResponse = paged([summary()])

  apiGet.mockImplementation((path: string) => {
    if (path === '/templates') return Promise.resolve(listResponse)
    if (path === '/templates/7') return Promise.resolve(detail())
    if (path === '/template-variables') return Promise.resolve(VARIABLES)
    if (path === '/settings/contract-types') return Promise.resolve([{ id: 3, name: 'MSA' }])
    return Promise.resolve(null)
  })
})

describe('Templates', () => {
  it('lists the library and says what belongs here when it is empty', async () => {
    listResponse = paged([])
    renderTemplates()

    expect(await screen.findByText('No templates yet')).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /new template/i }).length).toBeGreaterThan(0)
  })

  it('sends the search to the server once typing settles', async () => {
    const user = userEvent.setup()
    renderTemplates()
    await screen.findByRole('button', { name: 'Master services agreement — standard' })

    await user.type(screen.getByLabelText('Search templates'), 'msa')

    await waitFor(
      () => {
        const queries = apiGet.mock.calls.filter((call) => call[0] === '/templates')
        expect(queries[queries.length - 1][1]).toMatchObject({ q: 'msa', page: 1 })
      },
      { timeout: 3000 },
    )
  })

  it('opens the editor from the query string, so a template can be linked to', async () => {
    const body = await openEditor()

    expect(body).toHaveValue('This agreement is made between the Company and {{counterparty.name}}.')
  })

  it('inserts a merge variable from the palette rather than making the user type it', async () => {
    const user = userEvent.setup()
    const body = await openEditor()

    await user.click(screen.getByRole('button', { name: /Contract title/ }))

    await waitFor(() => expect(body.value).toContain('{{contract.title}}'))
  })

  it('refuses to save a body that uses a variable the registry does not have', async () => {
    const user = userEvent.setup()
    const body = await openEditor()

    fireEvent.change(body, { target: { value: 'Payable to {{counterparty.bank_account}} on signature.' } })

    expect(await screen.findByText(/not registered/i)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /save template/i }))

    expect(apiPut).not.toHaveBeenCalled()
    // Named on the field and in the alert, so the fix is obvious either way.
    expect(screen.getAllByText(/counterparty\.bank_account/).length).toBeGreaterThan(0)
  })

  it('saves a body whose variables are all registered', async () => {
    const user = userEvent.setup()
    apiPut.mockResolvedValue(summary({ version: 5 }))
    const body = await openEditor()

    fireEvent.change(body, { target: { value: 'For {{contract.title}} with {{counterparty.name}}.' } })
    await user.click(screen.getByRole('button', { name: /save template/i }))

    await waitFor(() =>
      expect(apiPut).toHaveBeenCalledWith(
        '/templates/7',
        expect.objectContaining({ body: 'For {{contract.title}} with {{counterparty.name}}.' }),
      ),
    )
  })

  it('shows earlier wording in the history instead of replacing it', async () => {
    const user = userEvent.setup()
    await openEditor()

    await user.click(screen.getByRole('tab', { name: /history/i }))
    await user.click(await screen.findByRole('button', { name: /show wording/i }))

    expect(
      screen.getByText('This agreement is made between the Company and the Counterparty.'),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Replaced the fixed counterparty name with a merge variable'),
    ).toBeInTheDocument()
  })

  it('reports which variables resolved and which were missing in a preview', async () => {
    const user = userEvent.setup()
    apiPost.mockResolvedValue({
      html: '<p>For Acme Pvt Ltd</p>',
      used: ['counterparty.name'],
      missing: ['contract.title'],
    })

    await openEditor()

    await user.click(screen.getByRole('tab', { name: /preview/i }))
    await user.click(await screen.findByRole('button', { name: /render with sample values/i }))

    await waitFor(() => expect(apiPost).toHaveBeenCalledWith('/templates/7/preview', { contract_id: null }))
    expect(await screen.findByText('Resolved · 1')).toBeInTheDocument()
    expect(screen.getByText('Missing · 1')).toBeInTheDocument()
    expect(screen.getByText('contract.title')).toBeInTheDocument()
  })
})
