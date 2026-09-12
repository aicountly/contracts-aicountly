/**
 * Permission slugs, mirroring server-php/app/Support/Permissions.php.
 *
 * These drive what the UI offers. They are NOT the control — every endpoint
 * checks the same slug server-side, and a user who forges a permission into
 * their browser state gets a 403 rather than an action.
 */
export const PERMISSION = {
  CONTRACT_VIEW: 'contract.view',
  CONTRACT_VIEW_ALL: 'contract.view_all',
  COMMERCIALS_VIEW: 'contract.commercials.view',
  AI_RISK_VIEW: 'contract.risk.view',
  AUDIT_VIEW: 'contract.audit.view',
  DOCUMENT_DOWNLOAD: 'contract.document.download',

  CONTRACT_CREATE: 'contract.create',
  CONTRACT_EDIT: 'contract.edit',
  COMMERCIALS_EDIT: 'contract.commercials.edit',
  DOCUMENT_UPLOAD: 'contract.document.upload',
  CONTRACT_DELETE: 'contract.delete',
  CONTRACT_ARCHIVE: 'contract.archive',
  CONTRACT_TERMINATE: 'contract.terminate',

  REQUEST_CREATE: 'request.create',
  REQUEST_REVIEW: 'request.review',
  APPROVAL_ACT: 'approval.act',
  SIGNATURE_ACT: 'signature.act',
  OBLIGATION_MANAGE: 'obligation.manage',
  RENEWAL_MANAGE: 'renewal.manage',
  AMENDMENT_MANAGE: 'amendment.manage',

  CLAUSE_MANAGE: 'clause.manage',
  TEMPLATE_MANAGE: 'template.manage',
  PLAYBOOK_MANAGE: 'playbook.manage',
  WORKFLOW_MANAGE: 'workflow.manage',
  SETTINGS_MANAGE: 'settings.manage',

  AI_USE: 'ai.use',
  EXPORT: 'export',
  REPORT_VIEW: 'report.view',
} as const

export type PermissionSlug = (typeof PERMISSION)[keyof typeof PERMISSION]
