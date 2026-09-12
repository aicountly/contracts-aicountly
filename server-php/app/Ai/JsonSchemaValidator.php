<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Checks a decoded model response against the schema we asked for, and fixes
 * the differences that are safe to fix.
 *
 * A structured-output flag is a request, not a contract: every provider will
 * occasionally return `"1500.00"` where the schema said number, `"true"` where
 * it said boolean, or a full ISO timestamp where it said date. Rejecting the
 * whole extraction over that wastes a paid call and a minute of the user's
 * time on something we can resolve without guessing.
 *
 * The line this class does not cross is ambiguity. `"1500.00"` has exactly one
 * numeric reading, so it is coerced. `1` as a boolean has two readings, so it
 * is an error. `2026-01-31T00:00:00Z` names one calendar day in UTC, so it is
 * truncated; `2026-02-01T02:00:00+05:30` names a different day depending on
 * which zone you read it in, so it is refused rather than silently filed under
 * the wrong month — a date is a contract deadline here, and a deadline that is
 * one day out is the bug this product exists to prevent.
 *
 * The supported subset is: type (object, array, string, number, integer,
 * boolean, null), properties, required, items, enum, minimum, maximum,
 * minLength, maxLength, pattern, additionalProperties, nullable. Anything else
 * in a schema is ignored rather than treated as a failure, so a schema written
 * for a provider's richer dialect still validates here.
 *
 * `additionalProperties: false` is reported as an error rather than quietly
 * dropping the extra keys, because a model inventing a field it was not asked
 * for is worth knowing about. A caller that does not care simply omits the
 * keyword.
 */
final class JsonSchemaValidator
{
    /** Guards against a pathological schema or a deeply self-similar response. */
    private const MAX_DEPTH = 24;

