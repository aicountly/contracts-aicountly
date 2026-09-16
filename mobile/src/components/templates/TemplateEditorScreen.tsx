import { useEffect, useMemo, useState } from 'react';
import { Alert, FlatList, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { useRouter } from 'expo-router';
import { WebView } from 'react-native-webview';
import { ScreenContainer } from '../ScreenContainer';
import { Card } from '../Card';
import { FormField } from '../FormField';
import { SelectField } from '../SelectField';
import { PrimaryButton } from '../PrimaryButton';
import { BottomSheet } from '../BottomSheet';
import { LoadingState } from '../LoadingState';
import { ErrorState } from '../ErrorState';
import { EmptyState } from '../EmptyState';
import { StatusBadge } from '../StatusBadge';
import { colors } from '../../theme/colors';
import { formatDateTime, humaniseSnakeCase } from '../../utils/format';
import { useAsync } from '../../utils/useAsync';
import { ApiError } from '../../api/errors';
import { listContractTypes } from '../../api/endpoints/settings';
import { listContracts } from '../../api/endpoints/contracts';
import {
  getTemplate,
  listTemplateVariables,
  createTemplate,
  updateTemplate,
  deleteTemplate,
  previewTemplate,
  createContractFromTemplate,
} from '../../api/endpoints/templates';
import {
  TEMPLATE_STATUSES,
  type TemplateStatus,
  type TemplateInput,
  type TemplateVariable,
  type TemplateVersion,
  type TemplatePreview,
  type PreviewVariable,
  type ContractListItem,
} from '../../types/contracts';

const SOURCE_ORDER = ['contract', 'counterparty', 'company', 'commercial', 'custom', 'system'];

function bodyTokens(body: string): string[] {
  const seen: string[] = [];
  for (const match of body.matchAll(/\{\{([^{}]*)\}\}/g)) {
    const token = match[1].trim();
    if (token !== '' && !seen.includes(token)) seen.push(token);
  }
  return seen;
}

function previewKey(entry: string | PreviewVariable): string {
  if (typeof entry === 'string') return entry;
  return entry.var_key ?? entry.key ?? entry.label ?? 'unknown';
}

function previewLabel(entry: string | PreviewVariable): string | null {
  return typeof entry === 'string' ? null : (entry.label ?? null);
}

/** Shared by app/(app)/more/templates/new.tsx and [id].tsx — 'new' starts a blank template, a number edits one. */
export function TemplateEditorScreen({ templateId }: { templateId: number | 'new' }) {
  const router = useRouter();
  const isNew = templateId === 'new';

  const resource = useAsync(async () => {
    const [template, variables] = await Promise.all([
      isNew ? Promise.resolve(null) : getTemplate(templateId as number),
      listTemplateVariables(),
    ]);
    return { template, variables };
  }, [templateId]);

  const contractTypes = useAsync(listContractTypes);

  const template = resource.data?.template ?? null;
  const variables = useMemo(() => resource.data?.variables ?? [], [resource.data]);

  const [tab, setTab] = useState<'body' | 'preview' | 'history'>('body');
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [contractTypeId, setContractTypeId] = useState<string | null>(null);
  const [status, setStatus] = useState<TemplateStatus>('draft');
  const [body, setBody] = useState('');
  const [changeNote, setChangeNote] = useState('');
  const [dirty, setDirty] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [variableSheetOpen, setVariableSheetOpen] = useState(false);
  const [createContractOpen, setCreateContractOpen] = useState(false);
  const [selection, setSelection] = useState({ start: 0, end: 0 });

  // The form is seeded once per load, adjusted during render (not a useEffect)
  // per React's "resetting state when a prop changes" pattern — re-running it
  // on every render would throw away whatever the user has typed since.
  const [seededFrom, setSeededFrom] = useState<typeof resource.data>(null);
  if (resource.data && resource.data !== seededFrom) {
    setSeededFrom(resource.data);
    const loaded = resource.data.template;
    setName(loaded?.name ?? '');
    setDescription(loaded?.description ?? '');
    setContractTypeId(loaded?.contract_type_id ? String(loaded.contract_type_id) : null);
    setStatus(loaded?.status ?? 'draft');
    setBody(loaded?.body ?? '');
    setChangeNote('');
    setDirty(false);
    setFieldErrors({});
  }

  const registry = useMemo(() => new Set(variables.map((v) => v.var_key)), [variables]);
  const usedTokens = useMemo(() => bodyTokens(body), [body]);
  const unregistered = useMemo(() => usedTokens.filter((t) => !registry.has(t)), [usedTokens, registry]);

  const groupedVariables = useMemo(() => {
    const map = new Map<string, TemplateVariable[]>();
    for (const v of variables) {
      const bucket = map.get(v.source) ?? [];
      bucket.push(v);
      map.set(v.source, bucket);
    }
    return [...map.entries()].sort(([a], [b]) => SOURCE_ORDER.indexOf(a) - SOURCE_ORDER.indexOf(b));
  }, [variables]);

  function insertVariable(key: string) {
    const token = `{{${key}}}`;
    const start = selection.start;
    const end = selection.end;
    setBody((current) => current.slice(0, start) + token + current.slice(end));
    setDirty(true);
    setVariableSheetOpen(false);
  }

  async function handleSave() {
    const errors: Record<string, string> = {};
    if (name.trim() === '') errors.name = 'Give the template a name.';
    if (body.trim() === '') errors.body = 'A template needs a body to render.';
    if (unregistered.length > 0) {
      errors.body = `${unregistered.length} merge ${unregistered.length === 1 ? 'variable is' : 'variables are'} not registered: ${unregistered.join(', ')}.`;
    }
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setSaving(true);
    setFieldErrors({});
    const payload: TemplateInput = {
      name: name.trim(),
      description: description.trim() === '' ? null : description.trim(),
      contract_type_id: contractTypeId ? Number(contractTypeId) : null,
      status,
      body,
      change_note: changeNote.trim() === '' ? null : changeNote.trim(),
    };

    try {
      const saved = isNew ? await createTemplate(payload) : await updateTemplate(templateId as number, payload);
      setDirty(false);
      if (isNew) router.replace(`/more/templates/${saved.id}`);
      else resource.reload();
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) setFieldErrors(err.fieldErrors);
      else Alert.alert('Could not save the template', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setSaving(false);
    }
  }

  function handleDelete() {
    if (isNew) return;
    Alert.alert(
      'Delete this template?',
      `${name || 'This template'} will no longer be available for drafting. Contracts already created from it are not affected.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Delete',
          style: 'destructive',
          onPress: async () => {
            setDeleting(true);
            try {
              await deleteTemplate(templateId as number);
              router.back();
            } catch (err) {
              Alert.alert('Could not delete the template', err instanceof ApiError ? err.message : 'Something went wrong.');
            } finally {
              setDeleting(false);
            }
          },
        },
      ],
    );
  }

  if (resource.loading && !resource.data) return <LoadingState label="Loading template…" />;
  if (resource.error && !resource.data) return <ErrorState message={resource.error} onRetry={resource.reload} />;

  const versions = template?.versions ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <View style={{ flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
          <StatusBadge value={status} />
          {template ? (
            <Text style={{ fontSize: 11.5, color: colors.textMuted }}>
              Version {template.version} · Updated {formatDateTime(template.updated_at)}
            </Text>
          ) : null}
          {dirty ? (
            <View style={{ backgroundColor: colors.warningSoft, borderRadius: 999, paddingHorizontal: 10, paddingVertical: 4 }}>
              <Text style={{ color: colors.warning, fontSize: 12, fontFamily: 'Nunito_600SemiBold' }}>Unsaved changes</Text>
            </View>
          ) : null}
        </View>

        <View style={{ flexDirection: 'row', backgroundColor: colors.surface, borderRadius: 12, padding: 4, marginBottom: 16 }}>
          {(['body', 'preview', 'history'] as const).map((t) => (
            <Pressable
              key={t}
              onPress={() => setTab(t)}
              style={{ flex: 1, paddingVertical: 9, borderRadius: 9, alignItems: 'center', backgroundColor: tab === t ? '#FFFFFF' : 'transparent' }}
            >
              <Text style={{ fontFamily: tab === t ? 'Nunito_700Bold' : 'Nunito_600SemiBold', fontSize: 13, color: tab === t ? colors.textPrimary : colors.textMuted }}>
                {t === 'body' ? 'Body' : t === 'preview' ? 'Preview' : `History${versions.length ? ` (${versions.length})` : ''}`}
              </Text>
            </Pressable>
          ))}
        </View>

        {tab === 'body' ? (
          <>
            <FormField
              label="Name"
              value={name}
              onChangeText={(t) => {
                setName(t);
                setDirty(true);
              }}
              error={fieldErrors.name}
              placeholder="Master services agreement — standard"
            />
            <FormField
              label="Description"
              value={description}
              onChangeText={(t) => {
                setDescription(t);
                setDirty(true);
              }}
              error={fieldErrors.description}
            />
            <SelectField
              label="Contract type"
              value={contractTypeId}
              placeholder="Any type"
              options={(contractTypes.data ?? []).map((t) => ({ value: String(t.id), label: t.name }))}
              onChange={(v) => {
                setContractTypeId(v || null);
                setDirty(true);
              }}
            />
            <SelectField
              label="Status"
              value={status}
              options={TEMPLATE_STATUSES.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
              onChange={(v) => {
                setStatus(v as TemplateStatus);
                setDirty(true);
              }}
            />

            <View style={{ marginBottom: 8 }}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
                <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textSecondary }}>Template body</Text>
                <Pressable onPress={() => setVariableSheetOpen(true)}>
                  <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 12.5, color: colors.primary }}>Insert variable</Text>
                </Pressable>
              </View>
              <TextInput
                multiline
                numberOfLines={14}
                value={body}
                onChangeText={(t) => {
                  setBody(t);
                  setDirty(true);
                }}
                onSelectionChange={(e) => setSelection(e.nativeEvent.selection)}
                placeholder="Write the standing wording here. Insert merge variables from the palette above."
                placeholderTextColor={colors.textMuted}
                style={{
                  borderWidth: 1,
                  borderColor: fieldErrors.body ? colors.danger : colors.border,
                  borderRadius: 10,
                  padding: 12,
                  fontSize: 13.5,
                  lineHeight: 20,
                  minHeight: 240,
                  textAlignVertical: 'top',
                  color: colors.textPrimary,
                  backgroundColor: '#FFFFFF',
                }}
              />
              {fieldErrors.body ? (
                <Text style={{ color: colors.danger, fontSize: 12, marginTop: 4 }}>{fieldErrors.body}</Text>
              ) : usedTokens.length > 0 ? (
                <Text style={{ color: colors.textMuted, fontSize: 12, marginTop: 4 }}>
                  {usedTokens.length} merge {usedTokens.length === 1 ? 'variable' : 'variables'} in use, all registered.
                </Text>
              ) : null}
            </View>

            <FormField label="Change note" value={changeNote} onChangeText={setChangeNote} placeholder="What changed and why" />

            <View style={{ gap: 10, marginTop: 6 }}>
              <PrimaryButton label={isNew ? 'Create template' : 'Save template'} onPress={handleSave} loading={saving} />
              {!isNew ? (
                <PrimaryButton label="Create contract from this template" variant="secondary" onPress={() => setCreateContractOpen(true)} />
              ) : null}
              {!isNew ? <PrimaryButton label="Delete template" variant="danger" onPress={handleDelete} loading={deleting} /> : null}
            </View>
          </>
        ) : null}

        {tab === 'preview' ? <PreviewSection templateId={templateId} dirty={dirty} /> : null}

        {tab === 'history' ? (
          <HistorySection
            versions={versions}
            currentVersion={template?.version ?? 1}
            onRestore={(v) => {
              setBody(v.body);
              setChangeNote(`Restored the wording of version ${v.version}`);
              setDirty(true);
              setTab('body');
            }}
          />
        ) : null}
      </ScrollView>

      <BottomSheet visible={variableSheetOpen} onClose={() => setVariableSheetOpen(false)}>
        <View style={{ padding: 16, borderBottomWidth: 1, borderBottomColor: colors.borderSoft }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary }}>Merge variables</Text>
          <Text style={{ fontSize: 12, color: colors.textSecondary, marginTop: 3 }}>Tap one to drop it in at the cursor.</Text>
        </View>
        <FlatList
          data={groupedVariables}
          keyExtractor={([source]) => source}
          contentContainerStyle={{ padding: 16 }}
          renderItem={({ item }) => {
            const [source, items] = item;
            return (
              <View style={{ marginBottom: 16 }}>
                <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4, marginBottom: 8 }}>
                  {humaniseSnakeCase(source)}
                </Text>
                <View style={{ gap: 6 }}>
                  {items.map((v) => (
                    <Pressable
                      key={v.id}
                      onPress={() => insertVariable(v.var_key)}
                      style={{ borderWidth: 1, borderColor: colors.borderSoft, borderRadius: 8, padding: 10 }}
                    >
                      <Text style={{ fontSize: 12.5, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>{v.label}</Text>
                      <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 2 }}>{`{{${v.var_key}}}`}</Text>
                    </Pressable>
                  ))}
                </View>
              </View>
            );
          }}
          ListEmptyComponent={<EmptyState icon="code-slash-outline" title="No variables registered" />}
        />
      </BottomSheet>

      <CreateContractSheet
        visible={createContractOpen}
        onClose={() => setCreateContractOpen(false)}
        templateId={isNew ? null : (templateId as number)}
        templateName={name}
        onCreated={(id) => router.replace(`/contracts/${id}`)}
      />
    </ScreenContainer>
  );
}

function PreviewSection({ templateId, dirty }: { templateId: number | 'new'; dirty: boolean }) {
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');
  const [chosen, setChosen] = useState<ContractListItem | null>(null);
  const [preview, setPreview] = useState<TemplatePreview | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(query.trim()), 300);
    return () => clearTimeout(timer);
  }, [query]);

  const search = useAsync(
    () => (debounced.length > 1 ? listContracts({ filters: { q: debounced }, perPage: 6 }) : Promise.resolve(null)),
    [debounced],
  );

  async function run() {
    if (templateId === 'new') return;
    setRunning(true);
    setError(null);
    try {
      const result = await previewTemplate(templateId, chosen?.id ?? null);
      setPreview(result);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'The preview did not render.');
    } finally {
      setRunning(false);
    }
  }

  if (templateId === 'new') {
    return <EmptyState icon="eye-outline" title="Save the template first" message="A preview is rendered by the server from the stored template." />;
  }

  const missing = preview?.missing ?? [];
  const used = preview?.used ?? [];
  const results = search.data?.items ?? [];

  return (
    <View>
      <Card>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>Preview against a contract</Text>
        <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginTop: 4, lineHeight: 18 }}>
          Merging real data is the only way to find out which variables actually resolve. Nothing is written to the contract.
        </Text>
        {dirty ? (
          <Text style={{ fontSize: 12, color: colors.warning, marginTop: 10 }}>You have unsaved changes. This preview renders the last saved version.</Text>
        ) : null}

        <View style={{ marginTop: 12 }}>
          <FormField
            label="Find a contract"
            value={query}
            onChangeText={(t) => {
              setQuery(t);
              setChosen(null);
            }}
            placeholder="Number, title or counterparty"
          />
          {chosen ? (
            <View
              style={{
                flexDirection: 'row',
                justifyContent: 'space-between',
                alignItems: 'center',
                padding: 10,
                borderRadius: 10,
                backgroundColor: colors.surface,
                marginBottom: 12,
              }}
            >
              <View style={{ flex: 1 }}>
                <Text style={{ fontSize: 13, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>{chosen.title}</Text>
                <Text style={{ fontSize: 11.5, color: colors.textMuted }}>{chosen.contract_number}</Text>
              </View>
              <Pressable onPress={() => setChosen(null)}>
                <Text style={{ color: colors.primary, fontSize: 12.5, fontFamily: 'Nunito_600SemiBold' }}>Change</Text>
              </Pressable>
            </View>
          ) : debounced.length > 1 && results.length > 0 ? (
            <View style={{ gap: 6, marginBottom: 12 }}>
              {results.map((c) => (
                <Pressable key={c.id} onPress={() => setChosen(c)} style={{ borderWidth: 1, borderColor: colors.borderSoft, borderRadius: 8, padding: 10 }}>
                  <Text style={{ fontSize: 13, fontFamily: 'Nunito_700Bold', color: colors.textPrimary }}>{c.title}</Text>
                  <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 2 }}>{c.contract_number}</Text>
                </Pressable>
              ))}
            </View>
          ) : null}

          <PrimaryButton label={chosen ? 'Render with this contract' : 'Render with sample values'} onPress={() => void run()} loading={running} />
        </View>
      </Card>

      {error ? (
        <View style={{ marginTop: 14 }}>
          <ErrorState message={error} onRetry={() => void run()} />
        </View>
      ) : preview ? (
        <View style={{ marginTop: 14, gap: 14 }}>
          <Card>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary }}>Resolved · {used.length}</Text>
            <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
              {used.map((entry) => (
                <View key={previewKey(entry)} style={{ backgroundColor: colors.successSoft, borderRadius: 999, paddingHorizontal: 9, paddingVertical: 4 }}>
                  <Text style={{ fontSize: 11.5, color: colors.primaryDark }}>{previewLabel(entry) ?? previewKey(entry)}</Text>
                </View>
              ))}
            </View>

            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13, color: colors.textPrimary, marginTop: 16 }}>Missing · {missing.length}</Text>
            {missing.length === 0 ? (
              <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginTop: 6 }}>Every variable in the body had a value.</Text>
            ) : (
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, marginTop: 8 }}>
                {missing.map((entry) => (
                  <View key={previewKey(entry)} style={{ backgroundColor: colors.dangerSoft, borderRadius: 999, paddingHorizontal: 9, paddingVertical: 4 }}>
                    <Text style={{ fontSize: 11.5, color: colors.danger }}>{previewLabel(entry) ?? previewKey(entry)}</Text>
                  </View>
                ))}
              </View>
            )}
          </Card>

          <Card style={{ padding: 0, overflow: 'hidden' }}>
            {preview.html?.trim() ? (
              <View style={{ height: 420 }}>
                <WebView originWhitelist={['*']} javaScriptEnabled={false} source={{ html: preview.html }} />
              </View>
            ) : (
              <View style={{ padding: 16 }}>
                <EmptyState icon="document-outline" title="The render came back empty" />
              </View>
            )}
          </Card>
        </View>
      ) : null}
    </View>
  );
}

function HistorySection({
  versions,
  currentVersion,
  onRestore,
}: {
  versions: TemplateVersion[];
  currentVersion: number;
  onRestore: (v: TemplateVersion) => void;
}) {
  const [openId, setOpenId] = useState<number | null>(null);

  if (versions.length === 0) {
    return <EmptyState icon="time-outline" title="No earlier versions yet" message="Each save keeps the wording it replaced." />;
  }

  const ordered = [...versions].sort((a, b) => b.version - a.version);

  return (
    <View style={{ gap: 10 }}>
      {ordered.map((v) => {
        const open = openId === v.id;
        return (
          <Card key={v.id}>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
              <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13.5, color: colors.textPrimary }}>Version {v.version}</Text>
              {v.version === currentVersion ? (
                <View style={{ backgroundColor: colors.primaryLight, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2 }}>
                  <Text style={{ color: colors.primaryDark, fontSize: 10.5, fontFamily: 'Nunito_700Bold' }}>Current</Text>
                </View>
              ) : null}
            </View>
            <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 3 }}>
              {formatDateTime(v.created_at)}
              {v.author_name ? ` · ${v.author_name}` : ''}
            </Text>
            {v.change_note ? <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginTop: 6 }}>{v.change_note}</Text> : null}

            <View style={{ flexDirection: 'row', gap: 14, marginTop: 10 }}>
              <Pressable onPress={() => setOpenId(open ? null : v.id)}>
                <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>{open ? 'Hide wording' : 'Show wording'}</Text>
              </Pressable>
              {v.version !== currentVersion ? (
                <Pressable onPress={() => onRestore(v)}>
                  <Text style={{ color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>Copy into editor</Text>
                </Pressable>
              ) : null}
            </View>
            {open ? (
              <View style={{ marginTop: 10, padding: 10, backgroundColor: colors.surface, borderRadius: 8 }}>
                <Text style={{ fontSize: 12, lineHeight: 18, color: colors.textPrimary }}>{v.body}</Text>
              </View>
            ) : null}
          </Card>
        );
      })}
    </View>
  );
}

function CreateContractSheet({
  visible,
  onClose,
  templateId,
  templateName,
  onCreated,
}: {
  visible: boolean;
  onClose: () => void;
  templateId: number | null;
  templateName: string;
  onCreated: (id: number) => void;
}) {
  const [title, setTitle] = useState('');
  const [counterparty, setCounterparty] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Prefill the title each time the sheet opens, adjusted during render rather
  // than in a useEffect — the sheet stays mounted (inside a Modal) while closed.
  const [wasVisible, setWasVisible] = useState(false);
  if (visible && !wasVisible) {
    setWasVisible(true);
    setTitle(`${templateName} — `);
  } else if (!visible && wasVisible) {
    setWasVisible(false);
  }

  async function submit() {
    if (!templateId || title.trim() === '') return;
    setBusy(true);
    setError(null);
    try {
      const result = await createContractFromTemplate(templateId, { title: title.trim(), counterparty_name: counterparty.trim() || null });
      const contract = 'contract' in result ? result.contract : result;
      onClose();
      onCreated(contract.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not create the contract.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <BottomSheet visible={visible} onClose={onClose}>
      <View style={{ padding: 16 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 16, color: colors.textPrimary, marginBottom: 4 }}>Create a contract from this template</Text>
        <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginBottom: 14 }}>
          The body is copied onto a new draft. Editing the draft afterwards does not change the template.
        </Text>
        {error ? <Text style={{ color: colors.danger, fontSize: 12.5, marginBottom: 10 }}>{error}</Text> : null}
        <FormField label="Contract title" value={title} onChangeText={setTitle} />
        <FormField label="Counterparty" value={counterparty} onChangeText={setCounterparty} placeholder="Optional" />
        <PrimaryButton label="Create draft" onPress={() => void submit()} loading={busy} disabled={title.trim() === ''} />
      </View>
    </BottomSheet>
  );
}
