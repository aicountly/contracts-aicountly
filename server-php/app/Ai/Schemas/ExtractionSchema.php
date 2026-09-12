<?php

declare(strict_types=1);

namespace App\Ai\Schemas;

use App\Support\Enums;

/**
 * The shape every model reply has to arrive in.
 *
 * A schema here is used twice: it is sent to the provider as a structured-output
 * request, and it is what JsonSchemaValidator checks the reply against before
 * anything is stored. The second use is the one that matters — a structured
 * output flag is a request, not a guarantee, and this product writes contract
 * terms that people act on.
 *
 * Two conventions run through all of them:
 *
 *   - Every extracted value is a `field object`: value, confidence, source_page,
 *     source_excerpt, with all four required. A model cannot return a value
 *     without saying how sure it is or where it read it, because a value with
 *     neither cannot be reviewed and therefore cannot be trusted.
 *   - `value` is nullable everywhere. A contract that does not state a term must
 *     be able to say so; making the field required and non-null would be an
 *     instruction to invent one.
 *
 * `additionalProperties: false` is set on the top level of each schema. A model
 * returning a field nobody asked for is worth failing the call over: it means
 * the reply is not the reply we specified, and quietly dropping the extra key
 * would hide that.
 */
final class ExtractionSchema
{
    /**
     * The management summary's sections, in reading order.
     *
     * ContractPrompts renders this list into the prompt and summary() renders
     * it into the schema, so the sections asked for and the sections accepted
     * cannot drift apart.
     *
     * `management_action_items` is the one section that is a list rather than
     * prose: its entries are stored in their own column so a dashboard can
     * count what is outstanding across a portfolio, which a paragraph cannot
     * answer.
     *
     * @var array<string,string>
     */
    public const SUMMARY_SECTIONS = [
        'executive_summary'       => 'Executive Summary',
        'parties'                 => 'Parties',
        'purpose'                 => 'Purpose',
        'effective_period'        => 'Effective Period',
        'commercial_terms'        => 'Commercial Terms',
        'payment_terms'           => 'Payment Terms',
        'renewal'                 => 'Renewal',
        'termination'             => 'Termination',
        'key_obligations'         => 'Key Obligations',
        'key_rights'              => 'Key Rights',
        'sla'                     => 'SLA',
        'liability'               => 'Liability',
        'indemnity'               => 'Indemnity',
        'ip'                      => 'IP',
        'confidentiality'         => 'Confidentiality',
        'data_protection'         => 'Data Protection',
        'dispute_resolution'      => 'Dispute Resolution',
        'governing_law'           => 'Governing Law',
        'high_risk_clauses'       => 'High-Risk Clauses',
        'missing_protections'     => 'Missing Protections',
        'management_action_items' => 'Management Action Items',
    ];

    /** The structured terms extractContractData asks for, and the type each value must arrive as. */
    public const CONTRACT_FIELDS = [
        'contract_title'     => 'text',
        'counterparty_name'  => 'text',
        'effective_date'     => 'date',
        'commencement_date'  => 'date',
        'execution_date'     => 'date',
        'expiry_date'        => 'date',
        'term_description'   => 'text',
        'renewal_type'       => 'text',
        'renewal_frequency'  => 'text',
        'auto_renewal'       => 'boolean',
        'notice_period_days' => 'number',
        'governing_law'      => 'text',
        'jurisdiction'       => 'text',
        'currency'           => 'text',
        'total_value'        => 'currency',
        'recurring_value'    => 'currency',
        'payment_frequency'  => 'text',
        'payment_terms_days' => 'number',
    ];

    /** Longest excerpt a reply may carry back per field. Matches ContractPrompts::MAX_EXCERPT_CHARS. */
    private const EXCERPT_MAX = 300;

    /** The schema for one AI job kind, or null where that kind produces no single document. */
    public static function forKind(string $kind): ?array
    {
        return match ($kind) {
            'extract'        => self::contractData(),
            'classify'       => self::classification(),
            'clauses'        => self::clauses(),
            'obligations'    => self::obligations(),
            'summarize'      => self::summary(),
            'risk'           => self::riskNarrative(),
            'compare'        => self::versionComparison(),
            'answer'         => self::answer(),
            'renewal_advice' => self::renewalRecommendation(),
            'deviation'      => self::clauseDeviation(),
            default          => null,
        };
    }

