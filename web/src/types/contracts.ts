/**
 * Payload shapes for the Contracts API.
 *
 * These mirror `docs/API_CONTRACT.md`. They are hand-written rather than
 * generated because the PHP API has no schema to generate from — which means a
 * field added server-side has to be added here too, and a field the API stops
 * sending is a compile error at every call site rather than an `undefined` that
 * renders as "undefined" in a table cell.
 *
 * Anything a second screen could plausibly need lives here. Screen-only shapes
 * stay next to the screen.
 */

/** The envelope every paged endpoint returns. */
export interface Paged<T> {
  items: T[]
  total: number
  page: number
  per_page: number
  total_pages: number
}

export type ContractStatus =
  | 'draft'
  | 'under_review'
  | 'awaiting_approval'
  | 'approved'
  | 'negotiation'
  | 'awaiting_signature'
  | 'active'
  | 'renewal_review'
  | 'expired'
  | 'terminated'
  | 'cancelled'

export type RiskLevel = 'low' | 'medium' | 'high' | 'critical'

export type ApprovalStatus = 'not_required' | 'pending' | 'in_progress' | 'approved' | 'rejected'

export type SigningStatus =
  | 'not_started'
  | 'sent'
  | 'viewed'
  | 'partially_signed'
  | 'signed'
  | 'declined'

export interface ContractListItem {
  id: number
  uuid: string
  contract_number: string
  title: string
  status: ContractStatus
  lifecycle_stage: string | null
  counterparty_name: string | null
  effective_date: string | null
  expiry_date: string | null
  notice_deadline: string | null
  renewal_type: string | null
  auto_renewal: boolean
  currency: string
  total_value: string | null
  risk_level: RiskLevel | null
  ai_risk_score: number | null
  health_score: number | null
  owner_uuid: string | null
  approval_status: ApprovalStatus | null
  signing_status: SigningStatus | null
  archived_at: string | null
  contract_type_id: number | null
  contract_type_name: string | null
  department_id: number | null
  department_name: string | null
  is_favourite: boolean
  days_to_expiry: number | null
  days_to_notice: number | null
  created_at: string
  updated_at: string
}

/** The per-tab row counts a contract carries, so tabs can show a badge. */
export interface ContractTabCounts {
  parties: number
  documents: number
  clauses: number
  obligations: number
  milestones: number
  approvals: number
  versions: number
  amendments: number
  risks: number
  comments: number
  links: number
}

export interface Contract extends ContractListItem {
  description: string | null
  commencement_date: string | null
  execution_date: string | null
  renewal_frequency: string | null
  notice_period_days: number | null
  governing_law: string | null
  jurisdiction: string | null
  recurring_value: string | null
  payment_frequency: string | null
  billing_frequency: string | null
  commercial_summary: string | null
  notes: string | null
  custom_fields: Record<string, unknown> | null
  verification_state: string | null
  parent_contract_id: number | null
  request_id: number | null
  template_id: number | null
  created_by: string | null
  updated_by: string | null
  tabs: ContractTabCounts
}

/** A configured contract type, from `GET /settings/contract-types`. */
export interface ContractTypeSummary {
  id: number
  name: string
  code?: string | null
  is_active?: boolean
}

/** A department, from `GET /settings/departments`. */
export interface DepartmentSummary {
  id: number
  name: string
  code?: string | null
  is_active?: boolean
}

/** One entry in an activity or audit stream. */
export interface ActivityEntry {
  id: number | string
  action: string
  description?: string | null
  actor_name?: string | null
  actor_uuid?: string | null
  subject_type?: string | null
  subject_id?: number | string | null
  contract_id?: number | null
  contract_number?: string | null
  contract_title?: string | null
  created_at: string
}

/* --- Dashboard ------------------------------------------------------------ */

/**
 * `GET /dashboard/kpis`.
 *
 * Every figure is nullable: a company with no commercial permission gets the
 * money fields withheld rather than zeroed, and a zero is a real answer that
 * must not be confused with "not available to you".
 */
