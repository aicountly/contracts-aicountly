import { Checkbox, DateInput, Input, Select } from '../ui'
import { humanise } from '../../utils/format'
import {
  APPROVAL_STATUSES,
  CONTRACT_STATUSES,
  CURRENCIES,
  EMPTY_CONTRACT_FILTERS,
  OBLIGATION_STATUSES,
  RISK_LEVELS,
  SIGNING_STATUSES,
  type ContractFilters,
  type ContractTypeSummary,
  type DepartmentSummary,
  type TagSummary,
} from '../../types/contracts'

/**
 * Every filter `GET /contracts` accepts, in one compact block.
 *
 * The same component renders inside a card on a desktop and inside the Drawer
 * on a phone — there is one definition of what a filter is and what it is
 * called, so the two never disagree. Status is a set of toggles rather than a
 * multi-select because "show me everything except drafts" is a two-second job
 * with toggles and a fight with a keyboard-driven multi-select.
 */

export interface FilterLookups {
  contractTypes: ContractTypeSummary[]
  departments: DepartmentSummary[]
  tags: TagSummary[]
}

const EXPIRING_WINDOWS = ['30', '60', '90', '180', '365']

function toOptions<T extends { id: number; name: string }>(rows: T[]) {
  return rows.map((row) => ({ value: String(row.id), label: row.name }))
}

function vocabulary(values: readonly string[]) {
  return values.map((value) => ({ value, label: humanise(value) }))
}