    /** @return array<string,mixed> */
    public static function classification(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['document_type', 'matched_known_type', 'document_completeness'],
            'properties'           => [
                'document_type'      => self::field('string', ['maxLength' => 120]),
                'matched_known_type' => self::field('string', ['maxLength' => 120]),
                'document_completeness' => self::field('string', [
                    'enum' => ['executed_agreement', 'draft', 'annexure', 'fragment', 'unknown'],
                ]),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function contractData(): array
    {
        $fields = [];
        foreach (self::CONTRACT_FIELDS as $key => $type) {
            $fields[$key] = match ($key) {
                // Dates are validated as dates rather than free text: the
                // validator normalises a UTC timestamp to a calendar day and
                // refuses one carrying an offset, which is the difference
                // between a notice deadline and a notice deadline one day out.
                'effective_date', 'commencement_date', 'execution_date', 'expiry_date'
                    => self::field('string', ['format' => 'date']),
                'auto_renewal'       => self::field('boolean'),
                'notice_period_days' => self::field('integer', ['minimum' => 0, 'maximum' => 3650]),
                'payment_terms_days' => self::field('integer', ['minimum' => 0, 'maximum' => 3650]),
                // Money arrives as a number and is stored as a string. Asking
                // for a number lets the validator strip a thousands separator;
                // the string conversion happens once, on the way to NUMERIC.
                'total_value', 'recurring_value' => self::field('number'),
                'renewal_type'      => self::field('string', ['enum' => Enums::RENEWAL_TYPES]),
                'renewal_frequency' => self::field('string', ['enum' => Enums::RENEWAL_FREQUENCIES]),
                'currency'          => self::field('string', ['pattern' => '^[A-Za-z]{3}$']),
                default             => self::field('string', ['maxLength' => 2000]),
            };
        }

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['fields', 'parties'],
            'properties'           => [
                'fields' => [
                    'type'       => 'object',
                    'required'   => array_keys(self::CONTRACT_FIELDS),
                    'properties' => $fields,
                ],
                'parties' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['name', 'role', 'confidence'],
                        'properties' => [
                            'name'           => ['type' => 'string', 'maxLength' => 255],
                            'role'           => ['type' => ['string', 'null'], 'maxLength' => 60],
                            'is_company'     => ['type' => ['boolean', 'null']],
                            'confidence'     => self::confidence(),
                            'source_page'    => self::sourcePage(),
                            'source_excerpt' => self::sourceExcerpt(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function clauses(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['clauses'],
            'properties'           => [
                'clauses' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['body_text', 'confidence'],
                        'properties' => [
                            'clause_number'  => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'heading'        => ['type' => ['string', 'null'], 'maxLength' => 255],
                            'category'       => ['type' => ['string', 'null'], 'maxLength' => 160],
                            'body_text'      => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
                            'summary'        => ['type' => ['string', 'null'], 'maxLength' => 1000],
                            'confidence'     => self::confidence(),
                            'source_page'    => self::sourcePage(),
                            'source_excerpt' => self::sourceExcerpt(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function obligations(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['obligations'],
            'properties'           => [
                'obligations' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['title', 'confidence'],
                        'properties' => [
                            'title'             => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'description'       => ['type' => ['string', 'null'], 'maxLength' => 4000],
                            'obligation_type'   => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'responsible_party' => ['type' => ['string', 'null'], 'enum' => Enums::OBLIGATION_RESPONSIBLE],
                            'frequency'         => ['type' => ['string', 'null'], 'enum' => Enums::OBLIGATION_FREQUENCIES],
                            'first_due_date'    => ['type' => ['string', 'null'], 'format' => 'date'],
                            'amount'            => ['type' => ['number', 'null']],
                            'currency'          => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z]{3}$'],
                            'evidence_required' => ['type' => ['boolean', 'null']],
                            'clause_reference'  => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'confidence'        => self::confidence(),
                            'source_page'       => self::sourcePage(),
                            'source_excerpt'    => self::sourceExcerpt(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function milestones(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['milestones'],
            'properties'           => [
                'milestones' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        // due_date is required and non-null: a milestone the
                        // document gives no date for is not storable, and the
                        // prompt says to leave it out rather than guess one.
                        'required' => ['title', 'due_date', 'confidence'],
                        'properties' => [
                            'title'            => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'description'      => ['type' => ['string', 'null'], 'maxLength' => 4000],
                            'milestone_type'   => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'due_date'         => ['type' => 'string', 'format' => 'date'],
                            'amount'           => ['type' => ['number', 'null']],
                            'currency'         => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z]{3}$'],
                            'clause_reference' => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'confidence'       => self::confidence(),
                            'source_page'      => self::sourcePage(),
                            'source_excerpt'   => self::sourceExcerpt(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function summary(): array
    {
        $sections = [];
        foreach (array_keys(self::SUMMARY_SECTIONS) as $key) {
            if ($key === 'management_action_items') {
                continue;
            }
            $sections[$key] = [
                'type'     => 'object',
                'required' => ['content', 'confidence'],
                'properties' => [
                    'content'        => ['type' => ['string', 'null'], 'maxLength' => 1200],
                    'confidence'     => self::confidence(),
                    'source_page'    => self::sourcePage(),
                    'source_excerpt' => self::sourceExcerpt(),
                ],
            ];
        }

        $sections['management_action_items'] = [
            'type'  => 'array',
            'items' => [
                'type'     => 'object',
                'required' => ['action', 'confidence'],
                'properties' => [
                    'action'         => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                    'why_it_matters' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                    'urgency'        => [
                        'type' => ['string', 'null'],
                        'enum' => ['immediate', 'before_signature', 'before_renewal', 'routine'],
                    ],
                    'confidence'     => self::confidence(),
                    'source_page'    => self::sourcePage(),
                    'source_excerpt' => self::sourceExcerpt(),
                ],
            ],
        ];

        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['sections'],
            'properties'           => [
                'sections' => [
                    'type'       => 'object',
                    'required'   => array_keys(self::SUMMARY_SECTIONS),
                    'properties' => $sections,
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function riskNarrative(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['findings'],
            'properties'           => [
                'overall_assessment' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                'findings' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['category', 'severity', 'title', 'confidence'],
                        'properties' => [
                            'category'         => ['type' => 'string', 'enum' => Enums::RISK_CATEGORIES],
                            'severity'         => ['type' => 'string', 'enum' => Enums::RISK_SEVERITIES],
                            'title'            => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'detail'           => ['type' => ['string', 'null'], 'maxLength' => 4000],
                            'clause_reference' => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'confidence'       => self::confidence(),
                            'source_page'      => self::sourcePage(),
                            'source_excerpt'   => self::sourceExcerpt(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function versionComparison(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['changes'],
            'properties'           => [
                'overall_assessment' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                'changes' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['change_type', 'effect', 'confidence'],
                        'properties' => [
                            'clause_reference' => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'change_type'      => ['type' => 'string', 'enum' => ['added', 'removed', 'amended', 'moved']],
                            'before_excerpt'   => ['type' => ['string', 'null'], 'maxLength' => 2000],
                            'after_excerpt'    => ['type' => ['string', 'null'], 'maxLength' => 2000],
                            'effect'           => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                            'direction'        => [
                                'type' => ['string', 'null'],
                                'enum' => ['favours_company', 'favours_counterparty', 'neutral', 'unclear'],
                            ],
                            'materiality'      => ['type' => ['string', 'null'], 'enum' => Enums::RISK_SEVERITIES],
                            'confidence'       => self::confidence(),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The Q&A reply.
     *
     * `answered` is required and separate from the answer text so the service
     * never has to decide what refusal wording looks like: the model states
     * whether the contract answered the question, and a false there is what
     * marks the stored message ungrounded.
     *
     * @return array<string,mixed>
     */
    public static function answer(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['answered', 'answer', 'citations'],
            'properties'           => [
                'answered'   => ['type' => 'boolean'],
                'answer'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6000],
                'confidence' => self::confidence(),
                'citations'  => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['excerpt'],
                        'properties' => [
                            'clause_reference' => ['type' => ['string', 'null'], 'maxLength' => 48],
                            'heading'          => ['type' => ['string', 'null'], 'maxLength' => 255],
                            'page'             => self::sourcePage(),
                            'excerpt'          => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::EXCERPT_MAX],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function renewalRecommendation(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['recommendation', 'rationale', 'confidence'],
            'properties'           => [
                'recommendation' => [
                    'type' => 'string',
                    'enum' => ['renew', 'renegotiate', 'terminate', 'review_further'],
                ],
                'rationale'          => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'notice_position'    => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'if_nothing_is_done' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                'points_to_negotiate' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['point'],
                        'properties' => [
                            'point'  => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                            'reason' => ['type' => ['string', 'null'], 'maxLength' => 1000],
                        ],
                    ],
                ],
                'risks_of_renewing' => [
                    'type'  => 'array',
                    'items' => [
                        'type'     => 'object',
                        'required' => ['risk'],
                        'properties' => [
                            'risk'     => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                            'severity' => ['type' => ['string', 'null'], 'enum' => Enums::RISK_SEVERITIES],
                        ],
                    ],
                ],
                'confidence'     => self::confidence(),
                'source_excerpt' => self::sourceExcerpt(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function clauseDeviation(): array
    {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => ['deviates', 'deviation_summary', 'severity', 'confidence'],
            'properties'           => [
                'deviates'          => ['type' => 'boolean'],
                'deviation_summary' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                'severity'          => ['type' => 'string', 'enum' => Enums::RISK_SEVERITIES],
                'affected_position' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                'fallback_position' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                'confidence'        => self::confidence(),
                'source_excerpt'    => self::sourceExcerpt(),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Building blocks
    // -----------------------------------------------------------------------

    /**
     * One extracted value with its evidence.
     *
     * All four properties are required. A model that may omit confidence will
     * omit it exactly on the fields it was least sure about, which is the
     * opposite of useful.
     *
     * @param  array<string,mixed> $extra constraints on the value itself
     * @return array<string,mixed>
     */
    private static function field(string $type, array $extra = []): array
    {
        return [
            'type'     => 'object',
            'required' => ['value', 'confidence', 'source_page', 'source_excerpt'],
            'properties' => [
                'value'          => array_merge(['type' => [$type, 'null']], $extra),
                'confidence'     => self::confidence(),
                'source_page'    => self::sourcePage(),
                'source_excerpt' => self::sourceExcerpt(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function confidence(): array
    {
        return ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 1];
    }

    /** @return array<string,mixed> */
    private static function sourcePage(): array
    {
        return ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 20000];
    }

    /** @return array<string,mixed> */
    private static function sourceExcerpt(): array
    {
        return ['type' => ['string', 'null'], 'maxLength' => self::EXCERPT_MAX];
    }
}
