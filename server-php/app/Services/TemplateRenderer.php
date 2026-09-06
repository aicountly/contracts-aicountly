<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Dates;
use App\Support\TenantContext;
use App\Support\ValidationFailed;

/**
 * Merge-field rendering for contract templates.
 *
 * One decision shapes everything here: a template is a SUBSTITUTION, never an
 * evaluation. `{{ contract.title }}` is a key looked up in a registry and then
 * in a prepared data bag; it is never parsed as an expression, never resolved
 * by walking an object graph, and never handed to anything that can call code.
 * A template author is a user of the product — usually a lawyer, occasionally
 * whoever compromised that lawyer's account — and a template engine that
 * evaluates is a template engine that runs their code on our server.
 *
 * The consequences of that decision, in order of how badly each would hurt:
 *
 *   - Only `{{ key }}`, `{{#if key}}`, `{{#unless key}}` and their closers are
 *     understood. Any other directive is refused rather than ignored, so a
 *     body written for a richer engine fails loudly instead of half-rendering.
 *   - A key must exist in the company's `template_variables` registry. The
 *     registry, not the key, decides which bag entry is read, so a key can
 *     only ever reach data this tenant was meant to see.
 *   - Every merged value is HTML-escaped. There is deliberately no raw form:
 *     a counterparty name arrives from Contacts and ends up in a document
 *     someone opens in a browser, and one unescaped field is stored XSS.
 *   - Size, nesting and output are all capped, so no template can turn one
 *     request into an unbounded amount of work.
 */
final class TemplateRenderer
{
    /** A template body larger than this is refused rather than rendered. */
    public const MAX_BODY_BYTES = 262144;

    /** Deepest nesting of conditional blocks. Real documents use two or three. */
    public const MAX_DEPTH = 8;

    /** A rendered document larger than this is refused. */
    public const MAX_OUTPUT_BYTES = 1048576;

    /** One merged value. A custom field is free text and this is what bounds it. */
    public const MAX_VALUE_LENGTH = 4000;

    /**
     * A placeholder.
     *
     * The inner part excludes braces on purpose: an unterminated `{{` then
     * stays literal text instead of swallowing the rest of the document
     * looking for a closer.
     */
    private const TOKEN = '/\{\{([^{}]{0,200})\}\}/';

    /** Identical to the CHECK constraint on `template_variables.var_key`. */
    private const KEY_SHAPE = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/';