export function RepositoryFilters({
  value,
  onChange,
  lookups,
  showCommercials,
  idPrefix = 'flt',
}: {
  value: ContractFilters
  onChange: (next: ContractFilters) => void
  lookups: FilterLookups
  /** Value and currency filters leak commercial data, so they follow the permission. */
  showCommercials: boolean
  idPrefix?: string
}) {
  const set = (patch: Partial<ContractFilters>) => onChange({ ...value, ...patch })

  const toggleStatus = (status: string) =>
    set({
      status: value.status.includes(status)
        ? value.status.filter((item) => item !== status)
        : [...value.status, status],
    })

  return (
    <div style={{ display: 'grid', gap: 16 }}>
      <fieldset style={{ border: 'none' }}>
        <legend
          style={{
            fontSize: 12.5,
            fontWeight: 600,
            color: 'var(--color-text-secondary)',
            marginBottom: 7,
          }}
        >
          Status
        </legend>
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
          {CONTRACT_STATUSES.map((status) => {
            const on = value.status.includes(status)
            return (
              <button
                key={status}
                type="button"
                aria-pressed={on}
                onClick={() => toggleStatus(status)}
                style={{
                  padding: '3px 10px',
                  borderRadius: 999,
                  fontSize: 12,
                  fontWeight: 600,
                  cursor: 'pointer',
                  background: on ? 'var(--color-primary-muted)' : 'var(--color-bg-subtle)',
                  color: on ? 'rgb(var(--color-primary-active))' : 'var(--color-text-secondary)',
                  border: `1px solid ${on ? 'var(--color-primary-border)' : 'rgb(var(--color-border))'}`,
                }}
              >
                {humanise(status)}
              </button>
            )
          })}
        </div>
      </fieldset>

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))',
          gap: 12,
        }}
      >
        <Select
          id={`${idPrefix}-type`}
          label="Contract type"
          placeholder="Any type"
          options={toOptions(lookups.contractTypes)}
          value={value.contract_type_id}
          onChange={(event) => set({ contract_type_id: event.target.value })}
        />
        <Select
          id={`${idPrefix}-dept`}
          label="Department"
          placeholder="Any department"
          options={toOptions(lookups.departments)}
          value={value.department_id}
          onChange={(event) => set({ department_id: event.target.value })}
        />
        <Select
          id={`${idPrefix}-tag`}
          label="Tag"
          placeholder="Any tag"
          options={toOptions(lookups.tags)}
          value={value.tag_id}
          onChange={(event) => set({ tag_id: event.target.value })}
        />
        <Select
          id={`${idPrefix}-risk`}
          label="Risk level"
          placeholder="Any risk"
          options={vocabulary(RISK_LEVELS)}
          value={value.risk_level}
          onChange={(event) => set({ risk_level: event.target.value })}
        />
        <Select
          id={`${idPrefix}-approval`}
          label="Approval"
          placeholder="Any approval state"
          options={vocabulary(APPROVAL_STATUSES)}
          value={value.approval_status}
          onChange={(event) => set({ approval_status: event.target.value })}
        />
        <Select
          id={`${idPrefix}-signing`}
          label="Signing"
          placeholder="Any signing state"
          options={vocabulary(SIGNING_STATUSES)}
          value={value.signing_status}
          onChange={(event) => set({ signing_status: event.target.value })}
        />
        <Select
          id={`${idPrefix}-obligation`}
          label="Obligations"
          placeholder="Any obligation state"
          options={vocabulary(OBLIGATION_STATUSES)}
          value={value.obligation_status}
          onChange={(event) => set({ obligation_status: event.target.value })}
        />
        <Input
          id={`${idPrefix}-counterparty`}
          label="Counterparty"
          placeholder="Name contains…"
          value={value.counterparty}
          onChange={(event) => set({ counterparty: event.target.value })}
        />
        <Input
          id={`${idPrefix}-owner`}
          label="Owner"
          placeholder="User ID"
          hint="The owner's AICOUNTLY user ID"
          value={value.owner_uuid}
          onChange={(event) => set({ owner_uuid: event.target.value })}
        />
        <Select
          id={`${idPrefix}-autorenew`}
          label="Auto renewal"
          placeholder="Either"
          options={[
            { value: '1', label: 'Auto-renews' },
            { value: '0', label: 'Does not auto-renew' },
          ]}
          value={value.auto_renewal}
          onChange={(event) => set({ auto_renewal: event.target.value })}
        />
        <Select
          id={`${idPrefix}-expiring`}
          label="Expiring within"
          placeholder="Any time"
          options={EXPIRING_WINDOWS.map((days) => ({ value: days, label: `${days} days` }))}
          value={value.expiring_within_days}
          onChange={(event) => set({ expiring_within_days: event.target.value })}
        />
        <Select
          id={`${idPrefix}-archived`}
          label="Archived"
          options={[
            { value: 'no', label: 'Hide archived' },
            { value: 'only', label: 'Archived only' },
            { value: 'all', label: 'Include archived' },
          ]}
          value={value.archived}
          onChange={(event) =>
            set({ archived: event.target.value as ContractFilters['archived'] })
          }
        />

        {showCommercials ? (
          <>
            <Select
              id={`${idPrefix}-currency`}
              label="Currency"
              placeholder="Any currency"
              options={CURRENCIES.map((code) => ({ value: code, label: code }))}
              value={value.currency}
              onChange={(event) => set({ currency: event.target.value })}
            />
            <Input
              id={`${idPrefix}-valmin`}
              label="Value from"
              inputMode="decimal"
              placeholder="0"
              value={value.value_min}
              onChange={(event) => set({ value_min: event.target.value })}
            />
            <Input
              id={`${idPrefix}-valmax`}
              label="Value to"
              inputMode="decimal"
              placeholder="No limit"
              value={value.value_max}
              onChange={(event) => set({ value_max: event.target.value })}
            />
          </>
        ) : null}

        <DateInput
          id={`${idPrefix}-efffrom`}
          label="Effective from"
          value={value.effective_from}
          onChange={(event) => set({ effective_from: event.target.value })}
        />
        <DateInput
          id={`${idPrefix}-effto`}
          label="Effective to"
          value={value.effective_to}
          onChange={(event) => set({ effective_to: event.target.value })}
        />
        <DateInput
          id={`${idPrefix}-expfrom`}
          label="Expires from"
          value={value.expiry_from}
          onChange={(event) => set({ expiry_from: event.target.value })}
        />
        <DateInput
          id={`${idPrefix}-expto`}
          label="Expires to"
          value={value.expiry_to}
          onChange={(event) => set({ expiry_to: event.target.value })}
        />
      </div>

      <Checkbox
        id={`${idPrefix}-fav`}
        label="Favourites only"
        hint="Contracts you have starred"
        checked={value.favourites_only}
        onChange={(event) => set({ favourites_only: event.target.checked })}
      />
    </div>
  )
}

export interface ActiveFilterChip {
  id: string
  label: string
  /** The filter set with this one condition removed. */
  next: ContractFilters
}

const NAMED_LABELS: Partial<Record<keyof ContractFilters, string>> = {
  q: 'Search',
  counterparty: 'Counterparty',
  owner_uuid: 'Owner',
  risk_level: 'Risk',
  approval_status: 'Approval',
  signing_status: 'Signing',
  obligation_status: 'Obligations',
  currency: 'Currency',
  value_min: 'Value from',
  value_max: 'Value to',
  effective_from: 'Effective from',
  effective_to: 'Effective to',
  expiry_from: 'Expires from',
  expiry_to: 'Expires to',
}

/**
 * The filters currently narrowing the list, as removable chips.
 *
 * Every one carries the filter set it would leave behind, so removing a chip is
 * a state replacement rather than a per-key switch statement at the call site.
 */
