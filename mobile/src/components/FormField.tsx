import { Text, TextInput, View, type TextInputProps } from 'react-native';
import { colors } from '../theme/colors';

interface FormFieldProps extends TextInputProps {
  label: string;
  error?: string | null;
  hint?: string | null;
}

export function FormField({ label, error, hint, style, ...rest }: FormFieldProps) {
  return (
    <View style={{ marginBottom: 16 }}>
      <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textSecondary, marginBottom: 6 }}>{label}</Text>
      <TextInput
        placeholderTextColor={colors.textMuted}
        style={[
          {
            borderWidth: 1,
            borderColor: error ? colors.danger : colors.border,
            borderRadius: 10,
            paddingHorizontal: 14,
            paddingVertical: 12,
            fontSize: 15,
            fontFamily: 'Nunito_400Regular',
            color: colors.textPrimary,
            backgroundColor: '#FFFFFF',
          },
          style,
        ]}
        {...rest}
      />
      {error ? <Text style={{ color: colors.danger, fontSize: 12, marginTop: 4 }}>{error}</Text> : null}
      {!error && hint ? <Text style={{ color: colors.textMuted, fontSize: 12, marginTop: 4 }}>{hint}</Text> : null}
    </View>
  );
}