    /**
     * @param  array<string,mixed> $schema
     * @return array{valid: bool, errors: list<string>, value: mixed}
     *         `value` is the coerced document; it is only meaningful when `valid` is true.
     */
    public static function validate(mixed $value, array $schema): array
    {
        $errors = [];
        $out    = self::check($value, $schema, '$', $errors, 0);

        return ['valid' => $errors === [], 'errors' => array_values($errors), 'value' => $out];
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string>        $errors
     */
    private static function check(mixed $value, array $schema, string $path, array &$errors, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            $errors[] = "{$path}: structure is nested too deeply to validate.";

            return $value;
        }

        $types = self::types($schema);

        if ($value === null) {
            if (in_array('null', $types, true) || $types === []) {
                return null;
            }
            $errors[] = "{$path}: expected " . self::describeTypes($types) . ', got null.';

            return null;
        }

        if ($types === []) {
            return $value;
        }

        $coerced = null;
        $matched = null;
        foreach ($types as $type) {
            $attempt = self::coerceTo($value, $type);
            if ($attempt !== null) {
                $coerced = $attempt['value'];
                $matched = $type;
                break;
            }
        }

        if ($matched === null) {
            $errors[] = "{$path}: expected " . self::describeTypes($types)
                . ', got ' . self::describe($value) . '.';

            return $value;
        }

        $coerced = match ($matched) {
            'object'           => self::checkObject($coerced, $schema, $path, $errors, $depth),
            'array'            => self::checkArray($coerced, $schema, $path, $errors, $depth),
            'string'           => self::checkString($coerced, $schema, $path, $errors),
            'number', 'integer' => self::checkNumber($coerced, $schema, $path, $errors),
            default            => $coerced,
        };

        return self::checkEnum($coerced, $schema, $path, $errors);
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string>        $errors
     * @return array<string,mixed>
     */
    private static function checkObject(mixed $value, array $schema, string $path, array &$errors, int $depth): array
    {
        /** @var array<string,mixed> $value */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required   = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $name) {
            if (! is_string($name)) {
                continue;
            }
            if (! array_key_exists($name, $value)) {
                $errors[] = "{$path}: missing required property '{$name}'.";
            }
        }

        foreach ($properties as $name => $sub) {
            if (! is_string($name) || ! is_array($sub) || ! array_key_exists($name, $value)) {
                continue;
            }
            $value[$name] = self::check($value[$name], $sub, self::child($path, $name), $errors, $depth + 1);
        }

        if (($schema['additionalProperties'] ?? true) === false && $properties !== []) {
            $extra = array_diff(array_keys($value), array_keys($properties));
            foreach ($extra as $name) {
                $errors[] = "{$path}: unexpected property '{$name}'.";
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string>        $errors
     * @return list<mixed>
     */
    private static function checkArray(mixed $value, array $schema, string $path, array &$errors, int $depth): array
    {
        /** @var list<mixed> $value */
        $items = is_array($schema['items'] ?? null) ? $schema['items'] : null;
        if ($items === null) {
            return $value;
        }

        foreach ($value as $index => $item) {
            $value[$index] = self::check($item, $items, "{$path}[{$index}]", $errors, $depth + 1);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string>        $errors
     */
    private static function checkString(mixed $value, array $schema, string $path, array &$errors): string
    {
        /** @var string $value */
        if (($schema['format'] ?? null) === 'date') {
            $date = self::toDate($value);
            if ($date === null) {
                $errors[] = "{$path}: expected a date as YYYY-MM-DD, got " . self::describe($value) . '.';

                return $value;
            }
            $value = $date;
        }

        $length = mb_strlen($value);
        if (isset($schema['minLength']) && is_numeric($schema['minLength']) && $length < (int) $schema['minLength']) {
            $errors[] = "{$path}: expected at least {$schema['minLength']} characters, got {$length}.";
        }
        if (isset($schema['maxLength']) && is_numeric($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $errors[] = "{$path}: expected at most {$schema['maxLength']} characters, got {$length}.";
        }

        if (isset($schema['pattern']) && is_string($schema['pattern']) && $schema['pattern'] !== '') {
            $delimited = '#' . str_replace('#', '\#', $schema['pattern']) . '#u';
            $matches   = @preg_match($delimited, $value);
            if ($matches === false) {
                // A schema we wrote carries a broken regex: that is our bug, not
                // the model's, and failing the extraction over it would hide it.
                $errors[] = "{$path}: pattern in the schema is not a usable expression.";
            } elseif ($matches === 0) {
                $errors[] = "{$path}: value does not match the required pattern.";
            }
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string>        $errors
     */
    private static function checkNumber(mixed $value, array $schema, string $path, array &$errors): int|float
    {
        /** @var int|float $value */
        if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum'] + 0) {
            $errors[] = "{$path}: expected {$schema['minimum']} or more, got {$value}.";
        }
        if (isset($schema['maximum']) && is_numeric($schema['maximum']) && $value > $schema['maximum'] + 0) {
            $errors[] = "{$path}: expected {$schema['maximum']} or less, got {$value}.";
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string>        $errors
     */
    private static function checkEnum(mixed $value, array $schema, string $path, array &$errors): mixed
    {
        $enum = $schema['enum'] ?? null;
        if (! is_array($enum) || $enum === []) {
            return $value;
        }

        if (in_array($value, $enum, true)) {
            return $value;
        }

        // A member matched by value but not by type — the model returned 30
        // where the enum holds "30", or vice versa. Adopting the member's own
        // representation is unambiguous, which is the whole test for coercion.
        if (is_scalar($value)) {
            foreach ($enum as $member) {
                if (is_scalar($member) && (string) $member === (string) $value) {
                    return $member;
                }
            }
        }

        $allowed  = implode(', ', array_map(static fn (mixed $m): string => self::describe($m), $enum));
        $errors[] = "{$path}: expected one of [{$allowed}], got " . self::describe($value) . '.';

        return $value;
    }

    /**
     * Try to read $value as $type, returning null when it cannot be done
     * without guessing.
     *
     * @return array{value: mixed}|null
     */
    private static function coerceTo(mixed $value, string $type): ?array
    {
        switch ($type) {
            case 'null':
                return $value === null ? ['value' => null] : null;

            case 'boolean':
                if (is_bool($value)) {
                    return ['value' => $value];
                }
                // Only the two words. `1` and `"yes"` are conventions, not
                // facts, and a wrong auto_renewal flag renews a contract.
                if (is_string($value)) {
                    $lower = strtolower(trim($value));
                    if ($lower === 'true') {
                        return ['value' => true];
                    }
                    if ($lower === 'false') {
                        return ['value' => false];
                    }
                }

                return null;

            case 'integer':
                if (is_int($value)) {
                    return ['value' => $value];
                }
                if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= PHP_INT_MAX) {
                    return ['value' => (int) $value];
                }
                if (is_string($value) && preg_match('/^-?\d{1,18}(\.0+)?$/', trim($value)) === 1) {
                    return ['value' => (int) (float) trim($value)];
                }

                return null;

            case 'number':
                if (is_int($value) || (is_float($value) && is_finite($value))) {
                    return ['value' => $value];
                }
                if (is_string($value)) {
                    // Thousands separators are stripped, but only in the exact
                    // 1,234,567.89 grouping: "1,23" is a European decimal comma
                    // in half the world and a typo in the other half.
                    $clean = trim(str_replace(' ', '', $value));
                    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $clean) === 1) {
                        $clean = str_replace(',', '', $clean);
                    }
                    if (preg_match('/^-?\d+(\.\d+)?$/', $clean) === 1) {
                        return ['value' => $clean + 0];
                    }
                }

                return null;

            case 'string':
                if (is_string($value)) {
                    return ['value' => $value];
                }
                // A number read as text loses nothing; a boolean would have to
                // choose between "true", "1" and "yes", so it is refused.
                if (is_int($value) || (is_float($value) && is_finite($value))) {
                    return ['value' => (string) $value];
                }

                return null;

            case 'object':
                // json_decode gives [] for both {} and []; an empty value is
                // accepted as either rather than guessed at.
                return is_array($value) && ($value === [] || ! array_is_list($value)) ? ['value' => $value] : null;

            case 'array':
                return is_array($value) && ($value === [] || array_is_list($value)) ? ['value' => array_values($value)] : null;

            default:
                return ['value' => $value];
        }
    }

    /**
     * A date-only string, or null when the value does not name one calendar
     * day without ambiguity.
     */
    private static function toDate(string $value): ?string
    {
        $value = trim($value);

        // A timestamp is truncated only when it is UTC or carries no zone at
        // all. With an offset, the calendar day depends on where you stand.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?[ ]?(?:Z|UTC|\+00:?00|-00:?00)?)?$/i', $value, $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    /**
     * @param  array<string,mixed> $schema
     * @return list<string>
     */
    private static function types(array $schema): array
    {
        $raw = $schema['type'] ?? null;
        $out = [];

        foreach (is_array($raw) ? $raw : [$raw] as $type) {
            if (is_string($type) && $type !== '') {
                $out[] = strtolower($type);
            }
        }

        if (($schema['nullable'] ?? false) === true && ! in_array('null', $out, true)) {
            $out[] = 'null';
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $types */
    private static function describeTypes(array $types): string
    {
        return $types === [] ? 'a value' : implode(' or ', $types);
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . (mb_strlen($value) > 40 ? mb_substr($value, 0, 40) . '…' : $value) . '"',
            is_bool($value)   => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null   => 'null',
            is_array($value)  => ($value === [] || array_is_list($value)) ? 'array' : 'object',
            default           => get_debug_type($value),
        };
    }

    private static function child(string $path, string $name): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1
            ? "{$path}.{$name}"
            : "{$path}['{$name}']";
    }
}
