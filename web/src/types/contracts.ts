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
  /** Approval throughput only. Null where a month had nothing to average. */
  avg_days?: number | null
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
  counterparty_mix: DashboardChartPoint[]
  approval_throughput: DashboardChartPoint[]
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

/* --- Contract workspace: documents and versions --------------------------- */

/**
 * One stored file of a contract document.
 *
 * `status` and `doc_kind` are open vocabularies on the server (a company can be
 * given new ones without a release here), so they are strings rather than
 * unions — the UI humanises whatever arrives instead of dropping a value it has
 * not been taught.
 */
export interface DocumentVersion {
  id: number
  document_id: number
  version_number: number
  status: string | null
  filename: string | null
  content_type: string | null
  size_bytes: number | null
  checksum: string | null
  notes: string | null
  is_current: boolean
  uploaded_by: string | null
  uploaded_by_name: string | null
  executed_at: string | null
  created_at: string
}

/** `GET /contracts/{id}/documents` — a document and every version of it. */
export interface ContractDocument {
  id: number
  contract_id: number
  title: string | null
  doc_kind: string | null
  storage_provider: string | null
  drive_document_id: string | null
  current_version_id: number | null
  created_at: string
  updated_at: string | null
  versions: DocumentVersion[]
}

/** `GET /versions/{id}/url` — a short-lived link to the stored file. */
export interface VersionUrl {
  url: string
  expires_at: string | null
}

/** `POST /uploads/sessions` — where to PUT the bytes, and with which headers. */
export interface UploadSession {
  session_id: string
  upload_url: string
  method: string | null
  headers: Record<string, string> | null
  expires_at: string | null
  storage_provider: string | null
}

/** `POST /uploads/sessions/{id}/finalize`. */
export interface UploadResult {
  document: ContractDocument
  version: DocumentVersion
}

/* --- Contract workspace: version comparison ------------------------------- */

/**
 * One run of text in a comparison.
 *
 * The diff engine names its operation `type` and the AI path names it `op`;
 * a replacement carries both sides. Every field is optional so a segment shape
 * the server adds later renders as unchanged text rather than as nothing.
 */
export interface CompareSegment {
  type?: string | null
  op?: string | null
  text?: string | null
  value?: string | null
  base_text?: string | null
  target_text?: string | null
  section?: string | null
  page?: number | null
}

/** A change the server judged material, e.g. an amount or a liability cap. */
export interface ClassifiedChange {
  id?: number | string | null
  category?: string | null
  severity?: string | null
  title?: string | null
  summary?: string | null
  description?: string | null
  base_value?: string | number | null
  target_value?: string | number | null
  section?: string | null
}

export interface CompareStats {
  added?: number | null
  removed?: number | null
  changed?: number | null
  unchanged?: number | null
  similarity?: number | null
}

/** `GET /contracts/{id}/compare?base=&target=`. */
export interface CompareResult {
  segments: CompareSegment[] | null
  stats: CompareStats | null
  classified: ClassifiedChange[] | null
  ai_explanation: string | null
}

/* --- Contract workspace: parties and links -------------------------------- */

/** `GET /contracts/{id}/parties`. */
export interface ContractParty {
  id: number
  contract_id: number
  party_role: string | null
  name: string
  legal_name: string | null
  contact_uuid: string | null
  contact_id: number | string | null
  email: string | null
  phone: string | null
  address: string | null
  registration_number: string | null
  signatory_name: string | null
  signatory_email: string | null
  signatory_designation: string | null
  is_primary: boolean
  snapshot_at: string | null
  created_at: string | null
}

/**
 * A party as it stood at a point in time.
 *
 * Contacts can be edited after a contract is signed; the snapshot is what the
 * agreement was actually executed against, so `data` is stored verbatim and
 * rendered as it arrives rather than mapped onto today's field names.
 */
export interface PartySnapshot {
  id: number | string
  party_id: number
  data: Record<string, unknown> | null
  captured_by_name: string | null
  created_at: string
}

