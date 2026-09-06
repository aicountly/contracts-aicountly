<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The out-of-the-box configuration a company starts with.
 *
 * Kept in PHP rather than in a seed migration because these rows are copied
 * *into each company* on first use (see CompanyBootstrapService) rather than
 * shared from a cmp_id=0 row. Sharing would mean every tenant-scoped query
 * needed `OR cmp_id = 0`, and every one of those is a place a cross-tenant leak
 * can hide. Copying costs one write per company and makes the isolation rule
 * absolute: a row belongs to exactly one company, always.
 *
 * It also means a company can rename "NDA" or rewrite its liability clause
 * without that edit reaching anyone else.
 */
final class SeedCatalog
{
    /**
     * @return list<array{code: string, name: string, category: string, side: string, description: string}>
     */
    public static function contractTypes(): array
    {
        return [
            ['code' => 'customer_agreement',   'name' => 'Customer Agreement',        'category' => 'revenue',    'side' => 'customer', 'description' => 'General agreement with a customer.'],
            ['code' => 'vendor_agreement',     'name' => 'Vendor Agreement',          'category' => 'procurement','side' => 'vendor',   'description' => 'General agreement with a supplier or vendor.'],
            ['code' => 'service_agreement',    'name' => 'Service Agreement',         'category' => 'services',   'side' => 'either',   'description' => 'Provision of services by one party to another.'],
            ['code' => 'msa',                  'name' => 'Master Service Agreement',  'category' => 'services',   'side' => 'either',   'description' => 'Framework agreement governing subsequent statements of work.'],
            ['code' => 'sow',                  'name' => 'Statement of Work',         'category' => 'services',   'side' => 'either',   'description' => 'Scope, deliverables and pricing under a master agreement.'],
            ['code' => 'nda',                  'name' => 'Non-Disclosure Agreement',  'category' => 'legal',      'side' => 'either',   'description' => 'One-way or mutual confidentiality undertaking.'],
            ['code' => 'confidentiality',      'name' => 'Confidentiality Agreement', 'category' => 'legal',      'side' => 'either',   'description' => 'Standalone confidentiality terms.'],
            ['code' => 'employment',           'name' => 'Employment Agreement',      'category' => 'people',     'side' => 'internal', 'description' => 'Terms of employment for an individual.'],
            ['code' => 'consultancy',          'name' => 'Consultancy Agreement',     'category' => 'services',   'side' => 'vendor',   'description' => 'Engagement of an independent consultant.'],
            ['code' => 'retainer',             'name' => 'Retainer Agreement',        'category' => 'services',   'side' => 'either',   'description' => 'Ongoing engagement for a recurring fee.'],
            ['code' => 'lease',                'name' => 'Lease Agreement',           'category' => 'property',   'side' => 'either',   'description' => 'Lease of premises or equipment.'],
            ['code' => 'rent',                 'name' => 'Rent Agreement',            'category' => 'property',   'side' => 'either',   'description' => 'Rental of premises.'],
            ['code' => 'purchase',             'name' => 'Purchase Agreement',        'category' => 'procurement','side' => 'vendor',   'description' => 'Purchase of goods or assets.'],
            ['code' => 'sales',                'name' => 'Sales Agreement',           'category' => 'revenue',    'side' => 'customer', 'description' => 'Sale of goods or assets.'],
            ['code' => 'distribution',         'name' => 'Distribution Agreement',    'category' => 'revenue',    'side' => 'either',   'description' => 'Appointment of a distributor.'],
            ['code' => 'dealer',               'name' => 'Dealer Agreement',          'category' => 'revenue',    'side' => 'either',   'description' => 'Appointment of a dealer.'],
            ['code' => 'partnership',          'name' => 'Partnership Agreement',     'category' => 'corporate',  'side' => 'either',   'description' => 'Terms between business partners.'],
            ['code' => 'loan',                 'name' => 'Loan Agreement',            'category' => 'finance',    'side' => 'either',   'description' => 'Advance and repayment of a loan.'],
            ['code' => 'licence',              'name' => 'Licence Agreement',         'category' => 'ip',         'side' => 'either',   'description' => 'Licence of intellectual property.'],
            ['code' => 'saas',                 'name' => 'SaaS Agreement',            'category' => 'technology', 'side' => 'either',   'description' => 'Subscription to a hosted software service.'],
            ['code' => 'software_licence',     'name' => 'Software Licence',          'category' => 'technology', 'side' => 'either',   'description' => 'Licence to use software.'],
            ['code' => 'amc',                  'name' => 'AMC / Maintenance Agreement','category' => 'services',  'side' => 'vendor',   'description' => 'Annual maintenance contract.'],
            ['code' => 'franchise',            'name' => 'Franchise Agreement',       'category' => 'revenue',    'side' => 'either',   'description' => 'Grant of franchise rights.'],
            ['code' => 'mou',                  'name' => 'Memorandum of Understanding','category' => 'legal',     'side' => 'either',   'description' => 'Statement of intent, often non-binding.'],
            ['code' => 'dpa',                  'name' => 'Data Processing Agreement', 'category' => 'compliance', 'side' => 'either',   'description' => 'Terms governing processing of personal data.'],
            ['code' => 'subscription',         'name' => 'Subscription Agreement',    'category' => 'revenue',    'side' => 'either',   'description' => 'Recurring subscription terms.'],
            ['code' => 'insurance',            'name' => 'Insurance Contract',        'category' => 'finance',    'side' => 'vendor',   'description' => 'Policy of insurance.'],
            ['code' => 'property',             'name' => 'Property Agreement',        'category' => 'property',   'side' => 'either',   'description' => 'Sale, purchase or transfer of property.'],
            ['code' => 'other',                'name' => 'Custom / Other',            'category' => 'general',    'side' => 'either',   'description' => 'Anything that does not fit another type.'],
        ];
    }

