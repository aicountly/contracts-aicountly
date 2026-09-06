<?php

declare(strict_types=1);

/**
 * Merge rendering, and the ways it refuses.
 *
 * The behaviour this suite exists to hold still: a template can only reach data
 * through a registered variable, a value can never become markup, a conditional
 * is a lookup rather than an expression, and no template — however malformed or
 * however large — can turn one request into unbounded work.
 *
 * No database: the renderer takes its registry and its data as arguments, which
 * is exactly what makes it testable and what keeps it from being able to reach
 * anything the caller did not already load under a tenant filter.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\TemplateRenderer;
use App\Support\ValidationFailed;

$renderer = new TemplateRenderer();

/** The registry a company would have; keyed exactly as template_variables stores it. */
$registry = [
    'company.legal_name'      => ['source' => 'company', 'source_path' => 'legal_name'],
    'counterparty.legal_name' => ['source' => 'counterparty', 'source_path' => 'legal_name'],
    'contract.title'          => ['source' => 'contract', 'source_path' => 'title'],
    'contract.number'         => ['source' => 'contract', 'source_path' => 'contract_number'],
    'contract.expiry_date'    => ['source' => 'contract', 'source_path' => 'expiry_date'],
    'contract.auto_renewal'   => ['source' => 'contract', 'source_path' => 'auto_renewal'],
    'contract.value'          => ['source' => 'commercial', 'source_path' => 'total_value'],
    'system.today'            => ['source' => 'system', 'source_path' => 'today'],
];

$bag = [
    'company'      => ['legal_name' => 'Aicountly Technologies Pvt Ltd'],
    'counterparty' => ['legal_name' => 'Acme Ltd'],
    'contract'     => [
        'title'           => 'Master Services Agreement',
        'contract_number' => 'CON-2026-000042',
        'expiry_date'     => '2027-03-31',
        // PostgreSQL hands a false boolean back as 'f' through PDO, which is
        // the exact value a conditional has to read as false.
        'auto_renewal'    => 'f',
    ],
    'commercial'   => ['total_value' => null],
    'system'       => ['today' => '2026-09-06'],
];

// ---------------------------------------------------------------------------
// Substitution
// ---------------------------------------------------------------------------

$result = $renderer->render(
    '<p>This agreement between {{ company.legal_name }} and {{ counterparty.legal_name }} '
    . '({{contract.number}}) is dated {{ system.today }}.</p>',
    $bag,
    $registry
);

assert_contains('Aicountly Technologies Pvt Ltd', $result['html'], 'a registered variable is substituted');
assert_contains('Acme Ltd', $result['html'], 'so is the counterparty');
assert_contains('CON-2026-000042', $result['html'], 'a placeholder without inner spaces works too');
assert_contains('<p>', $result['html'], 'the template\'s own markup is left alone');
assert_count(0, $result['missing'], 'nothing is missing when every value is present');
assert_count(4, $result['used'], 'every variable read is reported as used');

$missing = $renderer->render('Value: {{ contract.value }}. Number: {{ contract.number }}.', $bag, $registry);

assert_same('Value: . Number: CON-2026-000042.', $missing['html'], 'a variable with no value renders as nothing, not as the placeholder');
assert_same(['contract.value'], $missing['missing'], 'and is reported so a preview can show the gap');
assert_same(['contract.value', 'contract.number'], $missing['used'], 'used lists every field the template reads, in order');

// ---------------------------------------------------------------------------
// A variable nobody registered
// ---------------------------------------------------------------------------

assert_throws(
    static fn () => $renderer->render('Secret: {{ contract.margin }}', $bag, $registry),
    'an unregistered variable is refused',
    'contract.margin'
);

assert_throws(
    static fn () => $renderer->render('{{ contract.margin }}', $bag, $registry),
    'and the message says what to do about it',
    'Add it to the variable list'
);

// The registry, not the key, decides which bag entry is read: a key shaped like
// a path into somebody else's data resolves to nothing rather than to data.
assert_throws(
    static fn () => $renderer->render('{{ company.legal_name.secret }}', $bag, $registry),
    'a key is never walked as a path of its own',
    'Unknown merge variable'
);

assert_throws(
    static fn () => $renderer->render('{{ Contract.Title }}', $bag, $registry),
    'a key outside the registered shape is refused',
    'not a valid merge variable name'
);

// A variable inside a block that will not render is still checked, so a broken
// template fails in the editor rather than on the day the condition flips.
assert_throws(
    static fn () => $renderer->render('{{#if contract.auto_renewal}}{{ contract.margin }}{{/if}}', $bag, $registry),
    'an unknown variable hiding inside a false block is still refused'
);

