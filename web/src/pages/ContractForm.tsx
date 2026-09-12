import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { AlertTriangle, ArrowLeft, Save } from 'lucide-react'

import {
  Button,
  Card,
  CardHeader,
  Checkbox,
  ConfirmDialog,
  DateInput,
  ErrorState,
  Input,
  MoneyInput,
  PageHeader,
  Select,
  Skeleton,
  Textarea,
} from '../components/ui'
import { CounterpartyPicker } from '../components/contracts/CounterpartyPicker'
import { useSession } from '../context/SessionProvider'
import { useToast } from '../context/ToastProvider'
import { useApiResource } from '../hooks/useApiResource'
import { ApiError, api } from '../services/apiClient'
import { PERMISSION } from '../types/permissions'
import { humanise } from '../utils/format'
import {
  CURRENCIES,
  PAYMENT_FREQUENCIES,
  RENEWAL_FREQUENCIES,
  RENEWAL_TYPES,
  RISK_LEVELS,
  type Contract,
  type ContractTypeSummary,
  type CustomFieldDefinition,
  type DepartmentSummary,
} from '../types/contracts'

/**
 * Create or edit a contract.
 *
 * One form for both, because the fields are the same and two components would
 * drift apart on the first change. What differs is loaded, not branched: an id
 * in the route means fetch first and PUT on save.
 *
 * Client-side validation exists so the obvious mistakes are caught before a
 * round trip, but the server is the authority — a 422 replaces whatever the
 * client thought and lands on the fields it names.
 */

type CustomFieldValue = string | boolean

interface FormState {
  title: string
  contract_type_id: string
  department_id: string
  counterparty_name: string
  description: string
  effective_date: string
  commencement_date: string
  execution_date: string
  expiry_date: string
  renewal_type: string
  renewal_frequency: string
  auto_renewal: boolean
  notice_period_days: string
  currency: string
  total_value: string
  recurring_value: string
  payment_frequency: string
  billing_frequency: string
  commercial_summary: string
  governing_law: string
  jurisdiction: string
  risk_level: string
  notes: string
  custom_fields: Record<string, CustomFieldValue>
}

const BLANK_FORM: FormState = {
  title: '',
  contract_type_id: '',
  department_id: '',
  counterparty_name: '',
  description: '',
  effective_date: '',
  commencement_date: '',
  execution_date: '',
  expiry_date: '',
  renewal_type: '',
  renewal_frequency: '',
  auto_renewal: false,
  notice_period_days: '',
  currency: 'INR',
  total_value: '',
  recurring_value: '',
  payment_frequency: '',
  billing_frequency: '',
  commercial_summary: '',
  governing_law: '',
  jurisdiction: '',
  risk_level: '',
  notes: '',
  custom_fields: {},
}

/* --- Custom field definitions come from Settings in more than one shape ---- */

function definitionKey(definition: CustomFieldDefinition): string {
  return String(definition.key ?? definition.code ?? definition.id)
}

function definitionLabel(definition: CustomFieldDefinition): string {
  return definition.label ?? definition.name ?? humanise(definitionKey(definition))
}

function definitionType(definition: CustomFieldDefinition): string {
  return (definition.field_type ?? definition.type ?? 'text').toLowerCase()
}

function definitionRequired(definition: CustomFieldDefinition): boolean {
  return definition.is_required ?? definition.required ?? false
}

function definitionOptions(definition: CustomFieldDefinition): { value: string; label: string }[] {
  if (!definition.options) return []
  return definition.options.map((option) =>
    typeof option === 'string' ? { value: option, label: humanise(option) } : option,
  )
}

/* --- Payload helpers ------------------------------------------------------ */

const trimmedOrNull = (value: string): string | null => (value.trim() === '' ? null : value.trim())

function toNumberOrNull(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? parsed : null
}

function isMoney(value: string): boolean {
  return value.trim() === '' || /^\d+(\.\d{1,4})?$/.test(value.trim())
}

