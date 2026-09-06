<?php

declare(strict_types=1);

/**
 * The deterministic risk engine.
 *
 * Two halves. The first needs no database and is the important one: every
 * operator a company admin can choose, the guard that stops a bad regex taking
 * the site down, and the boundaries where a score becomes a level. The second
 * exercises the parts only PostgreSQL can hold honest — that a second
 * assessment demotes the first (the partial unique index makes this the only
 * way it can work), that a review rescores what it contradicts, and that
 * company 1 cannot reach company 2's findings whatever id it guesses.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\CompanyBootstrapService;
use App\Services\HealthScoreService;
use App\Services\RiskEngine;
use App\Support\Dates;

// ---------------------------------------------------------------------------
// Fixtures for the pure half
// ---------------------------------------------------------------------------

/** @param array<string,mixed> $overrides */
function r_rule(array $overrides): array
{
    return array_merge([
        'id'               => 7,
        'rule_key'         => 'test_rule',
        'name'             => 'Test rule',
        'description'      => 'Because the playbook says so.',
        'risk_category'    => 'legal',
        'severity'         => 'medium',
        'subject'          => 'clause_text',
        'operator'         => 'contains',
        'value_text'       => null,
        'value_numeric'    => null,
        'value_list'       => '[]',
        'applies_to_types' => '[]',
        'score_weight'     => 10,
        'recommendation'   => 'Fix it.',
    ], $overrides);
}

/** A subject bag shaped exactly like buildSubject() produces. */
$subject = [
    'contract_type_code' => 'msa',
    'clauses'            => [
        ['id' => 11, 'clause_number' => '9', 'heading' => 'Liability', 'source_page' => 3, 'category_code' => 'limitation_liability',
         'body_text' => 'Save for wilful misconduct, the liabilities of the parties shall be unlimited under this agreement.'],
        ['id' => 12, 'clause_number' => '10', 'heading' => 'Payment', 'source_page' => 4, 'category_code' => 'payment_terms',
         'body_text' => 'Invoices are payable within ninety (90) days of receipt.'],
    ],
    'clause_text'         => 'Save for wilful misconduct, the liabilities of the parties shall be unlimited under this agreement.'
                             . "\n\n" . 'Invoices are payable within ninety (90) days of receipt.',
    'clause_categories'   => ['limitation_liability', 'payment_terms'],
    'liability_cap'       => null,
    'auto_renewal'        => true,
    'notice_period'       => 90,
    'governing_law'       => 'Singapore law',
    'jurisdiction'        => null,
    'payment_terms'       => 90,
    'contract_value'      => '7500000.00',
    'duration_months'     => 36,
    'termination_right'   => false,
    'indemnity'           => true,
    'data_protection'     => false,
    'insurance'           => false,
    'sla_defined'         => false,
    'expiry_date'         => null,
    'counterparty_missing' => false,
    'signature_missing'   => true,
    'document_missing'    => false,
];

// ---------------------------------------------------------------------------
// Every operator
// ---------------------------------------------------------------------------

$contains = RiskEngine::evaluateRule(
    r_rule(['subject' => 'clause_text', 'operator' => 'contains', 'value_text' => 'shall be unlimited', 'severity' => 'critical', 'score_weight' => 25]),
    $subject
);
assert_not_null($contains, 'contains fires when a clause holds the phrase');
assert_same(11, $contains['clause_id'], 'the finding points at the clause that matched, not at the contract in general');
assert_same(3, $contains['source_page'], 'the page comes along so the finding can be shown against the paragraph');
assert_same(45, $contains['score_impact'], 'a weight of 25 at critical severity scores 45');
assert_contains('shall be unlimited', $contains['source_excerpt'], 'the excerpt is centred on what matched');

assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_text', 'operator' => 'contains', 'value_text' => 'liability is capped']), $subject),
    'contains stays silent when no clause holds the phrase'
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_text', 'operator' => 'not_contains', 'value_text' => 'force majeure']), $subject),
    'not_contains fires when no clause anywhere mentions the phrase'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_text', 'operator' => 'not_contains', 'value_text' => 'unlimited']), $subject),
    'not_contains is answered against the whole document, not clause by clause'
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'equals', 'value_text' => 'singapore LAW']), $subject),
    'equals ignores case'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'equals', 'value_text' => 'India']), $subject),
    'equals does not fire on a different value'
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'not_equals', 'value_text' => 'India']), $subject),
    'not_equals fires when the recorded value is something else'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'not_equals', 'value_text' => 'Singapore law']), $subject),
    'not_equals stays silent when the value is the one named'
);

