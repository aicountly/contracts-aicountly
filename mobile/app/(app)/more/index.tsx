import { Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card } from '../../../src/components';
import { colors } from '../../../src/theme/colors';
import { useAsync } from '../../../src/utils/useAsync';
import { listNotifications } from '../../../src/api/endpoints/notifications';

interface MenuItem {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  message: string;
  href: string;
}

const ITEMS: MenuItem[] = [
  { icon: 'search-outline', label: 'Search', message: 'Across contracts, clause wording and document text.', href: '/more/search' },
  { icon: 'document-text-outline', label: 'Templates', message: 'Standing wording with merge variables to draft from.', href: '/more/templates' },
  { icon: 'library-outline', label: 'Clause Library', message: 'Approved wording, by subject, with fallback and prohibited positions.', href: '/more/clause-library' },
  { icon: 'book-outline', label: 'Playbooks', message: 'The positions this company negotiates from, stated as rules.', href: '/more/playbooks' },
  { icon: 'sparkles-outline', label: 'AI Insights', message: 'Portfolio-wide findings surfaced by AI review.', href: '/more/ai-insights' },
  { icon: 'settings-outline', label: 'Settings', message: 'Contract types, departments, risk rules, roles and account.', href: '/more/settings' },
];

export default function MoreScreen() {
  const router = useRouter();
  const { data } = useAsync(() => listNotifications({ perPage: 1, unreadOnly: true }));
  const unread = data?.unread ?? data?.total ?? 0;

  return (
    <ScreenContainer bottomInset={false} style={{ padding: 16 }}>
      <View style={{ gap: 10 }}>
        <Pressable onPress={() => router.push('/more/notifications')}>
          <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
            <View style={{ width: 40, height: 40, borderRadius: 10, backgroundColor: colors.primaryLight, alignItems: 'center', justifyContent: 'center' }}>
              <Ionicons name="notifications-outline" size={20} color={colors.primaryDark} />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14.5, color: colors.textPrimary }}>Notifications</Text>
              <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>Updates about your contracts.</Text>
            </View>
            {unread > 0 ? (
              <View style={{ backgroundColor: colors.danger, borderRadius: 999, minWidth: 22, height: 22, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 6 }}>
                <Text style={{ color: '#FFFFFF', fontSize: 11, fontFamily: 'Nunito_700Bold' }}>{unread > 99 ? '99+' : unread}</Text>
              </View>
            ) : (
              <Ionicons name="chevron-forward" size={18} color={colors.textMuted} />
            )}
          </Card>
        </Pressable>

        {ITEMS.map((item) => (
          <Pressable key={item.href} onPress={() => router.push(item.href)}>
            <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 14 }}>
              <View style={{ width: 40, height: 40, borderRadius: 10, backgroundColor: colors.primaryLight, alignItems: 'center', justifyContent: 'center' }}>
                <Ionicons name={item.icon} size={20} color={colors.primaryDark} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14.5, color: colors.textPrimary }}>{item.label}</Text>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>{item.message}</Text>
              </View>
              <Ionicons name="chevron-forward" size={18} color={colors.textMuted} />
            </Card>
          </Pressable>
        ))}
      </View>
    </ScreenContainer>
  );
}
