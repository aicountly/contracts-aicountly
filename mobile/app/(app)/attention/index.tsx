import { ScrollView, Text, View, Pressable } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card } from '../../../src/components';
import { colors } from '../../../src/theme/colors';
import { useAsync } from '../../../src/utils/useAsync';
import { getMyActions } from '../../../src/api/endpoints/dashboard';

interface AttentionCardDef {
  key: string;
  title: string;
  description: string;
  icon: keyof typeof Ionicons.glyphMap;
  route: string;
  count?: number;
}

/** Collapses the web sidebar's "Needs attention" group (web/src/config/navigation.ts) into one hub screen for this tab. */
export default function AttentionScreen() {
  const router = useRouter();
  const { data: myActions } = useAsync(getMyActions);

  const cards: AttentionCardDef[] = [
    { key: 'requests', title: 'Requests', description: 'New contract requests awaiting review', icon: 'file-tray-full-outline', route: '/attention/requests' },
    {
      key: 'approvals',
      title: 'Approvals',
      description: 'Approval steps assigned to you',
      icon: 'checkmark-done-outline',
      route: '/attention/approvals',
      count: myActions?.approvals.length,
    },
    {
      key: 'obligations',
      title: 'Obligations',
      description: 'What is due, and from whom',
      icon: 'checkbox-outline',
      route: '/attention/obligations',
      count: myActions?.obligations.length,
    },
    {
      key: 'renewals',
      title: 'Renewals',
      description: 'Decisions and notice deadlines',
      icon: 'refresh-outline',
      route: '/attention/renewals',
      count: myActions?.renewals.length,
    },
    { key: 'amendments', title: 'Amendments', description: 'The portfolio-wide amendment register', icon: 'git-branch-outline', route: '/attention/amendments' },
    { key: 'risks', title: 'Risks', description: 'Findings across every contract', icon: 'alert-circle-outline', route: '/attention/risks' },
    {
      key: 'ai-review',
      title: 'AI Review Queue',
      description: 'AI extractions waiting on a human',
      icon: 'sparkles-outline',
      route: '/attention/ai-review',
      count: myActions?.ai_reviews.length,
    },
  ];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <Text style={{ fontSize: 13, fontFamily: 'Nunito_400Regular', color: colors.textMuted, marginBottom: 16 }}>
          Everything that needs a decision, across every contract.
        </Text>
        {cards.map((card) => (
          <Pressable key={card.key} onPress={() => router.push(card.route)}>
            <Card style={{ marginBottom: 10, flexDirection: 'row', alignItems: 'center' }}>
              <View
                style={{
                  width: 40,
                  height: 40,
                  borderRadius: 10,
                  backgroundColor: colors.primaryLight,
                  alignItems: 'center',
                  justifyContent: 'center',
                  marginRight: 12,
                }}
              >
                <Ionicons name={card.icon} size={20} color={colors.primary} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{card.title}</Text>
                <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12, color: colors.textMuted, marginTop: 2 }}>{card.description}</Text>
              </View>
              {card.count ? (
                <View
                  style={{
                    backgroundColor: colors.warning,
                    borderRadius: 999,
                    minWidth: 22,
                    height: 22,
                    alignItems: 'center',
                    justifyContent: 'center',
                    paddingHorizontal: 6,
                  }}
                >
                  <Text style={{ color: '#FFFFFF', fontSize: 11, fontFamily: 'Nunito_700Bold' }}>{card.count}</Text>
                </View>
              ) : null}
              <Ionicons name="chevron-forward" size={18} color={colors.textMuted} style={{ marginLeft: 8 }} />
            </Card>
          </Pressable>
        ))}
      </ScrollView>
    </ScreenContainer>
  );
}