/** `GET /contracts/{id}/links` — a relationship to another record. */
export interface ContractLink {
  id: number
  contract_id: number
  link_type: string | null
  related_contract_id: number | null
  related_contract_number: string | null
  related_contract_title: string | null
  related_contract_status: string | null
  related_type: string | null
  related_id: number | string | null
  label: string | null
  note: string | null
  created_at: string | null
}

/* --- The work queues ------------------------------------------------------ */

/**
 * A contract request: the business asking for an agreement, before there is
 * one. It survives the conversion, which is why it is a record of its own —
 * "who asked for this, and what did they say it was for" is a question only
 * the request can answer.
 */
export type RequestStatus =
  | 'draft'
  | 'submitted'
  | 'under_review'
  | 'more_info_required'
  | 'approved_for_drafting'
  | 'rejected'
  | 'converted'

export const REQUEST_STATUSES: readonly RequestStatus[] = [
  'draft',
  'submitted',
  'under_review',
  'more_info_required',
  'approved_for_drafting',
  'rejected',
  'converted',
]

/** The statuses a request is still moving through, as one filter value. */
export const OPEN_REQUEST_STATUSES: readonly RequestStatus[] = [
  'submitted',
  'under_review',
  'more_info_required',
]

/** The verdicts `POST /requests/{id}/decision` accepts. */
export type RequestDecision = 'review' | 'approve' | 'more_info' | 'reject'

/** One row of `GET /requests`. */
export interface ContractRequestListItem {
  id: number
  uuid: string
  request_number: string
  title: string
  status: RequestStatus
  requester_uuid: string
  reviewer_uuid: string | null
  required_by_date: string | null
  counterparty_name: string | null
  estimated_value: string | null
  currency: string
  contract_type_id: number | null
  contract_type_name: string | null
  department_id: number | null
  department_name: string | null
  converted_contract_id: number | null
  converted_contract_number: string | null
  converted_at: string | null
  decided_at: string | null
  created_at: string
  updated_at: string
}

/** `GET /requests/{id}` — the row plus everything the list omits. */
export interface ContractRequest extends ContractRequestListItem {
  contact_ref_id: string | null
  purpose: string | null
  business_justification: string | null
  preferred_template_id: number | null
  decision_notes: string | null
  decided_by: string | null
  notes: string | null
  metadata: Record<string, unknown> | null
  activity?: RequestActivityEntry[]
}

/**
 * One entry of a request's timeline.
 *
 * Anchored to the request rather than to a contract, so it reads correctly
 * before conversion — and conversion writes an entry on both sides.
 */
export interface RequestActivityEntry {
  id: number | string
  actor_uuid: string | null
  actor_label: string | null
  event_type: string
  summary: string | null
  icon: string | null
  metadata: Record<string, unknown> | null
  created_at: string
}

/** The body `POST /requests` and `PUT /requests/{id}` accept. */
export interface ContractRequestInput {
  title: string
  contract_type_id: number | null
  department_id: number | null
  required_by_date: string | null
  counterparty_name: string | null
  contact_ref_id: string | null
  purpose: string | null
  business_justification: string | null
  estimated_value: string | null
  currency: string
  preferred_template_id: number | null
  notes: string | null
}

/**
 * One row of `GET /approvals/queue` — a step assigned to the viewer, on an
 * instance that is currently sitting at that step.
 */
export interface ApprovalQueueItem {
  assignment_id: number
  instance_id: number
  instance_uuid: string | null
  step_no: number
  step_name: string | null
  status: string
  assigned_at: string
  due_at: string | null
  escalated_at: string | null
  delegated_from: string | null
  subject_type: string
  subject_id: number
  contract_id: number | null
  workflow_name: string | null
  instance_status: string
  current_step: number
  submitted_by: string | null
  submitted_at: string | null
  contract_number: string | null
  contract_title: string | null
  counterparty_name: string | null
  currency: string | null
  total_value: string | null
  risk_level: RiskLevel | null
  is_overdue: boolean
}

/** The actions `POST /approvals/{id}/act` accepts. */
export type ApprovalActionName =
  | 'approve'
  | 'reject'
  | 'send_back'
  | 'request_changes'
  | 'comment'
  | 'reassign'

export type ObligationOccurrenceStatus = (typeof OBLIGATION_STATUSES)[number]