    /**
     * Clause categories. `risk_weight` feeds the health score: a missing
     * limitation of liability matters more than a missing notices clause.
     *
     * @return list<array{code: string, name: string, weight: int}>
     */
    public static function clauseCategories(): array
    {
        return [
            ['code' => 'confidentiality',       'name' => 'Confidentiality',            'weight' => 7],
            ['code' => 'payment_terms',         'name' => 'Payment Terms',              'weight' => 8],
            ['code' => 'late_payment',          'name' => 'Late Payment',               'weight' => 5],
            ['code' => 'termination',           'name' => 'Termination',                'weight' => 9],
            ['code' => 'termination_convenience','name' => 'Termination for Convenience','weight' => 8],
            ['code' => 'termination_cause',     'name' => 'Termination for Cause',      'weight' => 8],
            ['code' => 'limitation_liability',  'name' => 'Limitation of Liability',    'weight' => 10],
            ['code' => 'indemnity',             'name' => 'Indemnity',                  'weight' => 9],
            ['code' => 'force_majeure',         'name' => 'Force Majeure',              'weight' => 5],
            ['code' => 'intellectual_property', 'name' => 'Intellectual Property',      'weight' => 9],
            ['code' => 'data_protection',       'name' => 'Data Protection',            'weight' => 9],
            ['code' => 'warranty',              'name' => 'Warranty',                   'weight' => 7],
            ['code' => 'governing_law',         'name' => 'Governing Law',              'weight' => 6],
            ['code' => 'arbitration',           'name' => 'Arbitration',                'weight' => 6],
            ['code' => 'jurisdiction',          'name' => 'Jurisdiction',               'weight' => 6],
            ['code' => 'assignment',            'name' => 'Assignment',                 'weight' => 5],
            ['code' => 'non_compete',           'name' => 'Non-Compete',                'weight' => 6],
            ['code' => 'non_solicitation',      'name' => 'Non-Solicitation',           'weight' => 5],
            ['code' => 'audit_rights',          'name' => 'Audit Rights',               'weight' => 5],
            ['code' => 'insurance',             'name' => 'Insurance',                  'weight' => 6],
            ['code' => 'sla',                   'name' => 'Service Levels (SLA)',       'weight' => 7],
            ['code' => 'service_credits',       'name' => 'Service Credits',            'weight' => 5],
            ['code' => 'renewal',               'name' => 'Renewal',                    'weight' => 8],
            ['code' => 'price_escalation',      'name' => 'Price Escalation',           'weight' => 7],
            ['code' => 'security',              'name' => 'Security',                   'weight' => 8],
            ['code' => 'compliance',            'name' => 'Compliance',                 'weight' => 7],
            ['code' => 'anti_bribery',          'name' => 'Anti-Bribery',               'weight' => 6],
            ['code' => 'survival',              'name' => 'Survival',                   'weight' => 4],
            ['code' => 'dispute_resolution',    'name' => 'Dispute Resolution',         'weight' => 6],
            ['code' => 'notices',               'name' => 'Notices',                    'weight' => 4],
        ];
    }