$greater = RiskEngine::evaluateRule(
    r_rule(['subject' => 'notice_period', 'operator' => 'greater_than', 'value_numeric' => 60, 'severity' => 'high', 'score_weight' => 15]),
    $subject
);
assert_not_null($greater, 'greater_than fires above the threshold');
assert_same(21, $greater['score_impact'], 'a weight of 15 at high severity scores 21');
assert_contains('Recorded value: 90', (string) $greater['detail'], 'the finding says what the recorded value actually was');
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'notice_period', 'operator' => 'greater_than', 'value_numeric' => 90]), $subject),
    'greater_than is strict — equal to the threshold is not over it'
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'duration_months', 'operator' => 'less_than', 'value_numeric' => 48]), $subject),
    'less_than fires below the threshold'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'duration_months', 'operator' => 'less_than', 'value_numeric' => 12]), $subject),
    'less_than stays silent above it'
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'in_list', 'value_list' => '["Singapore law","Delaware"]']), $subject),
    'in_list fires on a member of the list'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'in_list', 'value_list' => '["India"]']), $subject),
    'in_list stays silent off the list'
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'not_in_list', 'value_list' => '["India","Republic of India"]']), $subject),
    'not_in_list fires on a governing law the company has not approved'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'not_in_list', 'value_list' => '["Singapore law"]']), $subject),
    'not_in_list stays silent on an approved one'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'not_in_list', 'value_list' => '[]']), $subject),
    'not_in_list with an empty list is inert rather than universally true'
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'auto_renewal', 'operator' => 'is_true']), $subject),
    'is_true fires on a true flag'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'indemnity', 'operator' => 'is_false']), $subject),
    'is_false stays silent on a true flag'
);
assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'sla_defined', 'operator' => 'is_false']), $subject),
    'is_false fires on a false flag'
);

// PostgreSQL hands booleans back as 't'/'f'; a plain cast makes 'f' true, and
// this is the branch where that would silently invert a rule.
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'auto_renewal', 'operator' => 'is_true']), array_merge($subject, ['auto_renewal' => 'f'])),
    "is_true reads PostgreSQL's 'f' as false"
);
assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'auto_renewal', 'operator' => 'is_true']), array_merge($subject, ['auto_renewal' => 't'])),
    "is_true reads PostgreSQL's 't' as true"
);

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'expiry_date', 'operator' => 'is_null']), $subject),
    'is_null fires on a missing expiry date'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'expiry_date', 'operator' => 'is_not_null']), $subject),
    'is_not_null stays silent on a missing value'
);
assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'contract_value', 'operator' => 'is_not_null']), $subject),
    'is_not_null fires on a recorded value'
);

$regex = RiskEngine::evaluateRule(
    r_rule(['subject' => 'clause_text', 'operator' => 'regex', 'value_text' => 'liabilit(y|ies).{0,30}unlimited']),
    $subject
);
assert_not_null($regex, 'regex fires on a matching clause');
assert_same(11, $regex['clause_id'], 'a regex finding carries the clause it matched');

assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_text', 'operator' => 'regex', 'value_text' => 'force\s+majeure']), $subject),
    'regex stays silent when nothing matches'
);

// ---------------------------------------------------------------------------
// An unknown value cannot satisfy a positive test
// ---------------------------------------------------------------------------

$blank = array_merge($subject, ['governing_law' => null, 'notice_period' => null, 'payment_terms' => null]);

assert_null(RiskEngine::evaluateRule(r_rule(['subject' => 'notice_period', 'operator' => 'greater_than', 'value_numeric' => 60]), $blank),
    'a missing notice period is not longer than sixty days');
assert_null(RiskEngine::evaluateRule(r_rule(['subject' => 'notice_period', 'operator' => 'less_than', 'value_numeric' => 15]), $blank),
    'a missing notice period is not shorter than fifteen days either');
assert_null(RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'not_in_list', 'value_list' => '["India"]']), $blank),
    'a governing law nobody recorded is not a foreign governing law');
assert_not_null(RiskEngine::evaluateRule(r_rule(['subject' => 'governing_law', 'operator' => 'is_null']), $blank),
    'is_null is the operator that asks about absence, and it still fires');

// A cap stated in words is a real cap; casting it to 0.0 and comparing would
// report every one of them as breaching the floor.
$worded = array_merge($subject, ['liability_cap' => 'capped at fees paid in the preceding twelve months']);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'liability_cap', 'operator' => 'less_than', 'value_numeric' => 1000000]), $worded),
    'a non-numeric subject cannot satisfy a numeric comparison'
);
assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'liability_cap', 'operator' => 'less_than', 'value_numeric' => 1000000]), array_merge($subject, ['liability_cap' => '500000'])),
    'a cap that is a figure still compares as one'
);

// ---------------------------------------------------------------------------
// clause_missing reads as "this category is absent"
// ---------------------------------------------------------------------------

assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'confidentiality']), $subject),
    'clause_missing fires for a category the contract has no clause in'
);
assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_missing', 'operator' => 'equals', 'value_text' => 'payment_terms']), $subject),
    'clause_missing stays silent for a category that is present'
);
assert_not_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_missing', 'operator' => 'is_true']), array_merge($subject, ['clause_categories' => [], 'clauses' => []])),
    'with no category named, clause_missing asks whether anything was extracted at all'
);

