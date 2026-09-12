<?php

declare(strict_types=1);

/**
 * The company playbook and the deviations it raises.
 *
 * The behaviour this suite exists to hold still: a mandatory clause that is
 * absent becomes an open deviation with the library's own wording attached; a
 * deviation somebody resolves stays resolved when the check is re-run, while an
 * open one that has since been fixed disappears; and a rule the engine could
 * never measure is refused when it is written rather than sitting in the
 * playbook looking like a control.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\CompanyBootstrapService;
use App\Services\PlaybookService;
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

$playbooks = new PlaybookService($pdo);
$today     = Dates::today();

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function p_playbook_id(PDO $pdo, int $cmpId): int
{
    $st = $pdo->prepare('SELECT id FROM contract_playbooks WHERE environment = ? AND cmp_id = ? AND is_default');
    $st->execute(['sandbox', $cmpId]);

    return (int) $st->fetchColumn();
}

function p_category_id(PDO $pdo, int $cmpId, string $code): int
{
    $st = $pdo->prepare('SELECT id FROM clause_categories WHERE environment = ? AND cmp_id = ? AND code = ?');
    $st->execute(['sandbox', $cmpId, $code]);

    return (int) $st->fetchColumn();
}

/** @param array<string,mixed> $f */
function p_contract(PDO $pdo, int $cmpId, string $number, array $f = []): int
{
    $st = $pdo->prepare(
        'INSERT INTO contracts
         (environment, cmp_id, contract_number, title, status, lifecycle_stage, owner_uuid,
          effective_date, expiry_date, execution_date, notice_period_days, auto_renewal,
          renewal_type, governing_law, jurisdiction, currency, counterparty_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         RETURNING id'
    );
    $st->execute([
        'sandbox', $cmpId, $number,
        $f['title'] ?? 'Agreement',
        'draft', 'draft',
        'USER-A',
        $f['effective_date'] ?? null,
        $f['expiry_date'] ?? null,
        $f['execution_date'] ?? null,
        $f['notice_period_days'] ?? null,
        ($f['auto_renewal'] ?? false) ? 'true' : 'false',
        $f['renewal_type'] ?? 'fixed_term',
        $f['governing_law'] ?? null,
        $f['jurisdiction'] ?? null,
        'INR',
        $f['counterparty_name'] ?? 'Acme Ltd',
        'USER-A',
    ]);

    return (int) $st->fetchColumn();
}

function p_clause(PDO $pdo, int $cmpId, int $contractId, string $categoryCode, string $body): int
{
    $st = $pdo->prepare(
        'INSERT INTO contract_clauses (environment, cmp_id, contract_id, category_id, heading, body_text, verification_state)
         VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute([
        'sandbox', $cmpId, $contractId, p_category_id($pdo, $cmpId, $categoryCode),
        ucwords(str_replace('_', ' ', $categoryCode)), $body, 'human_verified',
    ]);

    return (int) $st->fetchColumn();
}

/** @param list<array<string,mixed>> $deviations @return list<string> */
function p_keys(array $deviations): array
{
    return array_map(static fn (array $d): string => (string) $d['rule_key'], $deviations);
}

/** @param list<array<string,mixed>> $deviations */
function p_by_key(array $deviations, string $ruleKey): ?array
{
    foreach ($deviations as $deviation) {
        if ((string) $deviation['rule_key'] === $ruleKey) {
            return $deviation;
        }
    }

    return null;
}

/** Assert that a write is refused, and that the message lands on the field the user typed. */
function p_invalid(callable $fn, string $field, string $label): void
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

// ---------------------------------------------------------------------------
// The seeded playbook
// ---------------------------------------------------------------------------

$playbookId = p_playbook_id($pdo, 1);
$seeded     = $playbooks->rules($ctx1, $playbookId);

assert_count(10, $seeded, 'the company starts with the seeded playbook');
assert_count(1, $playbooks->playbooks($ctx1), 'and with exactly one playbook');
assert_same(10, $playbooks->playbooks($ctx1)[0]['active_rules'], 'the listing counts its active rules');

$liabilityRule = null;
foreach ($seeded as $rule) {
    if ($rule['rule_key'] === 'approved_governing_law') {
        $liabilityRule = $rule;
    }
}
assert_not_null($liabilityRule, 'a seeded rule is readable through the service');
assert_same(['India', 'Indian law', 'Republic of India'], $liabilityRule['expected_list'], 'its list comes back as a list, not as JSON text');
assert_true($liabilityRule['is_active'], 'and its flag comes back as a real boolean');

// ---------------------------------------------------------------------------
// A mandatory clause that is absent
// ---------------------------------------------------------------------------

$bare  = p_contract($pdo, 1, 'CON-2026-000001', ['title' => 'Supply agreement, clauses not yet extracted']);
$first = $playbooks->evaluate($ctx1, $bare);

assert_count(5, $first, 'the five mandatory-clause rules are the only ones a contract with no clauses can breach');
assert_true(in_array('mandatory_confidentiality', p_keys($first), true), 'a missing confidentiality clause is raised');
assert_true(in_array('mandatory_liability_cap', p_keys($first), true), 'so is a missing liability cap');
assert_false(in_array('max_payment_terms', p_keys($first), true), 'a numeric rule with nothing to measure raises nothing');
assert_false(in_array('approved_governing_law', p_keys($first), true), 'a governing law nobody recorded is not an unapproved one');
assert_false(in_array('no_auto_renewal', p_keys($first), true), 'a contract that does not auto-renew satisfies the rule against it');

$confidentiality = p_by_key($first, 'mandatory_confidentiality');
assert_not_null($confidentiality, 'the deviation is readable');
assert_same('open', $confidentiality['review_status'], 'a new deviation is open');
assert_same('high', $confidentiality['severity'], 'it carries the severity the rule gave it');
assert_same('rules', $confidentiality['detected_by'], 'and is marked as found by the deterministic engine');
assert_contains('Confidentiality', (string) $confidentiality['deviation_summary'], 'the summary names the clause that is missing');
assert_contains('confidential', mb_strtolower((string) $confidentiality['preferred_wording']), 'the library wording the company would rather see comes with it');
assert_same('confidentiality', $confidentiality['category_code'], 'the deviation is filed under the clause category');
assert_null($confidentiality['clause_id'], 'a missing clause has no clause to point at');

assert_same('critical', p_by_key($first, 'mandatory_liability_cap')['severity'], 'a missing liability cap is the critical one');

// The worst deviation is listed first, so a reviewer reads the queue top down.
assert_same('critical', (string) $first[0]['severity'], 'deviations are ordered worst first');

// ---------------------------------------------------------------------------
// Resolving one, and re-running the check
// ---------------------------------------------------------------------------

$resolved = $playbooks->reviewDeviation(
    $ctx1,
    (int) $confidentiality['id'],
    'resolved',
    'Confidentiality is covered by the master NDA signed in March.'
);

assert_same('resolved', $resolved['review_status'], 'the reviewer decision is stored');
assert_same('USER-A', $resolved['reviewed_by'], 'and who made it');
assert_not_null($resolved['reviewed_at'], 'and when');
assert_contains('master NDA', (string) $resolved['review_notes'], 'and the reasoning');

$second = $playbooks->evaluate($ctx1, $bare);

assert_count(5, $second, 're-running the check does not duplicate the deviations already standing');
assert_same('resolved', p_by_key($second, 'mandatory_confidentiality')['review_status'], 'a resolved deviation is not reopened by the next run');
assert_count(
    1,
    array_filter($second, static fn (array $d): bool => (string) $d['review_status'] === 'resolved'),
    'exactly one deviation carries a decision'
);
assert_count(4, $playbooks->deviations($ctx1, $bare, ['open_only' => true]), 'the other four are still open');

// An open deviation whose cause has been fixed disappears on the next run.
p_clause($pdo, 1, $bare, 'limitation_liability', 'The liability of each party is limited to the fees paid in the preceding twelve months.');
$third = $playbooks->evaluate($ctx1, $bare);

assert_false(in_array('mandatory_liability_cap', p_keys($third), true), 'adding the clause clears the open deviation that asked for it');
assert_count(4, $third, 'and leaves the resolved one and the three still-open ones');
assert_same('resolved', p_by_key($third, 'mandatory_confidentiality')['review_status'], 'the resolved decision survives every re-run');

assert_throws(
    static fn () => $playbooks->reviewDeviation($ctx1, (int) $confidentiality['id'], 'sort_of_fine'),
    'an unknown deviation status is refused',
    'Unknown deviation status'
);

// ---------------------------------------------------------------------------
// The other rule types
// ---------------------------------------------------------------------------

$offside = p_contract($pdo, 1, 'CON-2026-000002', [
    'title'              => 'Distribution agreement on their paper',
    'governing_law'      => 'Singapore law',
    'jurisdiction'       => 'Singapore',
    'notice_period_days' => 120,
    'auto_renewal'       => true,
    'renewal_type'       => 'auto_renew',
]);

p_clause($pdo, 1, $offside, 'limitation_liability', 'The supplier accepts unlimited liability for any breach of this agreement.');
$pdo->prepare(
    'INSERT INTO contract_commercial_terms (environment, cmp_id, contract_id, currency, payment_terms_days)
     VALUES (?, ?, ?, ?, ?)'
)->execute(['sandbox', 1, $offside, 'INR', 90]);

$offsideDeviations = $playbooks->evaluate($ctx1, $offside);
$offsideKeys       = p_keys($offsideDeviations);

assert_true(in_array('prohibited_unlimited_liability', $offsideKeys, true), 'prohibited wording found in a clause is raised');
assert_true(in_array('max_payment_terms', $offsideKeys, true), 'payment terms past the company limit are raised');
assert_true(in_array('max_notice_period', $offsideKeys, true), 'so is a notice period past it');
assert_true(in_array('approved_governing_law', $offsideKeys, true), 'a governing law off the approved list is raised');
assert_true(in_array('no_auto_renewal', $offsideKeys, true), 'so is an automatic renewal the playbook discourages');
assert_false(in_array('mandatory_liability_cap', $offsideKeys, true), 'the liability clause exists, however bad it is');
assert_count(9, $offsideDeviations, 'nine playbook positions are breached in total');

$prohibited = p_by_key($offsideDeviations, 'prohibited_unlimited_liability');
assert_not_null($prohibited['clause_id'], 'a wording deviation points at the clause that contains it');
assert_contains('unlimited liability', mb_strtolower((string) $prohibited['contract_wording']), 'and quotes what the contract actually says');
assert_contains('unlimited liability', mb_strtolower((string) $prohibited['deviation_summary']), 'the summary names the prohibited wording');

$payment = p_by_key($offsideDeviations, 'max_payment_terms');
assert_contains('90', (string) $payment['deviation_summary'], 'a numeric deviation states the value it found');
assert_contains('45', (string) $payment['deviation_summary'], 'and the limit it breached');

$law = p_by_key($offsideDeviations, 'approved_governing_law');
assert_contains('Singapore law', (string) $law['deviation_summary'], 'a list deviation states the value it found');
assert_contains('India', (string) $law['deviation_summary'], 'and what the list allows');

$renewal = p_by_key($offsideDeviations, 'no_auto_renewal');
assert_contains('expects no', (string) $renewal['deviation_summary'], 'a yes/no deviation states both sides of the disagreement');

// ---------------------------------------------------------------------------
// Writing rules
// ---------------------------------------------------------------------------

$prohibitedList = $playbooks->createRule($ctx1, $playbookId, [
    'rule_key'      => 'prohibited_jurisdictions',
    'rule_type'     => 'prohibited_list',
    'label'         => 'Disputes must not be heard offshore',
    'category_id'   => p_category_id($pdo, 1, 'jurisdiction'),
    'expected_list' => ['Singapore', 'Dubai', 'Singapore'],
    'severity'      => 'high',
    'risk_category' => 'compliance',
    'recommendation' => 'Refer to Legal before agreeing an offshore forum.',
]);

assert_same('prohibited_jurisdictions', $prohibitedList['rule_key'], 'the rule is created');
assert_same(['Singapore', 'Dubai'], $prohibitedList['expected_list'], 'a repeated list value is stored once');
assert_true($prohibitedList['is_active'], 'a new rule is active');

$preferred = $playbooks->createRule($ctx1, $playbookId, [
    'rule_key'       => 'perpetual_confidentiality',
    'rule_type'      => 'preferred_wording',
    'label'          => 'Confidentiality should survive termination',
    'category_id'    => p_category_id($pdo, 1, 'confidentiality'),
    'expected_value' => 'survive termination',
    'severity'       => 'medium',
]);

$backdating = $playbooks->createRule($ctx1, $playbookId, [
    'rule_key'         => 'backdating_window',
    'rule_type'        => 'date_window',
    'label'            => 'A term may not begin more than 30 days before signature',
    'expected_numeric' => 30,
    'severity'         => 'high',
    'risk_category'    => 'compliance',
]);

p_clause($pdo, 1, $offside, 'confidentiality', 'Each party shall keep the other party confidential information in confidence during the term.');
$pdo->prepare('UPDATE contracts SET jurisdiction = ?, effective_date = ?, execution_date = ? WHERE id = ?')
    ->execute(['Singapore', Dates::addDays($today, -60), $today, $offside]);

$widened = p_keys($playbooks->evaluate($ctx1, $offside));

assert_true(in_array('prohibited_jurisdictions', $widened, true), 'a rule written today is evaluated on the next run');
assert_true(in_array('perpetual_confidentiality', $widened, true), 'a clause that omits the preferred wording is raised');
assert_true(in_array('backdating_window', $widened, true), 'a term backdated past the window is raised');
assert_false(in_array('mandatory_confidentiality', $widened, true), 'and the clause now present closes the rule that asked for it');

$wordingDeviation = p_by_key($playbooks->deviations($ctx1, $offside), 'perpetual_confidentiality');
assert_same('survive termination', $wordingDeviation['preferred_wording'], 'the preferred wording is what the rule asked for');
assert_contains('confidential information', (string) $wordingDeviation['contract_wording'], 'beside what the contract says instead');

$backdated = p_by_key($playbooks->deviations($ctx1, $offside), 'backdating_window');
assert_contains('60 days before', (string) $backdated['deviation_summary'], 'the date deviation states how far back the term reaches');

// ---------------------------------------------------------------------------
// A rule the engine could never act on is refused
// ---------------------------------------------------------------------------

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'no_limit', 'rule_type' => 'max_numeric', 'label' => 'A limit with no number',
        'category_id' => p_category_id($pdo, 1, 'payment_terms'),
    ]),
    'expected_numeric',
    'a numeric rule with no number is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'unmeasurable', 'rule_type' => 'max_numeric', 'label' => 'Confidentiality, but numeric',
        'category_id' => p_category_id($pdo, 1, 'confidentiality'), 'expected_numeric' => 10,
    ]),
    'category_id',
    'a numeric rule filed under a category nothing measurable maps to is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'empty_list', 'rule_type' => 'allowed_list', 'label' => 'An allowed list with nothing on it',
        'category_id' => p_category_id($pdo, 1, 'governing_law'), 'expected_list' => [],
    ]),
    'expected_list',
    'a list rule with an empty list is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'no_category', 'rule_type' => 'mandatory_clause', 'label' => 'A mandatory nothing',
    ]),
    'category_id',
    'a mandatory-clause rule that names no clause category is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'no_wording', 'rule_type' => 'preferred_wording', 'label' => 'Preferred, but unstated',
        'category_id' => p_category_id($pdo, 1, 'confidentiality'),
    ]),
    'expected_value',
    'a preferred-wording rule with no wording is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'no_answer', 'rule_type' => 'boolean_flag', 'label' => 'Yes or no, unstated',
        'category_id' => p_category_id($pdo, 1, 'renewal'),
    ]),
    'expected_value',
    'a yes/no rule with no expected answer is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'Bad Key!', 'rule_type' => 'mandatory_clause', 'label' => 'Bad key',
        'category_id' => p_category_id($pdo, 1, 'renewal'),
    ]),
    'rule_key',
    'a rule key that is not an identifier is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'unknown_type', 'rule_type' => 'vibes_based', 'label' => 'Something new',
    ]),
    'rule_type',
    'a rule type outside the schema vocabulary is refused'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'mandatory_confidentiality', 'rule_type' => 'mandatory_clause',
        'label' => 'A second confidentiality rule',
        'category_id' => p_category_id($pdo, 1, 'confidentiality'),
    ]),
    'rule_key',
    'a duplicate rule key is reported against the field the user typed, not as a server error'
);