/**
 * One row of `GET /obligations` — an occurrence, not an obligation.
 *
 * The obligation is the rule ("submit a quarterly SLA report"); the occurrence
 * is the instance that falls due on a date, and it is the instance somebody has
 * to act on.
 */
export interface ObligationOccurrenceRow {
  id: number
  uuid: string
  obligation_id: number
  contract_id: number
  sequence_no: number
  due_date: string
  grace_until: string | null
  period_start: string | null
  period_end: string | null
  status: ObligationOccurrenceStatus
  completed_at: string | null
  completed_by: string | null
  completion_note: string | null
  amount: string | null
  created_at: string
  updated_at: string
  obligation_title: string
  obligation_type: string | null
  responsible_party: 'company' | 'counterparty' | 'both'
  owner_uuid: string | null
  frequency: string | null
  evidence_required: boolean
  grace_period_days: number | null
  currency: string | null
  contract_number: string | null
  contract_title: string | null
  days_to_due: number | null
  /** Computed against today, so a list rendered between sweeps still tells the truth. */
  is_overdue: boolean
}

export const RESPONSIBLE_PARTIES = ['company', 'counterparty', 'both'] as const

export type RenewalStatus =
  | 'not_yet_due'
  | 'review_due'
  | 'under_review'
  | 'renew'
  | 'renegotiate'
  | 'terminate'
  | 'renewal_in_progress'
  | 'renewed'
  | 'closed'

export const RENEWAL_STATUSES: readonly RenewalStatus[] = [
  'not_yet_due',
  'review_due',
  'under_review',
  'renew',
  'renegotiate',
  'terminate',
  'renewal_in_progress',
  'renewed',
  'closed',
]

export type RenewalDecisionName = 'renew' | 'renegotiate' | 'terminate' | 'defer'

export type RenewalRecommendation = 'renew' | 'renegotiate' | 'terminate' | 'review_manually'

/** The `bucket` values `GET /renewals` understands. */
export type RenewalBucket =
  | 'all'
  | 'expiring_30'
  | 'expiring_60'
  | 'expiring_90'
  | 'notice_due'
  | 'auto_renewal_risk'

/** One row of `GET /renewals` — a renewal cycle joined to its contract. */
export interface RenewalPipelineItem {
  id: number
  uuid: string | null
  contract_id: number
  cycle_no: number
  current_expiry: string | null
  notice_deadline: string | null
  decision_due_date: string | null
  proposed_start: string | null
  proposed_expiry: string | null
  renewal_term_months: number | null
  status: RenewalStatus
  owner_uuid: string | null
  recommendation: RenewalRecommendation | null
  recommendation_reason: string | null
  recommendation_source: 'rules' | 'ai' | 'manual' | null
  decision: RenewalDecisionName | null
  decision_by: string | null
  decision_at: string | null
  decision_notes: string | null
  renegotiation_required: boolean
  renewed_contract_id: number | null
  notes: string | null
  created_at: string
  updated_at: string
  contract_number: string | null
  contract_title: string | null
  contract_status: ContractStatus | null
  counterparty_name: string | null
  auto_renewal: boolean
  currency: string | null
  total_value: string | null
  notice_period_days: number | null
  renewal_type: string | null
  renewal_frequency: string | null
  contract_type_id: number | null
  contract_type_name: string | null
  days_to_decision: number | null
  days_to_notice: number | null
  days_to_expiry: number | null
}

export type AmendmentStatus =
  | 'draft'
  | 'under_review'
  | 'awaiting_approval'
  | 'awaiting_signature'
  | 'executed'
  | 'cancelled'

export const AMENDMENT_STATUSES: readonly AmendmentStatus[] = [
  'draft',
  'under_review',
  'awaiting_approval',
  'awaiting_signature',
  'executed',
  'cancelled',
]

/**
 * One row of `GET /amendments` — the portfolio-wide register.
 *
 * The contract columns are optional because the register is a join the API
 * composes; a row that arrives without them still renders, minus the link.
 */
