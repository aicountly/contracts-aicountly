import * as SecureStore from 'expo-secure-store';

const AUTH_TOKEN_KEY = 'aic.contracts.auth_token';

/**
 * `auth_token` is long-lived and persisted (SecureStore — the native
 * equivalent of web's localStorage + .aicountly.com cookie). `ses_key` lives
 * ~15 minutes and is held in a plain module variable only — never
 * SecureStore, never AsyncStorage — matching the hard rule in
 * docs/auth/AICOUNTLY_AUTH_WORKFLOW.md: "ses_key must never be written to
 * localStorage or sessionStorage."
 */
let sesKey: string | null = null;
let sesKeyExpiresAt = 0;

export async function getAuthToken(): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(AUTH_TOKEN_KEY);
  } catch {
    return null;
  }
}

export async function hasStoredAuthToken(): Promise<boolean> {
  return (await getAuthToken()) !== null;
}

export async function saveAuthToken(token: string): Promise<void> {
  await SecureStore.setItemAsync(AUTH_TOKEN_KEY, token);
}

/** Renews a little early so a request starting just before expiry doesn't race it. */
export function saveSesKey(key: string, expiresInSeconds: number): void {
  sesKey = key;
  sesKeyExpiresAt = Date.now() + Math.max(expiresInSeconds - 30, 15) * 1000;
}

export function getSesKey(): string | null {
  return sesKey;
}

export function isSesKeyValid(): boolean {
  return sesKey !== null && Date.now() < sesKeyExpiresAt;
}

/** Full sign-out: clears both the persisted auth_token and the in-memory ses_key. */
export async function clearSession(): Promise<void> {
  sesKey = null;
  sesKeyExpiresAt = 0;
  try {
    await SecureStore.deleteItemAsync(AUTH_TOKEN_KEY);
  } catch {
    /* nothing to clear */
  }
}
