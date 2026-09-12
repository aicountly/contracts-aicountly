<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Contracts permission vocabulary and the roles built from it.
 *
 * Contracts owns its own RBAC because nothing in the ecosystem offers a
 * per-product role grant: Manage answers "may this user act for this company",
 * the portal answers "is this session live", and neither knows what a contract
 * approver is. Roles here are therefore company-scoped rows in
 * `contract_user_roles`, not something read from another product.
 *
 * Every slug is checked server-side. Hiding a button is a courtesy to the user;
 * it is never the control.
 */
final class Permissions
{
    // Reading
    public const CONTRACT_VIEW        = 'contract.view';
    public const CONTRACT_VIEW_ALL    = 'contract.view_all';
    public const COMMERCIALS_VIEW     = 'contract.commercials.view';
    public const AI_RISK_VIEW         = 'contract.risk.view';
    public const AUDIT_VIEW           = 'contract.audit.view';
    public const DOCUMENT_DOWNLOAD    = 'contract.document.download';

    // Writing
    public const CONTRACT_CREATE      = 'contract.create';
    public const CONTRACT_EDIT        = 'contract.edit';
    public const COMMERCIALS_EDIT     = 'contract.commercials.edit';
    public const DOCUMENT_UPLOAD      = 'contract.document.upload';
    public const CONTRACT_DELETE      = 'contract.delete';
    public const CONTRACT_ARCHIVE     = 'contract.archive';
    public const CONTRACT_TERMINATE   = 'contract.terminate';

    // Lifecycle
    public const REQUEST_CREATE       = 'request.create';
    public const REQUEST_REVIEW       = 'request.review';
    public const APPROVAL_ACT         = 'approval.act';
    public const SIGNATURE_ACT        = 'signature.act';
    public const OBLIGATION_MANAGE    = 'obligation.manage';
    public const RENEWAL_MANAGE       = 'renewal.manage';
    public const AMENDMENT_MANAGE     = 'amendment.manage';

    // Configuration
    public const CLAUSE_MANAGE        = 'clause.manage';
    public const TEMPLATE_MANAGE      = 'template.manage';
    public const PLAYBOOK_MANAGE      = 'playbook.manage';
    public const WORKFLOW_MANAGE      = 'workflow.manage';
    public const SETTINGS_MANAGE      = 'settings.manage';

