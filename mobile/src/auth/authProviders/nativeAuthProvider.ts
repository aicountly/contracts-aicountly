import { AUTH_ORIGIN, APPLE_CLIENT_ID, PORTAL_PRODUCT_KEY } from '../../config/env';
import { saveAuthToken, hasStoredAuthToken as hasToken, clearSession as clearStoredSession } from '../../api/sessionStore';
import { ensureSesKey } from '../../api/client';

/**
 * Native counterpart to web's portal-redirect sign-in
 * (web/src/auth/portal.ts). A mobile app authenticates directly against
 * my.aicountly.com instead of bouncing through a browser for the primary
 * flow — the same shift books-react-app/mobile made, and for the same
 * reason: a native app isn't subject to CORS, so there's no relay to build,
 * and leaving the app for every sign-in feels worse on a phone than in an
 * already-open browser tab. See docs/MOBILE_AUTH.md.
 */

export interface AuthUser {
  uuid?: string;
  name?: string | null;
  email?: string | null;
}

export interface SignInOutcome {
  ok: boolean;
  message?: string;
  user?: AuthUser;
}

interface LoginResponse {
  auth_token?: string;
  token?: string;
  user?: AuthUser;
  message?: string;
}

interface AuthOriginResult<T> {
  ok: boolean;
  status: number;
  data?: T;
  message?: string;
}

async function postAuthOrigin<T>(path: string, body: Record<string, unknown>): Promise<AuthOriginResult<T>> {
  try {
    const res = await fetch(`${AUTH_ORIGIN}${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    const text = await res.text();
    let json: unknown = null;
    try {
      json = text ? JSON.parse(text) : null;
    } catch {
      /* not JSON — fall through with json left null */
    }
    if (!res.ok) {
      const parsed = (json ?? {}) as { message?: string; error?: string };
      return { ok: false, status: res.status, message: parsed.message || parsed.error || text || `HTTP ${res.status}` };
    }
    return { ok: true, status: res.status, data: json as T };
  } catch (err) {
    return { ok: false, status: 0, message: err instanceof Error ? err.message : 'Network error' };
  }
}

/** Persists auth_token then mints the first ses_key — a bad or expired token fails right here instead of on the first screen's data fetch. */
async function establishSession(authToken: string, user?: AuthUser): Promise<SignInOutcome> {
  await saveAuthToken(authToken);
  try {
    await ensureSesKey();
  } catch (err) {
    return { ok: false, message: err instanceof Error ? err.message : 'Could not start a session.' };
  }
  return { ok: true, user };
}

export async function hasStoredAuthToken(): Promise<boolean> {
  return hasToken();
}

export async function signInWithCredentials(loginName: string, password: string): Promise<SignInOutcome> {
  const result = await postAuthOrigin<LoginResponse>('/api/login', {
    login: loginName,
    email: loginName,
    password,
    product: PORTAL_PRODUCT_KEY,
  });
  if (!result.ok || !result.data) return { ok: false, message: result.message || 'Incorrect email or password.' };
  const token = result.data.auth_token ?? result.data.token;
  if (!token) return { ok: false, message: 'Sign-in did not return a session token.' };
  return establishSession(token, result.data.user);
}

export async function requestOtp(identifier: string): Promise<{ ok: boolean; message?: string }> {
  const result = await postAuthOrigin('/api/auth/otp/send', { identifier, product: PORTAL_PRODUCT_KEY });
  return { ok: result.ok, message: result.message };
}

export async function verifyOtp(identifier: string, code: string): Promise<SignInOutcome> {
  const result = await postAuthOrigin<LoginResponse>('/api/auth/otp/verify', { identifier, code, product: PORTAL_PRODUCT_KEY });
  if (!result.ok || !result.data) return { ok: false, message: result.message || 'That code is incorrect or has expired.' };
  const token = result.data.auth_token ?? result.data.token;
  if (!token) return { ok: false, message: 'Sign-in did not return a session token.' };
  return establishSession(token, result.data.user);
}

export async function signInWithAppleIdentityToken(identityToken: string, user?: AuthUser): Promise<SignInOutcome> {
  const result = await postAuthOrigin<LoginResponse>('/api/auth/social_login', {
    provider: 'apple',
    id_token: identityToken,
    client_id: APPLE_CLIENT_ID,
    product: PORTAL_PRODUCT_KEY,
  });
  if (!result.ok || !result.data) return { ok: false, message: result.message || 'Apple sign-in failed.' };
  const token = result.data.auth_token ?? result.data.token;
  if (!token) return { ok: false, message: 'Sign-in did not return a session token.' };
  return establishSession(token, user ?? result.data.user);
}

/**
 * Finish signing in from an auth_token obtained some other way — the portal
 * SSO browser handoff. Every sign-in path ends here, so the
 * token-persist / ses_key-mint pair is written exactly once.
 */
export async function signInWithAuthToken(authToken: string, user?: AuthUser): Promise<SignInOutcome> {
  return establishSession(authToken, user);
}

export interface AuthConfig {
  password?: boolean;
  otp?: boolean;
  google?: boolean;
  linkedin?: boolean;
  microsoft?: boolean;
  apple?: boolean;
}

/**
 * Probes what the portal actually has deployed, so the login screen disables
 * a method that isn't live rather than failing on tap. Some of these
 * endpoints (OTP, social) live in the my-aicountly-com repo and may lag a
 * mobile release — see docs/MOBILE_AUTH.md.
 */
export async function fetchAuthConfig(): Promise<AuthConfig> {
  try {
    const res = await fetch(`${AUTH_ORIGIN}/api/auth/config`);
    if (!res.ok) return { password: true };
    return { password: true, ...((await res.json()) as AuthConfig) };
  } catch {
    return { password: true };
  }
}

export async function signOut(): Promise<void> {
  await clearStoredSession();
}