    /**
     * Merge variables a template may reference.
     *
     * `source_path` is a lookup into a prepared data bag, never an expression —
     * template rendering must stay a substitution, not an evaluation.
     *
     * @return list<array{key: string, label: string, source: string, path: string, type: string}>
     */
    public static function templateVariables(): array
    {
        return [
            ['key' => 'company.legal_name',        'label' => 'Company legal name',        'source' => 'company',      'path' => 'legal_name',        'type' => 'text'],
            ['key' => 'company.trading_name',      'label' => 'Company trading name',      'source' => 'company',      'path' => 'trading_name',      'type' => 'text'],
            ['key' => 'company.address',           'label' => 'Company address',           'source' => 'company',      'path' => 'address',           'type' => 'text'],
            ['key' => 'company.gstin',             'label' => 'Company GSTIN',             'source' => 'company',      'path' => 'gstin',             'type' => 'text'],
            ['key' => 'company.pan',               'label' => 'Company PAN',               'source' => 'company',      'path' => 'pan',               'type' => 'text'],
            ['key' => 'company.cin',               'label' => 'Company CIN',               'source' => 'company',      'path' => 'cin',               'type' => 'text'],
            ['key' => 'company.email',             'label' => 'Company email',             'source' => 'company',      'path' => 'email',             'type' => 'text'],
            ['key' => 'counterparty.legal_name',   'label' => 'Counterparty legal name',   'source' => 'counterparty', 'path' => 'legal_name',        'type' => 'text'],
            ['key' => 'counterparty.address',      'label' => 'Counterparty address',      'source' => 'counterparty', 'path' => 'registered_address','type' => 'text'],
            ['key' => 'counterparty.gstin',        'label' => 'Counterparty GSTIN',        'source' => 'counterparty', 'path' => 'gstin',             'type' => 'text'],
            ['key' => 'counterparty.signatory',    'label' => 'Counterparty signatory',    'source' => 'counterparty', 'path' => 'authorised_representative', 'type' => 'text'],
            ['key' => 'contract.number',           'label' => 'Contract number',           'source' => 'contract',     'path' => 'contract_number',   'type' => 'text'],
            ['key' => 'contract.title',            'label' => 'Contract title',            'source' => 'contract',     'path' => 'title',             'type' => 'text'],
            ['key' => 'contract.effective_date',   'label' => 'Effective date',            'source' => 'contract',     'path' => 'effective_date',    'type' => 'date'],
            ['key' => 'contract.expiry_date',      'label' => 'Expiry date',               'source' => 'contract',     'path' => 'expiry_date',       'type' => 'date'],
            ['key' => 'contract.execution_date',   'label' => 'Execution date',            'source' => 'contract',     'path' => 'execution_date',    'type' => 'date'],
            ['key' => 'contract.notice_days',      'label' => 'Notice period (days)',      'source' => 'contract',     'path' => 'notice_period_days','type' => 'number'],
            ['key' => 'contract.governing_law',    'label' => 'Governing law',             'source' => 'contract',     'path' => 'governing_law',     'type' => 'text'],
            ['key' => 'contract.jurisdiction',     'label' => 'Jurisdiction',              'source' => 'contract',     'path' => 'jurisdiction',      'type' => 'text'],
            ['key' => 'contract.value',            'label' => 'Total contract value',      'source' => 'commercial',   'path' => 'total_value',       'type' => 'currency'],
            ['key' => 'contract.currency',         'label' => 'Currency',                  'source' => 'contract',     'path' => 'currency',          'type' => 'text'],
            ['key' => 'contract.recurring_value',  'label' => 'Recurring amount',          'source' => 'commercial',   'path' => 'recurring_amount',  'type' => 'currency'],
            ['key' => 'contract.payment_terms',    'label' => 'Payment terms (days)',      'source' => 'commercial',   'path' => 'payment_terms_days','type' => 'number'],
            ['key' => 'system.today',              'label' => "Today's date",              'source' => 'system',       'path' => 'today',             'type' => 'date'],
        ];
    }

