# Mobile authentication

How sign-in works in the Contracts app, what it talks to, and how it differs
from the web app's own auth flow.

Related: [`docs/auth/AICOUNTLY_AUTH_WORKFLOW.md`](../../docs/auth/AICOUNTLY_AUTH_WORKFLOW.md)
(the workflow shared with the web SPA).

---

## The invariant

Every way of signing in ends at the same two steps, in `establishSession()`
in [`src/auth/authProviders/nativeAuthProvider.ts`](../src/auth/authProviders/nativeAuthProvider.ts):

```
obtain auth_token  →  saveAuthToken(token)  →  ensureSesKey()  →  signedIn
                       (expo-secure-store)     (~15-min ses_key, memory only)
```

A bad or expired token fails right here — in `establishSession()` — instead
of surfacing later as a confusing 401 on the first screen's data fetch. If
`ensureSesKey()` throws, the token is never considered "signed in."

`ses_key` is never persisted. `auth_token` is, under SecureStore — see
[`src/api/sessionStore.ts`](../src/api/sessionStore.ts).

## Why native, not a browser redirect

Web's own auth (`web/src/auth/portal.ts`) redirects the whole page to
`my.aicountly.com` and back, because a browser page can't call another
origin's login API directly without CORS. A native app has no such
restriction, so this app calls `my.aicountly.com` directly for the primary
flows (password, OTP, Apple) instead of leaving the app — the same shift
`books-react-app/mobile` already made and proved in production. Only the
three OAuth-consent flows (Google/LinkedIn/Microsoft) still need a browser,
since the actual consent screen is the provider's, not AICOUNTLY's.

## Screens

| Route | Purpose |
|---|---|
| `/login` | Email + password (default) and mobile OTP, behind one in-screen toggle, plus the SSO/Apple row |
| `/auth/callback` | Deep-link landing for `aicountlycontracts://auth/callback` — a fallback only, see below |
| `/company-picker` | Shown once signed in but no company/branch/FY is resolved yet |

There is no sign-up, forgot-password, or account-selection screen here.
Unlike Books, a Contracts company is provisioned through AICOUNTLY Manage,
not created from inside this app, so there is no account to create or
recover from a Contracts login screen — signing in always means an account
already exists.

Sign in with Apple has no route of its own — it's a button on `/login`
itself (`showSsoRow` in `app/(auth)/login.tsx`), shown only when
`AppleAuthentication.isAvailableAsync()` says the platform can offer it
(iOS 13+; never Android, never Expo Go on iOS without a dev client).

## Endpoints

All on `https://my.aicountly.com` (`AUTH_ORIGIN`, see `src/config/env.ts`)
regardless of `EXPO_PUBLIC_CONTRACTS_ENV` — the Contracts API server itself
has no auth endpoints.

| Endpoint | Used by |
|---|---|
| `POST /api/login` | email + password (`signInWithCredentials`) |
| `POST /api/seskey`, `/api/seskey/refresh` | session lifecycle (`ensureSesKey` in `src/api/client.ts`) |
| `POST /api/auth/otp/send`, `/api/auth/otp/verify` | mobile OTP |
| `POST /api/auth/social_login` | native Sign in with Apple (`provider: 'apple'`) |
| `GET /login/social_start/{provider}` | Google/LinkedIn/Microsoft browser handoff |
| `GET /api/auth/config` | capability probe — see below |

Every request from `signInWithCredentials`/`requestOtp`/`verifyOtp`/
`signInWithAppleIdentityToken` also sends `product: 'contracts'`
(`PORTAL_PRODUCT_KEY`), the same product key the web app registers under.

## Graceful degradation

`fetchAuthConfig()` calls `GET /api/auth/config` once on launch. A failure
resolves to `{ password: true }` only — OTP and every SSO button stay
hidden rather than shown-and-broken. **Email + password never depends on
this** — it's always offered, live or not, since `POST /api/login` has
always existed.

## Social sign-in (Google / LinkedIn / Microsoft)

The provider consent runs on the portal, not in the app, so no Google /
Azure / LinkedIn client IDs or secrets live here — see
[`src/auth/ssoFlow.ts`](../src/auth/ssoFlow.ts).