export function activeFilterChips(
  value: ContractFilters,
  lookups: FilterLookups,
): ActiveFilterChip[] {
  const chips: ActiveFilterChip[] = []

  for (const status of value.status) {
    chips.push({
      id: `status:${status}`,
      label: `Status: ${humanise(status)}`,
      next: { ...value, status: value.status.filter((item) => item !== status) },
    })
  }

  const named = (key: keyof typeof NAMED_LABELS) => {
    const raw = value[key]
    if (typeof raw !== 'string' || raw === '') return
    chips.push({
      id: key,
      label: `${NAMED_LABELS[key]}: ${raw}`,
      next: { ...value, [key]: '' },
    })
  }

  named('q')
  named('counterparty')
  named('owner_uuid')

  if (value.contract_type_id) {
    const match = lookups.contractTypes.find((row) => String(row.id) === value.contract_type_id)
    chips.push({
      id: 'contract_type_id',
      label: `Type: ${match?.name ?? value.contract_type_id}`,
      next: { ...value, contract_type_id: '' },
    })
  }

  if (value.department_id) {
    const match = lookups.departments.find((row) => String(row.id) === value.department_id)
    chips.push({
      id: 'department_id',
      label: `Department: ${match?.name ?? value.department_id}`,
      next: { ...value, department_id: '' },
    })
  }

  if (value.tag_id) {
    const match = lookups.tags.find((row) => String(row.id) === value.tag_id)
    chips.push({
      id: 'tag_id',
      label: `Tag: ${match?.name ?? value.tag_id}`,
      next: { ...value, tag_id: '' },
    })
  }

  if (value.risk_level) {
    chips.push({
      id: 'risk_level',
      label: `Risk: ${humanise(value.risk_level)}`,
      next: { ...value, risk_level: '' },
    })
  }

  for (const key of ['approval_status', 'signing_status', 'obligation_status'] as const) {
    if (!value[key]) continue
    chips.push({
      id: key,
      label: `${NAMED_LABELS[key]}: ${humanise(value[key])}`,
      next: { ...value, [key]: '' },
    })
  }

  if (value.auto_renewal) {
    chips.push({
      id: 'auto_renewal',
      label: value.auto_renewal === '1' ? 'Auto-renews' : 'Does not auto-renew',
      next: { ...value, auto_renewal: '' },
    })
  }

  if (value.expiring_within_days) {
    chips.push({
      id: 'expiring_within_days',
      label: `Expiring within ${value.expiring_within_days} days`,
      next: { ...value, expiring_within_days: '' },
    })
  }

  named('currency')
  named('value_min')
  named('value_max')
  named('effective_from')
  named('effective_to')
  named('expiry_from')
  named('expiry_to')

  if (value.favourites_only) {
    chips.push({
      id: 'favourites_only',
      label: 'Favourites only',
      next: { ...value, favourites_only: false },
    })
  }

  if (value.archived !== 'no') {
    chips.push({
      id: 'archived',
      label: value.archived === 'only' ? 'Archived only' : 'Including archived',
      next: { ...value, archived: 'no' },
    })
  }

  return chips
}

export function hasActiveFilters(value: ContractFilters): boolean {
  return (
    JSON.stringify({ ...value, q: '' }) !== JSON.stringify({ ...EMPTY_CONTRACT_FILTERS, q: '' }) ||
    value.q.trim() !== ''
  )
}

/** The query string `GET /contracts` and `GET /contracts/export` expect. */
export function filtersToQuery(
  value: ContractFilters,
): Record<string, string | number | boolean | string[] | undefined> {
  return {
    q: value.q.trim() || undefined,
    status: value.status.length ? value.status : undefined,
    contract_type_id: value.contract_type_id || undefined,
    department_id: value.department_id || undefined,
    owner_uuid: value.owner_uuid || undefined,
    counterparty: value.counterparty || undefined,
    risk_level: value.risk_level || undefined,
    currency: value.currency || undefined,
    auto_renewal: value.auto_renewal || undefined,
    approval_status: value.approval_status || undefined,
    signing_status: value.signing_status || undefined,
    effective_from: value.effective_from || undefined,
    effective_to: value.effective_to || undefined,
    expiry_from: value.expiry_from || undefined,
    expiry_to: value.expiry_to || undefined,
    value_min: value.value_min || undefined,
    value_max: value.value_max || undefined,
    tag_id: value.tag_id || undefined,
    favourites_only: value.favourites_only ? 1 : undefined,
    expiring_within_days: value.expiring_within_days || undefined,
    obligation_status: value.obligation_status || undefined,
    archived: value.archived,
  }
}