// ---------------------------------------------------------------------------
// The regex guard
// ---------------------------------------------------------------------------

assert_not_null(RiskEngine::compileRegex('net\s+\d{2,3}\s+days'), 'a sane pattern compiles');
assert_null(RiskEngine::compileRegex(str_repeat('a', 201)), 'a pattern longer than 200 characters is refused');
assert_not_null(RiskEngine::compileRegex(str_repeat('a', 200)), 'exactly 200 characters is still allowed');
assert_null(RiskEngine::compileRegex('(unclosed'), 'a pattern that does not compile is refused rather than warned about');
assert_null(RiskEngine::compileRegex('#~%!@;|'), 'a pattern using every available delimiter is refused rather than mangled by escaping');
assert_null(RiskEngine::compileRegex('   '), 'an empty pattern is refused');
assert_null(RiskEngine::compileRegex(null), 'a rule with no pattern at all is refused');

// The delimiter is chosen, never escaped: escaping would corrupt a pattern
// that already escapes the delimiter itself.
$slashes = RiskEngine::evaluateRule(
    r_rule(['subject' => 'clause_text', 'operator' => 'regex', 'value_text' => 'within\s+ninety\s+\(90\)\s+days']),
    $subject
);
assert_not_null($slashes, 'a pattern containing brackets and escapes still matches');

$long   = str_repeat('a', 40000) . 'X';
$evil   = array_merge($subject, [
    'clauses'     => [['id' => 99, 'heading' => null, 'source_page' => null, 'category_code' => null, 'body_text' => $long]],
    'clause_text' => $long,
]);
$started = microtime(true);
$result  = RiskEngine::evaluateRule(r_rule(['subject' => 'clause_text', 'operator' => 'regex', 'value_text' => '(a+)+b']), $evil);
$elapsed = microtime(true) - $started;

assert_null($result, 'a catastrophic pattern produces no finding rather than an outage');
assert_true($elapsed < 2.0, 'a catastrophic pattern is bounded by the subject cap and the backtrack limit');

assert_null(
    RiskEngine::evaluateRule(r_rule(['subject' => 'clause_text', 'operator' => 'regex', 'value_text' => str_repeat('(a|b)', 60)]), $subject),
    'an over-long pattern is refused at evaluation time too, not only at compile time'
);

// ---------------------------------------------------------------------------
// A rule the schema would have refused is inert, not fatal
// ---------------------------------------------------------------------------

assert_null(RiskEngine::evaluateRule(r_rule(['subject' => 'made_up_subject', 'operator' => 'is_true']), $subject),
    'an unknown subject fires nothing rather than stopping the assessment');
assert_null(RiskEngine::evaluateRule(r_rule(['subject' => 'auto_renewal', 'operator' => 'sounds_like']), $subject),
    'an unknown operator fires nothing either');

// ---------------------------------------------------------------------------
// Scoring boundaries
// ---------------------------------------------------------------------------

function r_finding(int $impact, string $severity = 'medium', string $category = 'legal', string $review = 'open'): array
{
    return [
        'severity'      => $severity,
        'risk_category' => $category,
        'score_impact'  => $impact,
        'review_status' => $review,
    ];
}

assert_same(['score' => 0, 'level' => 'low', 'categories' => []], RiskEngine::scoreFromFindings([]),
    'a contract nothing fired on scores zero and reads low');

assert_same('low', RiskEngine::scoreFromFindings([r_finding(39)])['level'], '39 is still low');
assert_same('medium', RiskEngine::scoreFromFindings([r_finding(40)])['level'], '40 is the first medium');
assert_same('medium', RiskEngine::scoreFromFindings([r_finding(59)])['level'], '59 is still medium');
assert_same('high', RiskEngine::scoreFromFindings([r_finding(60)])['level'], '60 is the first high');
assert_same('high', RiskEngine::scoreFromFindings([r_finding(79)])['level'], '79 is still high');
assert_same('critical', RiskEngine::scoreFromFindings([r_finding(80)])['level'], '80 is the first critical');
assert_same('critical', RiskEngine::scoreFromFindings([r_finding(100)])['level'], '100 is critical');

assert_same(0, RiskEngine::levelForScore(0) === 'low' ? 0 : 1, 'zero reads low');
assert_same('critical', RiskEngine::levelForScore(95), 'the level lookup is usable on its own');

// Findings combine with diminishing returns rather than adding: each takes a
// share of the remaining clean headroom. A linear sum saturates at 100 for any
// incomplete contract, and a score that is always "critical 100" tells a
// reviewer nothing.
$two = RiskEngine::scoreFromFindings([
    r_finding(30, 'medium', 'legal'),
    r_finding(30, 'medium', 'commercial'),
]);
assert_same(51, $two['score'], 'two 30s combine to 51, not 60');
assert_same(['commercial' => 30, 'legal' => 30], $two['categories'], 'the split is per category, in a stable order');

