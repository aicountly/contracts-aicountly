import { useState } from 'react';
import { Alert, FlatList, Pressable, Text, View } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, PrimaryButton, LoadingState, ErrorState, EmptyState } from '../../../src/components';
import { colors } from '../../../src/theme/colors';
import { formatDateTime, humaniseSnakeCase } from '../../../src/utils/format';
import { useAsync } from '../../../src/utils/useAsync';
import { listNotifications, markNotificationRead, markAllNotificationsRead } from '../../../src/api/endpoints/notifications';
import { ApiError } from '../../../src/api/errors';
import type { NotificationItem, NotificationSeverity } from '../../../src/types/contracts';

const SEVERITY_STYLE: Record<string, { icon: keyof typeof Ionicons.glyphMap; color: string }> = {
  info: { icon: 'information-circle-outline', color: colors.info },
  success: { icon: 'checkmark-circle-outline', color: colors.success },
  warning: { icon: 'warning-outline', color: colors.warning },
  critical: { icon: 'alert-circle', color: colors.danger },
};

function severityStyle(severity: NotificationSeverity | string) {
  return SEVERITY_STYLE[severity] ?? SEVERITY_STYLE.info;
}

export default function NotificationsScreen() {
  const router = useRouter();
  const [unreadOnly, setUnreadOnly] = useState(true);
  const [page, setPage] = useState(1);
  const [markingAll, setMarkingAll] = useState(false);
  const [readingId, setReadingId] = useState<number | null>(null);

  const { data, loading, error, reload } = useAsync(() => listNotifications({ page, perPage: 25, unreadOnly }), [unreadOnly, page]);
  const items = data?.items ?? [];
  const unread = data?.unread ?? 0;
  const total = data?.total ?? 0;

  async function handleOpen(item: NotificationItem) {
    if (!item.is_read) {
      setReadingId(item.id);
      try {
        await markNotificationRead(item.id);
        reload();
      } catch {
        // Best-effort — still navigate even if the read receipt didn't land.
      } finally {
        setReadingId(null);
      }
    }
    if (item.link_path) router.push(item.link_path);
  }

  async function handleMarkAll() {
    setMarkingAll(true);
    try {
      await markAllNotificationsRead();
      reload();
    } catch (err) {
      Alert.alert('Could not mark all as read', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setMarkingAll(false);
    }
  }

  return (
    <ScreenContainer bottomInset={false}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingHorizontal: 16, paddingTop: 12, paddingBottom: 8 }}>
        <View style={{ flexDirection: 'row', backgroundColor: colors.surface, borderRadius: 999, padding: 4 }}>
          {([
            { key: true, label: `Unread${unread ? ` (${unread})` : ''}` },
            { key: false, label: 'Everything' },
          ] as const).map((tab) => {
            const active = unreadOnly === tab.key;
            return (
              <Pressable
                key={String(tab.key)}
                onPress={() => {
                  setUnreadOnly(tab.key);
                  setPage(1);
                }}
                style={{ paddingHorizontal: 14, paddingVertical: 7, borderRadius: 999, backgroundColor: active ? colors.primary : 'transparent' }}
              >
                <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 12.5, color: active ? '#FFFFFF' : colors.textSecondary }}>{tab.label}</Text>
              </Pressable>
            );
          })}
        </View>
      </View>

      {unread > 0 ? (
        <View style={{ paddingHorizontal: 16, paddingBottom: 8, alignItems: 'flex-end' }}>
          <Pressable onPress={() => void handleMarkAll()} disabled={markingAll}>
            <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>{markingAll ? 'Marking…' : 'Mark all as read'}</Text>
          </Pressable>
        </View>
      ) : null}

      {loading && !data ? (
        <LoadingState label="Loading notifications…" />
      ) : error && !data ? (
        <ErrorState message={error} onRetry={reload} />
      ) : items.length === 0 ? (
        <EmptyState
          icon="notifications-outline"
          title={unreadOnly ? "You're all caught up" : 'No notifications yet'}
          message={unreadOnly ? 'Nothing unread right now.' : 'Notifications about your contracts will show up here.'}
        />
      ) : (
        <FlatList
          data={items}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ padding: 16, paddingTop: 4 }}
          renderItem={({ item }) => {
            const style = severityStyle(item.severity);
            const busy = readingId === item.id;
            return (
              <Pressable onPress={() => void handleOpen(item)} disabled={busy}>
                <Card style={{ marginBottom: 10, opacity: busy ? 0.6 : 1, borderColor: item.is_read ? colors.borderSoft : colors.primary }}>
                  <View style={{ flexDirection: 'row', gap: 10 }}>
                    <Ionicons name={style.icon} size={20} color={style.color} style={{ marginTop: 1 }} />
                    <View style={{ flex: 1 }}>
                      <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
                        <Text style={{ fontFamily: item.is_read ? 'Nunito_600SemiBold' : 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, flex: 1 }} numberOfLines={2}>
                          {item.title}
                        </Text>
                        {!item.is_read ? <View style={{ width: 8, height: 8, borderRadius: 4, backgroundColor: colors.primary, marginTop: 5 }} /> : null}
                      </View>
                      {item.body ? (
                        <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 12.5, color: colors.textSecondary, marginTop: 4 }} numberOfLines={3}>
                          {item.body}
                        </Text>
                      ) : null}
                      <View style={{ flexDirection: 'row', flexWrap: 'wrap', alignItems: 'center', gap: 8, marginTop: 8 }}>
                        <View style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 }}>
                          <Text style={{ fontSize: 10.5, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>{humaniseSnakeCase(item.event_type)}</Text>
                        </View>
                        {item.contract_number ? (
                          <Text style={{ fontSize: 11, color: colors.textMuted }}>
                            {item.contract_number}
                            {item.contract_title ? ` · ${item.contract_title}` : ''}
                          </Text>
                        ) : null}
                      </View>
                      <Text style={{ fontSize: 10.5, color: colors.textMuted, marginTop: 6 }}>{formatDateTime(item.created_at)}</Text>
                    </View>
                  </View>
                </Card>
              </Pressable>
            );
          }}
        />
      )}

      {total > items.length || page > 1 ? (
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', paddingHorizontal: 16, paddingBottom: 16 }}>
          <PrimaryButton label="Previous" variant="secondary" disabled={page <= 1} onPress={() => setPage((p) => Math.max(1, p - 1))} />
          <Text style={{ fontSize: 12.5, color: colors.textMuted }}>Page {page}</Text>
          <PrimaryButton label="Next" variant="secondary" disabled={items.length < 25} onPress={() => setPage((p) => p + 1)} />
        </View>
      ) : null}
    </ScreenContainer>
  );
}
