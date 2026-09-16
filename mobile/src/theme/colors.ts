/**
 * Brand palette shared conceptually with web/ (see web/src/index.css
 * --color-primary: 37 176 3, i.e. #25B003 — the same AICOUNTLY green every
 * product in the ecosystem uses). Mirrored here, not imported: web is a
 * separate Vite/DOM app and mobile has no build-time access to its CSS
 * variables. Unlike web, mobile ships light mode and the default accent only
 * — no dark mode, no alternate accent themes — the same scope-down books'
 * own mobile app made (see mobile/README.md's own "Out of scope" section).
 */
export const colors = {
  primary: '#25B003',
  primaryDark: '#1E8F03',
  primaryLight: '#E9FCE9',
  background: '#FFFFFF',
  surface: '#F7F9F7',
  border: '#E3E8E3',
  borderSoft: '#EDF0ED',
  textPrimary: '#1A1F1A',
  textSecondary: '#5B6B5B',
  textMuted: '#8A968A',
  danger: '#DC2626',
  dangerSoft: '#FDEDED',
  warning: '#D97706',
  warningSoft: '#FFF3EA',
  success: '#25B003',
  successSoft: '#E9FCE9',
  info: '#2563EB',
  infoSoft: '#EAF4FF',
  violet: '#7A42F4',
  violetSoft: '#F3EDFF',
  track: '#F0F2F0',
} as const;

export type ColorToken = keyof typeof colors;

/**
 * Mirrors RiskSeverity in web/src/types/contracts.ts. Ordered as a
 * good/warning/serious/critical status scale (see the dataviz skill's status
 * palette) rather than arbitrary hues — "low" reads as the green/good end,
 * not as an unrelated categorical color, so a glance at the dot alone still
 * carries the right direction even before the label is read.
 */
export const riskSeverityColors: Record<string, { fg: string; bg: string }> = {
  critical: { fg: '#B91C1C', bg: '#FDECEC' },
  high: { fg: '#C2410C', bg: '#FFEDE0' },
  medium: { fg: '#B45309', bg: '#FFF3EA' },
  low: { fg: '#15803D', bg: '#E9FCE9' },
  informational: { fg: '#5B6B5B', bg: '#F0F2F0' },
};

/** Same scale, for RiskLevel (contract-level, four buckets — no "informational"). */
export const riskLevelColors: Record<string, { fg: string; bg: string }> = {
  critical: { fg: '#B91C1C', bg: '#FDECEC' },
  high: { fg: '#C2410C', bg: '#FFEDE0' },
  medium: { fg: '#B45309', bg: '#FFF3EA' },
  low: { fg: '#15803D', bg: '#E9FCE9' },
};

/** Mirrors ContractStatus in web/src/types/contracts.ts. */
export const contractStatusColors: Record<string, { fg: string; bg: string }> = {
  draft: { fg: '#5B6B5B', bg: '#F0F2F0' },
  under_review: { fg: '#2563EB', bg: '#EAF4FF' },
  awaiting_approval: { fg: '#D97706', bg: '#FFF3EA' },
  approved: { fg: '#1E8F03', bg: '#E9FCE9' },
  negotiation: { fg: '#7A42F4', bg: '#F3EDFF' },
  awaiting_signature: { fg: '#D97706', bg: '#FFF3EA' },
  active: { fg: '#25B003', bg: '#E9FCE9' },
  renewal_review: { fg: '#7A42F4', bg: '#F3EDFF' },
  expired: { fg: '#DC2626', bg: '#FDEDED' },
  terminated: { fg: '#5B6B5B', bg: '#F0F2F0' },
  cancelled: { fg: '#5B6B5B', bg: '#F0F2F0' },
};

/** Mirrors ApprovalStatus / RequestStatus / RenewalStatus / AmendmentStatus loosely by meaning. */
export const workflowStatusColors: Record<string, { fg: string; bg: string }> = {
  not_required: { fg: '#8A968A', bg: '#F0F2F0' },
  pending: { fg: '#D97706', bg: '#FFF3EA' },
  in_progress: { fg: '#2563EB', bg: '#EAF4FF' },
  under_review: { fg: '#2563EB', bg: '#EAF4FF' },
  more_info_required: { fg: '#D97706', bg: '#FFF3EA' },
  approved: { fg: '#1E8F03', bg: '#E9FCE9' },
  approved_for_drafting: { fg: '#1E8F03', bg: '#E9FCE9' },
  rejected: { fg: '#DC2626', bg: '#FDEDED' },
  converted: { fg: '#25B003', bg: '#E9FCE9' },
  executed: { fg: '#1E8F03', bg: '#E9FCE9' },
};

export const cardShadow = {
  shadowColor: '#0F3D1A',
  shadowOpacity: 0.05,
  shadowRadius: 12,
  shadowOffset: { width: 0, height: 3 },
  elevation: 1,
} as const;