$ten = RiskEngine::scoreFromFindings(array_fill(0, 10, r_finding(10, 'medium')));
assert_same(65, $ten['score'], 'ten minor findings reach 65, not 100');

$twenty = RiskEngine::scoreFromFindings(array_fill(0, 20, r_finding(10, 'medium')));
assert_same(88, $twenty['score'], 'twenty minor findings reach 88 — the scale stays usable');
assert_true($twenty['score'] < 100, 'minor findings never saturate the scale on their own');

// The twenty-first minor finding moves the number less than the first serious
// one would, which is how a reviewer actually weighs them.
$twentyOne = RiskEngine::scoreFromFindings(array_fill(0, 21, r_finding(10, 'medium')));
assert_true(
    $twentyOne['score'] - $twenty['score'] < 2,
    'adding one more minor finding to twenty barely moves the score'
);

// Severity sets a floor. A contract with unlimited liability is high risk
// however tidy the rest of it is, and diminishing returns alone would let one
// critical finding beside a clean sheet read as medium.
$oneCritical = RiskEngine::scoreFromFindings([r_finding(25, 'critical', 'legal')]);
assert_same(80, $oneCritical['score'], 'a single critical finding floors the score at 80');
assert_same('critical', $oneCritical['level'], 'and therefore reads as critical');

// A single high finding lands in the upper medium band, not the high one. The
// severity of a finding is not the risk of the contract, and one flag on an
// otherwise sound agreement is worth reviewing rather than escalating.
$oneHigh = RiskEngine::scoreFromFindings([r_finding(15, 'high', 'legal')]);
assert_same(50, $oneHigh['score'], 'a single high finding floors the score at 50');
assert_same('medium', $oneHigh['level'], 'which reads as medium — a flag, not a crisis');

// The floor is a floor, not a ceiling: several high findings climb into the
// high band on their own, which is the right way to get there.
$threeHigh = RiskEngine::scoreFromFindings(array_fill(0, 3, r_finding(30, 'high')));
assert_true($threeHigh['score'] >= 60, 'three high findings reach the high band (got ' . $threeHigh['score'] . ')');
assert_same('high', $threeHigh['level'], 'and read as high');

$manyHigh = RiskEngine::scoreFromFindings(array_fill(0, 6, r_finding(30, 'high')));
assert_true($manyHigh['score'] > $threeHigh['score'], 'six high findings score above three');

$overflow = RiskEngine::scoreFromFindings([
    r_finding(80, 'critical'),
    r_finding(70, 'critical'),
    r_finding(60, 'high', 'compliance'),
]);
assert_true($overflow['score'] <= 100, 'the score never exceeds 100');
assert_same(98, $overflow['score'], 'three severe findings approach but do not reach 100');
assert_same('critical', $overflow['level'], 'and read as critical');

$total = RiskEngine::scoreFromFindings([r_finding(100, 'critical')]);
assert_same(100, $total['score'], 'only a finding that is itself total reaches 100');

$dismissed = RiskEngine::scoreFromFindings([r_finding(80, 'critical', 'legal', 'false_positive'), r_finding(10)]);
assert_same(10, $dismissed['score'], 'a finding a reviewer dismissed stops counting');
assert_same('low', $dismissed['level'], 'dismissing the only serious finding moves the level with it');

$accepted = RiskEngine::scoreFromFindings([r_finding(80, 'critical', 'legal', 'accepted')]);
assert_same(80, $accepted['score'], 'an accepted risk is still a risk — only a false positive is discounted');

assert_same(45, RiskEngine::impact(25, 'critical'), 'severity multiplies the rule weight');
assert_same(2, RiskEngine::impact(10, 'informational'), 'an informational rule barely moves the number');
assert_same(100, RiskEngine::impact(100, 'critical'), 'one rule cannot score more than the whole scale');

// ---------------------------------------------------------------------------
// Everything past here needs PostgreSQL
// ---------------------------------------------------------------------------

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx1 = t_context(1, 'USER-A');
$ctx2 = t_context(2, 'USER-B');

(new CompanyBootstrapService($pdo))->ensure('sandbox', 1);
(new CompanyBootstrapService($pdo))->ensure('sandbox', 2);

$engine = new RiskEngine($pdo);
$health = new HealthScoreService($pdo);
$today  = Dates::today();

function r_type_id(PDO $pdo, int $cmpId, string $code): int
{
    $st = $pdo->prepare('SELECT id FROM contract_types WHERE environment = ? AND cmp_id = ? AND code = ?');
    $st->execute(['sandbox', $cmpId, $code]);

    return (int) $st->fetchColumn();
}