export interface AmendmentRegisterItem {
  id: number
  contract_id: number
  amendment_no: number
  title: string
  description: string | null
  effective_date: string | null
  execution_date: string | null
  status: AmendmentStatus
  affected_fields: Record<string, { from?: unknown; to?: unknown }> | null
  applied_at: string | null
  applied_by: string | null
  created_by: string | null
  created_at: string
  updated_at: string
  contract_number?: string | null
  contract_title?: string | null
  counterparty_name?: string | null
  contract_status?: ContractStatus | null
}

export type RiskSeverity = 'informational' | 'low' | 'medium' | 'high' | 'critical'

export const RISK_SEVERITIES: readonly RiskSeverity[] = [
  'critical',
  'high',
  'medium',
  'low',
  'informational',
]

export const RISK_CATEGORIES = [
  'legal',
  'commercial',
  'financial',
  'compliance',
  'operational',
  'data_protection',
  'renewal',
  'counterparty',
  'sla',
] as const

export type RiskCategory = (typeof RISK_CATEGORIES)[number]

export type RiskReviewStatus = 'open' | 'accepted' | 'mitigated' | 'false_positive' | 'resolved'

export const RISK_REVIEW_STATUSES: readonly RiskReviewStatus[] = [
  'open',
  'accepted',
  'mitigated',
  'false_positive',
  'resolved',
]

/** One row of `GET /risks` — a finding, with the contract it was raised against. */
export interface PortfolioRiskFinding {
  id: number
  contract_id: number
  rule_key: string | null
  risk_category: RiskCategory | string
  severity: RiskSeverity
  title: string
  detail: string | null
  recommendation: string | null
  source_excerpt: string | null
  source_page: number | null
  detected_by: 'rules' | 'ai' | 'manual'
  ai_confidence: string | number | null
  review_status: RiskReviewStatus
  created_at: string
  contract_number: string | null
  contract_title: string | null
  counterparty_name: string | null
  contract_status: ContractStatus | null
}

/* --- Templates ------------------------------------------------------------ */

export type TemplateStatus = 'draft' | 'active' | 'deprecated'

export const TEMPLATE_STATUSES: readonly TemplateStatus[] = ['draft', 'active', 'deprecated']

/** A row of `GET /templates`. */
export interface TemplateSummary {
  id: number
  uuid: string
  name: string
  description: string | null
  contract_type_id: number | null
  contract_type_name?: string | null
  status: TemplateStatus
  version: number
  approval_status: string
  owner_uuid: string | null
  /** The merge keys the saved body uses, recomputed server-side on every save. */
  variables: string[]
  tags?: string[]
  archived_at: string | null
  created_at: string
  updated_at: string
}

/**
 * One superseded body of a template.
 *
 * Kept rather than overwritten: a contract drafted in March was drafted from
 * the wording as it stood in March, and answering "what did this produce then"
 * is only possible if the old body is still here.
 */
export interface TemplateVersion {
  id: number
  template_id: number
  version: number
  body: string
  variables: string[]
  change_note: string | null
  author_uuid: string | null
  author_name?: string | null
  created_at: string
}

/** `GET /templates/{id}`. */
export interface TemplateDetail extends TemplateSummary {
  body: string
  header_html: string | null
  footer_html: string | null
  optional_clauses?: unknown[]
  signature_blocks?: unknown[]
  schedules?: unknown[]
  versions?: TemplateVersion[]
}

/** The body `POST /templates` and `PUT /templates/{id}` accept. */
export interface TemplateInput {
  name: string
  description: string | null
  contract_type_id: number | null
  status: TemplateStatus
  body: string
  change_note?: string | null
}

/** One entry of the merge registry, `GET /template-variables`. */
export interface TemplateVariable {
  id: number
  var_key: string
  label: string
  source: 'company' | 'counterparty' | 'contract' | 'commercial' | 'custom' | 'system'
  source_path: string
  data_type: string
  example: string | null
  is_system: boolean
}

/**
 * A resolved or unresolved variable in a preview.
 *
 * `POST /templates/{id}/preview` reports `used` and `missing` as lists of keys;
 * an object form carrying the label and the resolved value is accepted too, so
 * a richer payload renders as more detail rather than as `[object Object]`.
 */
export interface PreviewVariable {
  key?: string | null
  var_key?: string | null
  label?: string | null
  value?: string | null
}

