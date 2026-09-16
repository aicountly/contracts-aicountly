import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Pressable, ScrollView, Text, TextInput, View, type TextStyle } from 'react-native';
import { useRouter } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { ScreenContainer, ErrorState, EmptyState, LoadingState } from '../../../src/components';
import { colors } from '../../../src/theme/colors';
import { useAsync } from '../../../src/utils/useAsync';
import { globalSearch } from '../../../src/api/endpoints/search';
import { humaniseSnakeCase } from '../../../src/utils/format';
import type { SearchClauseHit, SearchContractHit, SearchDocumentHit } from '../../../src/types/contracts';

const MIN_TERM = 2;
const DEBOUNCE_MS = 300;

function highlightParts(text: string, term: string): { text: string; match: boolean }[] {
  const needle = term.trim();
  if (needle.length < MIN_TERM) return [{ text, match: false }];
  const pattern = new RegExp(`(${needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'ig');
  return text.split(pattern).map((part) => ({ text: part, match: part.toLowerCase() === needle.toLowerCase() }));
}

function Highlighted({ text, term, style }: { text: string; term: string; style?: TextStyle }) {
  const parts = highlightParts(text, term);
  return (
    <Text style={style}>
      {parts.map((p, i) =>
        p.match ? (
          <Text key={i} style={{ backgroundColor: colors.primaryLight, color: colors.textPrimary }}>
            {p.text}
          </Text>
        ) : (
          p.text
        ),
      )}
    </Text>
  );
}

function Snippet({ text, term }: { text?: string | null; term: string }) {
  if (!text) return null;
  return <Highlighted text={text} term={term} style={{ fontSize: 12.5, lineHeight: 18, color: colors.textSecondary, marginTop: 4 }} />;
}

function SectionHeader({ icon, title, count }: { icon: keyof typeof Ionicons.glyphMap; title: string; count: number }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, marginTop: 18, marginBottom: 8 }}>
      <Ionicons name={icon} size={13} color={colors.textMuted} />
      <Text style={{ fontSize: 11.5, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 }}>{title}</Text>
      <View style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 7, paddingVertical: 1 }}>
        <Text style={{ fontSize: 10.5, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>{count}</Text>
      </View>
    </View>
  );
}

function HitRow({ onPress, children }: { onPress: () => void; children: ReactNode }) {
  return (
    <Pressable
      onPress={onPress}
      style={{ paddingVertical: 12, paddingHorizontal: 14, borderWidth: 1, borderColor: colors.borderSoft, borderRadius: 10, backgroundColor: '#FFFFFF', marginBottom: 8 }}
    >
      {children}
    </Pressable>
  );
}

export default function GlobalSearchScreen() {
  const router = useRouter();
  const [draft, setDraft] = useState('');
  const [term, setTerm] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setTerm(draft), DEBOUNCE_MS);
    return () => clearTimeout(timer);
  }, [draft]);

  const ready = term.trim().length >= MIN_TERM;
  const { data, loading, error, reload } = useAsync(() => (ready ? globalSearch(term.trim()) : Promise.resolve(null)), [ready, term]);

  const contracts = useMemo<SearchContractHit[]>(() => data?.contracts ?? [], [data]);
  const clauses = useMemo<SearchClauseHit[]>(() => data?.clauses ?? [], [data]);
  const documents = useMemo<SearchDocumentHit[]>(() => data?.documents ?? [], [data]);
  const total = contracts.length + clauses.length + documents.length;

  function open(path: string) {
    router.push(path);
  }

  return (
    <ScreenContainer bottomInset={false}>
      <View
        style={{
          marginHorizontal: 16,
          marginTop: 12,
          borderWidth: 1,
          borderColor: colors.border,
          borderRadius: 10,
          paddingHorizontal: 12,
          flexDirection: 'row',
          alignItems: 'center',
          backgroundColor: '#FFFFFF',
        }}
      >
        <Ionicons name="search" size={16} color={colors.textMuted} />
        <TextInput
          value={draft}
          onChangeText={setDraft}
          placeholder="Contract number, counterparty, a phrase from a clause…"
          placeholderTextColor={colors.textMuted}
          autoFocus
          style={{ flex: 1, paddingVertical: 10, paddingHorizontal: 8, fontFamily: 'Nunito_400Regular', fontSize: 14, color: colors.textPrimary }}
        />
      </View>

      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        {!ready ? (
          <EmptyState
            icon="search-outline"
            title="Type at least two characters"
            message="Search covers contract numbers, titles, counterparties and tags; the clause library and clauses standing in each contract; and extracted text from uploaded documents."
          />
        ) : loading && !data ? (
          <LoadingState label="Searching…" />
        ) : error && !data ? (
          <ErrorState message={error} onRetry={reload} />
        ) : total === 0 ? (
          <EmptyState
            icon="search-outline"
            title={`Nothing matches "${term.trim()}"`}
            message="Try a shorter phrase, the counterparty's name, or a contract number. Documents are searchable only once their text has been extracted."
          />
        ) : (
          <>
            <Text style={{ fontSize: 12.5, color: colors.textMuted }}>
              {total} result{total === 1 ? '' : 's'} for &ldquo;{term.trim()}&rdquo;
            </Text>

            {contracts.length > 0 ? (
              <View>
                <SectionHeader icon="document-text-outline" title="Contracts" count={contracts.length} />
                {contracts.map((hit, i) => (
                  <HitRow key={i} onPress={() => open(hit.link_path)}>
                    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
                      <Highlighted text={hit.title} term={term} style={{ fontSize: 13.5, fontFamily: 'Nunito_600SemiBold', color: colors.textPrimary }} />
                      {hit.status ? (
                        <View style={{ backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 7, paddingVertical: 2 }}>
                          <Text style={{ fontSize: 10.5, color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold' }}>{humaniseSnakeCase(hit.status)}</Text>
                        </View>
                      ) : null}
                    </View>
                    <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 2 }}>
                      {hit.contract_number ?? '—'}
                      {hit.counterparty_name ? ` · ${hit.counterparty_name}` : ''}
                      {hit.contract_type_name ? ` · ${hit.contract_type_name}` : ''}
                    </Text>
                    <Snippet text={hit.snippet} term={term} />
                  </HitRow>
                ))}
              </View>
            ) : null}

            {clauses.length > 0 ? (
              <View>
                <SectionHeader icon="reader-outline" title="Clauses" count={clauses.length} />
                {clauses.map((hit, i) => (
                  <HitRow key={i} onPress={() => open(hit.link_path)}>
                    <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
                      <Highlighted text={hit.heading ?? 'Untitled clause'} term={term} style={{ fontSize: 13.5, fontFamily: 'Nunito_600SemiBold', color: colors.textPrimary }} />
                      <View style={{ backgroundColor: hit.source === 'library' ? colors.surface : colors.warningSoft, borderRadius: 999, paddingHorizontal: 7, paddingVertical: 2 }}>
                        <Text style={{ fontSize: 10.5, fontFamily: 'Nunito_600SemiBold', color: hit.source === 'library' ? colors.textSecondary : colors.warning }}>
                          {hit.source === 'library' ? 'Standard wording' : 'In a contract'}
                        </Text>
                      </View>
                    </View>
                    <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 2 }}>
                      {hit.category_name ?? 'Uncategorised'}
                      {hit.contract_number ? ` · ${hit.contract_number}` : ''}
                    </Text>
                    <Snippet text={hit.snippet} term={term} />
                  </HitRow>
                ))}
              </View>
            ) : null}

            {documents.length > 0 ? (
              <View>
                <SectionHeader icon="document-attach-outline" title="Documents" count={documents.length} />
                {documents.map((hit, i) => (
                  <HitRow key={i} onPress={() => open(hit.link_path)}>
                    <Highlighted
                      text={hit.document_title ?? hit.filename ?? 'Untitled document'}
                      term={term}
                      style={{ fontSize: 13.5, fontFamily: 'Nunito_600SemiBold', color: colors.textPrimary }}
                    />
                    <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 2 }}>
                      {hit.contract_number ?? '—'}
                      {hit.contract_title ? ` · ${hit.contract_title}` : ''}
                    </Text>
                    <Snippet text={hit.snippet} term={term} />
                  </HitRow>
                ))}
              </View>
            ) : null}
          </>
        )}
      </ScrollView>
    </ScreenContainer>
  );
}