// ---------------------------------------------------------------------------
// Escaping — there is no raw form
// ---------------------------------------------------------------------------

$hostile = $bag;
$hostile['counterparty']['legal_name'] = '<script>alert("xss")</script> & "Co"';

$escaped = $renderer->render('Party: {{ counterparty.legal_name }}', $hostile, $registry);

assert_not_contains('<script>', $escaped['html'], 'an HTML payload in a value never reaches the output as markup');
assert_contains('&lt;script&gt;', $escaped['html'], 'it is escaped instead');
assert_contains('&amp;', $escaped['html'], 'and so is an ampersand');
assert_contains('&quot;Co&quot;', $escaped['html'], 'and a quote, so a value cannot break out of an attribute');

// ---------------------------------------------------------------------------
// Conditional blocks
// ---------------------------------------------------------------------------

$body = '{{#if contract.expiry_date}}Expires on {{ contract.expiry_date }}.{{/if}}'
    . '{{#unless contract.expiry_date}}Runs until terminated.{{/unless}}';

$conditional = $renderer->render($body, $bag, $registry);

assert_contains('Expires on 2027-03-31.', $conditional['html'], 'a block whose variable has a value renders');
assert_not_contains('Runs until terminated', $conditional['html'], 'and its {{#unless}} twin does not');

$noExpiry                            = $bag;
$noExpiry['contract']['expiry_date'] = null;
$flipped                             = $renderer->render($body, $noExpiry, $registry);

assert_contains('Runs until terminated.', $flipped['html'], 'an absent value flips both blocks');
assert_not_contains('Expires on', $flipped['html'], 'and the {{#if}} branch disappears entirely');
assert_count(0, $flipped['missing'], 'a block that is off because the data says so is not a missing field');

$falseFlag = $renderer->render('{{#if contract.auto_renewal}}Renews automatically.{{/if}}OK', $bag, $registry);
assert_same('OK', $falseFlag['html'], "PostgreSQL's 'f' reads as false, not as a non-empty string");

$nested = $renderer->render(
    '{{#if contract.title}}A{{#unless contract.value}}B{{#if contract.number}}C{{/if}}{{/unless}}D{{/if}}',
    $bag,
    $registry
);
assert_same('ABCD', $nested['html'], 'blocks nest');

// ---------------------------------------------------------------------------
// Malformed templates are refused, never half-rendered
// ---------------------------------------------------------------------------

assert_throws(
    static fn () => $renderer->render('{{#each contract.title}}x{{/each}}', $bag, $registry),
    'a directive from a richer engine is refused rather than ignored',
    'Unsupported template directive'
);

assert_throws(
    static fn () => $renderer->render('{{#if contract.title}}unterminated', $bag, $registry),
    'an unclosed block is refused',
    'never closed'
);

assert_throws(
    static fn () => $renderer->render('{{#if contract.title}}x{{/unless}}', $bag, $registry),
    'a mismatched closer is refused',
    'does not close an open block'
);

assert_throws(
    static fn () => $renderer->render('{{ }}', $bag, $registry),
    'an empty placeholder is refused'
);

$unterminated = $renderer->render('Total {{ 100 + 200', $bag, $registry);
assert_same('Total {{ 100 + 200', $unterminated['html'], 'an unterminated brace stays literal text instead of swallowing the document');

// ---------------------------------------------------------------------------
// The caps
// ---------------------------------------------------------------------------

assert_throws(
    static fn () => $renderer->render(str_repeat('a', TemplateRenderer::MAX_BODY_BYTES + 1), $bag, $registry),
    'a template body past the size cap is refused',
    'may not exceed'
);

$deep = str_repeat('{{#if contract.title}}', TemplateRenderer::MAX_DEPTH + 1)
    . 'x'
    . str_repeat('{{/if}}', TemplateRenderer::MAX_DEPTH + 1);

assert_throws(
    static fn () => $renderer->render($deep, $bag, $registry),
    'nesting past the depth cap is refused',
    'nested more than'
);

$atLimit = str_repeat('{{#if contract.title}}', TemplateRenderer::MAX_DEPTH)
    . 'x'
    . str_repeat('{{/if}}', TemplateRenderer::MAX_DEPTH);

assert_same('x', $renderer->render($atLimit, $bag, $registry)['html'], 'nesting exactly at the cap still renders');

// A small template whose values are large: the body cap alone does not bound
// the output, which is why the output has a cap of its own.
$fat                     = $bag;
$fat['contract']['title'] = str_repeat('x', TemplateRenderer::MAX_VALUE_LENGTH);

