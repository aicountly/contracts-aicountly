<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Request-body validation, collecting every problem rather than stopping at the
 * first.
 *
 * A form that reports one error, then another after the user fixes it, then a
 * third, is the reason people abandon a screen. Every rule runs; the caller
 * gets the whole map at once.
 *
 * Usage:
 *   $v = new Validator($body);
 *   $title = $v->requiredString('title', 255);
 *   $date  = $v->date('effective_date');
 *   $v->assert();   // throws ValidationFailed if anything failed
 */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->data);
    }

    public function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    public function requiredString(string $field, int $max = 255, int $min = 1): string
    {
        $value = $this->data[$field] ?? null;
        if (! is_string($value) && ! is_numeric($value)) {
            $this->errors[$field] = 'This field is required.';

            return '';
        }

        $value = trim((string) $value);
        if (mb_strlen($value) < $min) {
            $this->errors[$field] = $min === 1 ? 'This field is required.' : "Enter at least {$min} characters.";

            return '';
        }
        if (mb_strlen($value) > $max) {
            $this->errors[$field] = "Keep this under {$max} characters.";

            return mb_substr($value, 0, $max);
        }

        return $value;
    }

    public function optionalString(string $field, int $max = 255, ?string $default = null): ?string
    {
        if (! array_key_exists($field, $this->data)) {
            return $default;
        }

        $value = $this->data[$field];
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) && ! is_numeric($value)) {
            $this->errors[$field] = 'This value is not valid.';

            return $default;
        }

        $value = trim((string) $value);
        if (mb_strlen($value) > $max) {
            $this->errors[$field] = "Keep this under {$max} characters.";

            return mb_substr($value, 0, $max);
        }

        return $value === '' ? null : $value;
    }

    public function optionalText(string $field, int $max = 20000): ?string
    {
        return $this->optionalString($field, $max);
    }

    public function requiredEnum(string $field, array $allowed): string
    {
        $value = Enums::coerce($this->data[$field] ?? null, $allowed);
        if ($value === null) {
            $this->errors[$field] = 'Choose one of: ' . implode(', ', $allowed) . '.';

            return $allowed[0] ?? '';
        }

        return $value;
    }

    public function optionalEnum(string $field, array $allowed, ?string $default = null): ?string
    {
        if (! array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            return $default;
        }

        $value = Enums::coerce($this->data[$field], $allowed);
        if ($value === null) {
            $this->errors[$field] = 'Choose one of: ' . implode(', ', $allowed) . '.';

            return $default;
        }

        return $value;
    }

    public function optionalInt(string $field, ?int $min = null, ?int $max = null, ?int $default = null): ?int
    {
        if (! array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            return $default;
        }

        $raw = $this->data[$field];
        if (! is_int($raw) && ! (is_string($raw) && preg_match('/^-?\d+$/', trim($raw)))) {
            $this->errors[$field] = 'Enter a whole number.';

            return $default;
        }

        $value = (int) $raw;
        if ($min !== null && $value < $min) {
            $this->errors[$field] = "Enter {$min} or more.";

            return $default;
        }
        if ($max !== null && $value > $max) {
            $this->errors[$field] = "Enter {$max} or less.";

            return $default;
        }

        return $value;
    }

    public function optionalId(string $field): ?int
    {
        $value = $this->optionalInt($field, 1);

        return $value === 0 ? null : $value;
    }

    /**
     * A monetary amount.
     *
     * Returned as a string, not a float: money in a float is a rounding error
     * waiting to reach a contract value. PostgreSQL NUMERIC accepts the string
     * and keeps the precision.
     */
    public function optionalDecimal(string $field, int $scale = 2, ?string $default = null): ?string
    {
        if (! array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            return $default;
        }

        $raw = $this->data[$field];
        if (is_float($raw) || is_int($raw)) {
            $raw = (string) $raw;
        }
        if (! is_string($raw)) {
            $this->errors[$field] = 'Enter an amount.';

            return $default;
        }

        $clean = str_replace([',', ' '], '', trim($raw));
        if (! preg_match('/^-?\d{1,16}(\.\d{1,6})?$/', $clean)) {
            $this->errors[$field] = 'Enter a valid amount.';

            return $default;
        }

        return number_format((float) $clean, $scale, '.', '');
    }

    public function optionalBool(string $field, ?bool $default = null): ?bool
    {
        if (! array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            return $default;
        }

        $raw = $this->data[$field];
        if (is_bool($raw)) {
            return $raw;
        }
        $lower = strtolower(trim((string) $raw));
        if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($lower, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        $this->errors[$field] = 'Enter yes or no.';

        return $default;
    }

    /** ISO date `YYYY-MM-DD`, validated as a real calendar date. */
    public function optionalDate(string $field, ?string $default = null): ?string
    {
        if (! array_key_exists($field, $this->data) || $this->data[$field] === null || $this->data[$field] === '') {
            return $default;
        }

        $raw = $this->data[$field];
        if (! is_string($raw)) {
            $this->errors[$field] = 'Enter a date as YYYY-MM-DD.';

            return $default;
        }

        // Accept a full ISO timestamp too — a browser date-time input and a
        // JSON round trip both produce one, and truncating is kinder than
        // rejecting.
        $raw = trim($raw);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $raw, $m)) {
            $raw = $m[1];
        }

        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
            $this->errors[$field] = 'Enter a date as YYYY-MM-DD.';

            return $default;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $this->errors[$field] = 'That date does not exist.';

            return $default;
        }

        $year = (int) $m[1];
        if ($year < 1900 || $year > 2200) {
            $this->errors[$field] = 'Enter a year between 1900 and 2200.';

            return $default;
        }

        return $raw;
    }

    public function requiredDate(string $field): string
    {
        $value = $this->optionalDate($field);
        if ($value === null && ! isset($this->errors[$field])) {
            $this->errors[$field] = 'This date is required.';
        }

        return $value ?? '';
    }

    public function optionalCurrency(string $field, string $default = 'INR'): string
    {
        $value = $this->optionalString($field, 3);
        if ($value === null) {
            return $default;
        }

        $upper = strtoupper($value);
        if (! preg_match('/^[A-Z]{3}$/', $upper)) {
            $this->errors[$field] = 'Enter a 3-letter currency code, such as INR.';

            return $default;
        }

        return $upper;
    }

    public function optionalEmail(string $field): ?string
    {
        $value = $this->optionalString($field, 255);
        if ($value === null) {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[$field] = 'Enter a valid email address.';

            return null;
        }

        return $value;
    }

    /** @return list<mixed> */
    public function optionalArray(string $field, int $maxItems = 200): array
    {
        if (! array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return [];
        }

        $raw = $this->data[$field];
        if (! is_array($raw)) {
            $this->errors[$field] = 'Expected a list.';

            return [];
        }
        if (count($raw) > $maxItems) {
            $this->errors[$field] = "Provide at most {$maxItems} items.";

            return array_slice(array_values($raw), 0, $maxItems);
        }

        return array_values($raw);
    }

    /** @return array<string,mixed> */
    public function optionalObject(string $field): array
    {
        if (! array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return [];
        }

        $raw = $this->data[$field];
        if (! is_array($raw)) {
            $this->errors[$field] = 'Expected an object.';

            return [];
        }

        return $raw;
    }

    public function fail(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function failed(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @throws ValidationFailed */
    public function assert(): void
    {
        if ($this->errors !== []) {
            throw new ValidationFailed($this->errors);
        }
    }
}
