import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { useAuth } from '../../src/auth/AuthProvider';
import { ScreenContainer, FormField, PrimaryButton } from '../../src/components';
import { colors } from '../../src/theme/colors';

type Mode = 'password' | 'otp-request' | 'otp-verify';

export default function LoginScreen() {
  const { signIn, requestOtp, verifyOtp, signInWithSso, signInWithApple, authConfig, appleAvailable } = useAuth();

  const [mode, setMode] = useState<Mode>('password');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [otpIdentifier, setOtpIdentifier] = useState('');
  const [otpCode, setOtpCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [ssoBusy, setSsoBusy] = useState<string | null>(null);

  async function handlePasswordSignIn() {
    setError(null);
    setBusy(true);
    const result = await signIn(email.trim(), password);
    setBusy(false);
    if (!result.ok) setError(result.message ?? 'Sign-in failed.');
  }

  async function handleRequestOtp() {
    setError(null);
    setBusy(true);
    const result = await requestOtp(otpIdentifier.trim());
    setBusy(false);
    if (!result.ok) {
      setError(result.message ?? 'Could not send a code.');
      return;
    }
    setMode('otp-verify');
  }

  async function handleVerifyOtp() {
    setError(null);
    setBusy(true);
    const result = await verifyOtp(otpIdentifier.trim(), otpCode.trim());
    setBusy(false);
    if (!result.ok) setError(result.message ?? 'That code is incorrect or has expired.');
  }

  async function handleSso(provider: 'google' | 'linkedin' | 'microsoft') {
    setError(null);
    setSsoBusy(provider);
    const result = await signInWithSso(provider);
    setSsoBusy(null);
    if (!result.ok && result.message) setError(result.message);
  }

  async function handleApple() {
    setError(null);
    setSsoBusy('apple');
    const result = await signInWithApple();
    setSsoBusy(null);
    if (!result.ok && result.message) setError(result.message);
  }

  const showSsoRow = authConfig.google || authConfig.linkedin || authConfig.microsoft || appleAvailable;

  return (
    <ScreenContainer>
      <ScrollView contentContainerStyle={{ flexGrow: 1, padding: 24, justifyContent: 'center' }} keyboardShouldPersistTaps="handled">
        <View style={{ alignItems: 'center', marginBottom: 32 }}>
          <View
            style={{
              width: 64,
              height: 64,
              borderRadius: 18,
              backgroundColor: colors.primary,
              alignItems: 'center',
              justifyContent: 'center',
              marginBottom: 16,
            }}
          >
            <Text style={{ color: '#FFFFFF', fontSize: 28, fontFamily: 'Nunito_700Bold' }}>C</Text>
          </View>
          <Text style={{ fontSize: 22, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>Contracts</Text>
          <Text style={{ fontSize: 13, fontFamily: 'Nunito_400Regular', color: colors.textMuted, marginTop: 2 }}>AICOUNTLY</Text>
        </View>

        {error ? (
          <View style={{ backgroundColor: colors.dangerSoft, borderRadius: 10, padding: 12, marginBottom: 16 }}>
            <Text style={{ color: colors.danger, fontSize: 13 }}>{error}</Text>
          </View>
        ) : null}

        {mode === 'password' && (
          <>
            <FormField
              label="Email"
              autoCapitalize="none"
              keyboardType="email-address"
              value={email}
              onChangeText={setEmail}
              placeholder="you@company.com"
            />
            <FormField label="Password" secureTextEntry value={password} onChangeText={setPassword} placeholder="••••••••" />
            <PrimaryButton label="Sign in" onPress={handlePasswordSignIn} loading={busy} disabled={!email || !password} />
            {authConfig.otp ? (
              <Pressable
                onPress={() => {
                  setMode('otp-request');
                  setError(null);
                }}
                style={{ marginTop: 16, alignItems: 'center' }}
              >
                <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 13 }}>
                  Sign in with a one-time code instead
                </Text>
              </Pressable>
            ) : null}
          </>
        )}

        {mode === 'otp-request' && (
          <>
            <FormField label="Email or phone" autoCapitalize="none" value={otpIdentifier} onChangeText={setOtpIdentifier} placeholder="you@company.com" />
            <PrimaryButton label="Send code" onPress={handleRequestOtp} loading={busy} disabled={!otpIdentifier} />
            <Pressable
              onPress={() => {
                setMode('password');
                setError(null);
              }}
              style={{ marginTop: 16, alignItems: 'center' }}
            >
              <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 13 }}>Back to password sign-in</Text>
            </Pressable>
          </>
        )}

        {mode === 'otp-verify' && (
          <>
            <Text style={{ color: colors.textMuted, fontSize: 13, marginBottom: 12 }}>Enter the code sent to {otpIdentifier}.</Text>
            <FormField label="Code" keyboardType="number-pad" value={otpCode} onChangeText={setOtpCode} placeholder="123456" />
            <PrimaryButton label="Verify" onPress={handleVerifyOtp} loading={busy} disabled={!otpCode} />
            <Pressable
              onPress={() => {
                setMode('otp-request');
                setError(null);
              }}
              style={{ marginTop: 16, alignItems: 'center' }}
            >
              <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 13 }}>Resend code</Text>
            </Pressable>
          </>
        )}

        {showSsoRow ? (
          <View style={{ marginTop: 28 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', marginBottom: 16 }}>
              <View style={{ flex: 1, height: 1, backgroundColor: colors.border }} />
              <Text style={{ marginHorizontal: 12, color: colors.textMuted, fontSize: 12 }}>or continue with</Text>
              <View style={{ flex: 1, height: 1, backgroundColor: colors.border }} />
            </View>
            <View style={{ gap: 10 }}>
              {authConfig.google ? (
                <PrimaryButton label="Continue with Google" onPress={() => handleSso('google')} loading={ssoBusy === 'google'} variant="secondary" />
              ) : null}
              {authConfig.linkedin ? (
                <PrimaryButton label="Continue with LinkedIn" onPress={() => handleSso('linkedin')} loading={ssoBusy === 'linkedin'} variant="secondary" />
              ) : null}
              {authConfig.microsoft ? (
                <PrimaryButton
                  label="Continue with Microsoft"
                  onPress={() => handleSso('microsoft')}
                  loading={ssoBusy === 'microsoft'}
                  variant="secondary"
                />
              ) : null}
              {appleAvailable ? (
                <PrimaryButton label="Continue with Apple" onPress={handleApple} loading={ssoBusy === 'apple'} variant="secondary" />
              ) : null}
            </View>
          </View>
        ) : null}
      </ScrollView>
    </ScreenContainer>
  );
}
