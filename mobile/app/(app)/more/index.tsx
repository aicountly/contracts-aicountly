import { Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card } from '../../../src/components';
import { colors } from '../../../src/theme/colors';

interface MenuItem {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  message: string;
  href: string;
}

const ITEMS: MenuItem[] = [
  { icon: 'sparkles-outline', label: 'AI Insights', message: 'Portfolio-wide findings surfaced by AI review.', href: '/more/ai-insights' },
  { icon: 'document-text-outline', label: 'Templates', message: 'Standing wording with merge variables to draft from.', href: '/more/templates' },
  { icon: 'library-outline', label: 'Clause Library', message: 'Approved wording, by subject, with fallback and prohibited positions.', href: '/more/clause-library' },
  { icon: 'book-outline', label: 'Playbooks', message: 'The positions this company negotiates from, stated as rules.', href: '/more/playbooks' },
];

export default function MoreScreen() {
  const router = useRouter();

  return (
    <ScreenContainer bottomInset={false} style={{ padding: 16 }}>
      <View style={{ gap: 10 }}>
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
