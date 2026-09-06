<?php

declare(strict_types=1);

/**
 * The clause library and the clauses standing on a contract.
 *
 * The behaviour this suite exists to hold still: approved wording is superseded
 * rather than overwritten, so a contract reviewed against version 1 can still
 * be read against version 1; a clause taken from the library carries the words
 * and the provenance but not a live link to text that will change; the shelf a
 * drafter is offered is filtered to wording that is approved, current and meant
 * for this kind of contract; and none of it is visible to another company.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\ClauseService;
use App\Services\CompanyBootstrapService;
use App\Support\Dates;
use App\Support\ValidationFailed;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx1 = t_context(1, 'USER-A');
$ctx2 = t_context(2, 'USER-B');

(new CompanyBootstrapService($pdo))->ensure('sandbox', 1);
(new CompanyBootstrapService($pdo))->ensure('sandbox', 2);

$clauses = new ClauseService($pdo);

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function c_contract(PDO $pdo, int $cmpId, string $number, ?int $typeId = null): int
{
    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage,
                                owner_uuid, contract_type_id, currency, counterparty_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $number, 'Supply agreement', 'draft', 'draft', 'USER-A', $typeId, 'INR', 'Acme Ltd', 'USER-A']);

    return (int) $st->fetchColumn();
}

function c_type_id(PDO $pdo, int $cmpId, string $code): int
{
    $st = $pdo->prepare('SELECT id FROM contract_types WHERE environment = ? AND cmp_id = ? AND code = ?');
    $st->execute(['sandbox', $cmpId, $code]);

    return (int) $st->fetchColumn();
}

function c_category_id(PDO $pdo, int $cmpId, string $code): int
{
    $st = $pdo->prepare('SELECT id FROM clause_categories WHERE environment = ? AND cmp_id = ? AND code = ?');
    $st->execute(['sandbox', $cmpId, $code]);

    return (int) $st->fetchColumn();
}

/** @param list<array<string,mixed>> $rows @return list<string> */
function c_names(array $rows): array
{
    return array_map(static fn (array $r): string => (string) $r['name'], $rows);
}

/** Assert that a write is refused, and that the message lands on the field the user typed. */
function c_invalid(callable $fn, string $field, string $label): void
{
    try {
        $fn();
    } catch (ValidationFailed $e) {
        if (! array_key_exists($field, $e->errors)) {
            t_fail($label, "refused, but not on '{$field}': " . implode(', ', array_keys($e->errors)));
        }
        t_ok($label);

        return;
    } catch (Throwable $e) {
        t_fail($label, 'expected a validation failure, got ' . $e::class . ': ' . $e->getMessage());
    }
    t_fail($label, 'expected a validation failure, but the call returned normally');
}

$ndaType   = c_type_id($pdo, 1, 'nda');
$msaType   = c_type_id($pdo, 1, 'msa');
$liability = c_category_id($pdo, 1, 'limitation_liability');

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------

$seededCategories = $clauses->categories($ctx1);

assert_true(count($seededCategories) > 5, 'a new company starts with a shelf of categories');
assert_true($seededCategories[0]['is_system'], 'the seeded ones are marked as built in');
assert_true($seededCategories[0]['clause_count'] >= 0, 'each carries the size of its shelf as a number');

$spare = $clauses->createCategory($ctx1, [
    'code'        => 'spare_terms',
    'name'        => 'Spare Terms',
    'description' => 'A category with nothing in it.',
    'risk_weight' => 3,
]);

assert_same('spare_terms', $spare['code'], 'a category is created');
assert_same(3, $spare['risk_weight'], 'with the weight it was given');
assert_false($spare['is_system'], 'and is not a built-in one');

c_invalid(
    static fn () => $clauses->createCategory($ctx1, ['code' => 'spare_terms', 'name' => 'Spare Terms Again']),
    'code',
    'a duplicate code is reported against the field the user typed, not as a server error'
);
c_invalid(
    static fn () => $clauses->createCategory($ctx1, ['code' => 'Spare Terms!', 'name' => 'Punctuated']),
    'code',
    'a code that is not an identifier is refused'
);

$renamed = $clauses->updateCategory($ctx1, (int) $spare['id'], ['name' => 'Spare Terms (unused)']);
assert_same('Spare Terms (unused)', $renamed['name'], 'a category can be renamed');
assert_same('spare_terms', $renamed['code'], 'and keeps the code nothing asked to change');