function fromContract(contract: Contract, definitions: CustomFieldDefinition[]): FormState {
  const stored = contract.custom_fields ?? {}
  const custom: Record<string, CustomFieldValue> = {}

  for (const definition of definitions) {
    const key = definitionKey(definition)
    const raw = stored[key]
    custom[key] =
      definitionType(definition) === 'boolean' || definitionType(definition) === 'checkbox'
        ? raw === true || raw === 'true' || raw === 1
        : raw === null || raw === undefined
          ? ''
          : String(raw)
  }

  return {
    title: contract.title ?? '',
    contract_type_id: contract.contract_type_id === null ? '' : String(contract.contract_type_id),
    department_id: contract.department_id === null ? '' : String(contract.department_id),
    counterparty_name: contract.counterparty_name ?? '',
    description: contract.description ?? '',
    effective_date: contract.effective_date ?? '',
    commencement_date: contract.commencement_date ?? '',
    execution_date: contract.execution_date ?? '',
    expiry_date: contract.expiry_date ?? '',
    renewal_type: contract.renewal_type ?? '',
    renewal_frequency: contract.renewal_frequency ?? '',
    auto_renewal: contract.auto_renewal ?? false,
    notice_period_days:
      contract.notice_period_days === null ? '' : String(contract.notice_period_days),
    currency: contract.currency || 'INR',
    total_value: contract.total_value ?? '',
    recurring_value: contract.recurring_value ?? '',
    payment_frequency: contract.payment_frequency ?? '',
    billing_frequency: contract.billing_frequency ?? '',
    commercial_summary: contract.commercial_summary ?? '',
    governing_law: contract.governing_law ?? '',
    jurisdiction: contract.jurisdiction ?? '',
    risk_level: contract.risk_level ?? '',
    notes: contract.notes ?? '',
    custom_fields: custom,
  }
}

function toPayload(
  state: FormState,
  definitions: CustomFieldDefinition[],
  includeCommercials: boolean,
): Record<string, unknown> {
  const custom: Record<string, unknown> = {}
  for (const definition of definitions) {
    const key = definitionKey(definition)
    const value = state.custom_fields[key]
    const type = definitionType(definition)

    if (type === 'boolean' || type === 'checkbox') {
      custom[key] = value === true
    } else if (type === 'number') {
      custom[key] = typeof value === 'string' ? toNumberOrNull(value) : null
    } else {
      custom[key] = typeof value === 'string' ? trimmedOrNull(value) : null
    }
  }

  const payload: Record<string, unknown> = {
    title: state.title.trim(),
    contract_type_id: toNumberOrNull(state.contract_type_id),
    department_id: toNumberOrNull(state.department_id),
    counterparty_name: state.counterparty_name.trim(),
    description: trimmedOrNull(state.description),
    effective_date: trimmedOrNull(state.effective_date),
    commencement_date: trimmedOrNull(state.commencement_date),
    execution_date: trimmedOrNull(state.execution_date),
    expiry_date: trimmedOrNull(state.expiry_date),
    renewal_type: trimmedOrNull(state.renewal_type),
    renewal_frequency: trimmedOrNull(state.renewal_frequency),
    auto_renewal: state.auto_renewal,
    notice_period_days: toNumberOrNull(state.notice_period_days),
    governing_law: trimmedOrNull(state.governing_law),
    jurisdiction: trimmedOrNull(state.jurisdiction),
    risk_level: trimmedOrNull(state.risk_level),
    notes: trimmedOrNull(state.notes),
    custom_fields: custom,
  }

  // Sending commercial fields a user is not allowed to edit would either be
  // rejected or, worse, silently blank the values someone else set.
  if (includeCommercials) {
    payload.currency = state.currency
    payload.total_value = trimmedOrNull(state.total_value)
    payload.recurring_value = trimmedOrNull(state.recurring_value)
    payload.payment_frequency = trimmedOrNull(state.payment_frequency)
    payload.billing_frequency = trimmedOrNull(state.billing_frequency)
    payload.commercial_summary = trimmedOrNull(state.commercial_summary)
  }

  return payload
}

