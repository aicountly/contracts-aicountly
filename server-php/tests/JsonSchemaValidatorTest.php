<?php

declare(strict_types=1);

/**
 * The two halves of reading a model's answer: recovering JSON from the text it
 * actually sent, and deciding whether that JSON is the shape we asked for.
 *
 * Both are exercised here because they are one path in practice — every AI
 * caller runs AiResponseRepair::decode() and then JsonSchemaValidator::validate()
 * on the result — and the interesting failures are on the boundary between
 * them: text that decodes but is the wrong shape, and text that is the right
 * shape but had to be repaired first.
 *
 * No database and no network: this is pure logic and must stay runnable in CI
 * with neither.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Ai\AiResponseRepair;
use App\Ai\JsonSchemaValidator;

// ---------------------------------------------------------------------------
// type
// ---------------------------------------------------------------------------

$result = JsonSchemaValidator::validate(['title' => 'MSA'], ['type' => 'object']);
assert_true($result['valid'], 'an object matches type object');

$result = JsonSchemaValidator::validate(['a', 'b'], ['type' => 'object']);
assert_false($result['valid'], 'a list does not match type object');

$result = JsonSchemaValidator::validate(['a', 'b'], ['type' => 'array']);
assert_true($result['valid'], 'a list matches type array');

$result = JsonSchemaValidator::validate(['a' => 1], ['type' => 'array']);
assert_false($result['valid'], 'an object does not match type array');

$result = JsonSchemaValidator::validate('draft', ['type' => 'string']);
assert_true($result['valid'], 'a string matches type string');

$result = JsonSchemaValidator::validate(true, ['type' => 'string']);
assert_false($result['valid'], 'a boolean is not accepted as a string');

$result = JsonSchemaValidator::validate(12, ['type' => 'integer']);
assert_true($result['valid'], 'an int matches type integer');

$result = JsonSchemaValidator::validate(12.5, ['type' => 'integer']);
assert_false($result['valid'], 'a fractional float is not an integer');

$result = JsonSchemaValidator::validate(12.5, ['type' => 'number']);
assert_true($result['valid'], 'a float matches type number');

$result = JsonSchemaValidator::validate(false, ['type' => 'boolean']);
assert_true($result['valid'], 'a bool matches type boolean');

$result = JsonSchemaValidator::validate(null, ['type' => 'null']);
assert_true($result['valid'], 'null matches type null');

// nullable, and the union spelling of the same thing
$result = JsonSchemaValidator::validate(null, ['type' => 'string', 'nullable' => true]);
assert_true($result['valid'], 'nullable allows null');

$result = JsonSchemaValidator::validate(null, ['type' => 'string']);
assert_false($result['valid'], 'a non-nullable string rejects null');

$result = JsonSchemaValidator::validate(null, ['type' => ['string', 'null']]);
assert_true($result['valid'], 'a [string, null] union allows null');

// ---------------------------------------------------------------------------
// properties, required, additionalProperties
// ---------------------------------------------------------------------------

$contract = [
    'type'       => 'object',
    'properties' => [
        'title'       => ['type' => 'string'],
        'expiry_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
        'value'       => ['type' => 'number', 'nullable' => true],
    ],
    'required'   => ['title'],
];

$result = JsonSchemaValidator::validate(['title' => 'Master Services Agreement'], $contract);
assert_true($result['valid'], 'a required property that is present passes');

$result = JsonSchemaValidator::validate(['expiry_date' => '2026-03-31'], $contract);
assert_false($result['valid'], 'a missing required property fails');
assert_contains("missing required property 'title'", $result['errors'][0], 'the error names the missing property');

$result = JsonSchemaValidator::validate(['title' => 42], $contract);
assert_same('42', $result['value']['title'], 'a numeric title is coerced to a string');

$strict = $contract + ['additionalProperties' => false];
$result = JsonSchemaValidator::validate(['title' => 'MSA', 'invented' => 'x'], $strict);
assert_false($result['valid'], 'additionalProperties false rejects an unexpected key');
assert_contains("unexpected property 'invented'", $result['errors'][0], 'the error names the unexpected property');

$result = JsonSchemaValidator::validate(['title' => 'MSA', 'invented' => 'x'], $contract);
assert_true($result['valid'], 'an extra key is allowed when additionalProperties is not set');

// Nested paths are reported so a developer can find the field in a 40-key extraction.
$nested = [
    'type'       => 'object',
    'properties' => [
        'parties' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => ['name' => ['type' => 'string'], 'role' => ['type' => 'string']],
                'required'   => ['name'],
            ],
        ],
    ],
];

$result = JsonSchemaValidator::validate(['parties' => [['name' => 'Acme'], ['role' => 'vendor']]], $nested);
assert_false($result['valid'], 'a required property missing inside an array item fails');
assert_contains('$.parties[1]', $result['errors'][0], 'the error path points at the offending item');

// ---------------------------------------------------------------------------
// items
// ---------------------------------------------------------------------------

$obligations = ['type' => 'array', 'items' => ['type' => 'string']];

$result = JsonSchemaValidator::validate(['pay', 'report'], $obligations);
assert_true($result['valid'], 'a list of strings matches items string');

$result = JsonSchemaValidator::validate(['pay', ['nested' => true]], $obligations);
assert_false($result['valid'], 'a wrongly typed item fails');

// ---------------------------------------------------------------------------
// enum
// ---------------------------------------------------------------------------

$risk = ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']];

$result = JsonSchemaValidator::validate('high', $risk);
assert_true($result['valid'], 'an enum member passes');

$result = JsonSchemaValidator::validate('catastrophic', $risk);
assert_false($result['valid'], 'a value outside the enum fails');
assert_contains('expected one of', $result['errors'][0], 'the enum error lists what was allowed');

$result = JsonSchemaValidator::validate(30, ['type' => 'string', 'enum' => ['30', '60', '90']]);
assert_true($result['valid'], 'a number matching an enum member by value is accepted');
assert_same('30', $result['value'], 'and takes the member\'s own representation');

// ---------------------------------------------------------------------------
// minimum / maximum / minLength / maxLength / pattern
// ---------------------------------------------------------------------------

$score = ['type' => 'integer', 'minimum' => 0, 'maximum' => 100];

assert_true(JsonSchemaValidator::validate(0, $score)['valid'], 'minimum is inclusive');
assert_true(JsonSchemaValidator::validate(100, $score)['valid'], 'maximum is inclusive');
assert_false(JsonSchemaValidator::validate(-1, $score)['valid'], 'below minimum fails');
assert_false(JsonSchemaValidator::validate(101, $score)['valid'], 'above maximum fails');

$code = ['type' => 'string', 'minLength' => 2, 'maxLength' => 4];

assert_true(JsonSchemaValidator::validate('INR', $code)['valid'], 'a length inside the bounds passes');
assert_false(JsonSchemaValidator::validate('I', $code)['valid'], 'below minLength fails');
assert_false(JsonSchemaValidator::validate('RUPEE', $code)['valid'], 'above maxLength fails');

$currency = ['type' => 'string', 'pattern' => '^[A-Z]{3}$'];

assert_true(JsonSchemaValidator::validate('USD', $currency)['valid'], 'a matching pattern passes');
assert_false(JsonSchemaValidator::validate('usd', $currency)['valid'], 'a non-matching pattern fails');

// ---------------------------------------------------------------------------
// Coercion: safe cases
// ---------------------------------------------------------------------------

$result = JsonSchemaValidator::validate('1500.50', ['type' => 'number']);
assert_true($result['valid'], 'a numeric string is accepted as a number');
assert_same(1500.5, $result['value'], 'and comes back as a float');

$result = JsonSchemaValidator::validate('90', ['type' => 'integer']);
assert_same(90, $result['value'], 'a whole numeric string becomes an int');

$result = JsonSchemaValidator::validate('90.0', ['type' => 'integer']);
assert_same(90, $result['value'], 'a string with a zero fraction becomes an int');

$result = JsonSchemaValidator::validate('1,250,000.00', ['type' => 'number']);
assert_same(1250000.0, $result['value'], 'a fully grouped thousands separator is stripped');

$result = JsonSchemaValidator::validate('true', ['type' => 'boolean']);
assert_same(true, $result['value'], '"true" becomes true');

$result = JsonSchemaValidator::validate('False', ['type' => 'boolean']);
assert_same(false, $result['value'], '"False" becomes false, case-insensitively');

$date = ['type' => 'string', 'format' => 'date'];

$result = JsonSchemaValidator::validate('2026-01-31T00:00:00Z', $date);
assert_true($result['valid'], 'a UTC timestamp is accepted for a date');
assert_same('2026-01-31', $result['value'], 'and is truncated to the calendar day');

$result = JsonSchemaValidator::validate('2026-01-31 09:30:00', $date);
assert_same('2026-01-31', $result['value'], 'a zoneless timestamp truncates to its own day');

$result = JsonSchemaValidator::validate('2026-01-31', $date);
assert_same('2026-01-31', $result['value'], 'a plain date is left alone');

// ---------------------------------------------------------------------------
// Coercion: the cases that must NOT be guessed
// ---------------------------------------------------------------------------

$result = JsonSchemaValidator::validate(1, ['type' => 'boolean']);
assert_false($result['valid'], '1 is not coerced to true — the reading is a convention, not a fact');

$result = JsonSchemaValidator::validate('yes', ['type' => 'boolean']);
assert_false($result['valid'], '"yes" is not coerced to a boolean');

$result = JsonSchemaValidator::validate('', ['type' => 'boolean']);
assert_false($result['valid'], 'an empty string is not coerced to false');

$result = JsonSchemaValidator::validate(true, ['type' => 'integer']);
assert_false($result['valid'], 'true is not coerced to 1');

$result = JsonSchemaValidator::validate('90 days', ['type' => 'integer']);
assert_false($result['valid'], 'a number with a unit is not silently truncated to the number');

$result = JsonSchemaValidator::validate('1,23', ['type' => 'number']);
assert_false($result['valid'], 'an ambiguous decimal comma is refused rather than read as 123 or 1.23');

$result = JsonSchemaValidator::validate('31/01/2026', $date);
assert_false($result['valid'], 'a non-ISO date is refused rather than assumed to be day-first');

$result = JsonSchemaValidator::validate('2026-02-30', $date);
assert_false($result['valid'], 'a date that does not exist is refused');

// The offset case: 2026-02-01T02:00+05:30 is 2026-01-31 in UTC, so truncating
// the text would file it under the wrong month.
$result = JsonSchemaValidator::validate('2026-02-01T02:00:00+05:30', $date);
assert_false($result['valid'], 'a timestamp with a non-UTC offset is refused, not truncated');

$result = JsonSchemaValidator::validate(null, ['type' => 'string', 'format' => 'date', 'nullable' => true]);
assert_null($result['value'], 'a nullable date stays null rather than becoming an empty string');

// ---------------------------------------------------------------------------
// A whole extraction, the way a service uses it
// ---------------------------------------------------------------------------

$schema = [
    'type'       => 'object',
    'properties' => [
        'title'         => ['type' => 'string', 'minLength' => 1],
        'counterparty'  => ['type' => 'string', 'nullable' => true],
        'effective_date' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
        'total_value'   => ['type' => 'number', 'nullable' => true],
        'auto_renewal'  => ['type' => 'boolean'],
        'risk_level'    => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
        'obligations'   => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'description' => ['type' => 'string'],
                    'due_date'    => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                ],
                'required'   => ['description'],
            ],
        ],
    ],
    'required'   => ['title', 'auto_renewal', 'risk_level'],
];

$raw = <<<'TEXT'
Here is the extraction:

```json
{
  "title": "Master Services Agreement",
  "counterparty": "Acme Industries Pvt Ltd",
  "effective_date": "2026-04-01T00:00:00Z",
  "total_value": "2,400,000.00",
  "auto_renewal": "true",
  "risk_level": "medium",
  "obligations": [
    {"description": "Quarterly service review", "due_date": "2026-06-30"},
    {"description": "Annual security audit", "due_date": null},
  ]
}
```
TEXT;

$decoded = AiResponseRepair::decode($raw);
assert_not_null($decoded, 'the fenced, trailing-comma extraction is recovered');

$result = JsonSchemaValidator::validate($decoded, $schema);
assert_true($result['valid'], 'the recovered extraction validates: ' . implode(' | ', $result['errors']));
assert_same('2026-04-01', $result['value']['effective_date'], 'the timestamp became a date');
assert_same(2400000.0, $result['value']['total_value'], 'the money string became a number');
assert_same(true, $result['value']['auto_renewal'], 'the quoted boolean became a boolean');
assert_null($result['value']['obligations'][1]['due_date'], 'a null due date stayed null');

// ---------------------------------------------------------------------------
// AiResponseRepair on its own
// ---------------------------------------------------------------------------

assert_same(['a' => 1], AiResponseRepair::decode('{"a":1}'), 'clean JSON passes straight through');
assert_same(['a' => 1], AiResponseRepair::decode("```json\n{\"a\":1}\n```"), 'a json fence is stripped');
assert_same(['a' => 1], AiResponseRepair::decode("```\n{\"a\":1}\n```"), 'an unlabelled fence is stripped');
assert_same(['a' => 1], AiResponseRepair::decode('{"a":1,}'), 'a trailing comma before } is removed');
assert_same(['a' => [1, 2]], AiResponseRepair::decode('{"a":[1,2,],}'), 'trailing commas before ] and } are removed');
assert_same([['id' => 1]], AiResponseRepair::decode("Result:\n[{\"id\": 1}]\nLet me know if you need more."), 'prose either side of a JSON array is discarded');
assert_same(
    ['note' => 'the parties agree {as set out} below'],
    AiResponseRepair::decode('{"note": "the parties agree {as set out} below"}'),
    'braces inside a string do not confuse the balance scan'
);

// Unrecoverable: repairing these would mean inventing content.
assert_null(AiResponseRepair::decode('I could not read the attached document.'), 'prose with no JSON returns null');
assert_null(AiResponseRepair::decode(''), 'empty text returns null');
assert_null(AiResponseRepair::decode('{"title": "Master Services Agr'), 'a truncated object is not closed for the model');
assert_null(AiResponseRepair::decode("```json\n{\"obligations\": [{\"description\": \"pay"), 'a truncated fenced object returns null');
assert_null(AiResponseRepair::decode('{"a": }'), 'a missing value is not invented');
assert_null(AiResponseRepair::decode('"just a string"'), 'a bare JSON scalar is not an extraction');

t_done('JsonSchemaValidatorTest');