    /**
     * Deterministic risk rules shipped with the product.
     *
     * Each is a fact about the structured record, not a reading of prose, which
     * is why they can fire before any model is called and why their findings
     * are reproducible.
     *
     * @return list<array<string,mixed>>
     */
    public static function riskRules(): array
    {
        return [
            [
                'key' => 'unlimited_liability', 'name' => 'Unlimited liability',
                'category' => 'legal', 'severity' => 'critical', 'weight' => 25,
                'subject' => 'liability_cap', 'operator' => 'is_null', 'value_text' => null,
                'description' => 'No limitation of liability was found, or the clause states liability is unlimited.',
                'recommendation' => 'Negotiate a liability cap, typically fees paid in the preceding 12 months.',
            ],
            [
                'key' => 'missing_liability_clause', 'name' => 'Limitation of liability clause missing',
                'category' => 'legal', 'severity' => 'high', 'weight' => 18,
                'subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'limitation_liability',
                'description' => 'The contract has no limitation of liability clause.',
                'recommendation' => 'Add the standard limitation of liability clause from the library.',
            ],
            [
                'key' => 'auto_renewal_long_notice', 'name' => 'Auto-renewal with a long notice period',
                'category' => 'renewal', 'severity' => 'high', 'weight' => 15,
                'subject' => 'notice_period', 'operator' => 'greater_than', 'value_numeric' => 60,
                'description' => 'The contract renews automatically and requires more than 60 days notice to stop it.',
                'recommendation' => 'Diarise the cancellation deadline now, and seek a shorter notice period at renewal.',
            ],
            [
                'key' => 'auto_renewal_present', 'name' => 'Automatic renewal',
                'category' => 'renewal', 'severity' => 'medium', 'weight' => 8,
                'subject' => 'auto_renewal', 'operator' => 'is_true', 'value_text' => null,
                'description' => 'This contract renews automatically unless notice is given.',
                'recommendation' => 'Confirm the renewal is still wanted before the notice deadline.',
            ],
            [
                'key' => 'no_expiry_date', 'name' => 'No expiry date recorded',
                'category' => 'operational', 'severity' => 'medium', 'weight' => 10,
                'subject' => 'expiry_date', 'operator' => 'is_null', 'value_text' => null,
                'description' => 'Without an expiry date this contract cannot appear in any renewal or expiry report.',
                'recommendation' => 'Record the expiry date, or set the renewal type to perpetual if that is correct.',
            ],
            [
                'key' => 'missing_termination_right', 'name' => 'No termination for convenience',
                'category' => 'legal', 'severity' => 'medium', 'weight' => 10,
                'subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'termination_convenience',
                'description' => 'The contract cannot be exited without cause.',
                'recommendation' => 'Seek a termination for convenience right with a reasonable notice period.',
            ],
            [
                'key' => 'missing_data_protection', 'name' => 'No data protection clause',
                'category' => 'data_protection', 'severity' => 'high', 'weight' => 15,
                'subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'data_protection',
                'description' => 'Personal data may be processed under this contract without agreed protections.',
                'recommendation' => 'Add a data processing clause, or execute a separate DPA.',
            ],
            [
                'key' => 'missing_indemnity', 'name' => 'No indemnity clause',
                'category' => 'legal', 'severity' => 'medium', 'weight' => 10,
                'subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'indemnity',
                'description' => 'Neither party indemnifies the other for third-party claims.',
                'recommendation' => 'Consider a mutual indemnity for IP infringement and data breach.',
            ],
            [
                'key' => 'missing_confidentiality', 'name' => 'No confidentiality clause',
                'category' => 'legal', 'severity' => 'medium', 'weight' => 9,
                'subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'confidentiality',
                'description' => 'Information exchanged under this contract is not protected.',
                'recommendation' => 'Add the standard confidentiality clause.',
            ],
            [
                'key' => 'long_payment_terms', 'name' => 'Payment terms longer than 60 days',
                'category' => 'financial', 'severity' => 'medium', 'weight' => 10,
                'subject' => 'payment_terms', 'operator' => 'greater_than', 'value_numeric' => 60,
                'description' => 'Payment terms exceed 60 days, which affects working capital.',
                'recommendation' => 'Negotiate toward the company preferred payment term.',
            ],
            [
                'key' => 'foreign_governing_law', 'name' => 'Governing law outside approved list',
                'category' => 'compliance', 'severity' => 'high', 'weight' => 14,
                'subject' => 'governing_law', 'operator' => 'not_in_list',
                'value_list' => ['India', 'Indian law', 'Republic of India'],
                'description' => 'The governing law is not one the company has approved.',
                'recommendation' => 'Refer to Legal before signing, or negotiate the governing law.',
            ],
            [
                'key' => 'high_value_no_approval', 'name' => 'High value without recorded approval',
                'category' => 'compliance', 'severity' => 'high', 'weight' => 14,
                'subject' => 'contract_value', 'operator' => 'greater_than', 'value_numeric' => 5000000,
                'description' => 'A contract of this value should carry a recorded approval.',
                'recommendation' => 'Submit for approval before execution.',
            ],
            [
                'key' => 'no_signed_copy', 'name' => 'Active contract with no executed copy',
                'category' => 'compliance', 'severity' => 'high', 'weight' => 16,
                'subject' => 'signature_missing', 'operator' => 'is_true', 'value_text' => null,
                'description' => 'The contract is active but no executed copy has been uploaded.',
                'recommendation' => 'Upload the signed copy, or record the execution details.',
            ],
            [
                'key' => 'no_document', 'name' => 'No document attached',
                'category' => 'operational', 'severity' => 'medium', 'weight' => 12,
                'subject' => 'document_missing', 'operator' => 'is_true', 'value_text' => null,
                'description' => 'This contract record has no document behind it.',
                'recommendation' => 'Attach the agreement so its terms can be verified.',
            ],
            [
                'key' => 'no_counterparty', 'name' => 'No counterparty recorded',
                'category' => 'counterparty', 'severity' => 'medium', 'weight' => 10,
                'subject' => 'counterparty_missing', 'operator' => 'is_true', 'value_text' => null,
                'description' => 'The other party to this agreement has not been recorded.',
                'recommendation' => 'Link the counterparty from Contacts.',
            ],
            [
                'key' => 'missing_sla', 'name' => 'Service contract with no service levels',
                'category' => 'sla', 'severity' => 'medium', 'weight' => 9,
                'subject' => 'sla_defined', 'operator' => 'is_false', 'value_text' => null,
                'applies_to' => ['service_agreement', 'msa', 'saas', 'amc'],
                'description' => 'A service contract with no agreed service levels leaves performance unmeasurable.',
                'recommendation' => 'Add an SLA schedule with measurable targets.',
            ],
            [
                'key' => 'missing_insurance', 'name' => 'No insurance requirement',
                'category' => 'operational', 'severity' => 'low', 'weight' => 6,
                'subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'insurance',
                'description' => 'The counterparty is not required to hold insurance.',
                'recommendation' => 'Consider requiring proof of insurance where work is performed on site.',
            ],
            [
                'key' => 'short_notice_to_us', 'name' => 'Counterparty may exit at short notice',
                'category' => 'commercial', 'severity' => 'medium', 'weight' => 8,
                'subject' => 'notice_period', 'operator' => 'less_than', 'value_numeric' => 15,
                'description' => 'A notice period under 15 days gives little time to replace the arrangement.',
                'recommendation' => 'Seek a longer notice period for continuity.',
            ],
        ];
    }

