/**
 * Environment resolution for mobile — mirrors the host-based maps in
 * `web/src/auth/hostnames.ts` / `web/src/config.ts`, but mobile has no
 * hostname to infer from, so the target environment is an explicit
 * build-time choice instead.
 *
 * Set via EXPO_PUBLIC_CONTRACTS_ENV in .env ("sandbox" | "production") or
 * default to sandbox for local development.
 */
export type ContractsEnv = 'sandbox' | 'production';

const ENV_API_ORIGIN: Record<ContractsEnv, string> = {
  sandbox: 'https://contracts.gh.aicountly.com',
  production: 'https://contracts.aicountly.com',
};

/** my.aicountly.com is always the auth/global origin in both environments (see docs/auth). */
export const AUTH_ORIGIN = 'https://my.aicountly.com';

/**
 * Portal that serves the *login page* for the SSO in-app-browser handoff
 * (Google / LinkedIn / Microsoft). Unlike AUTH_ORIGIN this does flip per
 * environment, matching the host map in `docs/auth/AICOUNTLY_AUTH_WORKFLOW.md`:
 * sandbox hands off to sandbox.aicountly.com, production to my.aicountly.com.
 * Email/password, OTP, seskey and validatesession all still go to AUTH_ORIGIN
 * regardless of environment.
 */
const ENV_LOGIN_PORTAL: Record<ContractsEnv, string> = {
  sandbox: 'https://sandbox.aicountly.com',
  production: 'https://my.aicountly.com',
};

export function getLoginPortalOrigin(): string {
  return process.env.EXPO_PUBLIC_LOGIN_PORTAL_ORIGIN || ENV_LOGIN_PORTAL[resolveContractsEnv()];
}

/**
 * Product key this app identifies as when jumping through the portal for SSO.
 * Registered in Login::PRODUCT_CALLBACKS on my-aicountly-com (already true for
 * `contracts`, since the web app registers under the same key).
 */
export const PORTAL_PRODUCT_KEY = 'contracts';

/**
 * The audience Apple stamps into a native Sign in with Apple identity token.
 *
 * For a native credential this is the **bundle identifier**, not a web
 * Services ID — must match `ios.bundleIdentifier` in app.json exactly. The
 * portal checks the token's `aud` against its own allowlist.
 */
export const APPLE_CLIENT_ID = 'com.aicountly.contracts';

/**
 * Where a user finishes deleting their AICOUNTLY account on the web.
 *
 * App Review requires "a link directly to the website page where they can
 * complete the process" — never a generic help or settings landing page.
 */
export function getAccountDeletionUrl(): string {
  return process.env.EXPO_PUBLIC_ACCOUNT_DELETION_URL || `${AUTH_ORIGIN}/admin/home/data_privacy`;
}

export function resolveContractsEnv(): ContractsEnv {
  const raw = (process.env.EXPO_PUBLIC_CONTRACTS_ENV || 'sandbox').toLowerCase();
  return raw === 'production' ? 'production' : 'sandbox';
}

export function getApiOrigin(): string {
  return process.env.EXPO_PUBLIC_API_ORIGIN || ENV_API_ORIGIN[resolveContractsEnv()];
}

/** True for App Store / TestFlight builds targeting contracts.aicountly.com. */
export function isProductionContractsEnv(): boolean {
  return resolveContractsEnv() === 'production';
}