    /**
     * Merge $bag into $body.
     *
     * `missing` is what a preview screen shows as gaps to fill; `used` is what
     * the editor highlights as this template's live fields. A variable read by
     * a conditional counts as used but never as missing — a block that is off
     * because the data says so is a rendered outcome, not an omission.
     *
     * @param array<string,mixed>              $bag             source => path => scalar
     * @param array<string,array<string,mixed>> $allowedVariables the tenant's registry
     * @return array{html: string, missing: list<string>, used: list<string>}
     * @throws ValidationFailed
     */
    public function render(string $body, array $bag, array $allowedVariables): array
    {
        $this->assertSize($body);

        $allowed = self::indexVariables($allowedVariables);

        $html       = '';
        $cursor     = 0;
        $stack      = [];
        $suppressed = 0;
        $used       = [];
        $missing    = [];

        preg_match_all(self::TOKEN, $body, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        foreach ($matches as $match) {
            [$token, $offset] = $match[0];

            if ($suppressed === 0) {
                $html .= substr($body, $cursor, $offset - $cursor);
                $this->assertOutputSize($html);
            }
            $cursor = $offset + strlen($token);

            $inner = trim($match[1][0]);
            if ($inner === '') {
                throw new ValidationFailed(['body' => 'An empty {{ }} placeholder cannot be rendered.']);
            }

            if ($inner[0] === '#') {
                if (! preg_match('/^#(if|unless)\s+(\S{1,96})$/', $inner, $open)) {
                    throw new ValidationFailed(['body' => sprintf(
                        'Unsupported template directive {{%s}}. Only {{#if key}} and {{#unless key}} are understood.',
                        self::excerpt($inner)
                    )]);
                }

                $key = $open[2];
                $this->assertRegistered($key, $allowed);

                if (count($stack) >= self::MAX_DEPTH) {
                    throw new ValidationFailed(['body' => sprintf(
                        'Conditional blocks are nested more than %d deep.',
                        self::MAX_DEPTH
                    )]);
                }

                if ($suppressed === 0) {
                    $used[$key] = true;
                }

                $truthy  = self::isTruthy($this->lookup($bag, $allowed[$key]));
                $entered = $open[1] === 'if' ? $truthy : ! $truthy;

                $stack[] = ['directive' => $open[1], 'entered' => $entered];
                if (! $entered) {
                    $suppressed++;
                }

                continue;
            }

            if ($inner[0] === '/') {
                if (! preg_match('#^/(if|unless)$#', $inner, $close)) {
                    throw new ValidationFailed(['body' => sprintf(
                        'Unsupported closing directive {{%s}}.',
                        self::excerpt($inner)
                    )]);
                }

                $opened = array_pop($stack);
                if ($opened === null || $opened['directive'] !== $close[1]) {
                    throw new ValidationFailed(['body' => sprintf(
                        '{{/%s}} does not close an open block.',
                        $close[1]
                    )]);
                }
                if (! $opened['entered']) {
                    $suppressed--;
                }

                continue;
            }

            // A key is validated even inside a block that will not be rendered:
            // an unknown variable is a fault in the template, and hiding it
            // until the day the condition flips is how a broken document
            // reaches a counterparty.
            $this->assertRegistered($inner, $allowed);
            if ($suppressed > 0) {
                continue;
            }

            $used[$inner] = true;
            $value        = $this->lookup($bag, $allowed[$inner]);

            if ($value === null || $value === '') {
                $missing[$inner] = true;

                continue;
            }

            $html .= htmlspecialchars(
                mb_substr($value, 0, self::MAX_VALUE_LENGTH),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $this->assertOutputSize($html);
        }

        if ($stack !== []) {
            $unclosed = end($stack);
            throw new ValidationFailed(['body' => sprintf(
                'A {{#%s}} block was never closed.',
                is_array($unclosed) ? (string) $unclosed['directive'] : 'if'
            )]);
        }

        if ($suppressed === 0) {
            $html .= substr($body, $cursor);
            $this->assertOutputSize($html);
        }

        return [
            'html'    => $html,
            'missing' => array_keys($missing),
            'used'    => array_keys($used),
        ];
    }

    /**
     * Every merge variable a body references, in first-appearance order.
     *
     * Deliberately tolerant where render() is strict: this runs on a body
     * somebody is still typing, and it feeds `contract_templates.variables` and
     * the "unknown variable" check at save time. Anything malformed is simply
     * not a variable, and render() is where that becomes an error.
     *
     * @return list<string>
     */
    public function extractVariables(string $body): array
    {
        preg_match_all(self::TOKEN, $body, $matches);

        $keys = [];
        foreach ($matches[1] ?? [] as $raw) {
            $inner = trim((string) $raw);

            if (preg_match('/^#(?:if|unless)\s+(\S{1,96})$/', $inner, $m)) {
                $inner = $m[1];
            }

            if (preg_match(self::KEY_SHAPE, $inner)) {
                $keys[$inner] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * The data a render may read, arranged by the `source` values the registry
     * uses.
     *
     * Built once and handed in whole rather than resolved per key, so a lookup
     * is an array index into data this tenant already had in hand. Nothing here
     * queries anything: the caller has already loaded the contract under its
     * own tenant filter, and this only reshapes it.
     *
     * @param array<string,mixed>|null $contract
     * @param array<string,mixed>|null $counterpartySnapshot a contract_party_snapshots row
     * @param array<string,mixed>|null $commercial           a contract_commercial_terms row
     * @return array<string, array<string, string|null>>
     */
    public function buildBag(
        TenantContext $ctx,
        ?array $contract = null,
        ?array $counterpartySnapshot = null,
        ?array $commercial = null
    ): array {
        $company      = is_array($ctx->company) ? $ctx->company : [];
        $contract     = $contract ?? [];
        $counterparty = $counterpartySnapshot ?? [];
        $terms        = $commercial ?? [];

        return [
            'company' => [
                'legal_name'   => self::text($ctx->companyName()),
                'trading_name' => self::first($company, ['trading_name', 'brand_name', 'display_name', 'name']),
                'address'      => self::first($company, ['address', 'registered_address', 'billing_address', 'address_line1']),
                'gstin'        => self::first($company, ['gstin', 'gst_no', 'gst_number']),
                'pan'          => self::first($company, ['pan', 'pan_no', 'pan_number']),
                'cin'          => self::first($company, ['cin', 'cin_no', 'registration_number']),
                'email'        => self::first($company, ['email', 'company_email', 'contact_email']),
                'phone'        => self::first($company, ['phone', 'mobile', 'contact_number']),
            ],

            // The snapshot is preferred over the contract's own counterparty
            // name because it is the record of who the agreement was actually
            // made with; the live name in Contacts may since have changed.
            'counterparty' => [
                'legal_name'                 => self::first($counterparty, ['legal_name']) ?? self::first($contract, ['counterparty_name']),
                'trading_name'               => self::first($counterparty, ['trading_name']),
                'registered_address'         => self::first($counterparty, ['registered_address']),
                'address'                    => self::first($counterparty, ['registered_address']),
                'gstin'                      => self::first($counterparty, ['gstin']),
                'pan'                        => self::first($counterparty, ['pan']),
                'cin'                        => self::first($counterparty, ['cin']),
                'email'                      => self::first($counterparty, ['email']),
                'phone'                      => self::first($counterparty, ['phone']),
                'authorised_representative'  => self::first($counterparty, ['authorised_representative']),
                'representative_designation' => self::first($counterparty, ['representative_designation']),
            ],

            'contract' => [
                'contract_number'    => self::first($contract, ['contract_number']),
                'title'              => self::first($contract, ['title']),
                'description'        => self::first($contract, ['description']),
                'status'             => self::first($contract, ['status']),
                'effective_date'     => self::first($contract, ['effective_date']),
                'commencement_date'  => self::first($contract, ['commencement_date']),
                'execution_date'     => self::first($contract, ['execution_date']),
                'expiry_date'        => self::first($contract, ['expiry_date']),
                'notice_period_days' => self::first($contract, ['notice_period_days']),
                'notice_deadline'    => self::first($contract, ['notice_deadline']),
                'governing_law'      => self::first($contract, ['governing_law']),
                'jurisdiction'       => self::first($contract, ['jurisdiction']),
                'currency'           => self::first($contract, ['currency']),
                'counterparty_name'  => self::first($contract, ['counterparty_name']),
                'total_value'        => self::first($contract, ['total_value']),
                'recurring_value'    => self::first($contract, ['recurring_value']),
                'payment_frequency'  => self::first($contract, ['payment_frequency']),
                'billing_frequency'  => self::first($contract, ['billing_frequency']),
                'renewal_type'       => self::first($contract, ['renewal_type']),
                'renewal_frequency'  => self::first($contract, ['renewal_frequency']),
                'auto_renewal'       => self::first($contract, ['auto_renewal']),
            ],

            // Commercial terms live in their own table but a contract that has
            // no row there still has figures on the contract record, and a
            // template asking for the value should print the one the company
            // recorded rather than a blank.
            'commercial' => [
                'currency'           => self::first($terms, ['currency']) ?? self::first($contract, ['currency']),
                'total_value'        => self::first($terms, ['total_value']) ?? self::first($contract, ['total_value']),
                'recurring_amount'   => self::first($terms, ['recurring_amount']) ?? self::first($contract, ['recurring_value']),
                'billing_frequency'  => self::first($terms, ['billing_frequency']) ?? self::first($contract, ['billing_frequency']),
                'payment_terms_days' => self::first($terms, ['payment_terms_days']),
                'payment_terms_note' => self::first($terms, ['payment_terms_note']),
                'value_direction'    => self::first($terms, ['value_direction']),
                'advance_amount'     => self::first($terms, ['advance_amount']),
                'advance_percent'    => self::first($terms, ['advance_percent']),
                'security_deposit'   => self::first($terms, ['security_deposit']),
                'minimum_purchase'   => self::first($terms, ['minimum_purchase']),
                'unit_rate'          => self::first($terms, ['unit_rate']),
                'quantity_unit'      => self::first($terms, ['quantity_unit']),
            ],

            'system' => [
                'today'        => Dates::today(),
                'year'         => substr(Dates::today(), 0, 4),
                'company_name' => self::text($ctx->companyName()),
            ],

            'custom' => self::flattenCustom($contract['custom_fields'] ?? null),
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function assertSize(string $body): void
    {
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new ValidationFailed(['body' => sprintf(
                'A template body may not exceed %d KB.',
                (int) (self::MAX_BODY_BYTES / 1024)
            )]);
        }
    }

    /**
     * Refuse a render that has already produced more than a document's worth of
     * output.
     *
     * The body is capped, but a body of nothing but placeholders multiplied by
     * the value cap is still two orders of magnitude larger than the template
     * that produced it.
     */
    private function assertOutputSize(string $html): void
    {
        if (strlen($html) > self::MAX_OUTPUT_BYTES) {
            throw new ValidationFailed(['body' => sprintf(
                'This template renders to more than %d KB of document.',
                (int) (self::MAX_OUTPUT_BYTES / 1024)
            )]);
        }
    }

    /** @param array<string,array{source: string, source_path: string}> $allowed */
    private function assertRegistered(string $key, array $allowed): void
    {
        if (! preg_match(self::KEY_SHAPE, $key)) {
            throw new ValidationFailed(['body' => sprintf(
                '"%s" is not a valid merge variable name.',
                self::excerpt($key)
            )]);
        }

        if (! isset($allowed[$key])) {
            throw new ValidationFailed(['body' => sprintf(
                'Unknown merge variable {{ %s }}. Add it to the variable list before using it in a template.',
                $key
            )]);
        }
    }

    /**
     * Read one registry entry's value out of the bag.
     *
     * The path comes from the registry row, never from the template, and is
     * walked one array key at a time. A path that reaches an array, an object
     * or nothing at all resolves to null rather than to a rendered structure.
     *
     * @param array<string,mixed>                     $bag
     * @param array{source: string, source_path: string} $variable
     */
    private function lookup(array $bag, array $variable): ?string
    {
        if ($variable['source'] === '' || $variable['source_path'] === '') {
            return null;
        }

        $node = $bag[$variable['source']] ?? null;
        foreach (explode('.', $variable['source_path']) as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return self::text($node);
    }

    /**
     * @param array<string,mixed> $variables
     * @return array<string,array{source: string, source_path: string}>
     */
    private static function indexVariables(array $variables): array
    {
        $indexed = [];

        foreach ($variables as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            $varKey = is_string($key) && $key !== '' ? $key : (string) ($row['var_key'] ?? '');
            if (! preg_match(self::KEY_SHAPE, $varKey)) {
                continue;
            }

            $indexed[$varKey] = [
                'source'      => (string) ($row['source'] ?? ''),
                'source_path' => (string) ($row['source_path'] ?? ''),
            ];
        }

        return $indexed;
    }

    /**
     * Whether a conditional block renders.
     *
     * `'f'` has to be false explicitly: PostgreSQL hands booleans back through
     * PDO as 't'/'f', and a bare truthiness test on a non-empty string would
     * make every false flag render its block.
     */
    private static function isTruthy(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }

        return ! in_array(strtolower($trimmed), ['0', '0.00', 'f', 'false', 'no', 'off', 'null'], true);
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private static function first(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = self::text($row[$key] ?? null);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** A bag leaf is a string or nothing. An array never renders. */
    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * The contract's custom fields, flattened to scalars.
     *
     * A custom field holding a list would otherwise render as the word "Array";
     * a list is joined, and anything deeper is dropped rather than guessed at.
     *
     * @return array<string,string|null>
     */
    private static function flattenCustom(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw     = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $flat = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]*$/', $key)) {
                continue;
            }

            if (is_array($value)) {
                // A list — a multi-select field — joins. A map is a shape this
                // has no way to render honestly, so it renders as nothing
                // rather than as its values with the keys silently dropped.
                $scalars    = array_is_list($value) ? array_filter($value, is_scalar(...)) : [];
                $flat[$key] = $scalars === [] ? null : implode(', ', array_map(strval(...), $scalars));

                continue;
            }

            $flat[$key] = self::text($value);
        }

        return $flat;
    }

    private static function excerpt(string $value): string
    {
        return mb_substr($value, 0, 64);
    }
}
