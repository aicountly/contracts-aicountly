import { AUTH_ORIGIN, getApiOrigin } from '../config/env';
import { getAuthToken, getSesKey, saveSesKey, isSesKeyValid, clearSession } from './sessionStore';
import { ApiError, SessionExpiredError, MissingCompanyContextError } from './errors';

/**
 * Mobile counterpart to web/src/services/apiClient.ts. Key differences:
 * - No browser, so no CORS/hostname detection and no /api/global relay this
 *   app needs to route through — it mints/refreshes ses_key directly against
 *   my.aicountly.com the way books-react-app/mobile does, using the
 *   auth_token AuthProvider stored via sessionStore.
 * - No redirect-to-login: callers get a SessionExpiredError and the
 *   navigation layer (AuthProvider + app/(app)/_layout.tsx) decides what
 *   screen to show.
 * - Same company-context headers (X-AIC-CMP-ID/FY-ID/BO-ID) and the same
 *   `{ data: T }` response envelope as web, since it's the same server-php
 *   API underneath — see web/src/services/apiClient.ts.
 */

const SESKEY_TIMEOUT_MS = 15_000;

async function fetchWithTimeout(url: string, options: RequestInit, timeoutMs = SESKEY_TIMEOUT_MS): Promise<Response> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...options, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

interface MintResult {
  ok: boolean;
  key?: string;
  status: number;
  message?: string;
}