export interface DashboardKpis {
  total_contracts: number | null
  active: number | null
  draft: number | null
  awaiting_approval: number | null
  awaiting_signature: number | null
  expiring_soon: number | null
  renewals_due: number | null
  obligations_due: number | null
  overdue_obligations: number | null
  high_risk: number | null
  total_value: string | number | null
  receivable_commitments: string | number | null
  payable_commitments: string | number | null
  /** Currency the three money figures are expressed in. */
  currency?: string | null
  /** The window "Expiring Soon" counted, so the tile can say which one. */
  expiring_within_days?: number | null
}

/**
 * One bucket of a dashboard chart.
 *
 * The API names its bucket fields per series — a month bucket carries `month`,
 * a category bucket carries `label`, a money series carries `amount` where a
 * count series carries `count`. Rather than ten near-identical interfaces,
 * every field is optional and `toChartSeries` reads whichever arrived.
 */
export interface DashboardChartPoint {
  key?: string | number | null
  label?: string | null
  name?: string | null
  month?: string | null
  period?: string | null
  value?: number | string | null
  count?: number | string | null
  total?: number | string | null
  amount?: number | string | null
  currency?: string | null
}

/** `GET /dashboard/charts`. */
export interface DashboardCharts {
  by_status: DashboardChartPoint[]
  by_type: DashboardChartPoint[]
  by_department: DashboardChartPoint[]
  value_by_category: DashboardChartPoint[]
  expiry_timeline: DashboardChartPoint[]
  renewal_pipeline: DashboardChartPoint[]
  risk_distribution: DashboardChartPoint[]
  obligations_timeline: DashboardChartPoint[]
  customer_vs_vendor: DashboardChartPoint[]
  monthly_executed: DashboardChartPoint[]
}

/** One row of `GET /dashboard/my-actions`. */
export interface MyActionItem {
  id: number | string
  contract_id?: number | null
  contract_number?: string | null
  contract_title?: string | null
  title?: string | null
  description?: string | null
  due_date?: string | null
  days_remaining?: number | null
  status?: string | null
  risk_level?: RiskLevel | string | null
  amount?: string | number | null
  currency?: string | null
}

export interface MyActions {
  approvals: MyActionItem[]
  obligations: MyActionItem[]
  renewals: MyActionItem[]
  ai_reviews: MyActionItem[]
}

/** What the dashboard is narrowed to. Company is implicit in the API headers. */
export interface DashboardFilterState {
  branch_id: string
  contract_type_id: string
  department_id: string
  owner_uuid: string
  counterparty: string
  status: string
  risk_level: string
  /** A preset window; resolved to `effective_from`/`effective_to` before it is sent. */
  period: DashboardPeriod
}

export type DashboardPeriod = 'all' | 'last_30' | 'last_90' | 'last_12m' | 'financial_year'

/* --- Repository ----------------------------------------------------------- */

/**
 * The vocabularies the repository and the contract form offer.
 *
 * Derived from the unions above where one exists, so a status added to the type
 * is a compile error here until it is added to the list a user can pick from.
 * The lists with no union of their own are the ones the server owns without an
 * endpoint to read them from: a value it rejects comes back as a 422 on the
 * field, which is where the user can see it.
 */
export const CONTRACT_STATUSES: readonly ContractStatus[] = [
  'draft',
  'under_review',
  'awaiting_approval',
  'approved',
  'negotiation',
  'awaiting_signature',
  'active',
  'renewal_review',
  'expired',
  'terminated',
  'cancelled',
]

export const RISK_LEVELS: readonly RiskLevel[] = ['low', 'medium', 'high', 'critical']

export const APPROVAL_STATUSES: readonly ApprovalStatus[] = [
  'not_required',
  'pending',
  'in_progress',
  'approved',
  'rejected',
]

export const SIGNING_STATUSES: readonly SigningStatus[] = [
  'not_started',
  'sent',
  'viewed',
  'partially_signed',
  'signed',
  'declined',
]

export const OBLIGATION_STATUSES = [
  'upcoming',
  'due',
  'overdue',
  'completed',
  'waived',
  'disputed',
  'not_applicable',
] as const

export const RENEWAL_TYPES = ['none', 'auto', 'manual', 'evergreen'] as const