function validate(
  state: FormState,
  definitions: CustomFieldDefinition[],
  checkCommercials: boolean,
): Record<string, string> {
  const errors: Record<string, string> = {}

  if (!state.title.trim()) errors.title = 'Give the contract a title.'
  else if (state.title.trim().length > 255) errors.title = 'Keep the title under 255 characters.'

  if (!state.contract_type_id) errors.contract_type_id = 'Choose a contract type.'
  if (!state.counterparty_name.trim()) errors.counterparty_name = 'Name the other party.'

  if (state.effective_date && state.expiry_date && state.expiry_date < state.effective_date) {
    errors.expiry_date = 'The expiry date cannot fall before the effective date.'
  }

  if (state.notice_period_days.trim() !== '') {
    const days = Number(state.notice_period_days)
    if (!Number.isInteger(days) || days < 0) {
      errors.notice_period_days = 'Enter a whole number of days.'
    }
  }

  if (checkCommercials) {
    if (!isMoney(state.total_value)) errors.total_value = 'Enter an amount, for example 250000.00'
    if (!isMoney(state.recurring_value)) {
      errors.recurring_value = 'Enter an amount, for example 25000.00'
    }
  }

  for (const definition of definitions) {
    if (!definitionRequired(definition)) continue
    const key = definitionKey(definition)
    const value = state.custom_fields[key]
    const missing = typeof value === 'boolean' ? value === false : !String(value ?? '').trim()
    if (missing) errors[`custom_fields.${key}`] = `${definitionLabel(definition)} is required.`
  }

  return errors
}

/** The DOM id of the control a server or client error belongs to. */
function fieldElementId(field: string): string {
  return field.startsWith('custom_fields.')
    ? `cf-custom-${field.slice('custom_fields.'.length)}`
    : `cf-${field}`
}

