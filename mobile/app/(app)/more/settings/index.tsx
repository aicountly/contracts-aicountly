import { Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';

interface Row {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  href: string;
}

interface Group {
  title: string;
  rows: Row[];
}

const GROUPS: Group[] = [
  {
    title: 'Workspace',
    rows: [
      { icon: 'settings-outline', label: 'General & Numbering', href: '/more/settings/general' },
      { icon: 'alarm-outline', label: 'Reminders', href: '/more/settings/reminders' },
    ],
  },
  {
    title: 'Taxonomy',
    rows: [
      { icon: 'shapes-outline', label: 'Contract Types', href: '/more/settings/contract-types' },
      { icon: 'business-outline', label: 'Departments', href: '/more/settings/departments' },
      { icon: 'list-outline', label: 'Custom Fields', href: '/more/settings/custom-fields' },
      { icon: 'pricetags-outline', label: 'Tags', href: '/more/settings/tags' },
    ],
  },
  {
    title: 'Governance',
    rows: [
      { icon: 'shield-outline', label: 'Risk Rules', href: '/more/settings/risk-rules' },
      { icon: 'git-branch-outline', label: 'Approval Workflows', href: '/more/settings/workflows' },
      { icon: 'people-outline', label: 'Roles & Permissions', href: '/more/settings/roles' },
    ],
  },
  {
    title: 'Platform',
    rows: [{ icon: 'link-outline', label: 'Integrations & AI Status', href: '/more/settings/platform' }],
  },
  {
    title: 'You',
    rows: [{ icon: 'person-circle-outline', label: 'Account', href: '/more/settings/account' }],
  },
];

export default function SettingsHubScreen() {
  const router = useRouter();

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ padding: 16, gap: 20 }}>
        {GROUPS.map((group) => (
          <View key={group.title}>
            <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 10 }}>
              {group.title}
            </Text>
            <Card style={{ padding: 0, overflow: 'hidden' }}>
              {group.rows.map((row, i) => (
                <Pressable key={row.href} onPress={() => router.push(row.href)}>
                  <View
                    style={{
                      flexDirection: 'row',
                      alignItems: 'center',
                      gap: 12,
                      paddingHorizontal: 14,
                      paddingVertical: 13,
                      borderTopWidth: i === 0 ? 0 : 1,
                      borderTopColor: colors.borderSoft,
                    }}
                  >
                    <Ionicons name={row.icon} size={18} color={colors.primaryDark} />
                    <Text style={{ flex: 1, fontFamily: 'Nunito_600SemiBold', fontSize: 14, color: colors.textPrimary }}>{row.label}</Text>
                    <Ionicons name="chevron-forward" size={16} color={colors.textMuted} />
                  </View>
                </Pressable>
              ))}
            </Card>
          </View>
        ))}
      </View>
    </ScreenContainer>
  );
}
