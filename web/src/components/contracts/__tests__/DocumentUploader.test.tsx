import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

/**
 * The upload is four calls that have to happen in order, and the failure modes
 * are the interesting part: a session that is opened and then abandoned has to
 * be aborted, and a 422 belongs on the field the server named rather than in a
 * toast the user cannot act on.
 */

const apiPost = vi.fn()

vi.mock('../../../services/apiClient', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../services/apiClient')>()
  return {
    ...actual,
    api: { ...actual.api, post: (...args: unknown[]) => apiPost(...args) },
  }
})

import { DocumentUploader } from '../DocumentUploader'
import { ApiError } from '../../../services/apiClient'

interface ProgressLike {
  lengthComputable: boolean
  loaded: number
  total: number
}

/** A stand-in for the browser's upload transport, driven by the test. */
class FakeXhr {
  static last: FakeXhr | null = null

  status = 200
  method = ''
  url = ''
  headers: Record<string, string> = {}
  body: unknown = null
  upload: { onprogress: ((event: ProgressLike) => void) | null } = { onprogress: null }
  onload: (() => void) | null = null
  onerror: (() => void) | null = null
  onabort: (() => void) | null = null

  constructor() {
    FakeXhr.last = this
  }

  open(method: string, url: string) {
    this.method = method
    this.url = url
  }

  setRequestHeader(key: string, value: string) {
    this.headers[key] = value
  }

  send(body: unknown) {
    this.body = body
    queueMicrotask(() => {
      this.upload.onprogress?.({ lengthComputable: true, loaded: 5, total: 10 })
      this.onload?.()
    })
  }

  abort() {
    this.onabort?.()
  }
}

const session = {
  session_id: 'sess-1',
  upload_url: 'https://storage.example/put/sess-1',
  method: 'PUT',
  headers: { 'x-amz-acl': 'private' },
  expires_at: null,
  storage_provider: 's3',
}

function file(): File {
  return new File(['a signed agreement'], 'msa.pdf', { type: 'application/pdf' })
}

beforeEach(() => {
  apiPost.mockReset()
  FakeXhr.last = null
  vi.stubGlobal('XMLHttpRequest', FakeXhr)

  apiPost.mockImplementation((path: string) => {
    if (path === '/uploads/sessions') return Promise.resolve(session)
    if (path === '/uploads/sessions/sess-1/complete') return Promise.resolve({ status: 'complete' })
    if (path === '/uploads/sessions/sess-1/finalize') {
      return Promise.resolve({
        document: { id: 3, title: 'MSA' },
        version: { id: 9, version_number: 2 },
      })
    }
    return Promise.resolve(null)
  })
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('DocumentUploader', () => {
  it('walks the session, the transfer and the finalize in order', async () => {
    const user = userEvent.setup()
    const onUploaded = vi.fn()

    render(<DocumentUploader contractId={42} onUploaded={onUploaded} />)

    await user.upload(screen.getByLabelText(/^File/), file())
    await user.click(screen.getByRole('button', { name: /upload version/i }))

    await waitFor(() => expect(onUploaded).toHaveBeenCalledTimes(1))

    expect(apiPost.mock.calls.map((call) => call[0])).toEqual([
      '/uploads/sessions',
      '/uploads/sessions/sess-1/complete',
      '/uploads/sessions/sess-1/finalize',
    ])
    expect(apiPost.mock.calls[0][1]).toMatchObject({
      contract_id: 42,
      filename: 'msa.pdf',
      content_type: 'application/pdf',
      doc_kind: 'contract',
      version_status: 'draft',
    })
    expect(FakeXhr.last?.url).toBe(session.upload_url)
    expect(FakeXhr.last?.headers['x-amz-acl']).toBe('private')
    expect(onUploaded).toHaveBeenCalledWith(expect.objectContaining({ version: { id: 9, version_number: 2 } }))
  })

  it('puts a rejected field on the field, not in a toast', async () => {
    const user = userEvent.setup()
    apiPost.mockImplementation((path: string) => {
      if (path === '/uploads/sessions') {
        return Promise.reject(
          new ApiError('That file was refused.', 422, 'VALIDATION', {
            size_bytes: 'The file is larger than 25 MB.',
          }),
        )
      }
      return Promise.resolve(null)
    })

    render(<DocumentUploader contractId={42} onUploaded={vi.fn()} />)

    await user.upload(screen.getByLabelText(/^File/), file())
    await user.click(screen.getByRole('button', { name: /upload version/i }))

    expect(await screen.findByText('The file is larger than 25 MB.')).toBeInTheDocument()
  })

  it('abandons the session when the transfer fails, and says why', async () => {
    const user = userEvent.setup()
    // The transport reports a network failure instead of completing.
    class FailingXhr extends FakeXhr {
      send() {
        queueMicrotask(() => this.onerror?.())
      }
    }
    vi.stubGlobal('XMLHttpRequest', FailingXhr)

    render(<DocumentUploader contractId={42} onUploaded={vi.fn()} />)

    await user.upload(screen.getByLabelText(/^File/), file())
    await user.click(screen.getByRole('button', { name: /upload version/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent(/could not be sent to storage/i)
    await waitFor(() =>
      expect(apiPost.mock.calls.map((call) => call[0])).toContain('/uploads/sessions/sess-1/abort'),
    )
  })

  it('will not start without a file', async () => {
    const user = userEvent.setup()
    render(<DocumentUploader contractId={42} onUploaded={vi.fn()} />)

    await user.click(screen.getByRole('button', { name: /upload version/i }))

    expect(await screen.findByText('Choose a file to upload.')).toBeInTheDocument()
    expect(apiPost).not.toHaveBeenCalled()
  })
})
