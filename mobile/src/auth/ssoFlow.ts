import * as WebBrowser from 'expo-web-browser';
import * as Linking from 'expo-linking';
import { getLoginPortalOrigin, PORTAL_PRODUCT_KEY } from '../config/env';

export type SsoProvider = 'google' | 'linkedin' | 'microsoft';

const REDIRECT_URL = Linking.createURL('auth/callback');

export type SsoResult =
  | { type: 'success'; authToken: string }
  | { type: 'cancelled' }
  | { type: 'error'; message: string };

/**
 * Opens the portal's social sign-in page in an in-app browser session and
 * waits for the aicountlycontracts://auth/callback redirect — the same
 * `/login/social_start/{provider}` handoff the web app's portal already
 * serves, just completed in a native browser sheet instead of a full page
 * navigation. `openAuthSessionAsync` owns matching the redirect back to this
 * call; no separate deep-link listener is needed.
 */
export async function startSsoFlow(provider: SsoProvider): Promise<SsoResult> {
  const startUrl = `${getLoginPortalOrigin()}/login/social_start/${provider}?product=${PORTAL_PRODUCT_KEY}&returnUrl=${encodeURIComponent(REDIRECT_URL)}`;

  const result = await WebBrowser.openAuthSessionAsync(startUrl, REDIRECT_URL);

  if (result.type === 'cancel' || result.type === 'dismiss') return { type: 'cancelled' };
  if (result.type !== 'success' || !result.url) return { type: 'error', message: 'Sign-in did not complete.' };

  const { queryParams } = Linking.parse(result.url);
  const authToken = typeof queryParams?.auth_token === 'string' ? queryParams.auth_token : undefined;
  if (!authToken) {
    const errorParam = typeof queryParams?.error === 'string' ? queryParams.error : null;
    return { type: 'error', message: errorParam || 'Sign-in did not complete.' };
  }
  return { type: 'success', authToken };
}
