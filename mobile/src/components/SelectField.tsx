import { useState } from 'react';
import { FlatList, Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '../theme/colors';
import { BottomSheet } from './BottomSheet';

export interface SelectOption {
  label: string;
  value: string;
}

interface SelectFieldProps {
  label: string;
  value: string | null;
  options: SelectOption[];
  onChange: (value: string) => void;
  placeholder?: string;
  error?: string | null;
}

export function SelectField({ label, value, options, onChange, placeholder = 'Select…', error }: SelectFieldProps) {
  const [open, setOpen] = useState(false);
  const selected = options.find((o) => o.value === value);

  return (
    <View style={{ marginBottom: 16 }}>
      <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textSecondary, marginBottom: 6 }}>{label}</Text>
      <Pressable
        onPress={() => setOpen(true)}
        style={{
          borderWidth: 1,
          borderColor: error ? colors.danger : colors.border,
          borderRadius: 10,
          paddingHorizontal: 14,
          paddingVertical: 12,
          flexDirection: 'row',
          justifyContent: 'space-between',
          alignItems: 'center',
          backgroundColor: '#FFFFFF',
        }}
      >
        <Text style={{ fontSize: 15, fontFamily: 'Nunito_400Regular', color: selected ? colors.textPrimary : colors.textMuted }} numberOfLines={1}>
          {selected?.label ?? placeholder}
        </Text>
        <Ionicons name="chevron-down" size={16} color={colors.textMuted} />
      </Pressable>
      {error ? <Text style={{ color: colors.danger, fontSize: 12, marginTop: 4 }}>{error}</Text> : null}

      <BottomSheet visible={open} onClose={() => setOpen(false)}>
        <View style={{ padding: 16, borderBottomWidth: 1, borderBottomColor: colors.borderSoft }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary }}>{label}</Text>
        </View>
        <FlatList
          data={options}
          keyExtractor={(item) => item.value}
          renderItem={({ item }) => (
            <Pressable
              onPress={() => {
                onChange(item.value);
                setOpen(false);
              }}
              style={{
                paddingHorizontal: 16,
                paddingVertical: 14,
                flexDirection: 'row',
                justifyContent: 'space-between',
                alignItems: 'center',
                borderBottomWidth: 1,
                borderBottomColor: colors.borderSoft,
              }}
            >
              <Text style={{ fontSize: 15, color: colors.textPrimary, fontFamily: item.value === value ? 'Nunito_700Bold' : 'Nunito_400Regular' }}>
                {item.label}
              </Text>
              {item.value === value ? <Ionicons name="checkmark" size={18} color={colors.primary} /> : null}
            </Pressable>
          )}
        />
      </BottomSheet>
    </View>
  );
}