$special = $clauses->createCategory($ctx1, ['code' => 'special_terms', 'name' => 'Special Terms']);

$clauses->deleteCategory($ctx1, (int) $spare['id']);
assert_false(in_array('Spare Terms (unused)', c_names($clauses->categories($ctx1)), true), 'an empty category can be deleted');

assert_throws(
    static fn () => $clauses->deleteCategory($ctx1, $liability),
    'a built-in category cannot be deleted',
    'built-in category'
);

// ---------------------------------------------------------------------------
// The library
// ---------------------------------------------------------------------------

$cap = $clauses->create($ctx1, [
    'name'                => 'Liability cap — 12 months fees',
    'description'         => 'The cap Legal will accept without escalation.',
    'category_id'         => $liability,
    'standard_text'       => 'Each party\'s aggregate liability under this Agreement is limited to the fees paid in the twelve months preceding the claim.',
    'fallback_text'       => 'Each party\'s aggregate liability is limited to the fees paid under this Agreement.',
    'risk_classification' => 'high',
]);

assert_same(1, $cap['version'], 'a new clause starts at version 1');
assert_same('draft', $cap['approval_status'], 'and as a draft');
assert_same($liability, $cap['category_id'], 'filed under the category it was given');
assert_same('Limitation of Liability', $cap['category_name'], 'which comes back named');
assert_count(0, $clauses->versions($ctx1, (int) $cap['id']), 'a draft has no published history yet');

c_invalid(
    static fn () => $clauses->create($ctx1, ['name' => 'No wording', 'category_id' => $liability]),
    'standard_text',
    'a clause with no wording is refused'
);
c_invalid(
    static fn () => $clauses->create($ctx1, [
        'name'          => 'Their category',
        'standard_text' => 'x',
        'category_id'   => c_category_id($pdo, 2, 'limitation_liability'),
    ]),
    'category_id',
    'a category belonging to another company is refused'
);

$approved = $clauses->approve($ctx1, (int) $cap['id']);

assert_same('approved', $approved['approval_status'], 'approving publishes the wording');
assert_same('USER-A', $approved['approver_uuid'], 'and records who did it');
assert_not_null($approved['approved_at'], 'and when');
assert_count(1, $clauses->versions($ctx1, (int) $cap['id']), 'and pins the version a contract can now cite');

assert_same(
    'approved',
    $clauses->approve($ctx1, (int) $cap['id'])['approval_status'],
    'approving twice is a double click, not an error'
);
assert_count(1, $clauses->versions($ctx1, (int) $cap['id']), 'and does not write a second version row');

// Rewording published text supersedes it rather than overwriting it.
$reworded = $clauses->update($ctx1, (int) $cap['id'], [
    'standard_text' => 'Each party\'s aggregate liability under this Agreement is limited to the fees paid in the six months preceding the claim.',
    'change_note'   => 'Tightened to six months after the Q3 review.',
]);

$history = $clauses->versions($ctx1, (int) $cap['id']);

assert_same(2, $reworded['version'], 'a reworded published clause moves to the next version');
assert_count(2, $history, 'and both wordings are on record');
assert_same(2, $history[0]['version'], 'newest first');
assert_contains('six months', (string) $history[0]['standard_text'], 'the new wording is version 2');
assert_contains('twelve months', (string) $history[1]['standard_text'], 'and the wording a contract was reviewed against is still readable');
assert_contains('Q3 review', (string) $history[0]['change_note'], 'with the note explaining why it changed');

$untouched = $clauses->update($ctx1, (int) $cap['id'], ['description' => 'Same words, better description.']);
assert_same(2, $untouched['version'], 'a save that does not touch the wording does not make a version');
assert_count(2, $clauses->versions($ctx1, (int) $cap['id']), 'nor a history row');

// ---------------------------------------------------------------------------
// Applicability
// ---------------------------------------------------------------------------

$ndaOnly = $clauses->create($ctx1, [
    'name'             => 'Perpetual confidentiality (NDA only)',
    'category_id'      => c_category_id($pdo, 1, 'confidentiality'),
    'standard_text'    => 'The confidentiality obligations survive termination indefinitely.',
    'approval_status'  => 'approved',
    'applicable_types' => [$ndaType],
]);