/** @param array<string,mixed> $f */
function r_contract(PDO $pdo, int $cmpId, string $number, array $f = []): int
{
    $st = $pdo->prepare(
        'INSERT INTO contracts
         (environment, cmp_id, contract_number, title, status, lifecycle_stage, contract_type_id,
          owner_uuid, effective_date, expiry_date, execution_date, notice_period_days, auto_renewal,
          renewal_type, governing_law, jurisdiction, currency, total_value, counterparty_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         RETURNING id'
    );
    $st->execute([
        'sandbox', $cmpId, $number,
        $f['title'] ?? 'Agreement',
        $f['status'] ?? 'draft',
        $f['status'] ?? 'draft' === 'active' ? 'active' : 'draft',
        $f['contract_type_id'] ?? null,
        $f['owner_uuid'] ?? null,
        $f['effective_date'] ?? null,
        $f['expiry_date'] ?? null,
        $f['execution_date'] ?? null,
        $f['notice_period_days'] ?? null,
        ($f['auto_renewal'] ?? false) ? 'true' : 'false',
        $f['renewal_type'] ?? 'fixed_term',
        $f['governing_law'] ?? null,
        $f['jurisdiction'] ?? null,
        'INR',
        $f['total_value'] ?? null,
        $f['counterparty_name'] ?? null,
        'USER-A',
    ]);

    return (int) $st->fetchColumn();
}

function r_clause(PDO $pdo, int $cmpId, int $contractId, string $categoryCode, string $body): int
{
    $cat = $pdo->prepare('SELECT id FROM clause_categories WHERE environment = ? AND cmp_id = ? AND code = ?');
    $cat->execute(['sandbox', $cmpId, $categoryCode]);
    $categoryId = $cat->fetchColumn();

    $st = $pdo->prepare(
        'INSERT INTO contract_clauses (environment, cmp_id, contract_id, category_id, heading, body_text, verification_state)
         VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $contractId, $categoryId === false ? null : (int) $categoryId,
        ucwords(str_replace('_', ' ', $categoryCode)), $body, 'human_verified']);

    return (int) $st->fetchColumn();
}

function r_document(PDO $pdo, int $cmpId, int $contractId): int
{
    $st = $pdo->prepare(
        'INSERT INTO contract_documents (environment, cmp_id, contract_id, doc_kind, title)
         VALUES (?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $contractId, 'contract', 'Signed agreement']);

    return (int) $st->fetchColumn();
}

/** @return list<string> */
function r_keys(array $assessment): array
{
    return array_map(static fn (array $f): string => (string) $f['rule_key'], $assessment['findings']);
}

// ---------------------------------------------------------------------------
// A contract that satisfies every seeded rule fires nothing
// ---------------------------------------------------------------------------

$clean = r_contract($pdo, 1, 'CON-2026-000001', [
    'title'              => 'Mutual NDA with Acme',
    'status'             => 'active',
    'contract_type_id'   => r_type_id($pdo, 1, 'nda'),
    'owner_uuid'         => 'USER-A',
    'effective_date'     => $today,
    'expiry_date'        => Dates::addMonths($today, 12),
    'execution_date'     => $today,
    'notice_period_days' => 30,
    'governing_law'      => 'India',
    'jurisdiction'       => 'Mumbai',
    'total_value'        => '1000000.00',
    'counterparty_name'  => 'Acme Ltd',
]);

r_clause($pdo, 1, $clean, 'limitation_liability', 'The liability of each party shall be limited to the fees paid in the preceding twelve months.');
r_clause($pdo, 1, $clean, 'termination_convenience', 'Either party may terminate for convenience on thirty days written notice.');
r_clause($pdo, 1, $clean, 'data_protection', 'Each party shall process personal data in accordance with applicable data protection law.');
r_clause($pdo, 1, $clean, 'indemnity', 'The supplier shall indemnify the customer against third party intellectual property claims.');
r_clause($pdo, 1, $clean, 'confidentiality', 'Each party shall keep the other party confidential information in confidence.');
r_clause($pdo, 1, $clean, 'insurance', 'The supplier shall maintain public liability insurance of not less than one crore.');
r_document($pdo, 1, $clean);

