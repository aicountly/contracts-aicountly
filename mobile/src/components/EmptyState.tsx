import type { ReactNode } from 'react';
import { Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../theme/colors';

interface EmptyStateProps {
  icon?: keyof typeof Ionicons.glyphMap;
  title: string;
  message?: string;
  action?: ReactNode;
}

export function EmptyState({ icon = 'file-tray-outline', title, message, action }: EmptyStateProps) {
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <Ionicons name={icon} size={40} color={colors.textMuted} style={{ marginBottom: 12 }} />
      <Text style={{ textAlign: 'center', color: colors.textPrimary, fontFamily: 'Nunito_600SemiBold', marginBottom: 4, fontSize: 15 }}>{title}</Text>
      {message ? (
        <Text
          style={{ textAlign: 'center', color: colors.textMuted, fontFamily: 'Nunito_400Regular', fontSize: 13, marginBottom: action ? 16 : 0 }}
        >
          {message}
        </Text>
      ) : null}
      {action}
    </View>
  );
}