p_invalid(
    static fn () => $playbooks->createRule($ctx1, $playbookId, [
        'rule_key' => 'foreign_category', 'rule_type' => 'mandatory_clause', 'label' => 'Their category',
        'category_id' => p_category_id($pdo, 2, 'confidentiality'),
    ]),
    'category_id',
    'a clause category belonging to another company is refused'
);

assert_throws(
    static fn () => $playbooks->createRule($ctx1, p_playbook_id($pdo, 2), ['rule_key' => 'x', 'rule_type' => 'mandatory_clause', 'label' => 'x']),
    'a rule cannot be added to another company\'s playbook',
    'Playbook not found'
);

// ---------------------------------------------------------------------------
// Updating and deleting
// ---------------------------------------------------------------------------

$updated = $playbooks->updateRule($ctx1, (int) $prohibitedList['id'], [
    'label'         => 'Disputes must be heard in India',
    'severity'      => 'critical',
    'expected_list' => ['Singapore', 'Dubai', 'London'],
]);

assert_same('Disputes must be heard in India', $updated['label'], 'the label is changed');
assert_same('critical', $updated['severity'], 'and the severity');
assert_same(['Singapore', 'Dubai', 'London'], $updated['expected_list'], 'and the list');
assert_same('prohibited_jurisdictions', $updated['rule_key'], 'a field the caller did not send is left alone');
assert_same('compliance', $updated['risk_category'], 'including the risk category');