    /**
     * Default playbook — the company preferences the deviation engine measures against.
     *
     * @return list<array<string,mixed>>
     */
    public static function playbookRules(): array
    {
        return [
            ['key' => 'mandatory_confidentiality', 'type' => 'mandatory_clause', 'label' => 'Confidentiality clause is mandatory', 'category' => 'confidentiality', 'severity' => 'high', 'risk' => 'legal', 'recommendation' => 'Add the standard confidentiality clause.'],
            ['key' => 'mandatory_liability_cap', 'type' => 'mandatory_clause', 'label' => 'Limitation of liability is mandatory', 'category' => 'limitation_liability', 'severity' => 'critical', 'risk' => 'legal', 'recommendation' => 'Liability must be capped at fees paid in the preceding 12 months.'],
            ['key' => 'mandatory_termination', 'type' => 'mandatory_clause', 'label' => 'Termination clause is mandatory', 'category' => 'termination', 'severity' => 'high', 'risk' => 'legal', 'recommendation' => 'Every agreement must state how it ends.'],
            ['key' => 'mandatory_governing_law', 'type' => 'mandatory_clause', 'label' => 'Governing law is mandatory', 'category' => 'governing_law', 'severity' => 'medium', 'risk' => 'compliance', 'recommendation' => 'State the governing law explicitly.'],
            ['key' => 'prohibited_unlimited_liability', 'type' => 'prohibited_clause', 'label' => 'Unlimited liability is prohibited', 'category' => 'limitation_liability', 'severity' => 'critical', 'risk' => 'legal', 'expected_value' => 'unlimited liability', 'recommendation' => 'Unlimited liability requires board approval.'],
            ['key' => 'max_payment_terms', 'type' => 'max_numeric', 'label' => 'Payment terms must not exceed 45 days', 'category' => 'payment_terms', 'severity' => 'medium', 'risk' => 'financial', 'expected_numeric' => 45, 'recommendation' => 'The company preferred payment term is Net 30.'],
            ['key' => 'max_notice_period', 'type' => 'max_numeric', 'label' => 'Notice period must not exceed 90 days', 'category' => 'termination', 'severity' => 'medium', 'risk' => 'renewal', 'expected_numeric' => 90, 'recommendation' => 'A notice period over 90 days locks the company in.'],
            ['key' => 'approved_governing_law', 'type' => 'allowed_list', 'label' => 'Governing law must be approved', 'category' => 'governing_law', 'severity' => 'high', 'risk' => 'compliance', 'expected_list' => ['India', 'Indian law', 'Republic of India'], 'recommendation' => 'Foreign governing law requires Legal sign-off.'],
            ['key' => 'no_auto_renewal', 'type' => 'boolean_flag', 'label' => 'Automatic renewal is discouraged', 'category' => 'renewal', 'severity' => 'medium', 'risk' => 'renewal', 'expected_value' => 'false', 'recommendation' => 'Prefer an explicit renewal decision to an automatic one.'],
            ['key' => 'mandatory_data_protection', 'type' => 'mandatory_clause', 'label' => 'Data protection clause required where personal data is processed', 'category' => 'data_protection', 'severity' => 'high', 'risk' => 'data_protection', 'recommendation' => 'Add a DPA or data processing clause.'],
        ];
    }