export default function ContractForm() {
  const { id } = useParams<{ id: string }>()
  const editing = Boolean(id)
  const navigate = useNavigate()
  const toast = useToast()
  const { can } = useSession()

  const canViewCommercials = can(PERMISSION.COMMERCIALS_VIEW)
  const canEditCommercials = can(PERMISSION.COMMERCIALS_EDIT)
  const allowed = editing ? can(PERMISSION.CONTRACT_EDIT) : can(PERMISSION.CONTRACT_CREATE)

  const [form, setForm] = useState<FormState>(BLANK_FORM)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [leaveTo, setLeaveTo] = useState<string | null>(null)
  const baseline = useRef<string>(JSON.stringify(BLANK_FORM))

  const lookups = useApiResource(
    async (signal) => {
      const [contractTypes, departments, customFields] = await Promise.all([
        api.get<ContractTypeSummary[]>('/settings/contract-types', undefined, signal),
        api.get<DepartmentSummary[]>('/settings/departments', undefined, signal).catch(() => []),
        api
          .get<CustomFieldDefinition[]>('/settings/custom-fields', undefined, signal)
          .catch(() => []),
      ])
      return {
        contractTypes: contractTypes ?? [],
        departments: departments ?? [],
        customFields: (customFields ?? []).filter((field) => field.is_active !== false),
      }
    },
    [],
    { enabled: allowed },
  )

  const contract = useApiResource<Contract>(
    (signal) => api.get<Contract>(`/contracts/${id}`, undefined, signal),
    [id],
    { enabled: allowed && editing },
  )

  const definitions = useMemo(() => lookups.data?.customFields ?? [], [lookups.data])

  // The form is populated once, and only when both the contract and the field
  // definitions have arrived: custom field values cannot be shaped without
  // their types. Keyed by route id so moving straight from editing one contract
  // to editing another re-hydrates instead of showing the first one's values.
  const hydrationKey = id ?? 'new'
  const hydratedFor = useRef<string | null>(null)
  useEffect(() => {
    if (hydratedFor.current === hydrationKey || !lookups.data) return
    if (editing && (!contract.data || String(contract.data.id) !== id)) return

    const next = contract.data
      ? fromContract(contract.data, definitions)
      : {
          ...BLANK_FORM,
          custom_fields: Object.fromEntries(
            definitions.map((definition) => [
              definitionKey(definition),
              definitionType(definition) === 'boolean' || definitionType(definition) === 'checkbox'
                ? false
                : '',
            ]),
          ),
        }

    setForm(next)
    setErrors({})
    baseline.current = JSON.stringify(next)
    hydratedFor.current = hydrationKey
  }, [lookups.data, contract.data, definitions, editing, id, hydrationKey])

  const dirty = JSON.stringify(form) !== baseline.current

  useEffect(() => {
    if (!dirty) return
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  const set = useCallback(<K extends keyof FormState>(key: K, value: FormState[K]) => {
    setForm((current) => ({ ...current, [key]: value }))
    setErrors((current) => {
      if (!(key in current)) return current
      const { [key]: _removed, ...rest } = current
      return rest
    })
  }, [])

  const setCustom = useCallback((key: string, value: CustomFieldValue) => {
    setForm((current) => ({
      ...current,
      custom_fields: { ...current.custom_fields, [key]: value },
    }))
    setErrors((current) => {
      const field = `custom_fields.${key}`
      if (!(field in current)) return current
      const { [field]: _removed, ...rest } = current
      return rest
    })
  }, [])

  /**
   * Leaving with unsaved work.
   *
   * The app mounts a plain `BrowserRouter`, which is not a data router, so
   * `useBlocker` is unavailable — every exit this screen owns is therefore
   * routed through here, and `beforeunload` covers closing the tab.
   */
  const leave = (to: string) => {
    if (dirty) setLeaveTo(to)
    else navigate(to)
  }

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()

    const found = validate(form, definitions, canEditCommercials)
    if (Object.keys(found).length > 0) {
      setErrors(found)
      document.getElementById(fieldElementId(Object.keys(found)[0]))?.focus()
      return
    }

    setSaving(true)
    try {
      const payload = toPayload(form, definitions, canEditCommercials)
      const saved = editing
        ? await api.put<Contract>(`/contracts/${id}`, payload)
        : await api.post<Contract>('/contracts', payload)

      // Cleared before navigating, or the confirm dialog fires on the way out.
      baseline.current = JSON.stringify(form)
      setErrors({})
      toast.success(editing ? 'Contract saved' : 'Contract created', saved.contract_number || undefined)
      navigate(`/contracts/${saved.id}`)
    } catch (err) {
      if (err instanceof ApiError && err.isValidation) {
        setErrors(err.fieldErrors)
        document.getElementById(fieldElementId(Object.keys(err.fieldErrors)[0]))?.focus()
      } else {
        toast.error(
          editing ? 'Could not save the contract' : 'Could not create the contract',
          err instanceof Error ? err.message : undefined,
        )
      }
    } finally {
      setSaving(false)
    }
  }

  if (!allowed) {
    return (
      <ErrorState
        title={editing ? 'You cannot edit contracts' : 'You cannot create contracts'}
        detail="Ask an administrator to grant you the permission, then reload this page."
      />
    )
  }

  if (lookups.error) {
    return (
      <ErrorState
        title="Could not load the form"
        detail={lookups.error.message}
        onRetry={lookups.reload}
      />
    )
  }

  if (contract.error) {
    return (
      <ErrorState
        title="Could not load this contract"
        detail={contract.error.message}
        onRetry={contract.reload}
      />
    )
  }

  if (lookups.loading || contract.loading || hydratedFor.current !== hydrationKey) {
    return <FormSkeleton />
  }

  const errorList = Object.entries(errors)
  const commercialsReadOnly = canViewCommercials && !canEditCommercials

  return (
    <form onSubmit={submit} noValidate>
      <PageHeader
        title={editing ? 'Edit contract' : 'New contract'}
        description={
          editing
            ? 'Changes are recorded in the contract audit trail.'
            : 'The contract starts as a draft. Documents, parties and obligations are added once it exists.'
        }
        actions={
          <>
            <Button
              type="button"
              variant="ghost"
              icon={<ArrowLeft size={14} />}
              onClick={() => leave(editing ? `/contracts/${id}` : '/contracts')}
            >
              Cancel
            </Button>
            <Button type="submit" variant="primary" icon={<Save size={14} />} loading={saving}>
              {editing ? 'Save changes' : 'Create contract'}
            </Button>
          </>
        }
      />

      {errorList.length > 0 ? (
        <div
          role="alert"
          style={{
            display: 'flex',
            gap: 10,
            padding: '12px 14px',
            marginBottom: 16,
            borderRadius: 'var(--radius-md)',
            background: 'var(--color-danger-bg)',
            border: '1px solid var(--color-danger-border)',
          }}
        >
          <AlertTriangle size={17} aria-hidden style={{ color: 'var(--color-danger)', flexShrink: 0 }} />
          <div>
            <strong style={{ fontSize: 13.5 }}>
              {errorList.length === 1
                ? 'One field needs attention'
                : `${errorList.length} fields need attention`}
            </strong>
            <ul style={{ marginTop: 6, paddingLeft: 18, fontSize: 12.5 }}>
              {errorList.map(([field, message]) => (
                <li key={field}>
                  <a href={`#${fieldElementId(field)}`}>{message}</a>
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}

      <div style={{ display: 'grid', gap: 16 }}>
        <Card>
          <CardHeader title="Basics" description="What this agreement is and who owns it." />
          <div style={{ display: 'grid', gap: 14 }}>
            <Input
              id="cf-title"
              label="Title"
              required
              value={form.title}
              error={errors.title}
              placeholder="Master services agreement — Acme Pvt Ltd"
              onChange={(event) => set('title', event.target.value)}
            />
            <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
              <Select
                id="cf-contract_type_id"
                label="Contract type"
                required
                placeholder="Choose a type"
                options={lookups.data!.contractTypes.map((type) => ({
                  value: String(type.id),
                  label: type.name,
                }))}
                value={form.contract_type_id}
                error={errors.contract_type_id}
                onChange={(event) => set('contract_type_id', event.target.value)}
              />
              <Select
                id="cf-department_id"
                label="Department"
                placeholder="No department"
                options={lookups.data!.departments.map((department) => ({
                  value: String(department.id),
                  label: department.name,
                }))}
                value={form.department_id}
                error={errors.department_id}
                onChange={(event) => set('department_id', event.target.value)}
              />
              {editing && contract.data ? (
                <Input
                  id="cf-contract_number"
                  label="Contract number"
                  value={contract.data.contract_number}
                  readOnly
                  hint="Assigned by the numbering rules in Settings."
                  onChange={() => undefined}
                />
              ) : null}
            </div>
            <Textarea
              id="cf-description"
              label="Description"
              rows={3}
              value={form.description}
              error={errors.description}
              hint="A sentence someone scanning the repository would find useful."
              onChange={(event) => set('description', event.target.value)}
            />
          </div>
        </Card>

        <Card>
          <CardHeader
            title="Parties"
            description={
              editing
                ? 'Signatories, addresses and authorised representatives live on the contract’s Parties tab.'
                : 'Name the counterparty now; full party detail is added once the contract exists.'
            }
          />
          <CounterpartyPicker
            id="cf-counterparty_name"
            value={form.counterparty_name}
            error={errors.counterparty_name}
            required
            hint="Searched in Contacts. Any name can be typed if the party is not there yet."
            onChange={(name) => set('counterparty_name', name)}
          />
        </Card>

        <Card>
          <CardHeader title="Dates and renewal" description="What drives the expiry and notice alerts." />
          <div style={{ display: 'grid', gap: 14 }}>
            <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
              <DateInput
                id="cf-effective_date"
                label="Effective date"
                value={form.effective_date}
                error={errors.effective_date}
                onChange={(event) => set('effective_date', event.target.value)}
              />
              <DateInput
                id="cf-commencement_date"
                label="Commencement date"
                value={form.commencement_date}
                error={errors.commencement_date}
                hint="If the work starts on a different day."
                onChange={(event) => set('commencement_date', event.target.value)}
              />
              <DateInput
                id="cf-execution_date"
                label="Execution date"
                value={form.execution_date}
                error={errors.execution_date}
                onChange={(event) => set('execution_date', event.target.value)}
              />
              <DateInput
                id="cf-expiry_date"
                label="Expiry date"
                value={form.expiry_date}
                error={errors.expiry_date}
                onChange={(event) => set('expiry_date', event.target.value)}
              />
            </div>
            <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
              <Select
                id="cf-renewal_type"
                label="Renewal"
                placeholder="Not set"
                options={RENEWAL_TYPES.map((type) => ({ value: type, label: humanise(type) }))}
                value={form.renewal_type}
                error={errors.renewal_type}
                onChange={(event) => set('renewal_type', event.target.value)}
              />
              <Select
                id="cf-renewal_frequency"
                label="Renewal term"
                placeholder="Not set"
                options={RENEWAL_FREQUENCIES.map((value) => ({ value, label: humanise(value) }))}
                value={form.renewal_frequency}
                error={errors.renewal_frequency}
                onChange={(event) => set('renewal_frequency', event.target.value)}
              />
              <Input
                id="cf-notice_period_days"
                label="Notice period (days)"
                inputMode="numeric"
                placeholder="30"
                value={form.notice_period_days}
                error={errors.notice_period_days}
                hint="Drives the notice deadline shown in the repository."
                onChange={(event) => set('notice_period_days', event.target.value)}
              />
            </div>
            <Checkbox
              id="cf-auto_renewal"
              label="This contract renews automatically"
              hint="Auto-renewing contracts are surfaced ahead of their notice deadline."
              checked={form.auto_renewal}
              onChange={(event) => set('auto_renewal', event.target.checked)}
            />
          </div>
        </Card>

        {canViewCommercials ? (
          <Card>
            <CardHeader
              title="Commercials"
              description={
                commercialsReadOnly
                  ? 'You can see these figures but not change them.'
                  : 'Values feed the portfolio totals and the payment schedule.'
              }
            />
            <div style={{ display: 'grid', gap: 14 }}>
              <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
                <Select
                  id="cf-currency"
                  label="Currency"
                  options={CURRENCIES.map((code) => ({ value: code, label: code }))}
                  value={form.currency}
                  error={errors.currency}
                  disabled={commercialsReadOnly}
                  onChange={(event) => set('currency', event.target.value)}
                />
                <MoneyInput
                  id="cf-total_value"
                  label="Total value"
                  currency={form.currency}
                  value={form.total_value}
                  error={errors.total_value}
                  disabled={commercialsReadOnly}
                  onChange={(event) => set('total_value', event.target.value)}
                />
                <MoneyInput
                  id="cf-recurring_value"
                  label="Recurring value"
                  currency={form.currency}
                  value={form.recurring_value}
                  error={errors.recurring_value}
                  disabled={commercialsReadOnly}
                  onChange={(event) => set('recurring_value', event.target.value)}
                />
              </div>
              <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
                <Select
                  id="cf-payment_frequency"
                  label="Payment frequency"
                  placeholder="Not set"
                  options={PAYMENT_FREQUENCIES.map((value) => ({ value, label: humanise(value) }))}
                  value={form.payment_frequency}
                  error={errors.payment_frequency}
                  disabled={commercialsReadOnly}
                  onChange={(event) => set('payment_frequency', event.target.value)}
                />
                <Select
                  id="cf-billing_frequency"
                  label="Billing frequency"
                  placeholder="Not set"
                  options={PAYMENT_FREQUENCIES.map((value) => ({ value, label: humanise(value) }))}
                  value={form.billing_frequency}
                  error={errors.billing_frequency}
                  disabled={commercialsReadOnly}
                  onChange={(event) => set('billing_frequency', event.target.value)}
                />
              </div>
              <Textarea
                id="cf-commercial_summary"
                label="Commercial summary"
                rows={3}
                value={form.commercial_summary}
                error={errors.commercial_summary}
                disabled={commercialsReadOnly}
                onChange={(event) => set('commercial_summary', event.target.value)}
              />
            </div>
          </Card>
        ) : null}

        <Card>
          <CardHeader title="Governance" description="The law this agreement answers to, and how risky it is." />
          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
            <Input
              id="cf-governing_law"
              label="Governing law"
              placeholder="Laws of India"
              value={form.governing_law}
              error={errors.governing_law}
              onChange={(event) => set('governing_law', event.target.value)}
            />
            <Input
              id="cf-jurisdiction"
              label="Jurisdiction"
              placeholder="Courts at Bengaluru"
              value={form.jurisdiction}
              error={errors.jurisdiction}
              onChange={(event) => set('jurisdiction', event.target.value)}
            />
            <Select
              id="cf-risk_level"
              label="Risk level"
              placeholder="Not assessed"
              options={RISK_LEVELS.map((level) => ({ value: level, label: humanise(level) }))}
              value={form.risk_level}
              error={errors.risk_level}
              hint="An AI assessment can replace this later."
              onChange={(event) => set('risk_level', event.target.value)}
            />
          </div>
        </Card>

        {definitions.length > 0 ? (
          <Card>
            <CardHeader
              title="Custom fields"
              description="Configured for this company in Settings."
            />
            <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))' }}>
              {definitions.map((definition) => (
                <CustomField
                  key={definitionKey(definition)}
                  definition={definition}
                  value={form.custom_fields[definitionKey(definition)] ?? ''}
                  error={errors[`custom_fields.${definitionKey(definition)}`]}
                  onChange={(value) => setCustom(definitionKey(definition), value)}
                />
              ))}
            </div>
          </Card>
        ) : null}

        <Card>
          <CardHeader title="Notes" description="Internal only — never shared with the counterparty." />
          <Textarea
            id="cf-notes"
            label="Notes"
            rows={5}
            value={form.notes}
            error={errors.notes}
            onChange={(event) => set('notes', event.target.value)}
          />
        </Card>

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
          <Button
            type="button"
            variant="secondary"
            onClick={() => leave(editing ? `/contracts/${id}` : '/contracts')}
          >
            Cancel
          </Button>
          <Button type="submit" variant="primary" icon={<Save size={14} />} loading={saving}>
            {editing ? 'Save changes' : 'Create contract'}
          </Button>
        </div>
      </div>

      <ConfirmDialog
        open={leaveTo !== null}
        onClose={() => setLeaveTo(null)}
        onConfirm={() => {
          const to = leaveTo
          setLeaveTo(null)
          if (to) navigate(to)
        }}
        title="Leave without saving?"
        confirmLabel="Discard changes"
        cancelLabel="Keep editing"
        tone="danger"
        message="The changes on this form have not been sent to the server. Leaving now discards them."
      />
    </form>
  )
}

function CustomField({
  definition,
  value,
  error,
  onChange,
}: {
  definition: CustomFieldDefinition
  value: CustomFieldValue
  error?: string
  onChange: (value: CustomFieldValue) => void
}) {
  const key = definitionKey(definition)
  const id = `cf-custom-${key}`
  const label = definitionLabel(definition)
  const hint = definition.help_text ?? definition.hint ?? undefined
  const required = definitionRequired(definition)
  const type = definitionType(definition)
  const text = typeof value === 'string' ? value : ''

  switch (type) {
    case 'boolean':
    case 'checkbox':
      return (
        <Checkbox
          id={id}
          label={label}
          hint={hint}
          checked={value === true}
          onChange={(event) => onChange(event.target.checked)}
        />
      )
    case 'date':
      return (
        <DateInput
          id={id}
          label={label}
          hint={hint}
          error={error}
          required={required}
          value={text}
          onChange={(event) => onChange(event.target.value)}
        />
      )
    case 'number':
      return (
        <Input
          id={id}
          label={label}
          hint={hint}
          error={error}
          required={required}
          inputMode="decimal"
          value={text}
          onChange={(event) => onChange(event.target.value)}
        />
      )
    case 'select':
    case 'dropdown':
      return (
        <Select
          id={id}
          label={label}
          hint={hint}
          error={error}
          required={required}
          placeholder="Not set"
          options={definitionOptions(definition)}
          value={text}
          onChange={(event) => onChange(event.target.value)}
        />
      )
    case 'textarea':
      return (
        <Textarea
          id={id}
          label={label}
          hint={hint}
          error={error}
          required={required}
          rows={3}
          value={text}
          onChange={(event) => onChange(event.target.value)}
        />
      )
    default:
      return (
        <Input
          id={id}
          label={label}
          hint={hint}
          error={error}
          required={required}
          value={text}
          onChange={(event) => onChange(event.target.value)}
        />
      )
  }
}

function FormSkeleton() {
  return (
    <div role="status" aria-label="Loading the contract form" style={{ display: 'grid', gap: 16 }}>
      <span className="ct-sr-only">Loading…</span>
      <Skeleton width={220} height={26} />
      {[0, 1, 2].map((section) => (
        <div key={section} className="ct-card" style={{ padding: 18, display: 'grid', gap: 14 }}>
          <Skeleton width={160} height={16} />
          <div style={{ display: 'grid', gap: 14, gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))' }}>
            <Skeleton height={36} radius={10} />
            <Skeleton height={36} radius={10} />
            <Skeleton height={36} radius={10} />
          </div>
        </div>
      ))}
    </div>
  )
}