p_invalid(
    static fn () => $playbooks->updateRule($ctx1, (int) $prohibitedList['id'], ['expected_list' => []]),
    'expected_list',
    'an update that would leave a rule unmeasurable is refused too'
);

$deactivated = $playbooks->updateRule($ctx1, (int) $preferred['id'], ['is_active' => false]);
assert_false($deactivated['is_active'], 'a rule can be switched off without being deleted');
assert_false(
    in_array('perpetual_confidentiality', p_keys($playbooks->evaluate($ctx1, $offside)), true),
    'and an inactive rule stops being evaluated'
);

$deviationCountBefore = count($playbooks->deviations($ctx1, $offside));
$playbooks->deleteRule($ctx1, (int) $backdating['id']);

assert_null(
    p_by_key($playbooks->rules($ctx1, $playbookId), 'backdating_window'),
    'a deleted rule is gone from the playbook'
);
$orphaned = $pdo->prepare('SELECT playbook_rule_id FROM clause_deviations WHERE contract_id = ? AND deviation_summary LIKE ?');
$orphaned->execute([$offside, '%before the contract was signed%']);
$orphanRow = $orphaned->fetch();
assert_not_null($orphanRow, 'the deviation it raised survives the rule being deleted');
assert_null($orphanRow['playbook_rule_id'], 'with its rule reference cleared rather than the history destroyed');
assert_true($deviationCountBefore > 0, 'the contract had deviations to keep');

