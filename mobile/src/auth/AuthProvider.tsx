import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import {
  hasStoredAuthToken,
  signInWithCredentials,
  requestOtp as requestOtpCode,
  verifyOtp as verifyOtpCode,
  signInWithAuthToken,
  fetchAuthConfig,
  signOut as nativeSignOut,
  type AuthUser,
  type SignInOutcome,
  type AuthConfig,
} from './authProviders/nativeAuthProvider';
import { signInWithApple, isAppleSignInAvailable } from './appleSignIn';
import { startSsoFlow, type SsoProvider } from './ssoFlow';

export type AuthStatus = 'loading' | 'signedOut' | 'signedIn';
export type { AuthUser, AuthConfig } from './authProviders/nativeAuthProvider';
export type { SsoProvider } from './ssoFlow';

interface AuthResult {
  ok: boolean;
  message?: string;
}

interface AuthContextValue {
  status: AuthStatus;
  /** Profile of the signed-in user, when the sign-in path supplied one. */
  user: AuthUser | null;
  /** What the portal actually has deployed — the login screen hides what isn't live rather than failing on tap. */
  authConfig: AuthConfig;
  appleAvailable: boolean;
  signIn: (loginName: string, password: string) => Promise<AuthResult>;
  requestOtp: (identifier: string) => Promise<AuthResult>;
  verifyOtp: (identifier: string, code: string) => Promise<AuthResult>;
  signInWithSso: (provider: SsoProvider) => Promise<AuthResult>;
  signInWithApple: () => Promise<AuthResult>;
  signOut: () => Promise<void>;
}

const AuthContext = createContext<AuthContextValue | null>(null);

const BOOTSTRAP_TIMEOUT_MS = 4000;

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [status, setStatus] = useState<AuthStatus>('loading');
  const [user, setUser] = useState<AuthUser | null>(null);
  const [authConfig, setAuthConfig] = useState<AuthConfig>({});
  const [appleAvailable, setAppleAvailable] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function bootstrap() {
      try {
        const [hasToken, config, apple] = await Promise.all([
          Promise.race([
            hasStoredAuthToken(),
            new Promise<boolean>((resolve) => {
              setTimeout(() => resolve(false), BOOTSTRAP_TIMEOUT_MS);
            }),
          ]),
          fetchAuthConfig(),
          isAppleSignInAvailable(),
        ]);
        if (cancelled) return;
        setAuthConfig(config);
        setAppleAvailable(apple);
        setStatus(hasToken ? 'signedIn' : 'signedOut');
      } catch (err) {
        console.error('[AuthProvider] bootstrap failed', err);
        if (!cancelled) setStatus('signedOut');
      }
    }

    bootstrap();
    return () => {
      cancelled = true;
    };
  }, []);

  const applyOutcome = useCallback((outcome: SignInOutcome): AuthResult => {
    if (outcome.ok) {
      setStatus('signedIn');
      if (outcome.user) setUser(outcome.user);
    }
    return { ok: outcome.ok, message: outcome.message };
  }, []);

  const signIn = useCallback(async (loginName: string, password: string) => applyOutcome(await signInWithCredentials(loginName, password)), [applyOutcome]);

  const requestOtp = useCallback(async (identifier: string) => requestOtpCode(identifier), []);

  const verifyOtp = useCallback(async (identifier: string, code: string) => applyOutcome(await verifyOtpCode(identifier, code)), [applyOutcome]);

  const signInWithSso = useCallback(
    async (provider: SsoProvider): Promise<AuthResult> => {
      const result = await startSsoFlow(provider);
      if (result.type === 'cancelled') return { ok: false };
      if (result.type === 'error') return { ok: false, message: result.message };
      return applyOutcome(await signInWithAuthToken(result.authToken));
    },
    [applyOutcome],
  );

  const signInWithAppleAction = useCallback(async () => applyOutcome(await signInWithApple()), [applyOutcome]);

  const signOut = useCallback(async () => {
    await nativeSignOut();
    setUser(null);
    setStatus('signedOut');
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      status,
      user,
      authConfig,
      appleAvailable,
      signIn,
      requestOtp,
      verifyOtp,
      signInWithSso,
      signInWithApple: signInWithAppleAction,
      signOut,
    }),
    [status, user, authConfig, appleAvailable, signIn, requestOtp, verifyOtp, signInWithSso, signInWithAppleAction, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
