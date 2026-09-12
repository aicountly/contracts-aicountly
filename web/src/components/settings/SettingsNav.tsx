import { NavLink } from 'react-router-dom'
import {
  Bell,
  BellRing,
  BookMarked,
  BookOpen,
  Building2,
  FileStack,
  ListPlus,
  PenTool,
  Plug,
  ShieldAlert,
  SlidersHorizontal,
  Sparkles,
  Tag,
  Users,
  Workflow,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'

import { useSession } from '../../context/SessionProvider'
import { PERMISSION } from '../../types/permissions'

/**
 * The settings sections, and the rail that moves between them.
 *
 * The list is data rather than markup because two things read it: this nav, and
 * the router in Settings, which has to answer "is /settings/tags a real section
 * and may this person open it" before it renders anything. Two hand-kept copies
 * of that list would disagree the first time a section was added.
 *
 * A section names the permissions that open it, any one of which is enough — a
 * workflow is readable by an administrator and by whoever holds the workflow
 * grant. Sections naming none are the read-only status panels: what AI,
 * signatures and the integrations are configured to do is worth seeing without
 * holding the grant that changes it.
 */

export interface SettingsSectionDef {
  id: string
  label: string
  icon: LucideIcon
  description: string
  /** Any one of these opens the section. Absent means a read-only panel. */
  permissions?: string[]
}

export interface SettingsGroupDef {
  label: string
  sections: SettingsSectionDef[]
}

export const SETTINGS_GROUPS: SettingsGroupDef[] = [
  {
    label: 'Workspace',
    sections: [
      {
        id: 'general',
        label: 'General & numbering',
        icon: SlidersHorizontal,
        description: 'How contracts are numbered, and the defaults a new contract starts from.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
      {
        id: 'reminders',
        label: 'Reminder rules',
        icon: BellRing,
        description: 'How far ahead expiry, obligation and approval reminders are raised.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
      {
        id: 'notifications',
        label: 'Notifications',
        icon: Bell,
        description: 'Which channels reminders can reach people through.',
      },
    ],
  },
  {
    label: 'Taxonomy',
    sections: [
      {
        id: 'contract-types',
        label: 'Contract types',
        icon: FileStack,
        description: 'The kinds of agreement this company signs, and what each one defaults to.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
      {
        id: 'departments',
        label: 'Departments',
        icon: Building2,
        description: 'Who owns which contracts, and where department-head approval routes to.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
      {
        id: 'custom-fields',
        label: 'Custom fields',
        icon: ListPlus,
        description: 'Extra fields this company records on a contract.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
      {
        id: 'tags',
        label: 'Tags',
        icon: Tag,
        description: 'Free labels for grouping contracts across types and departments.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
    ],
  },
  {
    label: 'Governance',
    sections: [
      {
        id: 'workflows',
        label: 'Approval workflows',
        icon: Workflow,
        description: 'Which contracts need approval, from whom, and in what order.',
        permissions: [PERMISSION.WORKFLOW_MANAGE, PERMISSION.SETTINGS_MANAGE],
      },
      {
        id: 'risk-rules',
        label: 'Risk rules',
        icon: ShieldAlert,
        description: 'The deterministic checks every contract is assessed against.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
      {
        id: 'playbooks',
        label: 'Playbooks',
        icon: BookMarked,
        description: 'The positions this company negotiates from, stated as rules.',
        permissions: [PERMISSION.PLAYBOOK_MANAGE],
      },
      {
        id: 'roles',
        label: 'Roles & permissions',
        icon: Users,
        description: 'Who may do what inside Contracts.',
        permissions: [PERMISSION.SETTINGS_MANAGE],
      },
    ],
  },
  {
    label: 'Library',
    sections: [
      {
        id: 'clauses',
        label: 'Clause library',
        icon: BookOpen,
        description: 'Approved wording, kept with the rest of the library.',
      },
      {
        id: 'templates',
        label: 'Templates',
        icon: FileStack,
        description: 'The documents contracts are drafted from.',
      },
    ],
  },
  {
    label: 'Platform',
    sections: [
      {
        id: 'ai',
        label: 'AI configuration',
        icon: Sparkles,
        description: 'What AI is configured to use. Credentials are issued by Console.',
      },
      {
        id: 'signatures',
        label: 'Signature providers',
        icon: PenTool,
        description: 'How contracts are sent for signature.',
      },
      {
        id: 'integrations',
        label: 'Integrations',
        icon: Plug,
        description: 'The services Contracts depends on, and whether each is reachable.',
      },
    ],
  },
]

export const SETTINGS_SECTIONS: SettingsSectionDef[] = SETTINGS_GROUPS.flatMap(
  (group) => group.sections,
)

export const DEFAULT_SETTINGS_SECTION = 'general'

export function findSettingsSection(id: string): SettingsSectionDef | undefined {
  return SETTINGS_SECTIONS.find((section) => section.id === id)
}

/** Whether this user may open a section at all. */
export function sectionIsOpen(
  section: SettingsSectionDef,
  canAny: (permissions: string[]) => boolean,
): boolean {
  return section.permissions === undefined || canAny(section.permissions)
}

/**
 * The section rail.
 *
 * A column of links beside the panel on a desktop, and a horizontally scrolling
 * strip above it on a phone — the same `<nav>` of real links either way, so the
 * browser's history, middle-click and find-in-page all keep working.
 */
export function SettingsNav({ active }: { active: string }) {
  const { canAny } = useSession()

  const groups = SETTINGS_GROUPS.map((group) => ({
    ...group,
    sections: group.sections.filter((section) => sectionIsOpen(section, canAny)),
  })).filter((group) => group.sections.length > 0)

  if (groups.length === 0) return null

  return (
    <nav aria-label="Settings sections" className="ct-settings-nav ct-no-print">
      <style>{`
        .ct-settings-nav { position: sticky; top: calc(var(--topbar-height) + 12px); }
        .ct-settings-nav-list { list-style: none; display: grid; gap: 2px; }
        .ct-settings-group + .ct-settings-group { margin-top: 16px; }
        @media (max-width: 900px) {
          .ct-settings-nav { position: static; }
          .ct-settings-groups {
            display: flex;
            gap: 14px;
            overflow-x: auto;
            padding-bottom: 8px;
            -webkit-overflow-scrolling: touch;
          }
          .ct-settings-group + .ct-settings-group { margin-top: 0; }
          .ct-settings-nav-list { grid-auto-flow: column; gap: 6px; }
          .ct-settings-nav-link { white-space: nowrap; }
        }
      `}</style>

      <div className="ct-settings-groups">
        {groups.map((group) => (
          <div key={group.label} className="ct-settings-group">
            <h2
              style={{
                fontSize: 10.5,
                fontWeight: 800,
                letterSpacing: '.07em',
                textTransform: 'uppercase',
                color: 'var(--color-text-subtle)',
                padding: '0 10px 6px',
              }}
            >
              {group.label}
            </h2>
            <ul className="ct-settings-nav-list">
              {group.sections.map((section) => {
                const Icon = section.icon
                const isActive = section.id === active

                return (
                  <li key={section.id}>
                    <NavLink
                      to={`/settings/${section.id}`}
                      className="ct-settings-nav-link"
                      aria-current={isActive ? 'page' : undefined}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 9,
                        padding: '7px 10px',
                        borderRadius: 'var(--radius-md)',
                        fontSize: 13,
                        fontWeight: isActive ? 700 : 600,
                        color: isActive
                          ? 'rgb(var(--color-primary-active))'
                          : 'var(--color-text-secondary)',
                        background: isActive ? 'var(--color-primary-muted)' : 'transparent',
                      }}
                    >
                      <Icon size={15.5} aria-hidden style={{ flexShrink: 0 }} />
                      {section.label}
                    </NavLink>
                  </li>
                )
              })}
            </ul>
          </div>
        ))}
      </div>
    </nav>
  )
}