assert_throws(
    static fn () => $renderer->render(str_repeat('{{ contract.title }}', 400), $fat, $registry),
    'a small template that renders to a huge document is refused',
    'of document'
);

// ---------------------------------------------------------------------------
// extractVariables
// ---------------------------------------------------------------------------

assert_same(
    ['company.legal_name', 'contract.expiry_date', 'contract.title'],
    $renderer->extractVariables(
        '{{ company.legal_name }} {{#if contract.expiry_date}}{{ contract.title }}{{/if}} {{ company.legal_name }}'
    ),
    'every referenced variable is extracted once, conditions included'
);

assert_same([], $renderer->extractVariables('No placeholders here at all.'), 'a body with no placeholders uses nothing');
assert_same([], $renderer->extractVariables('{{ NotAKey }} {{#each x}}'), 'anything malformed is simply not a variable');

// ---------------------------------------------------------------------------
// The data bag
// ---------------------------------------------------------------------------

$ctx = t_context(1, 'USER-A');

$contract = [
    'id'                => 7,
    'contract_number'   => 'CON-2026-000007',
    'title'             => 'Supply Agreement',
    'counterparty_name' => 'Acme Ltd',
    'expiry_date'       => '2027-03-31',
    'currency'          => 'INR',
    'total_value'       => '250000.00',
    'auto_renewal'      => false,
    'custom_fields'     => ['site_code' => 'BLR-2', 'contacts' => ['a@example.com', 'b@example.com'], 'nested' => ['x' => 1]],
];

$built = $renderer->buildBag($ctx, $contract, null, null);

assert_same('Test Company 1', $built['company']['legal_name'], 'the company name comes from the tenant context, never from input');
assert_same('CON-2026-000007', $built['contract']['contract_number'], 'contract fields are laid out under their source');
assert_same('Acme Ltd', $built['counterparty']['legal_name'], 'with no snapshot the contract\'s own counterparty name stands in');
assert_same('250000.00', $built['commercial']['total_value'], 'a contract with no commercial terms row still reports its own value');
assert_same('No', $built['contract']['auto_renewal'], 'a boolean becomes a word rather than an empty string');
assert_same('BLR-2', $built['custom']['site_code'], 'custom fields are reachable');
assert_same('a@example.com, b@example.com', $built['custom']['contacts'], 'a list custom field is joined rather than rendered as "Array"');
assert_null($built['custom']['nested'], 'anything deeper than a list is dropped rather than guessed at');
assert_same(\App\Support\Dates::today(), $built['system']['today'], 'system.today is today');

$snapshot = [
    'legal_name'                => 'Acme Limited (formerly Acme Ltd)',
    'registered_address'        => '1 Industrial Estate, Pune',
    'authorised_representative' => 'R. Sharma',
];
$commercial = ['total_value' => '900000.00', 'currency' => 'USD', 'payment_terms_days' => 45];

$withParty = $renderer->buildBag($ctx, $contract, $snapshot, $commercial);

assert_same(
    'Acme Limited (formerly Acme Ltd)',
    $withParty['counterparty']['legal_name'],
    'the snapshot is preferred: it records who the agreement was made with, not who they are called today'
);
assert_same('1 Industrial Estate, Pune', $withParty['counterparty']['address'], 'the address alias resolves to the snapshot column');
assert_same('R. Sharma', $withParty['counterparty']['authorised_representative'], 'and the signatory');
assert_same('900000.00', $withParty['commercial']['total_value'], 'commercial terms win over the contract summary when they exist');
assert_same('45', $withParty['commercial']['payment_terms_days'], 'and every leaf is a string, so nothing renders as a type name');

$empty = $renderer->buildBag($ctx, null, null, null);
assert_null($empty['contract']['title'], 'a bag built without a contract has no contract values');
assert_same('Test Company 1', $empty['company']['legal_name'], 'but still knows the company, which is what makes an unbound preview useful');

// A value that is an array can never become output, whatever the registry says.
$arrayBag = $bag;
$arrayBag['contract']['title'] = ['nope'];
assert_same(
    ['contract.title'],
    $renderer->render('{{ contract.title }}', $arrayBag, $registry)['missing'],
    'a bag entry that is not a scalar renders as missing rather than as a structure'
);

// A registry entry pointing nowhere resolves to nothing rather than to an error.
assert_same(
    '',
    $renderer->render('{{ contract.title }}', $bag, ['contract.title' => ['source' => 'nowhere', 'source_path' => 'x']])['html'],
    'a registry row pointing at a source the bag does not have renders as empty'
);

t_done('TemplateRendererTest');
