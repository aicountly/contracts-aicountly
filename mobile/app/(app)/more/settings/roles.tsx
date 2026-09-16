import { useState } from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, Card, StatusBadge, LoadingState, ErrorState, EmptyState } from '../../../../src/components';
import { colors } from '../../../../src/theme/colors';
import { useAsync } from '../../../../src/utils/useAsync';
import { getRoles } from '../../../../src/api/endpoints/settings';
import { formatDate, humaniseSnakeCase } from '../../../../src/utils/format';
import type { RoleDefinition } from '../../../../src/types/contracts';

function RoleCard({ role, isDefault }: { role: RoleDefinition; isDefault: boolean }) {
  const [open, setOpen] = useState(false);
  return (
    <Card style={{ marginBottom: 10 }}>
      <Pressable onPress={() => setOpen((o) => !o)}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
          <View style={{ flex: 1 }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>{role.label}</Text>
              {isDefault ? (
                <View style={{ backgroundColor: colors.primaryLight, borderRadius: 999, paddingHorizontal: 7, paddingVertical: 2 }}>
                  <Text style={{ fontSize: 10, fontFamily: 'Nunito_700Bold', color: colors.primaryDark }}>Default</Text>
                </View>
              ) : null}
            </View>
            {role.description ? <Text style={{ fontSize: 12, color: colors.textSecondary, marginTop: 3 }}>{role.description}</Text> : null}
            <Text style={{ fontSize: 11, color: colors.textMuted, marginTop: 4 }}>{role.permissions?.length ?? 0} permissions</Text>
          </View>
          <Ionicons name={open ? 'chevron-up' : 'chevron-down'} size={16} color={colors.textMuted} />
        </View>
        {open ? (
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 10 }}>
            {(role.permissions ?? []).map((p) => (
              <View key={p} style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 3 }}>
                <Text style={{ fontSize: 10.5, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>{humaniseSnakeCase(p)}</Text>
              </View>
            ))}
          </View>
        ) : null}
      </Pressable>
    </Card>
  );
}

export default function RolesScreen() {
  const { data, loading, error, reload } = useAsync(getRoles);

  if (loading && !data) return <LoadingState label="Loading roles…" />;
  if (error && !data) return <ErrorState message={error} onRetry={reload} />;

  const roles = data?.roles ?? [];
  const grants = data?.grants ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }}>
        <Card style={{ marginBottom: 16, backgroundColor: colors.surface, borderColor: colors.borderSoft }}>
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, lineHeight: 18 }}>
            Read-only on mobile — granting or changing roles needs the web app.
          </Text>
        </Card>

        <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 }}>
          Role catalogue
        </Text>
        {roles.length === 0 ? (
          <EmptyState icon="people-outline" title="No roles found" />
        ) : (
          roles.map((role) => <RoleCard key={role.slug} role={role} isDefault={role.slug === data?.default_role} />)
        )}

        {grants.length > 0 ? (
          <View style={{ marginTop: 10 }}>
            <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 10 }}>
              Current grants
            </Text>
            {grants.map((grant, i) => (
              <Card key={i} style={{ marginBottom: 8 }}>
                <Text style={{ fontSize: 12.5, fontFamily: 'Nunito_600SemiBold', color: colors.textPrimary }} numberOfLines={1}>
                  {grant.user_uuid}
                </Text>
                <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 6 }}>
                  {grant.roles.map((r) => (
                    <StatusBadge key={r} value={r} label={humaniseSnakeCase(r)} kind="workflow" />
                  ))}
                </View>
                {grant.granted_at ? <Text style={{ fontSize: 10.5, color: colors.textMuted, marginTop: 6 }}>Granted {formatDate(grant.granted_at)}</Text> : null}
              </Card>
            ))}
          </View>
        ) : null}
      </ScrollView>
    </ScreenContainer>
  );
}
