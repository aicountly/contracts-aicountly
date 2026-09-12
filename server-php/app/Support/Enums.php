<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Controlled vocabularies.
 *
 * These are enums rather than free text because a contract's status drives
 * money and deadlines: a renewal sweep that misses a contract because someone
 * typed "active " with a trailing space is a missed cancellation window, not a
 * cosmetic bug. Every one of these is also a CHECK constraint in the schema —
 * the constant list and the constraint must be changed together, and a
 * migration is what makes that visible in review.
 */
final class Enums
{
    /** Contract lifecycle status. Mirrors CHECK constraint on contracts.status. */
    public const CONTRACT_STATUSES = [
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
        'archived',
    ];

    /** Statuses a contract counts as live in. Used by dashboards and the renewal sweep. */
    public const ACTIVE_STATUSES = ['active', 'renewal_review'];

    /** Statuses that are still pre-execution — editable metadata, no obligations yet. */
    public const PRE_EXECUTION_STATUSES = [
        'draft', 'under_review', 'awaiting_approval', 'approved', 'negotiation', 'awaiting_signature',
    ];

    /** Terminal states. A contract here is history and must not be silently reopened. */
    public const CLOSED_STATUSES = ['expired', 'terminated', 'cancelled', 'archived'];

    public const LIFECYCLE_STAGES = [
        'request', 'draft', 'analysis', 'internal_review', 'approval', 'negotiation',
        'signature', 'active', 'obligations', 'renewal', 'closed',
    ];

    public const CONTRACT_SOURCES = ['drafted', 'uploaded', 'imported', 'from_request', 'from_template'];

    public const PARTY_ROLES = [
        'company', 'counterparty', 'customer', 'vendor', 'supplier', 'partner',
        'employee', 'consultant', 'landlord', 'tenant', 'licensor', 'licensee',
        'guarantor', 'witness', 'other',
    ];

    public const RENEWAL_TYPES = ['fixed_term', 'auto_renew', 'perpetual', 'evergreen', 'none'];

    public const RENEWAL_FREQUENCIES = ['monthly', 'quarterly', 'half_yearly', 'annual', 'biennial', 'custom'];

    public const RENEWAL_STATUSES = [
        'not_yet_due', 'review_due', 'under_review', 'renew', 'renegotiate',
        'terminate', 'renewal_in_progress', 'renewed', 'closed',
    ];

    public const REQUEST_STATUSES = [
        'draft', 'submitted', 'under_review', 'more_info_required',
        'approved_for_drafting', 'rejected', 'converted',
    ];

    public const APPROVAL_INSTANCE_STATUSES = ['pending', 'in_progress', 'approved', 'rejected', 'sent_back', 'cancelled'];

    public const APPROVAL_ACTIONS = ['approve', 'reject', 'send_back', 'request_changes', 'comment', 'reassign'];

    public const OBLIGATION_STATUSES = ['upcoming', 'due', 'overdue', 'completed', 'waived', 'not_applicable', 'disputed'];

    public const OBLIGATION_FREQUENCIES = [
        'one_time', 'daily', 'weekly', 'fortnightly', 'monthly', 'quarterly',
        'half_yearly', 'annual', 'custom',
    ];

    public const OBLIGATION_RESPONSIBLE = ['company', 'counterparty', 'both'];

    public const MILESTONE_STATUSES = ['pending', 'in_progress', 'completed', 'missed', 'cancelled'];

    public const RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public const RISK_SEVERITIES = ['informational', 'low', 'medium', 'high', 'critical'];

    public const RISK_CATEGORIES = [
        'legal', 'commercial', 'financial', 'compliance', 'operational',
        'data_protection', 'renewal', 'counterparty', 'sla',
    ];

    public const SIGNATURE_STATUSES = ['not_started', 'draft', 'sent', 'viewed', 'signed', 'declined', 'expired', 'cancelled', 'completed'];

    public const AI_JOB_STATUSES = ['queued', 'running', 'succeeded', 'failed', 'cancelled'];

    public const AI_JOB_KINDS = [
        'extract', 'classify', 'summarize', 'clauses', 'obligations',
        'risk', 'compare', 'answer', 'renewal_advice', 'deviation',
    ];

    public const VERIFICATION_STATES = ['ai_extracted', 'human_verified', 'human_edited', 'rejected'];

    public const TERMINATION_TYPES = ['for_convenience', 'for_cause', 'mutual', 'expiry', 'breach', 'insolvency', 'other'];

    public const AMENDMENT_STATUSES = ['draft', 'under_review', 'awaiting_approval', 'awaiting_signature', 'executed', 'cancelled'];

    public const DOCUMENT_VERSION_STATUSES = [
        'internal_draft', 'legal_review', 'sent_to_counterparty',
        'counterparty_redline', 'final_draft', 'executed', 'superseded',
    ];

    public const CLAUSE_APPROVAL_STATUSES = ['draft', 'approved', 'deprecated'];

    public const TEMPLATE_STATUSES = ['draft', 'active', 'deprecated'];

    public const CUSTOM_FIELD_TYPES = [
        'text', 'textarea', 'number', 'currency', 'date', 'boolean',
        'select', 'multi_select', 'contact_reference', 'user_reference',
    ];

    public const JOB_STATUSES = ['queued', 'running', 'succeeded', 'failed', 'dead'];

    public const NOTIFICATION_CHANNELS = ['in_app', 'email', 'push'];

    /**
     * Coerce caller input to a member of $allowed, or null.
     *
     * Returning null rather than throwing lets a filter quietly drop a bad
     * value while a required field can still turn the null into a validation
     * error — the caller decides which of those two it is.
     */
    public static function coerce(mixed $value, array $allowed, ?string $default = null): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $default;
        }

        $needle = strtolower(trim((string) $value));
        $needle = str_replace([' ', '-'], '_', $needle);

        return in_array($needle, $allowed, true) ? $needle : $default;
    }

    public static function isValid(mixed $value, array $allowed): bool
    {
        return is_string($value) && in_array($value, $allowed, true);
    }

    /** Human label for a snake_case enum member. */
    public static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