$expired = $clauses->create($ctx1, [
    'name'            => 'Withdrawn indemnity',
    'category_id'     => c_category_id($pdo, 1, 'indemnity'),
    'standard_text'   => 'The Supplier indemnifies the Customer without limit.',
    'approval_status' => 'approved',
    'effective_from'  => '2020-01-01',
    'effective_to'    => Dates::addDays(Dates::today(), -1),
]);

$stillDraft = $clauses->create($ctx1, [
    'name'          => 'Proposed force majeure wording',
    'category_id'   => c_category_id($pdo, 1, 'force_majeure'),
    'standard_text' => 'Neither party is liable for a failure caused by an event beyond its control.',
]);

assert_same([$ndaType], $ndaOnly['applicable_types'], 'a clause can be restricted to a contract type');
assert_count(1, $clauses->versions($ctx1, (int) $ndaOnly['id']), 'a clause created as approved is pinned immediately');

c_invalid(
    static fn () => $clauses->create($ctx1, [
        'name'             => 'Their type',
        'standard_text'    => 'x',
        'applicable_types' => [c_type_id($pdo, 2, 'nda')],
    ]),
    'applicable_types',
    'a contract type belonging to another company is refused'
);

$forNda = c_names($clauses->applicableFor($ctx1, $ndaType));
$forMsa = c_names($clauses->applicableFor($ctx1, $msaType));

assert_true(in_array('Perpetual confidentiality (NDA only)', $forNda, true), 'a clause restricted to this type is offered for it');
assert_false(in_array('Perpetual confidentiality (NDA only)', $forMsa, true), 'and is not offered for another type');
assert_true(in_array('Liability cap — 12 months fees', $forMsa, true), 'a clause restricted to nothing is offered for every type');
assert_false(in_array('Withdrawn indemnity', $forNda, true), 'wording whose window has closed is not offered');
assert_false(in_array('Proposed force majeure wording', $forNda, true), 'nor is a draft nobody has approved');

$byCategory = $clauses->applicableFor($ctx1, $ndaType, c_category_id($pdo, 1, 'confidentiality'));
assert_true(in_array('Perpetual confidentiality (NDA only)', c_names($byCategory), true), 'the shelf can be narrowed to one category');
assert_false(in_array('Liability cap — 12 months fees', c_names($byCategory), true), 'and then holds only that category');

$found = $clauses->search($ctx1, ['q' => 'six months preceding'], 25, 0);
assert_same(1, $found['total'], 'the library is searchable by wording');
assert_same('Liability cap — 12 months fees', $found['items'][0]['name'], 'and finds the clause that contains it');

$drafts = $clauses->search($ctx1, ['approval_status' => 'draft'], 25, 0);
assert_true(in_array('Proposed force majeure wording', c_names($drafts['items']), true), 'and filterable by approval state');

// ---------------------------------------------------------------------------
// Retiring wording
// ---------------------------------------------------------------------------

$deprecated = $clauses->deprecate($ctx1, (int) $ndaOnly['id']);

assert_same('deprecated', $deprecated['approval_status'], 'wording can be retired from the shelf');
assert_count(1, $clauses->versions($ctx1, (int) $ndaOnly['id']), 'and keeps the history contracts were reviewed against');
assert_false(
    in_array('Perpetual confidentiality (NDA only)', c_names($clauses->applicableFor($ctx1, $ndaType)), true),
    'and stops being offered to drafters'
);

$clauses->delete($ctx1, (int) $stillDraft['id']);
assert_false(
    in_array('Proposed force majeure wording', c_names($clauses->search($ctx1, [], 100, 0)['items']), true),
    'a deleted clause leaves the library'
);
assert_true(
    in_array('Proposed force majeure wording', c_names($clauses->search($ctx1, ['archived' => 'only'], 100, 0)['items']), true),
    'archived rather than destroyed, because contracts point at it'
);

// ---------------------------------------------------------------------------
// A category with wording in it
// ---------------------------------------------------------------------------

$clauses->create($ctx1, [
    'name'          => 'Special escalation',
    'category_id'   => (int) $special['id'],
    'standard_text' => 'Disputes are escalated to the steering committee before any notice is served.',
]);