/** `POST /templates/{id}/preview`. */
export interface TemplatePreview {
  html: string | null
  missing: (string | PreviewVariable)[] | null
  used: (string | PreviewVariable)[] | null
}

/* --- Clause library ------------------------------------------------------- */

export type ClauseApprovalStatus = 'draft' | 'approved' | 'deprecated'

export const CLAUSE_APPROVAL_STATUSES: readonly ClauseApprovalStatus[] = [
  'draft',
  'approved',
  'deprecated',
]

/** `GET /clause-categories`. */
export interface ClauseCategory {
  id: number
  code: string
  name: string
  description: string | null
  risk_weight: number
  is_system: boolean
  sort_order: number
  /** Present when the API counts the library for each category. */
  clause_count?: number | null
}

/** A row of `GET /clauses` — the approved wording, not a contract's copy of it. */
export interface LibraryClauseItem {
  id: number
  uuid: string
  category_id: number | null
  category_name?: string | null
  name: string
  description: string | null
  standard_text: string
  fallback_text: string | null
  prohibited_wording: string | null
  risk_classification: RiskLevel
  /** Contract type ids this wording applies to; empty means every type. */
  applicable_types: (number | string)[]
  jurisdiction: string | null
  version: number
  approval_status: ClauseApprovalStatus
  effective_from: string | null
  effective_to: string | null
  author_uuid: string | null
  approver_uuid: string | null
  approved_at: string | null
  is_system: boolean
  archived_at: string | null
  created_at: string
  updated_at: string
}

/** `GET /clauses/{id}/versions` — superseded wording, kept verbatim. */
export interface LibraryClauseVersion {
  id: number
  clause_id: number
  version: number
  standard_text: string
  fallback_text: string | null
  change_note: string | null
  author_uuid: string | null
  author_name?: string | null
  created_at: string
}

/** The body `POST /clauses` and `PUT /clauses/{id}` accept. */
export interface LibraryClauseInput {
  name: string
  description: string | null
  category_id: number | null
  standard_text: string
  fallback_text: string | null
  prohibited_wording: string | null
  risk_classification: RiskLevel
  applicable_types: number[]
  jurisdiction: string | null
  approval_status: ClauseApprovalStatus
  effective_from: string | null
  effective_to: string | null
  change_note?: string | null
}

/* --- AI ------------------------------------------------------------------- */

/** `GET /ai/status`. Reported honestly; Console owns the provider credentials. */
export interface AiStatus {
  configured: boolean
  provider?: string | null
  model?: string | null
  source?: string | null
  message?: string | null
  disclaimer?: string | null
}

export type AiJobStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'cancelled'

/** A row of `GET /ai/jobs`. */
export interface AiJobRow {
  id: number
  uuid?: string
  kind: string
  contract_id: number | null
  contract_number?: string | null
  contract_title?: string | null
  version_id: number | null
  status: AiJobStatus
  attempts: number
  max_attempts: number
  provider: string | null
  model: string | null
  error_code: string | null
  error_message: string | null
  requested_by: string | null
  started_at: string | null
  completed_at: string | null
  created_at: string
  updated_at?: string | null
}

export type ExtractionReviewState = 'pending' | 'accepted' | 'edited' | 'rejected'

/**
 * One field a model read out of a document.
 *
 * `confidence` is 0–1 and may be absent; a missing confidence is treated as the
 * least certain case, never as certainty.
 */
export interface AiExtractionRow {
  id: number
  job_id: number | null
  contract_id: number
  contract_number?: string | null
  contract_title?: string | null
  version_id: number | null
  field_key: string
  field_label: string | null
  extracted_value: string | null
  normalised_value: string | null
  value_type: string
  confidence: number | null
  source_page: number | null
  source_excerpt: string | null
  review_state: ExtractionReviewState
  accepted_value: string | null
  reviewed_by: string | null
  reviewed_at: string | null
  created_at: string
}

/** `POST /ai/contracts/{id}/apply-verified`. */
export interface ApplyVerifiedResult {
  contract?: Contract | null
  /** A count in the contract, a column → value map from the service. */
  applied?: number | Record<string, unknown> | null
  skipped?: Record<string, string> | null
}

