import {
  Activity,
  AlertTriangle,
  BarChart3,
  BookOpen,
  CheckSquare,
  ClipboardList,
  FileDiff,
  FilePlus2,
  FileStack,
  FileText,
  LayoutDashboard,
  RefreshCw,
  Settings,
  Sparkles,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

import { PERMISSION } from '../types/permissions'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  /** Hidden unless the user holds this permission. The API enforces it as well. */
  permission?: string
  /** Which live count to show as a badge, if any. */
  badge?: 'approvals' | 'obligations' | 'renewals' | 'review_queue'
  /** Matches child routes too, so /contracts/42 lights up "Contracts". */
  matchPrefix?: string
}

export interface NavSection {
  label: string | null
  items: NavItem[]
}

/**
 * The left navigation.
 *
 * Grouped rather than a flat list of fourteen: an unbroken column of links is
 * a menu people scan every time instead of learning. The groups follow the
 * contract's own life — what exists, what needs a decision, what the
 * configuration is.
 */
export const NAVIGATION: NavSection[] = [
  {
    label: null,
    items: [
      { to: '/', label: 'Dashboard', icon: LayoutDashboard },
    ],
  },
  {
    label: 'Contracts',
    items: [
      {
        to: '/contracts',
        label: 'Repository',
        icon: FileText,
        matchPrefix: '/contracts',
        permission: PERMISSION.CONTRACT_VIEW,
      },
      {
        to: '/requests',
        label: 'Requests',
        icon: FilePlus2,
        matchPrefix: '/requests',
        permission: PERMISSION.CONTRACT_VIEW,
      },
      {
        to: '/amendments',
        label: 'Amendments',
        icon: FileDiff,
        permission: PERMISSION.CONTRACT_VIEW,
      },
    ],
  },
  {
    label: 'Needs attention',
    items: [
      {
        to: '/approvals',
        label: 'Approvals',
        icon: CheckSquare,
        badge: 'approvals',
        permission: PERMISSION.CONTRACT_VIEW,
      },
      {
        to: '/obligations',
        label: 'Obligations',
        icon: ClipboardList,
        badge: 'obligations',
        permission: PERMISSION.CONTRACT_VIEW,
      },
      {
        to: '/renewals',
        label: 'Renewals',
        icon: RefreshCw,
        badge: 'renewals',
        permission: PERMISSION.CONTRACT_VIEW,
      },
      {
        to: '/risks',
        label: 'Risks',
        icon: AlertTriangle,
        permission: PERMISSION.AI_RISK_VIEW,
      },
      {
        to: '/ai/review',
        label: 'AI review queue',
        icon: Sparkles,
        badge: 'review_queue',
        permission: PERMISSION.AI_USE,
      },
    ],
  },
  {
    label: 'Library',
    items: [
      { to: '/templates', label: 'Templates', icon: FileStack, permission: PERMISSION.CONTRACT_VIEW },
      { to: '/clauses', label: 'Clause library', icon: BookOpen, permission: PERMISSION.CONTRACT_VIEW },
    ],
  },
  {
    label: 'Insight',
    items: [
      { to: '/insights', label: 'AI insights', icon: Activity, permission: PERMISSION.AI_USE },
      { to: '/reports', label: 'Reports', icon: BarChart3, permission: PERMISSION.REPORT_VIEW },
    ],
  },
  {
    label: null,
    items: [
      { to: '/settings', label: 'Settings', icon: Settings, matchPrefix: '/settings' },
    ],
  },
]