export const RENEWAL_FREQUENCIES = [
  'monthly',
  'quarterly',
  'half_yearly',
  'annual',
  'biennial',
] as const

export const PAYMENT_FREQUENCIES = [
  'one_time',
  'monthly',
  'quarterly',
  'half_yearly',
  'annual',
  'milestone',
] as const

/** The codes AICOUNTLY companies transact in, most common first. */
export const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'AED', 'SGD', 'AUD', 'CAD', 'JPY'] as const

/** A tag, from `GET /settings/tags`. */
export interface TagSummary {
  id: number
  name: string
  colour?: string | null
}

/**
 * A custom field definition, from `GET /settings/custom-fields`.
 *
 * Settings has spelled the same idea two ways across the AICOUNTLY fleet
 * (`name`/`label`, `field_type`/`type`), so both are accepted and normalised
 * where they are rendered rather than assumed correct here.
 */
export interface CustomFieldDefinition {
  id: number | string
  key?: string | null
  code?: string | null
  name?: string | null
  label?: string | null
  field_type?: string | null
  type?: string | null
  options?: string[] | { value: string; label: string }[] | null
  is_required?: boolean
  required?: boolean
  help_text?: string | null
  hint?: string | null
  is_active?: boolean
}

/** A row from Contacts, proxied through `GET /counterparties/search`. */
export interface CounterpartyContact {
  id?: number | string
  uuid?: string | null
  name: string
  organisation?: string | null
  organization?: string | null
  company_name?: string | null
  email?: string | null
  phone?: string | null
}

/**
 * Everything `GET /contracts` accepts.
 *
 * Held as strings because that is what the controls produce and what the query
 * string carries; the empty string means "not filtered" throughout, so one
 * check clears any field.
 */
export interface ContractFilters {
  q: string
  status: string[]
  contract_type_id: string
  department_id: string
  owner_uuid: string
  counterparty: string
  risk_level: string
  currency: string
  auto_renewal: string
  approval_status: string
  signing_status: string
  effective_from: string
  effective_to: string
  expiry_from: string
  expiry_to: string
  value_min: string
  value_max: string
  tag_id: string
  favourites_only: boolean
  expiring_within_days: string
  obligation_status: string
  archived: 'no' | 'only' | 'all'
}

export const EMPTY_CONTRACT_FILTERS: ContractFilters = {
  q: '',
  status: [],
  contract_type_id: '',
  department_id: '',
  owner_uuid: '',
  counterparty: '',
  risk_level: '',
  currency: '',
  auto_renewal: '',
  approval_status: '',
  signing_status: '',
  effective_from: '',
  effective_to: '',
  expiry_from: '',
  expiry_to: '',
  value_min: '',
  value_max: '',
  tag_id: '',
  favourites_only: false,
  expiring_within_days: '',
  obligation_status: '',
  archived: 'no',
}

/** The sort keys `GET /contracts` understands. */
export const CONTRACT_SORT_KEYS = [
  'updated_at',
  'created_at',
  'title',
  'contract_number',
  'counterparty',
  'effective_date',
  'expiry_date',
  'total_value',
  'risk',
  'status',
] as const

export type ContractSortKey = (typeof CONTRACT_SORT_KEYS)[number]

export interface ContractSort {
  key: ContractSortKey
  dir: 'asc' | 'desc'
}

/** The body `POST /contracts` and `PUT /contracts/{id}` accept. */
export interface ContractInput {
  title: string
  contract_type_id: number | null
  department_id: number | null
  counterparty_name: string
  description: string | null
  effective_date: string | null
  commencement_date: string | null
  execution_date: string | null
  expiry_date: string | null
  renewal_type: string | null
  renewal_frequency: string | null
  auto_renewal: boolean
  notice_period_days: number | null
  currency: string
  total_value: string | null
  recurring_value: string | null
  payment_frequency: string | null
  billing_frequency: string | null
  commercial_summary: string | null
  governing_law: string | null
  jurisdiction: string | null
  risk_level: string | null
  notes: string | null
  custom_fields: Record<string, unknown>
}