/* --- Reports -------------------------------------------------------------- */

/**
 * The only filters `GET /reports/{key}` reads.
 *
 * ReportController drops every other query parameter rather than passing it on,
 * so a control the catalogue asks for outside this set would look like a filter
 * and quietly do nothing. The report screen renders from this list, and a
 * definition names the subset that applies to it.
 */
export const REPORT_FILTER_KEYS = [
  'contract_type_id',
  'department_id',
  'owner_uuid',
  'counterparty',
  'status',
  'risk_level',
  'date_from',
  'date_to',
] as const

export type ReportFilterKey = (typeof REPORT_FILTER_KEYS)[number]

export type ReportFilterValues = Partial<Record<ReportFilterKey, string>>

export type ReportColumnType =
  | 'text'
  | 'number'
  | 'money'
  | 'percent'
  | 'date'
  | 'datetime'
  | 'boolean'
  | 'status'
  | 'risk'

/**
 * One column of a report, as the catalogue describes it.
 *
 * `label`/`header` and the loose `type` are both accepted because the catalogue
 * is server-side data rather than a compiled contract: a report added after this
 * file was written must still render, with an unknown type falling back to text
 * rather than throwing the screen away.
 */
export interface ReportColumnDefinition {
  key: string
  label?: string | null
  header?: string | null
  type?: ReportColumnType | string | null
  align?: 'left' | 'right' | 'center' | null
  width?: number | string | null
  /** Row field holding the currency for a money column. */
  currency_key?: string | null
  /** Row field holding a contract id, so the cell can link to the record. */
  link_key?: string | null
  /** Hidden below this viewport width, so a phone shows the columns that matter. */
  hide_below?: 'sm' | 'md' | 'lg' | null
}

/** Columns as `GET /reports/{key}` may spell them. */
export type ReportColumnsPayload =
  | ReportColumnDefinition[]
  | string[]
  | Record<string, string>

/** One entry of `GET /reports`. */
export interface ReportDefinition {
  key: string
  name?: string | null
  title?: string | null
  description?: string | null
  group?: string | null
  category?: string | null
  /** Which of REPORT_FILTER_KEYS this report accepts. */
  filters?: string[] | null
  columns?: ReportColumnsPayload | null
  default_filters?: ReportFilterValues | null
}

/** A report row is whatever its columns say; the definition is the schema. */
export type ReportRow = Record<string, unknown>

/** Totals and counts the report carries alongside its rows. */
export type ReportSummary = Record<string, unknown>

/** `GET /reports/{key}` — a page of rows, plus the columns to read them with. */
export interface ReportResult extends Paged<ReportRow> {
  columns?: ReportColumnsPayload | null
  rows?: ReportRow[] | null
  summary?: ReportSummary | null
}

/* --- Notifications -------------------------------------------------------- */

/** Mirrors ck_notification_severity. */
export const NOTIFICATION_SEVERITIES = ['info', 'success', 'warning', 'critical'] as const

export type NotificationSeverity = (typeof NOTIFICATION_SEVERITIES)[number]

/** One row of `GET /notifications`. */
export interface NotificationItem {
  id: number
  uuid?: string | null
  event_type: string
  title: string
  body?: string | null
  severity: NotificationSeverity | string
  contract_id?: number | null
  contract_number?: string | null
  contract_title?: string | null
  link_path?: string | null
  metadata?: Record<string, unknown> | null
  is_read?: boolean
  read_at?: string | null
  email_sent_at?: string | null
  created_at: string
}

/** The unread count rides along with the page so the bell and the list agree. */
export interface NotificationPage extends Paged<NotificationItem> {
  unread?: number
}

/* --- Global search -------------------------------------------------------- */

export interface SearchContractHit {
  id: number
  contract_number?: string | null
  title: string
  counterparty_name?: string | null
  status?: string | null
  contract_type_name?: string | null
  snippet?: string | null
}

export interface SearchClauseHit {
  id: number
  title: string
  category_name?: string | null
  approval_status?: string | null
  snippet?: string | null
  /** Present when the hit is a clause inside a contract rather than a library entry. */
  contract_id?: number | null
  contract_title?: string | null
}

