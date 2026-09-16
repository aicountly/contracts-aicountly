import { useState } from 'react';
import { ActivityIndicator, Pressable, Text, type GestureResponderEvent } from 'react-native';
import { colors } from '../theme/colors';

interface PrimaryButtonProps {
  label: string;
  onPress: (e: GestureResponderEvent) => void;
  loading?: boolean;
  disabled?: boolean;
  variant?: 'primary' | 'secondary' | 'danger';
}

/**
 * A `style={({ pressed }) => …}` callback is dropped by NativeWind on-device
 * (see eslint.config.js's noStyleCallback rule) — press state is tracked with
 * plain useState + onPressIn/onPressOut instead.
 */
export function PrimaryButton({ label, onPress, loading, disabled, variant = 'primary' }: PrimaryButtonProps) {
  const [pressed, setPressed] = useState(false);
  const isDisabled = disabled || loading;

  const bg = variant === 'danger' ? colors.danger : variant === 'secondary' ? colors.surface : colors.primary;
  const textColor = variant === 'secondary' ? colors.textPrimary : '#FFFFFF';

  return (
    <Pressable
      onPress={onPress}
      disabled={isDisabled}
      onPressIn={() => setPressed(true)}
      onPressOut={() => setPressed(false)}
      style={{
        backgroundColor: bg,
        opacity: isDisabled ? 0.6 : pressed ? 0.85 : 1,
        borderWidth: variant === 'secondary' ? 1 : 0,
        borderColor: colors.border,
        borderRadius: 12,
        paddingVertical: 14,
        alignItems: 'center',
        justifyContent: 'center',
        flexDirection: 'row',
      }}
    >
      {loading ? <ActivityIndicator color={textColor} style={{ marginRight: 8 }} /> : null}
      <Text style={{ color: textColor, fontFamily: 'Nunito_700Bold', fontSize: 15 }}>{label}</Text>
    </Pressable>
  );
}
