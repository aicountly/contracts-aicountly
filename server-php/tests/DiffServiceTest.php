<?php

declare(strict_types=1);

/**
 * The deterministic redline.
 *
 * The behaviour this suite exists to hold still: an added paragraph reads as an
 * addition and a reworded one reads as a rewrite of that same paragraph; a
 * changed figure, a changed liability sentence and a changed forum are named
 * without a model being asked; a comparison is computed once and then read from
 * the cache; and a document large enough to be dangerous is compared coarsely
 * rather than slowly.
 */

require_once __DIR__ . '/bootstrap.php';

use App\Services\CompanyBootstrapService;
use App\Services\DiffService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

$ctx1 = t_context(1, 'USER-A');
$ctx2 = t_context(2, 'USER-B');

(new CompanyBootstrapService($pdo))->ensure('sandbox', 1);
(new CompanyBootstrapService($pdo))->ensure('sandbox', 2);

$diffs = new DiffService($pdo);

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function d_contract(PDO $pdo, int $cmpId, string $number): int
{
    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage,
                                owner_uuid, currency, counterparty_name, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $number, 'Supply agreement', 'draft', 'draft', 'USER-A', 'INR', 'Acme Ltd', 'USER-A']);

    return (int) $st->fetchColumn();
}

function d_document(PDO $pdo, int $cmpId, int $contractId): int
{
    $st = $pdo->prepare(
        'INSERT INTO contract_documents (environment, cmp_id, contract_id, doc_kind, title, created_by)
         VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['sandbox', $cmpId, $contractId, 'contract', 'Supply agreement', 'USER-A']);

    return (int) $st->fetchColumn();
}