    /**
     * Standard clause wording seeded into the library.
     *
     * Plain, conservative drafting a company can adopt or replace. It is not
     * legal advice and the product never presents it as such.
     *
     * @return list<array{category: string, name: string, text: string, fallback: string|null, risk: string}>
     */
    public static function clauses(): array
    {
        return [
            [
                'category' => 'confidentiality', 'name' => 'Mutual confidentiality', 'risk' => 'medium',
                'text' => 'Each party shall keep confidential all information disclosed by the other party that is marked confidential or would reasonably be understood to be confidential, and shall not disclose it to any third party except to those of its personnel and advisers who need to know it for the purposes of this Agreement and who are bound by equivalent obligations. These obligations survive for three (3) years after termination.',
                'fallback' => 'Each party shall keep confidential all information disclosed by the other party, for a period of two (2) years after termination.',
            ],
            [
                'category' => 'limitation_liability', 'name' => 'Liability capped at 12 months fees', 'risk' => 'high',
                'text' => "Except in respect of death or personal injury caused by negligence, fraud, or a breach of confidentiality, each party's total aggregate liability arising out of or in connection with this Agreement shall not exceed the total fees paid or payable under this Agreement in the twelve (12) months preceding the event giving rise to the claim. Neither party shall be liable for indirect or consequential loss, or loss of profit, revenue or anticipated savings.",
                'fallback' => "Each party's total aggregate liability shall not exceed twice the total fees paid in the twelve (12) months preceding the claim.",
            ],
            [
                'category' => 'termination_convenience', 'name' => 'Termination for convenience — 30 days', 'risk' => 'medium',
                'text' => 'Either party may terminate this Agreement at any time for convenience by giving not less than thirty (30) days written notice to the other party. Termination shall not affect any accrued rights or liabilities of either party as at the date of termination.',
                'fallback' => 'Either party may terminate this Agreement for convenience on sixty (60) days written notice.',
            ],
            [
                'category' => 'termination_cause', 'name' => 'Termination for cause', 'risk' => 'medium',
                'text' => 'Either party may terminate this Agreement immediately by written notice if the other party commits a material breach which is not remedied within thirty (30) days of written notice requiring it to be remedied, or if the other party becomes insolvent, enters liquidation, or has a receiver appointed over any of its assets.',
                'fallback' => null,
            ],
            [
                'category' => 'payment_terms', 'name' => 'Net 30 payment terms', 'risk' => 'low',
                'text' => 'All undisputed invoices shall be paid within thirty (30) days of the date of invoice. All amounts are exclusive of applicable taxes, which shall be charged in addition at the prevailing rate.',
                'fallback' => 'All undisputed invoices shall be paid within forty-five (45) days of the date of invoice.',
            ],
            [
                'category' => 'late_payment', 'name' => 'Interest on late payment', 'risk' => 'low',
                'text' => 'Without prejudice to any other right or remedy, interest may be charged on any undisputed amount not paid by its due date at the rate of one percent (1%) per month, calculated daily from the due date until payment is received.',
                'fallback' => null,
            ],
            [
                'category' => 'indemnity', 'name' => 'IP infringement indemnity', 'risk' => 'high',
                'text' => 'The Supplier shall indemnify the Customer against all losses, damages, costs and expenses arising from any claim that the Deliverables infringe the intellectual property rights of any third party, provided that the Customer notifies the Supplier promptly, gives the Supplier control of the defence, and does not admit liability without consent.',
                'fallback' => null,
            ],
            [
                'category' => 'intellectual_property', 'name' => 'IP ownership — customer owns deliverables', 'risk' => 'high',
                'text' => 'All intellectual property rights in the Deliverables created specifically for the Customer under this Agreement shall vest in the Customer on payment in full. The Supplier retains all rights in its pre-existing materials and grants the Customer a perpetual, non-exclusive licence to use them to the extent embedded in the Deliverables.',
                'fallback' => 'The Supplier grants the Customer a perpetual, worldwide, non-exclusive licence to use the Deliverables.',
            ],
            [
                'category' => 'data_protection', 'name' => 'Data protection', 'risk' => 'high',
                'text' => 'Each party shall comply with applicable data protection law. Where the Supplier processes personal data on behalf of the Customer it shall do so only on the Customer\'s documented instructions, shall implement appropriate technical and organisational measures, shall impose equivalent obligations on any sub-processor, and shall delete or return all personal data on termination.',
                'fallback' => null,
            ],
            [
                'category' => 'force_majeure', 'name' => 'Force majeure', 'risk' => 'low',
                'text' => 'Neither party shall be liable for any failure or delay in performing its obligations to the extent caused by an event beyond its reasonable control. The affected party shall notify the other promptly. If the event continues for more than sixty (60) days, either party may terminate this Agreement on written notice.',
                'fallback' => null,
            ],
            [
                'category' => 'governing_law', 'name' => 'Governing law — India', 'risk' => 'medium',
                'text' => 'This Agreement and any dispute arising out of or in connection with it shall be governed by and construed in accordance with the laws of India.',
                'fallback' => null,
            ],
            [
                'category' => 'dispute_resolution', 'name' => 'Arbitration', 'risk' => 'medium',
                'text' => 'Any dispute arising out of or in connection with this Agreement shall first be referred to the senior representatives of each party. If not resolved within thirty (30) days, the dispute shall be referred to and finally resolved by arbitration under the Arbitration and Conciliation Act, 1996, by a sole arbitrator appointed by agreement between the parties. The seat of arbitration shall be as stated in the Schedule, and the language shall be English.',
                'fallback' => null,
            ],
            [
                'category' => 'renewal', 'name' => 'Renewal by agreement', 'risk' => 'low',
                'text' => 'This Agreement may be renewed for a further term by written agreement between the parties, entered into not less than thirty (30) days before the expiry of the then-current term. It shall not renew automatically.',
                'fallback' => 'This Agreement shall renew automatically for successive periods of twelve (12) months unless either party gives sixty (60) days written notice not to renew.',
            ],
            [
                'category' => 'sla', 'name' => 'Service levels', 'risk' => 'medium',
                'text' => 'The Supplier shall provide the Services in accordance with the service levels set out in the Service Level Schedule, and shall report performance against those service levels monthly. Failure to meet a service level shall entitle the Customer to the service credits set out in that Schedule.',
                'fallback' => null,
            ],
            [
                'category' => 'assignment', 'name' => 'Assignment', 'risk' => 'low',
                'text' => 'Neither party may assign, transfer or subcontract any of its rights or obligations under this Agreement without the prior written consent of the other party, such consent not to be unreasonably withheld, save that either party may assign to a member of its group on written notice.',
                'fallback' => null,
            ],
            [
                'category' => 'insurance', 'name' => 'Insurance', 'risk' => 'medium',
                'text' => 'The Supplier shall maintain, with a reputable insurer, public liability and professional indemnity insurance in an amount not less than that stated in the Schedule, and shall provide evidence of cover on request.',
                'fallback' => null,
            ],
            [
                'category' => 'audit_rights', 'name' => 'Audit rights', 'risk' => 'low',
                'text' => 'The Customer may, on not less than fourteen (14) days written notice and not more than once in any twelve (12) month period, audit the Supplier\'s records relating to this Agreement, during normal business hours and subject to reasonable confidentiality undertakings.',
                'fallback' => null,
            ],
            [
                'category' => 'anti_bribery', 'name' => 'Anti-bribery', 'risk' => 'medium',
                'text' => 'Each party shall comply with all applicable anti-bribery and anti-corruption laws, shall maintain policies and procedures to ensure compliance, and shall not offer or accept any improper payment in connection with this Agreement.',
                'fallback' => null,
            ],
            [
                'category' => 'survival', 'name' => 'Survival', 'risk' => 'low',
                'text' => 'The provisions relating to confidentiality, intellectual property, limitation of liability, indemnity, governing law and dispute resolution shall survive termination or expiry of this Agreement.',
                'fallback' => null,
            ],
            [
                'category' => 'notices', 'name' => 'Notices', 'risk' => 'low',
                'text' => 'Any notice under this Agreement shall be in writing and shall be delivered by hand, by registered post, or by email to the address stated in this Agreement. A notice sent by email is deemed received on the next business day.',
                'fallback' => null,
            ],
        ];
    }
}
