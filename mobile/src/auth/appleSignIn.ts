import * as AppleAuthentication from 'expo-apple-authentication';
import { signInWithAppleIdentityToken, type SignInOutcome } from './authProviders/nativeAuthProvider';

export async function isAppleSignInAvailable(): Promise<boolean> {
  try {
    return await AppleAuthentication.isAvailableAsync();
  } catch {
    return false;
  }
}

/** Apple requires this whenever another 3rd-party social login is offered (App Store guideline 4.8) — see docs/MOBILE_AUTH.md. */
export async function signInWithApple(): Promise<SignInOutcome> {
  try {
    const credential = await AppleAuthentication.signInAsync({
      requestedScopes: [AppleAuthentication.AppleAuthenticationScope.FULL_NAME, AppleAuthentication.AppleAuthenticationScope.EMAIL],
    });

    if (!credential.identityToken) {
      return { ok: false, message: 'Apple did not return a sign-in token.' };
    }

    // Apple sends the name only on the very first authorization ever, and
    // never again — capture it now or it is gone for this user for good.
    const name = credential.fullName
      ? [credential.fullName.givenName, credential.fullName.familyName].filter(Boolean).join(' ')
      : undefined;

    return signInWithAppleIdentityToken(credential.identityToken, {
      email: credential.email ?? undefined,
      name: name || undefined,
    });
  } catch (err) {
    const code = (err as { code?: string } | null)?.code;
    if (code === 'ERR_REQUEST_CANCELED') return { ok: false };
    return { ok: false, message: err instanceof Error ? err.message : 'Apple sign-in failed.' };
  }
}