$pdo->prepare(
    'INSERT INTO contract_commercial_terms (environment, cmp_id, contract_id, currency, total_value, payment_terms_days)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute(['sandbox', 1, $clean, 'INR', '1000000.00', 30]);

$cleanAssessment = $engine->assess($ctx1, $clean);

assert_count(0, $cleanAssessment['findings'], 'a contract that satisfies every seeded rule produces no findings');
assert_same(0, $cleanAssessment['overall_score'], 'nothing fired, so nothing scored');
assert_same('low', $cleanAssessment['risk_level'], 'a score of zero reads low');
assert_true($cleanAssessment['is_current'], 'the assessment just written is the current one');
assert_false($cleanAssessment['ai_used'], 'the rules engine never claims a model was involved');
assert_same('1', $cleanAssessment['engine_version'], 'the engine version is stamped on the row');

// buildSubject is what every rule reads, so its derivations are worth pinning.
$cleanRow     = (new App\Services\ContractService($pdo))->findOrFail($ctx1, $clean);
$cleanSubject = $engine->buildSubject($ctx1, $cleanRow);

assert_not_null($cleanSubject['liability_cap'], 'a cap stated in words is read as a cap, not as a missing one');
assert_true($cleanSubject['indemnity'], 'an indemnity clause is seen');
assert_true($cleanSubject['termination_right'], 'a termination for convenience clause is a termination right');
assert_false($cleanSubject['document_missing'], 'a contract with a document is not missing one');
assert_false($cleanSubject['signature_missing'], 'an execution date counts as evidence of signature');
assert_same(12, $cleanSubject['duration_months'], 'a twelve month term reads as twelve months');
assert_same(30, $cleanSubject['payment_terms'], 'payment terms come from the commercial terms row');

// A cap that says "unlimited" is the same risk as no cap at all.
$unlimited = r_clause($pdo, 1, $clean, 'limitation_liability', 'The liability of the supplier under this agreement shall be unlimited.');
$pdo->prepare('DELETE FROM contract_clauses WHERE id <> ? AND contract_id = ? AND category_id = (SELECT id FROM clause_categories WHERE environment = ? AND cmp_id = ? AND code = ?)')
    ->execute([$unlimited, $clean, 'sandbox', 1, 'limitation_liability']);
assert_null(
    $engine->buildSubject($ctx1, $cleanRow)['liability_cap'],
    'a clause that says liability is unlimited leaves no cap behind'
);
$pdo->prepare('DELETE FROM contract_clauses WHERE id = ?')->execute([$unlimited]);
r_clause($pdo, 1, $clean, 'limitation_liability', 'The liability of each party shall be limited to the fees paid in the preceding twelve months.');

// ---------------------------------------------------------------------------
// An empty record is the worst contract there is
// ---------------------------------------------------------------------------

$empty      = r_contract($pdo, 1, 'CON-2026-000002', ['title' => 'Uploaded agreement, nothing captured']);
$emptyFirst = $engine->assess($ctx1, $empty);
$emptyKeys  = r_keys($emptyFirst);

// A record with nothing captured fires almost every rule, so it lands near the
// top of the scale — but not at 100. Reserving the ceiling means an empty
// record and a fully-populated contract with unlimited liability and no
// termination right are still distinguishable, which they should be: the second
// is a worse contract, the first is merely an unfinished one.
assert_true(
    $emptyFirst['overall_score'] >= 80,
    'a record with nothing in it is critical (got ' . $emptyFirst['overall_score'] . ')'
);
assert_same('critical', (string) $emptyFirst['risk_level'], 'and reads as critical');
assert_true(
    $emptyFirst['overall_score'] < 100,
    'but it does not saturate the scale — the top is reserved for something worse'
);
assert_same('critical', $emptyFirst['risk_level'], 'and reads critical');
assert_true(in_array('no_document', $emptyKeys, true), 'a contract with no document behind it is flagged');
assert_true(in_array('no_counterparty', $emptyKeys, true), 'so is one with no counterparty');
assert_true(in_array('unlimited_liability', $emptyKeys, true), 'no liability cap is the finding that carries the most weight');
assert_false(in_array('foreign_governing_law', $emptyKeys, true), 'a governing law nobody recorded is not a foreign governing law');
assert_false(in_array('auto_renewal_present', $emptyKeys, true), 'a contract that does not auto-renew is not flagged for auto-renewal');
assert_false(in_array('missing_sla', $emptyKeys, true), 'a rule scoped to service contracts does not fire on an untyped one');
assert_contains('critical', (string) $emptyFirst['summary'], 'the stored summary counts the serious findings');

// applies_to_types is the only thing that separates these two.
$serviceContract = r_contract($pdo, 1, 'CON-2026-000003', [
    'title'            => 'Managed services',
    'contract_type_id' => r_type_id($pdo, 1, 'msa'),
]);
assert_true(
    in_array('missing_sla', r_keys($engine->assess($ctx1, $serviceContract)), true),
    'the same rule fires on a service contract, because that is the type it names'
);

// ---------------------------------------------------------------------------
// A second assessment demotes the first
// ---------------------------------------------------------------------------

$emptySecond = $engine->assess($ctx1, $empty);

assert_true($emptySecond['id'] > $emptyFirst['id'], 'reassessing writes a new row rather than editing the old one');

$rows = $pdo->prepare('SELECT id, is_current FROM contract_risk_assessments WHERE contract_id = ? ORDER BY id');
$rows->execute([$empty]);
$history = $rows->fetchAll();

assert_count(2, $history, 'both assessments are kept — the history is the point of the table');
assert_false(App\Services\ContractService::toBool($history[0]['is_current']), 'the earlier assessment was demoted');
assert_true(App\Services\ContractService::toBool($history[1]['is_current']), 'the later one is current');
assert_same($emptySecond['id'], (int) $engine->currentAssessment($ctx1, $empty)['id'], 'the current assessment is the one just written');

// The demotion is not politeness; the index refuses anything else.
assert_throws(
    static function () use ($pdo, $empty): void {
        $pdo->prepare(
            'INSERT INTO contract_risk_assessments (environment, cmp_id, contract_id, overall_score, risk_level, is_current)
             VALUES (?, ?, ?, ?, ?, TRUE)'
        )->execute(['sandbox', 1, $empty, 10, 'low']);
    },
    'a second current assessment is refused by the database',
    'uq_risk_assessment_current'
);

// ---------------------------------------------------------------------------
// Reviewing a finding rescores what it contradicts
// ---------------------------------------------------------------------------

$renewing = r_contract($pdo, 1, 'CON-2026-000004', [
    'title'              => 'Facilities management, auto-renewing',
    'status'             => 'active',
    'contract_type_id'   => r_type_id($pdo, 1, 'nda'),
    'owner_uuid'         => 'USER-A',
    'effective_date'     => $today,
    'expiry_date'        => Dates::addMonths($today, 12),
    'execution_date'     => $today,
    'notice_period_days' => 90,
    'auto_renewal'       => true,
    'renewal_type'       => 'auto_renew',
    'governing_law'      => 'India',
    'jurisdiction'       => 'Mumbai',
    'total_value'        => '1000000.00',
    'counterparty_name'  => 'Acme Facilities',
]);

foreach ([
    'limitation_liability'    => 'Liability shall be limited to the fees paid in the preceding twelve months.',
    'termination_convenience' => 'Either party may terminate for convenience on ninety days notice.',
    'data_protection'         => 'Personal data shall be processed only on documented instructions.',
    'indemnity'               => 'Each party shall indemnify the other against third party claims.',
    'confidentiality'         => 'Each party shall keep confidential information in confidence.',
    'insurance'               => 'The supplier shall maintain insurance appropriate to the services.',
] as $code => $text) {
    r_clause($pdo, 1, $renewing, $code, $text);
}
r_document($pdo, 1, $renewing);
$pdo->prepare(
    'INSERT INTO contract_commercial_terms (environment, cmp_id, contract_id, currency, payment_terms_days)
     VALUES (?, ?, ?, ?, ?)'
)->execute(['sandbox', 1, $renewing, 'INR', 30]);

$renewingAssessment = $engine->assess($ctx1, $renewing);
$renewingKeys       = r_keys($renewingAssessment);

assert_count(2, $renewingAssessment['findings'], 'an auto-renewing contract with a long notice period fires exactly the two renewal rules');
assert_true(in_array('auto_renewal_long_notice', $renewingKeys, true), 'the long notice period is found');
assert_true(in_array('auto_renewal_present', $renewingKeys, true), 'so is the automatic renewal itself');
// One high finding (a long notice period on an auto-renewal) and one medium
// (the auto-renewal itself). Worth reviewing before the window closes, not a
// crisis — the high-severity floor puts it at the top of the medium band.
assert_same(50, $renewingAssessment['overall_score'], 'an auto-renewing contract with a long notice period reads medium');
assert_same('medium', (string) $renewingAssessment['risk_level'], 'and is levelled accordingly');
assert_same(['renewal' => 27], $renewingAssessment['category_scores'], 'both findings land in the renewal category');

$longNotice = null;
foreach ($renewingAssessment['findings'] as $finding) {
    if ($finding['rule_key'] === 'auto_renewal_long_notice') {
        $longNotice = $finding;
    }
}
assert_not_null($longNotice, 'the finding is readable from the assessment it belongs to');
assert_same('open', $longNotice['review_status'], 'a new finding starts open');

$reviewed = $engine->reviewFinding($ctx1, (int) $longNotice['id'], 'false_positive', 'The notice period was renegotiated in the addendum.');
assert_same('false_positive', $reviewed['review_status'], 'the reviewer decision is stored');
assert_same('USER-A', $reviewed['reviewed_by'], 'and who made it');
assert_not_null($reviewed['reviewed_at'], 'and when');

$rescored = $engine->currentAssessment($ctx1, $renewing);
assert_same(8, $rescored['overall_score'], 'dismissing a finding rescores the assessment it belonged to');
assert_same(['renewal' => 8], $rescored['category_scores'], 'and the category split with it');

$contractRow = $pdo->prepare('SELECT ai_risk_score, risk_level, health_score FROM contracts WHERE id = ?');
$contractRow->execute([$renewing]);
$scores = $contractRow->fetch();
assert_same(8, (int) $scores['ai_risk_score'], 'the contract carries the rescored number, not the one it was assessed with');

assert_throws(
    static fn () => $engine->reviewFinding($ctx1, (int) $longNotice['id'], 'invented_status'),
    'an unknown review status is refused',
    'Unknown review status'
);

// ---------------------------------------------------------------------------
// Findings are readable and filterable
// ---------------------------------------------------------------------------

assert_count(2, $engine->listFindings($ctx1, $renewing), 'the current assessment findings are listed by default');
assert_count(1, $engine->listFindings($ctx1, $renewing, ['open_only' => true]), 'the dismissed one drops out of the open list');
assert_count(1, $engine->listFindings($ctx1, $renewing, ['severity' => 'high']), 'findings filter by severity');
assert_count(0, $engine->listFindings($ctx1, $renewing, ['risk_category' => 'financial']), 'and by category');
assert_count(
    2,
    $engine->listFindings($ctx1, $renewing, ['detected_by' => 'rules']),
    'every finding this engine writes is marked as rules-detected'
);

$ordered = $engine->listFindings($ctx1, $empty);
assert_same('critical', (string) $ordered[0]['severity'], 'the worst finding is listed first');

// ---------------------------------------------------------------------------
// Health is derived from real gaps, not from a constant
// ---------------------------------------------------------------------------

$cleanHealth = $health->evaluate($ctx1, $clean);
$emptyHealth = $health->evaluate($ctx1, $empty);

assert_true($cleanHealth['overall'] > $emptyHealth['overall'], 'a complete contract is healthier than an empty one');
assert_same(88, $cleanHealth['categories']['compliance'], 'the only gap on the clean contract is that no parties are recorded');
assert_same(97, $cleanHealth['overall'], 'which costs it three points overall, weighted');
assert_same(100, $cleanHealth['categories']['legal'], 'nothing legal is missing, so nothing is deducted');
assert_true($emptyHealth['overall'] < 40, 'an empty record is not a healthy contract');
assert_contains('No document is attached', implode(' | ', $emptyHealth['explanations']), 'every deduction says what it was for');
assert_contains('No counterparty is recorded', implode(' | ', $emptyHealth['explanations']), 'including the ones that are pure data gaps');
assert_count(0, array_diff(array_keys($cleanHealth['categories']), HealthScoreService::CATEGORIES), 'the five panels are the five categories');

$pdo->prepare(
    'INSERT INTO contract_parties (contract_id, environment, cmp_id, party_role, display_name)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$clean, 'sandbox', 1, 'counterparty', 'Acme Ltd']);

$repaired = $health->evaluate($ctx1, $clean);
assert_same(100, $repaired['categories']['compliance'], 'recording the party closes the only compliance gap');
assert_same(100, $repaired['overall'], 'and the contract scores a hundred because there is nothing left to deduct');
assert_count(0, $repaired['explanations'], 'a contract with nothing wrong has nothing to explain');

$stored = $pdo->prepare('SELECT health_score, health_breakdown FROM contract_risk_assessments WHERE contract_id = ? AND is_current');
$stored->execute([$clean]);
$storedRow = $stored->fetch();
assert_same(100, (int) $storedRow['health_score'], 'the breakdown is persisted onto the assessment in force');
assert_contains('"categories"', (string) $storedRow['health_breakdown'], 'and the per-category detail with it');

$contractHealth = $pdo->prepare('SELECT health_score FROM contracts WHERE id = ?');
$contractHealth->execute([$clean]);
assert_same(100, (int) $contractHealth->fetchColumn(), 'the contract carries the headline number for the repository list');

// ---------------------------------------------------------------------------
// Recalculation, bounded
// ---------------------------------------------------------------------------

assert_same(2, $engine->recalculateAll($ctx1, 2), 'a recalculation stops at the limit it was given');
assert_same(4, $engine->recalculateAll($ctx1), 'without a limit it reaches every live contract for the company');

// ---------------------------------------------------------------------------
// Company 2 is invisible to company 1
// ---------------------------------------------------------------------------

$theirs = r_contract($pdo, 2, 'CON-2026-000001', ['title' => 'Their master agreement']);
$theirAssessment = $engine->assess($ctx2, $theirs);
$theirFinding    = (int) $theirAssessment['findings'][0]['id'];

assert_throws(
    static fn () => $engine->assess($ctx1, $theirs),
    'company 1 cannot assess company 2\'s contract',
    'Contract not found'
);
assert_throws(
    static fn () => $engine->reviewFinding($ctx1, $theirFinding, 'accepted'),
    'company 1 cannot review company 2\'s finding',
    'Risk finding not found'
);
assert_count(0, $engine->listFindings($ctx1, $theirs), 'a contract id from another company yields no findings');
assert_null($engine->currentAssessment($ctx1, $theirs), 'nor an assessment');
assert_not_null($engine->currentAssessment($ctx2, $theirs), 'company 2 reads its own');
assert_throws(
    static fn () => $health->evaluate($ctx1, $theirs),
    'health cannot be recomputed for another company\'s contract',
    'Contract not found'
);

// Company 2's rules are its own: deactivating one changes nothing for company 1.
$pdo->prepare("UPDATE contract_risk_rules SET is_active = FALSE WHERE cmp_id = 2 AND rule_key = 'no_document'")->execute();
assert_false(
    in_array('no_document', r_keys($engine->assess($ctx2, $theirs)), true),
    'company 2 turned a rule off and it stopped firing for them'
);
assert_true(
    in_array('no_document', r_keys($engine->assess($ctx1, $empty)), true),
    'and company 1 never noticed'
);

t_done('RiskEngineTest');