    // Cross-cutting
    public const AI_USE               = 'ai.use';
    public const EXPORT               = 'export';
    public const REPORT_VIEW          = 'report.view';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::CONTRACT_VIEW, self::CONTRACT_VIEW_ALL, self::COMMERCIALS_VIEW,
            self::AI_RISK_VIEW, self::AUDIT_VIEW, self::DOCUMENT_DOWNLOAD,
            self::CONTRACT_CREATE, self::CONTRACT_EDIT, self::COMMERCIALS_EDIT,
            self::DOCUMENT_UPLOAD, self::CONTRACT_DELETE, self::CONTRACT_ARCHIVE,
            self::CONTRACT_TERMINATE,
            self::REQUEST_CREATE, self::REQUEST_REVIEW, self::APPROVAL_ACT,
            self::SIGNATURE_ACT, self::OBLIGATION_MANAGE, self::RENEWAL_MANAGE,
            self::AMENDMENT_MANAGE,
            self::CLAUSE_MANAGE, self::TEMPLATE_MANAGE, self::PLAYBOOK_MANAGE,
            self::WORKFLOW_MANAGE, self::SETTINGS_MANAGE,
            self::AI_USE, self::EXPORT, self::REPORT_VIEW,
        ];
    }

    /**
     * Built-in roles.
     *
     * `contract_admin` is deliberately the only role holding SETTINGS_MANAGE —
     * the settings screen configures approval routing and risk rules, so
     * granting it widely would let a user route their own contract around the
     * approver who is supposed to see it.
     *
     * @return array<string, array{label: string, description: string, permissions: list<string>}>
     */
    public static function roles(): array
    {
        $viewer = [self::CONTRACT_VIEW, self::REPORT_VIEW];

        return [
            'contract_admin' => [
                'label'       => 'Contract Administrator',
                'description' => 'Full control of contracts and of the Contracts configuration.',
                'permissions' => self::all(),
            ],
            'legal' => [
                'label'       => 'Legal',
                'description' => 'Drafts, reviews and approves; owns the clause library and playbook.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::CONTRACT_VIEW_ALL, self::COMMERCIALS_VIEW, self::AI_RISK_VIEW,
                    self::AUDIT_VIEW, self::DOCUMENT_DOWNLOAD, self::DOCUMENT_UPLOAD,
                    self::CONTRACT_CREATE, self::CONTRACT_EDIT, self::CONTRACT_TERMINATE,
                    self::REQUEST_CREATE, self::REQUEST_REVIEW, self::APPROVAL_ACT,
                    self::AMENDMENT_MANAGE, self::RENEWAL_MANAGE, self::OBLIGATION_MANAGE,
                    self::CLAUSE_MANAGE, self::TEMPLATE_MANAGE, self::PLAYBOOK_MANAGE,
                    self::AI_USE, self::EXPORT,
                ]))),
            ],
            'finance' => [
                'label'       => 'Finance',
                'description' => 'Sees and edits commercial terms; tracks payment obligations.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::CONTRACT_VIEW_ALL, self::COMMERCIALS_VIEW, self::COMMERCIALS_EDIT,
                    self::AI_RISK_VIEW, self::DOCUMENT_DOWNLOAD, self::APPROVAL_ACT,
                    self::OBLIGATION_MANAGE, self::RENEWAL_MANAGE, self::AI_USE, self::EXPORT,
                ]))),
            ],
            'procurement' => [
                'label'       => 'Procurement',
                'description' => 'Raises and owns vendor-side contracts.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::COMMERCIALS_VIEW, self::AI_RISK_VIEW, self::DOCUMENT_DOWNLOAD,
                    self::DOCUMENT_UPLOAD, self::CONTRACT_CREATE, self::CONTRACT_EDIT,
                    self::REQUEST_CREATE, self::OBLIGATION_MANAGE, self::RENEWAL_MANAGE,
                    self::AI_USE, self::EXPORT,
                ]))),
            ],
            'sales' => [
                'label'       => 'Sales',
                'description' => 'Raises and owns customer-side contracts.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::COMMERCIALS_VIEW, self::DOCUMENT_DOWNLOAD, self::DOCUMENT_UPLOAD,
                    self::CONTRACT_CREATE, self::CONTRACT_EDIT, self::REQUEST_CREATE,
                    self::RENEWAL_MANAGE, self::AI_USE, self::EXPORT,
                ]))),
            ],
            'contract_owner' => [
                'label'       => 'Contract Owner',
                'description' => 'Owns specific contracts end to end.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::COMMERCIALS_VIEW, self::AI_RISK_VIEW, self::DOCUMENT_DOWNLOAD,
                    self::DOCUMENT_UPLOAD, self::CONTRACT_CREATE, self::CONTRACT_EDIT,
                    self::REQUEST_CREATE, self::OBLIGATION_MANAGE, self::RENEWAL_MANAGE,
                    self::AI_USE,
                ]))),
            ],
            'reviewer' => [
                'label'       => 'Reviewer',
                'description' => 'Comments on contracts under review without editing them.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::CONTRACT_VIEW_ALL, self::AI_RISK_VIEW, self::DOCUMENT_DOWNLOAD, self::AI_USE,
                ]))),
            ],
            'approver' => [
                'label'       => 'Approver',
                'description' => 'Acts on approval steps routed to them.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::CONTRACT_VIEW_ALL, self::COMMERCIALS_VIEW, self::AI_RISK_VIEW,
                    self::DOCUMENT_DOWNLOAD, self::APPROVAL_ACT, self::AI_USE,
                ]))),
            ],
            'signatory' => [
                'label'       => 'Signatory',
                'description' => 'Executes contracts and records signature evidence.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::COMMERCIALS_VIEW, self::DOCUMENT_DOWNLOAD, self::SIGNATURE_ACT,
                ]))),
            ],
            'auditor' => [
                'label'       => 'Auditor',
                'description' => 'Read-only across every contract, including the audit trail.',
                'permissions' => array_values(array_unique(array_merge($viewer, [
                    self::CONTRACT_VIEW_ALL, self::COMMERCIALS_VIEW, self::AI_RISK_VIEW,
                    self::AUDIT_VIEW, self::DOCUMENT_DOWNLOAD, self::EXPORT,
                ]))),
            ],
            'read_only' => [
                'label'       => 'Read Only',
                'description' => 'Sees contracts they are involved in, and nothing else.',
                'permissions' => $viewer,
            ],
        ];
    }

    /**
     * The permission set for a list of role slugs.
     *
     * @param list<string> $roleSlugs
     * @return list<string>
     */
    public static function forRoles(array $roleSlugs): array
    {
        $roles  = self::roles();
        $result = [];
        foreach ($roleSlugs as $slug) {
            if (isset($roles[$slug])) {
                foreach ($roles[$slug]['permissions'] as $permission) {
                    $result[$permission] = true;
                }
            }
        }

        return array_keys($result);
    }

    public static function isKnownRole(string $slug): bool
    {
        return isset(self::roles()[$slug]);
    }

    /** @return list<string> */
    public static function roleSlugs(): array
    {
        return array_keys(self::roles());
    }
}