export interface SearchDocumentHit {
  id: number
  title?: string | null
  filename?: string | null
  doc_kind?: string | null
  version_id?: number | null
  contract_id?: number | null
  contract_number?: string | null
  contract_title?: string | null
  snippet?: string | null
}

/** `GET /search?q=` — three lists the header box searches across. */
export interface SearchResults {
  contracts?: SearchContractHit[] | null
  clauses?: SearchClauseHit[] | null
  documents?: SearchDocumentHit[] | null
  total?: number | null
}

/* --- Settings ------------------------------------------------------------- */

/** The `contract_settings` row, from `GET /settings`. */
export interface ContractSettings {
  id?: number
  number_prefix: string
  number_pad: number
  number_include_year: boolean
  number_reset_yearly: boolean
  default_currency: string
  default_notice_days: number
  /** A descending ladder of days, e.g. "90,60,30,15,7". */
  expiry_alert_days: string
  obligation_alert_days: string
  approval_escalation_days: number
  ai_enabled: boolean
  ai_auto_extract: boolean
  ai_auto_risk: boolean
  default_role: string
  settings_json?: Record<string, unknown> | null
  created_at?: string | null
  updated_at?: string | null
}

/** What the next contract and request will be numbered. */
export interface NumberingPreview {
  contract?: string | null
  request?: string | null
}

export interface SettingsPayload {
  settings: ContractSettings
  numbering_preview?: NumberingPreview | null
}

export const COUNTERPARTY_SIDES = ['customer', 'vendor', 'internal', 'either'] as const

/** A configured contract type, in full, from `GET /settings/contract-types`. */
export interface ContractTypeRow {
  id: number
  uuid?: string | null
  code: string
  name: string
  description?: string | null
  category: string
  counterparty_side: string
  default_renewal_type?: string | null
  default_notice_days?: number | null
  default_term_months?: number | null
  /** Opaque to this screen, and resent on save so a PUT does not clear it. */
  required_fields?: unknown[] | null
  mandatory_clauses?: unknown[] | null
  default_template_id?: number | null
  approval_workflow_id?: number | null
  is_system?: boolean
  is_active: boolean
  sort_order: number
}

/** A department, in full, from `GET /settings/departments`. */
export interface DepartmentRow {
  id: number
  name: string
  code: string
  head_uuid?: string | null
  is_active: boolean
  created_at?: string | null
}

/** Mirrors ck_custom_fields_type. */
export const CUSTOM_FIELD_TYPES = [
  'text',
  'textarea',
  'number',
  'currency',
  'date',
  'boolean',
  'select',
  'multi_select',
  'contact_reference',
  'user_reference',
] as const

export type CustomFieldType = (typeof CUSTOM_FIELD_TYPES)[number]

/** A custom field definition row, from `GET /settings/custom-fields`. */
export interface CustomFieldRow {
  id: number
  field_key: string
  label: string
  field_type: string
  contract_type_id?: number | null
  options?: string[] | null
  is_required: boolean
  is_filterable: boolean
  help_text?: string | null
  sort_order: number
  is_active: boolean
}

/** A tag row, with how many contracts carry it. */
export interface TagRow {
  id: number
  name: string
  colour?: string | null
  usage_count?: number
  created_at?: string | null
}

/** The palette a tag colour is chosen from; the server stores a token, not CSS. */
export const TAG_COLOURS = [
  'slate',
  'green',
  'blue',
  'amber',
  'red',
  'violet',
  'teal',
  'pink',
] as const

/** Mirrors RiskEngine::SUBJECTS. */
export const RISK_RULE_SUBJECTS = [
  'clause_text',
  'clause_missing',
  'liability_cap',
  'auto_renewal',
  'notice_period',
  'governing_law',
  'jurisdiction',
  'payment_terms',
  'contract_value',
  'duration_months',
  'termination_right',
  'indemnity',
  'data_protection',
  'insurance',
  'sla_defined',
  'expiry_date',
  'counterparty_missing',
  'signature_missing',
  'document_missing',
] as const

