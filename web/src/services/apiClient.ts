/**
 * The one way this app talks to its own API.
 *
 * Three things happen here that must not be repeated at call sites:
 *
 *  1. A fresh `ses_key` is minted when the in-memory one has expired. The key
 *     lives ~15 minutes and never touches storage, so a page left open
 *     overnight would otherwise 401 on its next click.
 *  2. The company context (`cmp_id` / `fy_id` / `bo_id`) is attached as
 *     `X-AIC-*` headers. The API refuses any request without it, and forgetting
 *     it on one endpoint is the kind of bug that only shows up for the second
 *     company a user switches to.
 *  3. The API's error envelope is turned into a typed `ApiError`, so screens
 *     can branch on `status` and `code` instead of parsing messages.
 */

import { ensureSesKey } from '../auth/portal'
import { getApiBaseUrl } from '../config'

export interface CompanyContextIds {
  cmp_id: string
  fy_id: string
  bo_id: string
}

/** Field-level messages from a 422, keyed by field name. */
export type FieldErrors = Record<string, string>

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string = 'UNKNOWN',
    readonly fieldErrors: FieldErrors = {},
  ) {
    super(message)
    this.name = 'ApiError'
  }

  /** A 422 with per-field messages — the caller should highlight fields, not toast. */
  get isValidation(): boolean {
    return this.status === 422 && Object.keys(this.fieldErrors).length > 0
  }

  get isPermission(): boolean {
    return this.status === 403
  }

  get isNotFound(): boolean {
    return this.status === 404
  }

  /** Worth offering a retry button for; a 4xx is not. */
  get isTransient(): boolean {
    return this.status === 0 || this.status === 429 || this.status >= 500
  }
}

let currentContext: CompanyContextIds | null = null

/** Set by CompanyProvider whenever the selected company, branch or FY changes. */
export function setCompanyContext(ctx: CompanyContextIds | null): void {
  currentContext = ctx
}

export function getCompanyContext(): CompanyContextIds | null {
  return currentContext
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  query?: Record<string, string | number | boolean | null | undefined | string[]>
  signal?: AbortSignal
  /** Endpoints that legitimately run before a company is chosen. */
  skipCompanyContext?: boolean
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const base = getApiBaseUrl()
  const url = new URL(`${base}${path.startsWith('/') ? path : `/${path}`}`, window.location.origin)

  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined || value === '') continue
      if (Array.isArray(value)) {
        // Repeated key rather than a comma list: a counterparty name can
        // legitimately contain a comma, and splitting on it server-side would
        // silently corrupt the filter.
        for (const item of value) {
          if (item !== '') url.searchParams.append(key, String(item))
        }
        continue
      }
      url.searchParams.set(key, String(value))
    }
  }

  return url.toString()
}

async function parseError(response: Response): Promise<ApiError> {
  let payload: unknown = null
  try {
    payload = await response.json()
  } catch {
    // A non-JSON body means something upstream of the API answered — an Apache
    // error page, a proxy timeout. The status is all we have.
    return new ApiError(
      response.status >= 500
        ? 'The server had a problem with that request.'
        : 'That request could not be completed.',
      response.status,
    )
  }

  const body = (payload ?? {}) as {
    message?: string
    error?: string
    errors?: Record<string, string>
  }

  const errors = body.errors ?? {}
  // The envelope uses `errors` for both field messages and a single
  // {CODE: message} pair. A key matching the error code is the latter, and is
  // not a field the form can highlight.
  const fieldErrors: FieldErrors = {}
  for (const [key, value] of Object.entries(errors)) {
    if (key !== body.error && typeof value === 'string') fieldErrors[key] = value
  }

  return new ApiError(
    body.message ?? 'That request could not be completed.',
    response.status,
    body.error ?? 'UNKNOWN',
    fieldErrors,
  )
}

/**
 * Call the Contracts API and return the `data` payload.
 *
 * Throws `ApiError` for anything that is not a 2xx, including a network
 * failure (status 0), so a caller only ever has one failure shape to handle.
 */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, signal, skipCompanyContext = false } = options

  let sesKey: string
  try {
    sesKey = await ensureSesKey()
  } catch (err) {
    throw new ApiError(
      err instanceof Error ? err.message : 'Could not reach the sign-in service.',
      401,
      'SESSION_UNAVAILABLE',
    )
  }

  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
  }

  if (!skipCompanyContext) {
    if (!currentContext) {
      throw new ApiError('Select a company first.', 400, 'MISSING_COMPANY_CONTEXT')
    }
    headers['X-AIC-CMP-ID'] = currentContext.cmp_id
    headers['X-AIC-FY-ID'] = currentContext.fy_id
    headers['X-AIC-BO-ID'] = currentContext.bo_id
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  let response: Response
  try {
    response = await fetch(buildUrl(path, query), {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    })
  } catch (err) {
    // AbortError is the caller cancelling on unmount; rethrowing it as a
    // network failure would show an error toast for a screen the user has
    // already left.
    if (err instanceof DOMException && err.name === 'AbortError') throw err
    throw new ApiError('Could not reach the Contracts service.', 0, 'NETWORK_ERROR')
  }

  if (response.status === 204) {
    return undefined as T
  }

  if (!response.ok) {
    throw await parseError(response)
  }

  const payload = (await response.json()) as { data?: T }

  return (payload?.data ?? null) as T
}

export const api = {
  get: <T>(path: string, query?: RequestOptions['query'], signal?: AbortSignal) =>
    apiRequest<T>(path, { method: 'GET', query, signal }),
  post: <T>(path: string, body?: unknown, query?: RequestOptions['query']) =>
    apiRequest<T>(path, { method: 'POST', body, query }),
  put: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string) => apiRequest<T>(path, { method: 'DELETE' }),
  /** For the endpoints that answer before a company is selected. */
  getWithoutCompany: <T>(path: string, query?: RequestOptions['query']) =>
    apiRequest<T>(path, { method: 'GET', query, skipCompanyContext: true }),
}
