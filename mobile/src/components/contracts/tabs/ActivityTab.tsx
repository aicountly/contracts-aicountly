import { useState } from 'react';
import { Alert, ScrollView, Text, TextInput, View } from 'react-native';
import { Card, ErrorState, EmptyState, PrimaryButton } from '../../index';
import { colors } from '../../../theme/colors';
import { formatDateTime } from '../../../utils/format';
import { useAsync } from '../../../utils/useAsync';
import { getContractActivity } from '../../../api/endpoints/contracts';
import { listComments, addComment, resolveComment, deleteComment, type CommentRow } from '../../../api/endpoints/comments';
import { ApiError } from '../../../api/errors';
import type { ActivityEntry } from '../../../types/contracts';

interface ActivityTabProps {
  contractId: number;
}

/** Comments aren't a separate tab in web's TABS array either — folded in here with the timeline, same screen. */
export function ActivityTab({ contractId }: ActivityTabProps) {
  const activity = useAsync(() => getContractActivity(contractId), [contractId]);
  const comments = useAsync(() => listComments(contractId), [contractId]);
  const [draft, setDraft] = useState('');
  const [posting, setPosting] = useState(false);

  async function handlePost() {
    if (!draft.trim()) return;
    setPosting(true);
    try {
      await addComment(contractId, draft.trim());
      setDraft('');
      comments.reload();
    } catch (err) {
      Alert.alert('Could not post comment', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setPosting(false);
    }
  }

  async function handleResolve(comment: CommentRow) {
    try {
      await resolveComment(comment.id);
      comments.reload();
    } catch (err) {
      Alert.alert('Could not resolve', err instanceof ApiError ? err.message : 'Something went wrong.');
    }
  }

  function confirmDelete(comment: CommentRow) {
    Alert.alert('Delete comment', 'Delete this comment?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Delete',
        style: 'destructive',
        onPress: async () => {
          try {
            await deleteComment(comment.id);
            comments.reload();
          } catch (err) {
            Alert.alert('Could not delete', err instanceof ApiError ? err.message : 'Something went wrong.');
          }
        },
      },
    ]);
  }

  return (
    <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
      <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Comments</Text>
      <View style={{ flexDirection: 'row', gap: 8, marginBottom: 16 }}>
        <TextInput
          value={draft}
          onChangeText={setDraft}
          placeholder="Add a comment…"
          placeholderTextColor={colors.textMuted}
          style={{
            flex: 1,
            borderWidth: 1,
            borderColor: colors.border,
            borderRadius: 10,
            paddingHorizontal: 12,
            paddingVertical: 10,
            fontSize: 14,
            color: colors.textPrimary,
          }}
          multiline
        />
        <PrimaryButton label="Post" onPress={handlePost} loading={posting} disabled={!draft.trim()} />
      </View>

      {comments.loading && !comments.data ? (
        <Text style={{ color: colors.textMuted, fontSize: 13 }}>Loading comments…</Text>
      ) : comments.data && comments.data.length > 0 ? (
        comments.data.map((c) => (
          <Card key={c.id} style={{ marginBottom: 10, opacity: c.resolved_at ? 0.6 : 1 }}>
            <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary }}>{c.body ?? c.text}</Text>
            <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 11, color: colors.textMuted, marginTop: 4 }}>
              {[c.author_name, formatDateTime(c.created_at)].filter(Boolean).join(' · ')}
              {c.resolved_at ? ' · Resolved' : ''}
            </Text>
            <View style={{ flexDirection: 'row', gap: 14, marginTop: 8 }}>
              {!c.resolved_at ? (
                <Text onPress={() => handleResolve(c)} style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 11 }}>
                  Resolve
                </Text>
              ) : null}
              <Text onPress={() => confirmDelete(c)} style={{ color: colors.danger, fontFamily: 'Nunito_600SemiBold', fontSize: 11 }}>
                Delete
              </Text>
            </View>
          </Card>
        ))
      ) : (
        <Text style={{ color: colors.textMuted, fontSize: 13, marginBottom: 8 }}>No comments yet.</Text>
      )}

      <View style={{ height: 1, backgroundColor: colors.borderSoft, marginVertical: 20 }} />

      <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 10 }}>Activity</Text>
      {activity.loading && !activity.data ? (
        <Text style={{ color: colors.textMuted, fontSize: 13 }}>Loading…</Text>
      ) : activity.error ? (
        <ErrorState message={activity.error} onRetry={activity.reload} />
      ) : !activity.data || activity.data.length === 0 ? (
        <EmptyState icon="time-outline" title="No activity yet" />
      ) : (
        activity.data.map((entry: ActivityEntry) => (
          <View key={entry.id} style={{ paddingVertical: 8, borderTopWidth: 1, borderTopColor: colors.borderSoft }}>
            <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textPrimary }}>{entry.description ?? entry.action}</Text>
            <Text style={{ fontFamily: 'Nunito_400Regular', fontSize: 11, color: colors.textMuted, marginTop: 2 }}>
              {[entry.actor_name, formatDateTime(entry.created_at)].filter(Boolean).join(' · ')}
            </Text>
          </View>
        ))
      )}
    </ScrollView>
  );
}