// ---------------------------------------------------------------------------
// Company 2 is invisible to company 1
// ---------------------------------------------------------------------------

$theirs = p_contract($pdo, 2, 'CON-2026-000001', ['title' => 'Their supply agreement']);
$theirDeviations = $playbooks->evaluate($ctx2, $theirs);
$theirDeviation  = (int) $theirDeviations[0]['id'];
$theirRule       = (int) $playbooks->rules($ctx2, p_playbook_id($pdo, 2))[0]['id'];

assert_count(5, $theirDeviations, 'company 2 gets its own deviations from its own playbook');
assert_count(0, $playbooks->deviations($ctx1, $theirs), 'company 1 sees none of them');
assert_count(0, $playbooks->rules($ctx1, p_playbook_id($pdo, 2)), 'nor any rule from company 2\'s playbook');

assert_throws(
    static fn () => $playbooks->evaluate($ctx1, $theirs),
    'company 1 cannot run the playbook over company 2\'s contract',
    'Contract not found'
);
assert_throws(
    static fn () => $playbooks->reviewDeviation($ctx1, $theirDeviation, 'accepted'),
    'company 1 cannot review company 2\'s deviation',
    'Deviation not found'
);
assert_throws(
    static fn () => $playbooks->updateRule($ctx1, $theirRule, ['label' => 'Rewritten']),
    'company 1 cannot edit company 2\'s rule',
    'Playbook rule not found'
);
assert_throws(
    static fn () => $playbooks->deleteRule($ctx1, $theirRule),
    'company 1 cannot delete company 2\'s rule',
    'Playbook rule not found'
);

t_done('PlaybookServiceTest');
