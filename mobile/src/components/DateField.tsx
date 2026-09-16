import { useState } from 'react';
import { Platform, Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import DateTimePicker, { type DateTimePickerEvent } from '@react-native-community/datetimepicker';
import { colors } from '../theme/colors';
import { formatDate } from '../utils/format';
import { BottomSheet } from './BottomSheet';

interface DateFieldProps {
  label: string;
  /** ISO date, "YYYY-MM-DD". */
  value: string | null;
  onChange: (value: string | null) => void;
  error?: string | null;
  clearable?: boolean;
  placeholder?: string;
}

function toIsoDate(date: Date): string {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

/**
 * Android shows the OS's own modal date dialog (fire-and-dismiss); iOS's
 * spinner picker has no such dismiss behaviour of its own, so it's
 * presented inside our BottomSheet with explicit Cancel/Done actions.
 */
export function DateField({ label, value, onChange, error, clearable = true, placeholder = 'Select a date' }: DateFieldProps) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<Date>(() => (value ? new Date(`${value}T00:00:00`) : new Date()));

  function openPicker() {
    setDraft(value ? new Date(`${value}T00:00:00`) : new Date());
    setOpen(true);
  }

  function handleAndroidChange(event: DateTimePickerEvent, selected?: Date) {
    setOpen(false);
    if (event.type === 'set' && selected) onChange(toIsoDate(selected));
  }

  return (
    <View style={{ marginBottom: 16 }}>
      <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textSecondary, marginBottom: 6 }}>{label}</Text>
      <Pressable
        onPress={openPicker}
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
        <Text style={{ fontSize: 15, fontFamily: 'Nunito_400Regular', color: value ? colors.textPrimary : colors.textMuted }}>
          {value ? formatDate(value) : placeholder}
        </Text>
        <View style={{ flexDirection: 'row', gap: 10, alignItems: 'center' }}>
          {clearable && value ? (
            <Pressable onPress={() => onChange(null)} hitSlop={8}>
              <Ionicons name="close-circle" size={16} color={colors.textMuted} />
            </Pressable>
          ) : null}
          <Ionicons name="calendar-outline" size={16} color={colors.textMuted} />
        </View>
      </Pressable>
      {error ? <Text style={{ color: colors.danger, fontSize: 12, marginTop: 4 }}>{error}</Text> : null}

      {open && Platform.OS === 'android' ? <DateTimePicker value={draft} mode="date" display="default" onChange={handleAndroidChange} /> : null}

      {Platform.OS === 'ios' ? (
        <BottomSheet visible={open} onClose={() => setOpen(false)}>
          <View style={{ padding: 16, borderBottomWidth: 1, borderBottomColor: colors.borderSoft, flexDirection: 'row', justifyContent: 'space-between' }}>
            <Pressable onPress={() => setOpen(false)}>
              <Text style={{ color: colors.textMuted, fontFamily: 'Nunito_600SemiBold', fontSize: 15 }}>Cancel</Text>
            </Pressable>
            <Text style={{ fontFamily: 'Nunito_700Bold', color: colors.textPrimary, fontSize: 15 }}>{label}</Text>
            <Pressable
              onPress={() => {
                onChange(toIsoDate(draft));
                setOpen(false);
              }}
            >
              <Text style={{ color: colors.primary, fontFamily: 'Nunito_700Bold', fontSize: 15 }}>Done</Text>
            </Pressable>
          </View>
          <DateTimePicker
            value={draft}
            mode="date"
            display="spinner"
            onChange={(_event: DateTimePickerEvent, selected?: Date) => selected && setDraft(selected)}
            style={{ height: 200 }}
          />
        </BottomSheet>
      ) : null}
    </View>
  );
}