/** Mirrors RiskEngine::OPERATORS. */
export const RISK_RULE_OPERATORS = [
  'contains',
  'not_contains',
  'equals',
  'not_equals',
  'greater_than',
  'less_than',
  'in_list',
  'not_in_list',
  'is_true',
  'is_false',
  'is_null',
  'is_not_null',
  'regex',
] as const

/** Operators that compare against nothing — the subject alone decides. */
export const UNARY_RISK_OPERATORS = ['is_true', 'is_false', 'is_null', 'is_not_null'] as const

/** A configured risk rule, from `GET /settings/risk-rules`. */
export interface RiskRuleRow {
  id: number
  rule_key: string
  name: string
  description?: string | null
  risk_category: string
  severity: string
  subject: string
  operator: string
  value_text?: string | null
  value_numeric?: string | number | null
  value_list?: string[] | null
  applies_to_types?: number[] | null
  score_weight: number
  recommendation?: string | null
  is_system?: boolean
  is_active: boolean
}

/** A built-in role and what it grants. */
export interface RoleDefinition {
  slug: string
  label: string
  description: string
  permissions: string[]
}

/** One person's roles in this company. */
export interface RoleGrant {
  user_uuid: string
  roles: string[]
  granted_at?: string | null
}

/** `GET /settings/roles`. */
export interface RolesPayload {
  roles: RoleDefinition[]
  grants: RoleGrant[]
  permissions?: string[] | null
  default_role?: string | null
}

/** One dependency, and whether it is wired up. */
export interface IntegrationStatus {
  configured: boolean
  provider?: string | null
  detail?: string | null
}

/** `GET /settings/integrations`. */
export interface IntegrationsPayload {
  manage?: IntegrationStatus
  contacts?: IntegrationStatus
  drive?: IntegrationStatus
  console?: IntegrationStatus
  signature?: IntegrationStatus
  email?: IntegrationStatus
  ai?: AiStatus
}

export const APPROVAL_WORKFLOW_SUBJECTS = [
  'contract',
  'request',
  'amendment',
  'termination',
  'renewal',
] as const

export const APPROVER_TYPES = [
  'user',
  'role',
  'department_head',
  'contract_owner',
  'manager',
] as const

export type ApproverType = (typeof APPROVER_TYPES)[number]

/** One step of an approval workflow. */
export interface ApprovalWorkflowStep {
  id?: number
  step_no: number
  name: string
  execution: 'sequential' | 'parallel' | string
  approver_type: ApproverType | string
  approver_value?: string | null
  min_approvals: number
  can_edit?: boolean
  escalation_days?: number | null
  escalate_to_uuid?: string | null
}

/** A routing rule, from `GET /approval-workflows`. */
export interface ApprovalWorkflow {
  id: number
  uuid?: string | null
  name: string
  description?: string | null
  applies_to: string
  /** Opaque to the editor, and resent on save so a PUT does not clear it. */
  conditions?: unknown[] | null
  match_mode: 'all' | 'any' | string
  priority: number
  is_active: boolean
  escalation_days?: number | null
  steps?: ApprovalWorkflowStep[] | null
  created_at?: string | null
  updated_at?: string | null
}

/** A negotiation playbook, from `GET /playbooks`. */
export interface PlaybookSummary {
  id: number
  uuid?: string | null
  name: string
  description?: string | null
  contract_type_id?: number | null
  contract_type_name?: string | null
  is_default: boolean
  is_active: boolean
  rule_count?: number | null
  created_at?: string | null
}

/** Mirrors ck_playbook_rules_type. */
export const PLAYBOOK_RULE_TYPES = [
  'mandatory_clause',
  'prohibited_clause',
  'preferred_wording',
  'max_numeric',
  'min_numeric',
  'allowed_list',
  'prohibited_list',
  'boolean_flag',
  'date_window',
] as const

/** One playbook rule, from `GET /playbooks/{id}/rules`. */
export interface PlaybookRule {
  id: number
  playbook_id: number
  rule_key: string
  category_id?: number | null
  rule_type: string
  label: string
  description?: string | null
  operator?: string | null
  expected_value?: string | null
  expected_numeric?: string | number | null
  expected_list?: string[] | null
  severity: string
  risk_category: string
  recommendation?: string | null
  is_active: boolean
  sort_order: number
}
