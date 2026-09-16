import { Text, View } from 'react-native';
import { contractStatusColors, riskSeverityColors, workflowStatusColors } from '../theme/colors';

interface StatusBadgeProps {
  value: string | null | undefined;
  kind?: 'contract' | 'risk' | 'workflow';
  label?: string;
}

const PALETTES: Record<string, Record<string, { fg: string; bg: string }>> = {
  contract: contractStatusColors,
  risk: riskSeverityColors,
  workflow: workflowStatusColors,
};

const FALLBACK = { fg: '#5B6B5B', bg: '#F0F2F0' };

function humanise(value: string): string {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/** A colour-coded pill for a contract status, risk severity, or workflow (approval/request/renewal/amendment) status — see theme/colors.ts for the palettes. */
export function StatusBadge({ value, kind = 'contract', label }: StatusBadgeProps) {
  if (!value) return null;
  const palette = PALETTES[kind]?.[value] ?? FALLBACK;
  return (
    <View style={{ backgroundColor: palette.bg, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4, alignSelf: 'flex-start' }}>
      <Text style={{ color: palette.fg, fontSize: 12, fontFamily: 'Nunito_600SemiBold' }}>{label ?? humanise(value)}</Text>
    </View>
  );
}
