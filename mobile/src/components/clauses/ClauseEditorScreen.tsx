import { useMemo, useState } from 'react';
import { Alert, Pressable, ScrollView, Text, TextInput, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { ScreenContainer } from '../ScreenContainer';
import { Card } from '../Card';
import { FormField } from '../FormField';
import { SelectField } from '../SelectField';
import { DateField } from '../DateField';
import { PrimaryButton } from '../PrimaryButton';
import { LoadingState } from '../LoadingState';
import { ErrorState } from '../ErrorState';
import { EmptyState } from '../EmptyState';
import { StatusBadge } from '../StatusBadge';
import { colors } from '../../theme/colors';
import { formatDate, formatDateTime, humaniseSnakeCase } from '../../utils/format';
import { useAsync } from '../../utils/useAsync';
import { ApiError } from '../../api/errors';
import { listContractTypes } from '../../api/endpoints/settings';
import {
  listClauseCategories,
  listClauseVersions,
  createClause,
  updateClause,
  deleteClause,
} from '../../api/endpoints/clauseLibrary';
import {
  CLAUSE_APPROVAL_STATUSES,
  RISK_LEVELS,
  type ClauseApprovalStatus,
  type LibraryClauseInput,
  type LibraryClauseItem,
  type LibraryClauseVersion,
  type RiskLevel,
} from '../../types/contracts';

function toNumberIds(values: (number | string)[] | null | undefined): number[] {
  if (!Array.isArray(values)) return [];
  return values.map((v) => (typeof v === 'number' ? v : Number(v))).filter((v) => Number.isInteger(v) && v > 0);
}

/** Shared by app/(app)/more/clause-library/new.tsx and [id].tsx. `clause` is the row itself — the API has no single-clause GET, only the list and its versions. */
export function ClauseEditorScreen({ clause }: { clause: LibraryClauseItem | null }) {
  const router = useRouter();
  const clauseId = clause?.id ?? null;

  const categories = useAsync(listClauseCategories);
  const contractTypes = useAsync(listContractTypes);
  const versions = useAsync(() => (clauseId === null ? Promise.resolve([]) : listClauseVersions(clauseId)), [clauseId]);

  const [name, setName] = useState(clause?.name ?? '');
  const [description, setDescription] = useState(clause?.description ?? '');
  const [categoryId, setCategoryId] = useState<string | null>(clause?.category_id != null ? String(clause.category_id) : null);
  const [standardText, setStandardText] = useState(clause?.standard_text ?? '');
  const [fallbackText, setFallbackText] = useState(clause?.fallback_text ?? '');
  const [prohibited, setProhibited] = useState(clause?.prohibited_wording ?? '');
  const [risk, setRisk] = useState<RiskLevel>(clause?.risk_classification ?? 'medium');
  const [applicableTypes, setApplicableTypes] = useState<number[]>(toNumberIds(clause?.applicable_types));
  const [jurisdiction, setJurisdiction] = useState(clause?.jurisdiction ?? '');
  const [approvalStatus, setApprovalStatus] = useState<ClauseApprovalStatus>(clause?.approval_status ?? 'draft');
  const [effectiveFrom, setEffectiveFrom] = useState<string | null>(clause?.effective_from ?? null);
  const [effectiveTo, setEffectiveTo] = useState<string | null>(clause?.effective_to ?? null);
  const [changeNote, setChangeNote] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [openVersionId, setOpenVersionId] = useState<number | null>(null);

  const categoryOptions = useMemo(() => (categories.data ?? []).map((c) => ({ value: String(c.id), label: c.name })), [categories.data]);

  async function save() {
    const next: Record<string, string> = {};
    if (name.trim() === '') next.name = 'Give the clause a name a drafter would search for.';
    if (standardText.trim() === '') next.standard_text = 'The standard wording is what this clause is.';
    if (effectiveFrom && effectiveTo && effectiveTo < effectiveFrom) next.effective_to = 'The end date cannot be before the start date.';

    if (Object.keys(next).length > 0) {
      setErrors(next);
      return;
    }

    setSaving(true);
    setErrors({});
    const payload: LibraryClauseInput = {
      name: name.trim(),
      description: description.trim() === '' ? null : description.trim(),
      category_id: categoryId ? Number(categoryId) : null,
      standard_text: standardText,
      fallback_text: fallbackText.trim() === '' ? null : fallbackText,
      prohibited_wording: prohibited.trim() === '' ? null : prohibited,
      risk_classification: risk,
      applicable_types: applicableTypes,
      jurisdiction: jurisdiction.trim() === '' ? null : jurisdiction.trim(),
      approval_status: approvalStatus,
      effective_from: effectiveFrom,
      effective_to: effectiveTo,
      change_note: changeNote.trim() === '' ? null : changeNote.trim(),
    };

    try {
      const saved = clauseId === null ? await createClause(payload) : await updateClause(clauseId, payload);
      setChangeNote('');
      if (clauseId === null) router.replace(`/more/clause-library/${saved.id}`);
      else versions.reload();
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) setErrors(err.fieldErrors);
      else Alert.alert('Could not save the clause', err instanceof ApiError ? err.message : 'Something went wrong.');
    } finally {
      setSaving(false);
    }
  }

  function remove() {
    if (clauseId === null) return;
    Alert.alert(
      'Remove this clause from the library?',
      `${name || 'This clause'} will no longer be offered when drafting, and playbook checks that reference it stop matching. Clauses already copied onto contracts are untouched.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Remove',
          style: 'destructive',
          onPress: async () => {
            setDeleting(true);
            try {
              await deleteClause(clauseId);
              router.back();
            } catch (err) {
              Alert.alert('Could not remove the clause', err instanceof ApiError ? err.message : 'Something went wrong.');
            } finally {
              setDeleting(false);
            }
          },
        },
      ],
    );
  }

  const versionRows = versions.data ?? [];

  return (
    <ScreenContainer bottomInset={false}>
      <ScrollView contentContainerStyle={{ padding: 16, paddingBottom: 40 }} keyboardShouldPersistTaps="handled">
        <View style={{ flexDirection: 'row', alignItems: 'center', flexWrap: 'wrap', gap: 8, marginBottom: 14 }}>
          <StatusBadge value={approvalStatus} />
          <StatusBadge value={risk} kind="risk" />
          {clause ? <Text style={{ fontSize: 11.5, color: colors.textMuted }}>Version {clause.version} · Updated {formatDateTime(clause.updated_at)}</Text> : null}
        </View>

        <Card>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 12 }}>Identity</Text>
          <FormField label="Name" value={name} onChangeText={setName} error={errors.name} placeholder="Limitation of liability — capped at fees paid" />
          <FormField label="Description" value={description} onChangeText={setDescription} error={errors.description} hint="When to reach for this clause." />
          <SelectField label="Category" value={categoryId} placeholder="Uncategorised" options={categoryOptions} onChange={(v) => setCategoryId(v || null)} error={errors.category_id} />
          <SelectField
            label="Risk classification"
            value={risk}
            options={RISK_LEVELS.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
            onChange={(v) => setRisk(v as RiskLevel)}
            error={errors.risk_classification}
          />
          <SelectField
            label="Approval status"
            value={approvalStatus}
            options={CLAUSE_APPROVAL_STATUSES.map((v) => ({ value: v, label: humaniseSnakeCase(v) }))}
            onChange={(v) => setApprovalStatus(v as ClauseApprovalStatus)}
            error={errors.approval_status}
          />
          <FormField label="Jurisdiction" value={jurisdiction} onChangeText={setJurisdiction} error={errors.jurisdiction} placeholder="India, England and Wales, Singapore…" hint="Leave blank when the wording travels." />
          <DateField label="Effective from" value={effectiveFrom} onChange={setEffectiveFrom} error={errors.effective_from} />
          <DateField label="Effective to" value={effectiveTo} onChange={setEffectiveTo} error={errors.effective_to} />
        </Card>

        <Card style={{ marginTop: 14 }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary }}>Applicable contract types</Text>
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginTop: 3, marginBottom: 12 }}>
            {applicableTypes.length === 0 ? 'None selected — this clause is offered for every contract type.' : `Offered for ${applicableTypes.length} of ${(contractTypes.data ?? []).length} types.`}
          </Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
            {(contractTypes.data ?? []).map((type) => {
              const active = applicableTypes.includes(type.id);
              return (
                <Pressable
                  key={type.id}
                  onPress={() =>
                    setApplicableTypes((current) => (active ? current.filter((id) => id !== type.id) : [...current, type.id]))
                  }
                  style={{
                    flexDirection: 'row',
                    alignItems: 'center',
                    gap: 6,
                    paddingHorizontal: 12,
                    paddingVertical: 7,
                    borderRadius: 999,
                    backgroundColor: active ? colors.primaryLight : colors.surface,
                    borderWidth: 1,
                    borderColor: active ? colors.primary : colors.border,
                  }}
                >
                  <Ionicons name={active ? 'checkmark-circle' : 'ellipse-outline'} size={14} color={active ? colors.primaryDark : colors.textMuted} />
                  <Text style={{ fontSize: 12.5, fontFamily: 'Nunito_600SemiBold', color: active ? colors.primaryDark : colors.textSecondary }}>{type.name}</Text>
                </Pressable>
              );
            })}
          </View>
        </Card>

        <Card style={{ marginTop: 14 }}>
          <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 4 }}>Wording</Text>
          <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginBottom: 14, lineHeight: 18 }}>
            The standard text is what we ask for, the fallback is what we will accept, and the prohibited wording is what a reviewer must push back on.
          </Text>

          <WordingInput label="Standard wording" value={standardText} onChangeText={setStandardText} error={errors.standard_text} hint="Copied onto a contract when this clause is attached." />

          <View style={{ borderLeftWidth: 3, borderLeftColor: colors.warning, paddingLeft: 12, marginTop: 16 }}>
            <WordingInput label="Fallback wording" value={fallbackText} onChangeText={setFallbackText} error={errors.fallback_text} hint="The position to concede to when the counterparty will not take the standard text." rows={5} />
          </View>

          <View style={{ borderLeftWidth: 3, borderLeftColor: colors.danger, paddingLeft: 12, marginTop: 16 }}>
            <WordingInput label="Prohibited wording" value={prohibited} onChangeText={setProhibited} error={errors.prohibited_wording} hint="Language that must not be agreed. The playbook check reads this to raise a deviation." rows={4} />
            <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 6 }}>This text is never inserted into a contract.</Text>
          </View>

          <View style={{ marginTop: 16 }}>
            <FormField label="Change note" value={changeNote} onChangeText={setChangeNote} placeholder="What changed and why" hint="Kept with the version this save creates." />
          </View>
        </Card>

        <View style={{ gap: 10, marginTop: 14 }}>
          <PrimaryButton label={clauseId === null ? 'Add to library' : 'Save clause'} onPress={() => void save()} loading={saving} />
          {clauseId !== null ? <PrimaryButton label="Remove from library" variant="danger" onPress={remove} loading={deleting} /> : null}
        </View>

        {clauseId !== null ? (
          <View style={{ marginTop: 18 }}>
            <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 14, color: colors.textPrimary, marginBottom: 3 }}>Version history</Text>
            <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginBottom: 12 }}>Every earlier wording is kept. Nothing here changes what the clause says today.</Text>

            {versions.loading ? (
              <LoadingState label="Loading history…" />
            ) : versions.error ? (
              <ErrorState message={versions.error} onRetry={versions.reload} />
            ) : versionRows.length === 0 ? (
              <EmptyState icon="time-outline" title="No earlier versions yet" message="This is the wording as first written." />
            ) : (
              <View style={{ gap: 10 }}>
                {[...versionRows]
                  .sort((a, b) => b.version - a.version)
                  .map((v) => (
                    <VersionRow
                      key={v.id}
                      version={v}
                      currentVersion={clause?.version ?? 1}
                      open={openVersionId === v.id}
                      onToggle={() => setOpenVersionId(openVersionId === v.id ? null : v.id)}
                      onCopy={() => {
                        setStandardText(v.standard_text);
                        if (v.fallback_text !== null) setFallbackText(v.fallback_text);
                        setChangeNote(`Reinstated the wording of version ${v.version}`);
                      }}
                    />
                  ))}
              </View>
            )}
          </View>
        ) : null}
      </ScrollView>
    </ScreenContainer>
  );
}

function WordingInput({
  label,
  value,
  onChangeText,
  error,
  hint,
  rows = 7,
}: {
  label: string;
  value: string;
  onChangeText: (t: string) => void;
  error?: string | null;
  hint?: string | null;
  rows?: number;
}) {
  return (
    <View>
      <Text style={{ fontFamily: 'Nunito_600SemiBold', fontSize: 13, color: colors.textSecondary, marginBottom: 6 }}>{label}</Text>
      <TextInput
        multiline
        numberOfLines={rows}
        value={value}
        onChangeText={onChangeText}
        placeholderTextColor={colors.textMuted}
        style={{
          borderWidth: 1,
          borderColor: error ? colors.danger : colors.border,
          borderRadius: 10,
          padding: 12,
          fontSize: 13.5,
          lineHeight: 20,
          minHeight: rows * 20,
          textAlignVertical: 'top',
          color: colors.textPrimary,
          backgroundColor: '#FFFFFF',
        }}
      />
      {error ? <Text style={{ color: colors.danger, fontSize: 12, marginTop: 4 }}>{error}</Text> : hint ? <Text style={{ color: colors.textMuted, fontSize: 12, marginTop: 4 }}>{hint}</Text> : null}
    </View>
  );
}

function VersionRow({
  version,
  currentVersion,
  open,
  onToggle,
  onCopy,
}: {
  version: LibraryClauseVersion;
  currentVersion: number;
  open: boolean;
  onToggle: () => void;
  onCopy: () => void;
}) {
  return (
    <Card>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8 }}>
        <Text style={{ fontFamily: 'Nunito_700Bold', fontSize: 13.5, color: colors.textPrimary }}>Version {version.version}</Text>
        {version.version === currentVersion ? (
          <View style={{ backgroundColor: colors.primaryLight, borderRadius: 999, paddingHorizontal: 8, paddingVertical: 2 }}>
            <Text style={{ color: colors.primaryDark, fontSize: 10.5, fontFamily: 'Nunito_700Bold' }}>Current</Text>
          </View>
        ) : null}
      </View>
      <Text style={{ fontSize: 11.5, color: colors.textMuted, marginTop: 3 }}>
        {formatDate(version.created_at)}
        {version.author_name ? ` · ${version.author_name}` : ''}
      </Text>
      {version.change_note ? <Text style={{ fontSize: 12.5, color: colors.textSecondary, marginTop: 6 }}>{version.change_note}</Text> : null}

      <View style={{ flexDirection: 'row', gap: 14, marginTop: 10 }}>
        <Pressable onPress={onToggle}>
          <Text style={{ color: colors.primary, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>{open ? 'Hide wording' : 'Show wording'}</Text>
        </Pressable>
        {version.version !== currentVersion ? (
          <Pressable onPress={onCopy}>
            <Text style={{ color: colors.textSecondary, fontFamily: 'Nunito_600SemiBold', fontSize: 12.5 }}>Copy into editor</Text>
          </Pressable>
        ) : null}
      </View>

      {open ? (
        <View style={{ marginTop: 10, gap: 10 }}>
          <View style={{ padding: 10, backgroundColor: colors.surface, borderRadius: 8 }}>
            <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 }}>Standard wording, as it stood</Text>
            <Text style={{ fontSize: 12.5, lineHeight: 19, color: colors.textPrimary, marginTop: 6 }}>{version.standard_text}</Text>
          </View>
          {version.fallback_text ? (
            <View style={{ padding: 10, backgroundColor: colors.surface, borderRadius: 8 }}>
              <Text style={{ fontSize: 11, fontFamily: 'Nunito_700Bold', color: colors.textMuted, textTransform: 'uppercase', letterSpacing: 0.4 }}>Fallback wording, as it stood</Text>
              <Text style={{ fontSize: 12.5, lineHeight: 19, color: colors.textPrimary, marginTop: 6 }}>{version.fallback_text}</Text>
            </View>
          ) : null}
        </View>
      ) : null}
    </Card>
  );
}