async function requestSesKey(path: string): Promise<MintResult> {
  const authToken = await getAuthToken();
  if (!authToken) return { ok: false, status: 401, message: 'No auth token stored' };

  let res: Response;
  try {
    res = await fetchWithTimeout(`${AUTH_ORIGIN}${path}`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${authToken}` },
    });
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'Network error' };
  }

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    return { ok: false, status: res.status, message: text || `HTTP ${res.status}` };
  }

  const data = await res.json();
  const key = data?.ses_key ?? data?.sesKey ?? data?.token ?? data?.access_token;
  const expiresIn = data?.expires_in ?? data?.expiresIn ?? 900;
  if (!key) return { ok: false, status: 200, message: 'No session key in response' };

  saveSesKey(key, expiresIn);
  return { ok: true, key, status: 200 };
}

let mintPromise: Promise<MintResult> | null = null;
async function mintSesKey(): Promise<MintResult> {
  if (mintPromise) return mintPromise;
  mintPromise = requestSesKey('/api/seskey').finally(() => {
    mintPromise = null;
  });
  return mintPromise;
}

let refreshPromise: Promise<MintResult> | null = null;
async function refreshSesKey(): Promise<MintResult> {
  if (refreshPromise) return refreshPromise;
  refreshPromise = requestSesKey('/api/seskey/refresh').finally(() => {
    refreshPromise = null;
  });
  return refreshPromise;
}

/** Ensures a valid ses_key exists, minting one if needed. Throws SessionExpiredError if auth_token is missing/invalid. */
export async function ensureSesKey(): Promise<string> {
  if (isSesKeyValid()) return getSesKey() as string;

  const result = await mintSesKey();
  if (result.ok && result.key) return result.key;

  if (result.status === 401) {
    await clearSession();
    throw new SessionExpiredError();
  }
  // Anything else (network failure, 5xx) is transient — do not sign the user
  // out just because the portal was briefly unreachable.
  throw new ApiError(result.message || 'Could not reach the sign-in service.', result.status, 'SESSION_UNAVAILABLE');
}

export interface CompanyContextIds {
  cmp_id: string;
  fy_id: string;
  bo_id: string;
}

let currentContext: CompanyContextIds | null = null;

/** Set by CompanyContext whenever the selected company, branch or FY changes. */
export function setCompanyContext(ctx: CompanyContextIds | null): void {
  currentContext = ctx;
}

export function getCompanyContext(): CompanyContextIds | null {
  return currentContext;
}

function contextHeaders(skip: boolean): Record<string, string> {
  if (skip) return {};
  if (!currentContext) throw new MissingCompanyContextError();
  return {
    'X-AIC-CMP-ID': currentContext.cmp_id,
    'X-AIC-FY-ID': currentContext.fy_id,
    'X-AIC-BO-ID': currentContext.bo_id,
  };
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined | string[]>;
  signal?: AbortSignal;
  /** Endpoints that legitimately run before a company is chosen (e.g. /manage/companies). */
  skipCompanyContext?: boolean;
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const base = getApiOrigin();
  const url = new URL(`${base}/api${path.startsWith('/') ? path : `/${path}`}`);

  if (query) {
    for (const [key, value] of Object.entries(query)) {
      if (value === null || value === undefined || value === '') continue;
      if (Array.isArray(value)) {
        // Repeated key rather than a comma list — a counterparty name can
        // legitimately contain a comma.
        for (const item of value) {
          if (item !== '') url.searchParams.append(key, String(item));
        }
        continue;
      }
      url.searchParams.set(key, String(value));
    }
  }

  return url.toString();
}

async function parseError(response: Response): Promise<ApiError> {
  let payload: unknown = null;
  try {
    payload = await response.json();
  } catch {
    return new ApiError(
      response.status >= 500 ? 'The server had a problem with that request.' : 'That request could not be completed.',
      response.status,
    );
  }

  const body = (payload ?? {}) as { message?: string; error?: string; errors?: Record<string, string> };
  const errors = body.errors ?? {};
  // The envelope uses `errors` for both field messages and a single
  // {CODE: message} pair. A key matching the error code is the latter, and
  // is not a field a form can highlight.
  const fieldErrors: Record<string, string> = {};
  for (const [key, value] of Object.entries(errors)) {
    if (key !== body.error && typeof value === 'string') fieldErrors[key] = value;
  }

  return new ApiError(body.message ?? 'That request could not be completed.', response.status, body.error ?? 'UNKNOWN', fieldErrors);
}

/** Call the Contracts API and return the `data` payload. Throws ApiError for anything not a 2xx, network failure included. */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, query, signal, skipCompanyContext = false } = options;

  const sesKey = await ensureSesKey();
  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
    ...contextHeaders(skipCompanyContext),
  };
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const doFetch = (auth: string) =>
    fetch(buildUrl(path, query), {
      method,
      headers: { ...headers, Authorization: `Bearer ${auth}` },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal,
    });

  let response: Response;
  try {
    response = await doFetch(sesKey);
  } catch (err) {
    if (err instanceof DOMException && err.name === 'AbortError') throw err;
    throw new ApiError('Could not reach the Contracts service.', 0, 'NETWORK_ERROR');
  }

  if (response.status === 401) {
    const refreshed = await refreshSesKey();
    if (!refreshed.ok || !refreshed.key) {
      await clearSession();
      throw new SessionExpiredError();
    }
    response = await doFetch(refreshed.key);
    if (response.status === 401) {
      await clearSession();
      throw new SessionExpiredError();
    }
  }

  if (response.status === 204) return undefined as T;
  if (!response.ok) throw await parseError(response);

  const payload = (await response.json()) as { data?: T };
  return (payload?.data ?? null) as T;
}

export const api = {
  get: <T>(path: string, query?: RequestOptions['query'], signal?: AbortSignal) => apiRequest<T>(path, { method: 'GET', query, signal }),
  post: <T>(path: string, body?: unknown, query?: RequestOptions['query']) => apiRequest<T>(path, { method: 'POST', body, query }),
  put: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: 'PATCH', body }),
  delete: <T>(path: string) => apiRequest<T>(path, { method: 'DELETE' }),
  /** For the endpoints that answer before a company is selected. */
  getWithoutCompany: <T>(path: string, query?: RequestOptions['query']) => apiRequest<T>(path, { method: 'GET', query, skipCompanyContext: true }),
};

/**
 * Multipart upload (document versions, obligation evidence). Same ses_key +
 * header handling as apiRequest; the JSON content type is deliberately
 * omitted so the runtime can set `multipart/form-data` with the boundary it
 * generated for this FormData — supplying our own would leave the boundary
 * off and the server would parse no file at all.
 */
export async function apiUpload<T>(path: string, form: FormData, options: { method?: 'POST' | 'PUT'; skipCompanyContext?: boolean } = {}): Promise<T> {
  const sesKey = await ensureSesKey();
  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
    ...contextHeaders(options.skipCompanyContext ?? false),
  };
  const response = await fetch(buildUrl(path), { method: options.method ?? 'POST', headers, body: form });
  if (!response.ok) throw await parseError(response);
  if (response.status === 204) return undefined as T;
  const payload = (await response.json()) as { data?: T };
  return (payload?.data ?? null) as T;
}

/** Authenticated fetch against my.aicountly.com global APIs — an escape hatch mirroring web's, rarely needed since Contracts proxies most of Manage under /api/manage/*. */
export async function globalFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const sesKey = await ensureSesKey();
  const url = `${AUTH_ORIGIN}/api${path.startsWith('/') ? path : `/${path}`}`;
  const headers = { 'Content-Type': 'application/json', ...(options.headers as Record<string, string> | undefined), Authorization: `Bearer ${sesKey}` };
  const response = await fetch(url, { ...options, headers });
  if (!response.ok) throw await parseError(response);
  if (response.status === 204) return undefined as T;
  const payload = (await response.json()) as { data?: T };
  return (payload?.data ?? payload) as T;
}