function d_version(PDO $pdo, int $cmpId, int $documentId, int $versionNo, ?string $text): int
{
    $st = $pdo->prepare(
        'INSERT INTO contract_document_versions
         (document_id, environment, cmp_id, version_no, version_status, filename, content_type,
          size_bytes, storage_provider, local_path, extracted_text, text_extracted_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute([
        $documentId, 'sandbox', $cmpId, $versionNo, 'internal_draft',
        sprintf('agreement-v%d.pdf', $versionNo), 'application/pdf', 2048,
        'local', sprintf('/tmp/contracts-test/v%d.pdf', $versionNo),
        $text, $text === null ? null : date('Y-m-d H:i:s'),
    ]);

    return (int) $st->fetchColumn();
}

/** @param list<array<string,mixed>> $changes */
function d_change(array $changes, int $segment): ?array
{
    foreach ($changes as $change) {
        if ($change['segment'] === $segment) {
            return $change;
        }
    }

    return null;
}

/** @param list<array<string,mixed>> $segments @return list<string> */
function d_types(array $segments): array
{
    return array_map(static fn (array $s): string => (string) $s['type'], $segments);
}

$base = "1. Definitions. In this Agreement, \"Services\" means the services described in Schedule A.\n\n"
    . "2. Term. This Agreement commences on 1 April 2026 and continues for twelve months.\n\n"
    . "3. Fees. The total contract value is INR 5,00,000.\n\n"
    . "4. Limitation of Liability. Neither party shall be liable for indirect or consequential loss.\n\n"
    . '5. Governing Law. This Agreement is governed by the laws of India.';

$target = "1. Definitions. In this Agreement, \"Services\" means the services described in Schedule A.\n\n"
    . "2. Term. This Agreement commences on 1 April 2026 and continues for twelve months.\n\n"
    . "2A. Notices. Any notice under this Agreement shall be in writing and sent to the addresses in Schedule B.\n\n"
    . "3. Fees. The total contract value is INR 7,50,000.\n\n"
    . "4. Limitation of Liability. Each party's aggregate liability is limited to the fees paid in the preceding twelve months.\n\n"
    . '5. Governing Law. This Agreement is governed by the laws of India.';

// ---------------------------------------------------------------------------
// The diff itself
// ---------------------------------------------------------------------------

$unchanged = $diffs->diff($base, $base);

assert_same(['equal'], d_types($unchanged['segments']), 'a document compared with itself is one equal segment');
assert_same(0, $unchanged['stats']['changed'], 'and nothing changed');
assert_same(1.0, $unchanged['stats']['similarity'], 'and is completely similar to itself');

$result   = $diffs->diff($base, $target);
$segments = $result['segments'];

assert_same(
    ['equal', 'insert', 'replace', 'replace', 'equal'],
    d_types($segments),
    'an inserted paragraph is an insertion, and the two reworded ones are rewrites rather than a bulk add and remove'
);

assert_contains('2A. Notices', $segments[1]['target'], 'the inserted paragraph is the new one');
assert_same('', $segments[1]['base'], 'an insertion has nothing on the base side');
assert_contains('Schedule A', $segments[0]['base'], 'the untouched head is one equal run');
assert_contains('Governing Law', $segments[4]['base'], 'and so is the untouched tail');

assert_same(1, $result['stats']['added'], 'one paragraph was added');
assert_same(0, $result['stats']['removed'], 'none was removed outright');
assert_same(2, $result['stats']['changed'], 'and two were rewritten');
assert_true($result['stats']['similarity'] > 0.6, 'the documents are still mostly the same document');
assert_true($result['stats']['similarity'] < 1.0, 'but not identical');
assert_same('lcs', $result['mode'], 'a document this size gets the real thing');
assert_false($result['truncated'], 'and is not truncated');

// Word level, so the UI can strike out a figure instead of a clause.
$words = $segments[2]['words'] ?? [];
assert_count(3, $words, 'the reworded fee paragraph is diffed down to words');
assert_same('equal', $words[0]['type'], 'the wording it kept comes first');
assert_contains('total contract value', $words[0]['text'], 'and is the part that did not move');
assert_same('5,00,000.', $words[1]['text'], 'the old figure is marked as removed');
assert_same('7,50,000.', $words[2]['text'], 'and the new one as added');

$removal = $diffs->diff($base, str_replace("3. Fees. The total contract value is INR 5,00,000.\n\n", '', $base));
assert_same(['equal', 'delete', 'equal'], d_types($removal['segments']), 'a deleted paragraph is a deletion');
assert_same(1, $removal['stats']['removed'], 'and is counted as removed');

// Line-wrapping is not a change: the same clause extracted from a PDF and from
// a DOCX wraps differently, and reporting that would bury the real edits.
$wrapped = $diffs->diff(
    "The Supplier shall deliver the Services with reasonable skill and care.",
    "The Supplier shall deliver\nthe Services with reasonable   skill and care."
);
assert_same(['equal'], d_types($wrapped['segments']), 'a re-wrapped paragraph is not a change');

// ---------------------------------------------------------------------------
// Naming the change
// ---------------------------------------------------------------------------

$changes = $diffs->classifyChanges($segments);

assert_count(3, $changes, 'every non-equal segment is classified, and nothing else is');

$fees = d_change($changes, 2);
assert_same('amount', $fees['category'], 'a changed monetary figure is an amount change');
assert_same('A monetary amount changed.', $fees['summary'], 'and says so in a sentence the UI can print');
assert_contains('inr7,50,000', implode(' ', $fees['matched']), 'with the figures that moved as the evidence');

$liability = d_change($changes, 3);
assert_same('liability', $liability['category'], 'a changed liability sentence is a liability change, not merely a wording one');
assert_same('The liability language changed.', $liability['summary'], 'which is the sentence a negotiator needs');
assert_contains('limitation of liability', implode(' ', $liability['matched']), 'and the term that placed it');

assert_same('other', d_change($changes, 1)['category'], 'a paragraph matching nothing in particular is not forced into a category');

// A clause keyword beats a bare figure: when a cap is renegotiated the useful
// sentence is that liability changed, not that a number did.
$capped = $diffs->diff(
    'Liability is capped at INR 1,00,000 in aggregate.',
    'Liability is capped at INR 9,00,000 in aggregate.'
);
assert_same('liability', $diffs->classifyChanges($capped['segments'])[0]['category'], 'a cap change inside a liability clause is filed under liability');

$forum = $diffs->diff(
    'This Agreement is governed by the laws of India and the courts of Mumbai have exclusive jurisdiction.',
    'This Agreement is governed by the laws of Singapore and the courts of Singapore have exclusive jurisdiction.'
);
assert_same('governing_law', $diffs->classifyChanges($forum['segments'])[0]['category'], 'a changed forum is a governing law change');

$term = $diffs->diff(
    'Either party may terminate this Agreement on 30 days written notice.',
    'Either party may terminate this Agreement on 90 days written notice.'
);
assert_same('termination', $diffs->classifyChanges($term['segments'])[0]['category'], 'a changed termination notice is a termination change');

$dated = $diffs->diff(
    'The Supplier will deliver the goods by 31 March 2026.',
    'The Supplier will deliver the goods by 30 June 2026.'
);
assert_same('date', $diffs->classifyChanges($dated['segments'])[0]['category'], 'a changed date with no other signal is a date change');

// The amount rule compares the figures rather than merely finding one, so a
// clause whose amount is the one thing that stayed put is not called an
// amount change.
$sameMoney = $diffs->diff(
    'The deposit of INR 50,000 is held by the Supplier.',
    'The deposit of INR 50,000 is held by the Customer.'
);
assert_same('other', $diffs->classifyChanges($sameMoney['segments'])[0]['category'], 'an unchanged figure does not make a change an amount change');

// ---------------------------------------------------------------------------
// The caps
// ---------------------------------------------------------------------------

$huge = $diffs->diff(str_repeat('word ', 90000), str_repeat('other ', 90000));
assert_true($huge['truncated'], 'input past the byte cap is clipped rather than compared whole');

$manyA = [];
$manyB = [];
for ($i = 0; $i < 1100; $i++) {
    $manyA[] = 'Base paragraph number ' . $i . ' says something specific.';
    $manyB[] = 'Target paragraph number ' . $i . ' says something else entirely.';
}
$wide = $diffs->diff(implode("\n\n", $manyA), implode("\n\n", $manyB));

assert_same('paragraph_only', $wide['mode'], 'a document with too many changed paragraphs falls back rather than hanging');
assert_same(1100, $wide['stats']['changed'], 'the fallback still reports the size of the change');

// ---------------------------------------------------------------------------
// Comparing two stored versions
// ---------------------------------------------------------------------------

$contractId = d_contract($pdo, 1, 'CON-2026-000001');
$documentId = d_document($pdo, 1, $contractId);
$v1         = d_version($pdo, 1, $documentId, 1, $base);
$v2         = d_version($pdo, 1, $documentId, 2, $target);
$v3         = d_version($pdo, 1, $documentId, 3, null);

$first = $diffs->compareVersions($ctx1, $v1, $v2);

assert_false($first['cached'], 'the first comparison is computed');
assert_same($contractId, $first['contract_id'], 'and filed against the contract both versions belong to');
assert_same(1, $first['base']['version_no'], 'the payload names the version it compared from');
assert_same(2, $first['target']['version_no'], 'and the one it compared to');
assert_count(5, $first['segments'], 'the stored diff is the diff');
assert_count(3, $first['changes'], 'and the classification is stored beside it');
assert_same(2, $first['stats']['changed'], 'as are the statistics');
assert_same('USER-A', $first['generated_by'], 'with who asked for it');

// The inputs of a comparison cannot change, so the answer never needs
// recomputing. Rewriting the extracted text here is a thing only a test can do,
// and it is what proves the second call read the cache rather than the source.
$pdo->prepare('UPDATE contract_document_versions SET extracted_text = ? WHERE id = ?')
    ->execute(['Something else entirely.', $v2]);
$pdo->prepare("UPDATE contract_version_comparisons SET ai_explanation = 'explained once' WHERE id = ?")
    ->execute([$first['id']]);

$second = $diffs->compareVersions($ctx1, $v1, $v2);

assert_true($second['cached'], 'the second comparison is served from the cache');
assert_same($first['id'], $second['id'], 'it is the same stored comparison');
assert_same($first['stats'], $second['stats'], 'with the statistics it was computed with');
assert_count(5, $second['segments'], 'and the segments it was computed with');
assert_same('explained once', $second['ai_explanation'], 'anything written against it since comes back too');

$rows = $pdo->prepare('SELECT COUNT(*) FROM contract_version_comparisons WHERE base_version_id = ? AND target_version_id = ?');
$rows->execute([$v1, $v2]);
assert_same(1, (int) $rows->fetchColumn(), 'and one comparison is stored, not two');

// ---------------------------------------------------------------------------
// What it refuses
// ---------------------------------------------------------------------------

assert_throws(
    static fn () => $diffs->compareVersions($ctx1, $v1, $v1),
    'a version cannot be compared with itself',
    'two different versions'
);

assert_throws(
    static fn () => $diffs->compareVersions($ctx1, $v1, $v3),
    'a version whose text has not been extracted is refused rather than cached as an empty document',
    'no extracted text'
);

$otherContract = d_contract($pdo, 1, 'CON-2026-000002');
$otherDocument = d_document($pdo, 1, $otherContract);
$otherVersion  = d_version($pdo, 1, $otherDocument, 1, $base);

assert_throws(
    static fn () => $diffs->compareVersions($ctx1, $v1, $otherVersion),
    'two versions from different contracts are not comparable',
    'same contract'
);

// ---------------------------------------------------------------------------
// Company 2 cannot reach company 1's versions
// ---------------------------------------------------------------------------

$theirContract = d_contract($pdo, 2, 'CON-2026-000001');
$theirDocument = d_document($pdo, 2, $theirContract);
$theirV1       = d_version($pdo, 2, $theirDocument, 1, $base);
$theirV2       = d_version($pdo, 2, $theirDocument, 2, $target);

assert_throws(
    static fn () => $diffs->compareVersions($ctx2, $v1, $v2),
    'company 2 cannot compare company 1\'s versions',
    'Document version not found'
);

$theirs = $diffs->compareVersions($ctx2, $theirV1, $theirV2);
assert_same($theirContract, $theirs['contract_id'], 'company 2 gets its own comparison of its own versions');

assert_throws(
    static fn () => $diffs->compareVersions($ctx1, $theirV1, $theirV2),
    'and company 1 cannot read it',
    'Document version not found'
);

t_done('DiffServiceTest');