1. `handleSso(provider)` on `/login` opens
   `WebBrowser.openAuthSessionAsync` at
   `{portal}/login/social_start/{provider}?product=contracts&returnUrl=…`.
2. The portal runs its existing server-side OAuth and redirects to
   `aicountlycontracts://auth/callback?auth_token=…`.
3. `openAuthSessionAsync` intercepts that redirect itself and returns it to
   the caller — `/auth/callback` (the actual screen) is a fallback for the
   rare case the OS routes the deep link to the app directly instead; it
   just bounces back to `/` and lets `AuthProvider`'s current state decide
   where to go.
4. `signInWithAuthToken(authToken)` finishes through the same
   `establishSession()` every other method uses.

`getLoginPortalOrigin()` is the one place this flow differs by environment:
sandbox hands off to `sandbox.aicountly.com`, production to
`my.aicountly.com` — email/password, OTP and seskey calls are unaffected
and always go to `my.aicountly.com`.

## Sign in with Apple

Required by App Review guideline 4.8 once the app offers any other
third-party login — Google, LinkedIn and Microsoft all get an equivalent
here. See [`src/auth/appleSignIn.ts`](../src/auth/appleSignIn.ts).

Unlike the other three, this never opens a browser:

1. `AppleAuthenticationButton` (Apple's own certified button) calls
   `AppleAuthentication.signInAsync()` directly, which shows Apple's native
   sheet and returns an `identityToken` (a JWT Apple itself signs) plus,
   **only on the account's first authorization ever**, the user's name.
   Every sign-in after that returns no name — that's expected, not an
   error, and is why the name is captured into `AuthUser` right there
   rather than re-requested later.
2. `signInWithAppleIdentityToken()` sends
   `{ provider: 'apple', id_token, client_id: APPLE_CLIENT_ID }` to
   `POST /api/auth/social_login` — the same endpoint the Google/LinkedIn/
   Microsoft browser handoff ultimately resolves to on the portal side,
   just reached directly with a fourth provider value.
3. The portal verifies the JWT's signature and claims against Apple's
   published keys before minting an `auth_token` — this app is never
   trusted for the identity, only (once, cosmetically) for the display
   name it happened to capture.

### The audience is the bundle id, not a Services ID

`APPLE_CLIENT_ID` in `src/config/env.ts` is `com.aicountly.contracts` — the
**bundle identifier**, matching `ios.bundleIdentifier` in `app.json`
exactly. A native Sign in with Apple credential is issued to the bundle id,
not to a web Services ID; sending the wrong one is the most common cause of
the portal's verifier rejecting an otherwise-valid token.

## Account deletion

This app never creates the AICOUNTLY account (see Screens above), so
deleting it isn't an in-app flow either — the Account screen's "Delete
account" button opens `getAccountDeletionUrl()` (default
`https://my.aicountly.com/admin/home/data_privacy`, overridable via
`EXPO_PUBLIC_ACCOUNT_DELETION_URL`), the page that actually completes it.
App Review's guideline 5.1.1(v) explicitly allows a direct link out when
account creation itself happens outside the app.

## Testing social sign-in

`Linking.createURL('auth/callback')` returns `aicountlycontracts://auth/callback`
in a dev-client or store build, but an `exp://<lan-host>:<port>/--/auth/callback`
URL under Expo Go. That host changes with the network, so it can't be
allowlisted literally — check with whoever operates the sandbox portal
whether it accepts an `exp://` callback the way the equivalent Books
sandbox does, before assuming SSO is testable in Expo Go here.

To test against production, use a dev client rather than Expo Go:

```bash
npx expo run:ios      # or run:android
```

## Testing the OTP flow without real SMS

Same recipe as Books' own mobile app — see
[`books-react-app/mobile/docs/MOBILE_AUTH.md`](https://github.com/aicountly/books-react-app/blob/main/mobile/docs/MOBILE_AUTH.md#testing-the-otp-flow-without-real-sms)
for the portal-side `SMS_SKIP_SEND` setup, since the OTP endpoints
(`/api/auth/otp/send` / `/verify`) and the portal that serves them are
shared infrastructure, not specific to either app.