assert_throws(
    static fn () => $clauses->deleteCategory($ctx1, (int) $special['id']),
    'a category with clauses filed under it cannot be deleted',
    'still has library clauses'
);

// ---------------------------------------------------------------------------
// Clauses on a contract
// ---------------------------------------------------------------------------

$contractId = c_contract($pdo, 1, 'CON-2026-000001', $msaType);

$attached = $clauses->attachToContract($ctx1, $contractId, (int) $cap['id']);

assert_same('Liability cap — 12 months fees', $attached['heading'], 'the library clause arrives under its own name');
assert_contains('six months preceding', (string) $attached['body_text'], 'carrying the wording as it stands today');
assert_same((int) $cap['id'], $attached['library_clause_id'], 'and the provenance that says where it came from');
assert_same('human_verified', $attached['verification_state'], 'a clause a person chose is not waiting for anyone to confirm it');
assert_false($attached['is_ai_extracted'], 'and was not read out of a document');

// The copy is a copy: the library moving on cannot restate a signed agreement.
$clauses->update($ctx1, (int) $cap['id'], ['standard_text' => 'Liability is unlimited.']);
assert_contains(
    'six months preceding',
    (string) $clauses->listForContract($ctx1, $contractId)[0]['body_text'],
    'and rewriting the library afterwards does not rewrite the contract'
);

$typed = $clauses->createForContract($ctx1, $contractId, [
    'clause_number' => '7.2',
    'heading'       => 'Negotiated payment terms',
    'body_text'     => 'Invoices are payable within 45 days of receipt.',
    'category_id'   => c_category_id($pdo, 1, 'payment_terms'),
    'source_page'   => 4,
]);

assert_same('7.2', $typed['clause_number'], 'a negotiated clause can be recorded by hand');
assert_null($typed['library_clause_id'], 'with no library link, because it did not come from there');
assert_count(2, $clauses->listForContract($ctx1, $contractId), 'both clauses stand on the contract');

$verified = $clauses->updateForContract($ctx1, (int) $typed['id'], ['verification_state' => 'human_verified']);
assert_contains('45 days', (string) $verified['body_text'], 'a save carrying one field does not blank the rest');
assert_same('human_verified', $verified['verification_state'], 'and records the decision it carried');

$edited = $clauses->updateForContract($ctx1, (int) $typed['id'], ['body_text' => 'Invoices are payable within 30 days of receipt.']);
assert_same('human_edited', $edited['verification_state'], 'rewording is itself a review decision');

$clauses->deleteForContract($ctx1, (int) $typed['id']);
assert_count(1, $clauses->listForContract($ctx1, $contractId), 'a clause read wrongly can be taken off the contract');

// ---------------------------------------------------------------------------
// Company 2 sees none of it
// ---------------------------------------------------------------------------

$theirContract = c_contract($pdo, 2, 'CON-2026-000001');

assert_false(
    in_array('Liability cap — 12 months fees', c_names($clauses->search($ctx2, [], 100, 0)['items']), true),
    'company 2 does not see company 1\'s wording in its library'
);
assert_count(0, $clauses->listForContract($ctx2, $theirContract), 'nor any clause on its own contract it did not add');

assert_throws(
    static fn () => $clauses->listForContract($ctx2, $contractId),
    'company 2 cannot read the clauses of company 1\'s contract',
    'Contract not found'
);
assert_throws(
    static fn () => $clauses->attachToContract($ctx2, $theirContract, (int) $cap['id']),
    'nor take a copy of company 1\'s wording',
    'Clause not found'
);
assert_throws(
    static fn () => $clauses->update($ctx2, (int) $cap['id'], ['name' => 'Rewritten']),
    'nor edit it',
    'Clause not found'
);
assert_throws(
    static fn () => $clauses->approve($ctx2, (int) $stillDraft['id']),
    'nor approve it',
    'Clause not found'
);
assert_throws(
    static fn () => $clauses->updateForContract($ctx2, (int) $attached['id'], ['heading' => 'Theirs now']),
    'nor touch a clause standing on company 1\'s contract',
    'Clause not found'
);
assert_throws(
    static fn () => $clauses->deleteCategory($ctx2, (int) $special['id']),
    'nor delete company 1\'s category',
    'Clause category not found'
);

t_done('ClauseServiceTest');
