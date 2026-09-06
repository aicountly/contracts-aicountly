<?php

declare(strict_types=1);

/**
 * The AI queue and the extraction pipeline that runs on it.
 *
 * Everything here runs without a network: a scripted provider stands in for the
 * model, keyed by the schema each call asks for, so a stage's answer does not
 * depend on the order the pipeline happens to make its calls in.
 *
 * What is being defended:
 *   - one request is paid for once
 *   - one job is served to one worker, even with two workers running
 *   - a failure backs off, retries, and then stops
 *   - a dead worker's job comes back
 *   - a reply that does not match its schema is never stored
 *   - nothing a person confirmed is overwritten by a later model reading
 */

require_once __DIR__ . '/bootstrap.php';

use App\Ai\ContractsAiProvider;
use App\Ai\Schemas\ExtractionSchema;
use App\Services\AiAnalysisService;
use App\Services\AiExtractionService;
use App\Services\AiJobService;
use App\Services\ContractService;

$pdo = t_database();
if ($pdo === null) {
    t_skip('no test database configured (set DB_* in server-php/.env)');
}
t_reset_database($pdo);

/**
 * A provider that answers from a script instead of a network.
 *
 * Keyed on the schema name the caller asked for rather than on call order: the
 * pipeline is allowed to reorder or skip stages, and a test that broke when it
 * did would be testing the order rather than the behaviour.
 */
final class ScriptedAiProvider implements ContractsAiProvider
{
    /** @var list<array{messages: list<array{role: string, content: string}>, options: array<string,mixed>}> */
    public array $calls = [];

    /** @param array<string,list<string>> $replies schema name to the replies it gets, in order */
    public function __construct(private array $replies, private string $fallback = '{}')
    {
    }

    public function name(): string
    {
        return 'scripted';
    }

    public function complete(array $messages, array $options = []): array
    {
        $this->calls[] = ['messages' => $messages, 'options' => $options];

        $key  = (string) ($options['schema_name'] ?? '');
        $text = $this->fallback;
        if (isset($this->replies[$key]) && $this->replies[$key] !== []) {
            $text = count($this->replies[$key]) === 1
                ? $this->replies[$key][0]
                : array_shift($this->replies[$key]);
        }

        return [
            'text'          => $text,
            'prompt_tokens' => 1200,
            'output_tokens' => 300,
            'model'         => 'scripted-1',
            'raw'           => [],
        ];
    }

    /** Every prompt this provider has been shown, concatenated. */
    public function seenText(): string
    {
        $out = '';
        foreach ($this->calls as $call) {
            foreach ($call['messages'] as $message) {
                $out .= $message['content'] . "\n";
            }
        }

        return $out;
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

$ctx      = t_context();
$otherCtx = t_context(2, 'USER-B');

$makeContract = static function (array $overrides = []) use ($pdo): int {
    static $seq = 0;
    $seq++;

    $row = array_merge([
        'cmp_id' => 1,
        'number' => sprintf('CON-2026-%06d', $seq),
        'title'  => 'AI fixture ' . $seq,
        'status' => 'draft',
    ], $overrides);

    $st = $pdo->prepare(
        'INSERT INTO contracts (environment, cmp_id, contract_number, title, status, lifecycle_stage, created_by)
         VALUES (?, ?, ?, ?, ?, \'draft\', \'USER-A\') RETURNING id'
    );
    $st->execute(['sandbox', $row['cmp_id'], $row['number'], $row['title'], $row['status']]);

    return (int) $st->fetchColumn();
};

$makeVersion = static function (int $contractId, ?string $text, bool $scanned = false, int $cmpId = 1) use ($pdo): int {
    $doc = $pdo->prepare(
        'INSERT INTO contract_documents (environment, cmp_id, contract_id, doc_kind, title, created_by)
         VALUES (?, ?, ?, \'contract\', ?, \'USER-A\') RETURNING id'
    );
    $doc->execute(['sandbox', $cmpId, $contractId, 'Agreement.pdf']);
    $documentId = (int) $doc->fetchColumn();

    $st = $pdo->prepare(
        'INSERT INTO contract_document_versions
         (document_id, environment, cmp_id, version_no, filename, content_type, storage_provider,
          drive_document_id, extracted_text, extracted_pages, is_scanned)
         VALUES (?, ?, ?, 1, \'Agreement.pdf\', \'application/pdf\', \'drive\', \'DRIVE-1\', ?, 4, ?)
         RETURNING id'
    );
    $st->execute([$documentId, 'sandbox', $cmpId, $text, $scanned ? 'true' : 'false']);

    return (int) $st->fetchColumn();
};

/** A complete, schema-valid script for every stage of the pipeline. */
$script = static function (array $overrides = []): array {
    $field = static fn (mixed $value, float $confidence = 0.9): array => [
        'value'          => $value,
        'confidence'     => $confidence,
        'source_page'    => 1,
        'source_excerpt' => 'Clause 1.1 of the agreement.',
    ];

    $fields = [];
    foreach (array_keys(ExtractionSchema::CONTRACT_FIELDS) as $key) {
        $fields[$key] = $field(null, 0.2);
    }
    $fields['contract_title']     = $field('Master Services Agreement');
    $fields['counterparty_name']  = $field('Globex Ltd');
    $fields['effective_date']     = $field('2026-01-01');
    $fields['expiry_date']        = $field('2027-12-31');
    $fields['currency']           = $field('INR');
    $fields['total_value']        = $field(150000);
    $fields['auto_renewal']       = $field(true);
    $fields['notice_period_days'] = $field(60);
    $fields['renewal_type']       = $field('auto_renew');
    $fields['governing_law']      = $field('Laws of India');

    return array_merge([
        'contract_classification' => [json_encode([
            'document_type'         => $field('Master Services Agreement'),
            'matched_known_type'    => $field(null, 0.3),
            'document_completeness' => $field('executed_agreement', 0.8),
        ])],
        'contract_data' => [json_encode([
            'fields'  => $fields,
            'parties' => [
                ['name' => 'Acme Private Limited', 'role' => 'company', 'is_company' => true, 'confidence' => 0.95, 'source_page' => 1, 'source_excerpt' => 'Acme Private Limited'],
                ['name' => 'Globex Ltd', 'role' => 'counterparty', 'is_company' => false, 'confidence' => 0.9, 'source_page' => 1, 'source_excerpt' => 'Globex Ltd'],
            ],
        ])],
        'clauses' => [json_encode(['clauses' => [
            ['clause_number' => '7.2', 'heading' => 'Limitation of Liability', 'category' => null,
             'body_text' => 'Neither party shall be liable for indirect losses.', 'summary' => 'Caps indirect loss.',
             'confidence' => 0.88, 'source_page' => 3, 'source_excerpt' => 'Neither party shall be liable'],
            ['clause_number' => '9.1', 'heading' => 'Termination', 'category' => null,
             'body_text' => 'Either party may terminate on sixty days notice.', 'summary' => 'Termination for convenience.',
             'confidence' => 0.91, 'source_page' => 4, 'source_excerpt' => 'Either party may terminate'],
        ]])],
        'obligations' => [json_encode(['obligations' => [
            ['title' => 'Submit quarterly SLA report', 'description' => 'Supplier reports service levels each quarter.',
             'obligation_type' => 'reporting', 'responsible_party' => 'counterparty', 'frequency' => 'quarterly',
             'first_due_date' => '2026-04-01', 'amount' => null, 'currency' => null, 'evidence_required' => true,
             'clause_reference' => '5.3', 'confidence' => 0.8, 'source_page' => 2, 'source_excerpt' => 'quarterly report'],
        ]])],
        'milestones' => [json_encode(['milestones' => [
            ['title' => 'Go live', 'description' => 'Service commences.', 'milestone_type' => 'delivery',
             'due_date' => '2026-02-01', 'amount' => null, 'currency' => null, 'clause_reference' => '2.1',
             'confidence' => 0.85, 'source_page' => 1, 'source_excerpt' => 'go live on 1 February 2026'],
        ]])],
    ], $overrides);
};

$documentText = "MASTER SERVICES AGREEMENT\n\nThis agreement is made between Acme Private Limited and Globex Ltd.\n\n"
    . "1.1 The agreement is effective from 1 January 2026 and expires on 31 December 2027.\n\n"
    . "5.3 The supplier shall submit a quarterly SLA report.\n\n"
    . "7.2 Neither party shall be liable for indirect losses.\n\n"
    . "9.1 Either party may terminate on sixty days notice.\n";

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

$contractId = $makeContract();
$versionId  = $makeVersion($contractId, $documentText);

$jobs  = new AiJobService($pdo);
$first = $jobs->enqueue($ctx, 'extract', ['scope' => 'full', 'depth' => 2], $contractId, $versionId);

assert_same('queued', $first['status'], 'a new job starts queued');
assert_same(0, $first['attempts'], 'and with no attempts spent');

$again = $jobs->enqueue($ctx, 'extract', ['depth' => 2, 'scope' => 'full'], $contractId, $versionId);
assert_same($first['id'], $again['id'], 'an identical request returns the job that already exists');

$count = $pdo->prepare('SELECT COUNT(*) FROM ai_jobs WHERE contract_id = ? AND kind = \'extract\'');
$count->execute([$contractId]);
assert_same(1, (int) $count->fetchColumn(), 'and does not queue a second one to pay for');

$different = $jobs->enqueue($ctx, 'extract', ['scope' => 'clauses_only'], $contractId, $versionId);
assert_true($different['id'] !== $first['id'], 'a different payload is a different job');

$summarise = $jobs->enqueue($ctx, 'summarize', ['scope' => 'full', 'depth' => 2], $contractId, $versionId);
assert_true($summarise['id'] !== $first['id'], 'the same payload for a different kind is a different job');

assert_throws(
    static fn () => $jobs->enqueue($ctx, 'transmogrify', [], $contractId),
    'an unknown job kind is refused',
    'Unknown AI job kind'
);

$otherContract = $makeContract(['cmp_id' => 2, 'number' => 'CON-OTHER-1']);
assert_throws(
    static fn () => $jobs->enqueue($ctx, 'extract', [], $otherContract),
    'a job cannot be queued against another company\'s contract',
    'Contract not found'
);

// ---------------------------------------------------------------------------
// Claiming: SKIP LOCKED must not serve one job twice
// ---------------------------------------------------------------------------

$pdo->exec('TRUNCATE ai_jobs RESTART IDENTITY CASCADE');
$jobA = $jobs->enqueue($ctx, 'extract', ['n' => 1], $contractId, $versionId);
$jobB = $jobs->enqueue($ctx, 'extract', ['n' => 2], $contractId, $versionId);

$params = App\Core\Database::connectionParams();
$second = new PDO($params['dsn'], $params['user'], App\Core\Env::get('DB_PASS'), [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$second->exec("SET TIME ZONE 'UTC'");
$otherWorker = new AiJobService($second);

// Worker one holds its claim open, the way a worker does while it is calling a
// model. Worker two must find the other job, not this one.
$pdo->beginTransaction();
$claimedByOne = $jobs->claim('sandbox', 1, 'worker-one');
assert_count(1, $claimedByOne, 'a worker claims the job it asked for');

$claimedByTwo = $otherWorker->claim('sandbox', 5, 'worker-two');
assert_count(1, $claimedByTwo, 'a second worker running at the same time claims only what is left');
assert_true(
    $claimedByOne[0]['id'] !== $claimedByTwo[0]['id'],
    'and never the job the first worker is already holding'
);
$pdo->commit();

assert_same('running', $claimedByOne[0]['status'], 'a claimed job is running');
assert_same(1, $claimedByOne[0]['attempts'], 'and has spent one attempt');
assert_same('worker-one', $claimedByOne[0]['locked_by'], 'and records which worker holds it');

assert_count(0, $jobs->claim('sandbox', 5, 'worker-three'), 'a third worker finds nothing left to claim');

// ---------------------------------------------------------------------------
// Backoff and max_attempts
// ---------------------------------------------------------------------------

assert_same(60, AiJobService::backoffSeconds(1), 'the first retry waits a minute');
assert_same(120, AiJobService::backoffSeconds(2), 'the second waits two');
assert_same(240, AiJobService::backoffSeconds(3), 'the third waits four');
assert_same(3600, AiJobService::backoffSeconds(30), 'and nothing waits longer than an hour');

$pdo->exec('TRUNCATE ai_jobs RESTART IDENTITY CASCADE');
$retryJob = $jobs->enqueue($ctx, 'classify', ['n' => 'retry'], $contractId, $versionId);
$retryId  = (int) $retryJob['id'];

$jobs->claim('sandbox', 1, 'worker-one');
$jobs->fail($retryId, 'PROVIDER_TIMEOUT', 'The provider did not answer in time.');

$afterFirst = $jobs->find($ctx, $retryId);
assert_same('queued', $afterFirst['status'], 'a failure inside the attempt budget goes back on the queue');
assert_same('PROVIDER_TIMEOUT', $afterFirst['error_code'], 'and records why it is waiting');
assert_not_null($afterFirst['next_attempt_at'], 'and when it will be tried again');

assert_count(0, $jobs->claim('sandbox', 5, 'worker-one'), 'a job inside its backoff window is not claimed');

$forward = $pdo->prepare('UPDATE ai_jobs SET next_attempt_at = CURRENT_TIMESTAMP - INTERVAL \'1 hour\' WHERE id = ?');
$forward->execute([$retryId]);

$jobs->claim('sandbox', 1, 'worker-one');
$jobs->fail($retryId, 'PROVIDER_TIMEOUT', 'Again.');
$forward->execute([$retryId]);

$jobs->claim('sandbox', 1, 'worker-one');
$jobs->fail($retryId, 'PROVIDER_TIMEOUT', 'And again.');

$spent = $jobs->find($ctx, $retryId);
assert_same('failed', $spent['status'], 'the attempt budget runs out and the job stops');
assert_same(3, $spent['attempts'], 'after exactly max_attempts attempts');
assert_same('PROVIDER_TIMEOUT', $spent['error_code'], 'with the error kept');
assert_not_null($spent['completed_at'], 'and an end time, so the queue is not still waiting on it');
assert_null($spent['next_attempt_at'], 'and no next attempt scheduled');

assert_count(0, $jobs->claim('sandbox', 5, 'worker-one'), 'a spent job is never claimed again');

// Asking for the same work again is a retry request, not a duplicate charge.
$revived = $jobs->enqueue($ctx, 'classify', ['n' => 'retry'], $contractId, $versionId);
assert_same($retryId, (int) $revived['id'], 'asking again reuses the failed job rather than opening a second');
assert_same('queued', $revived['status'], 'and puts it back on the queue');
assert_same(0, $revived['attempts'], 'with a fresh attempt budget');

// ---------------------------------------------------------------------------
// Reaping a worker that died
// ---------------------------------------------------------------------------

$pdo->exec('TRUNCATE ai_jobs RESTART IDENTITY CASCADE');
$lost = $jobs->enqueue($ctx, 'classify', ['n' => 'lost'], $contractId, $versionId);
$jobs->claim('sandbox', 1, 'worker-that-died');

assert_same(0, $jobs->reapStale('sandbox', 900), 'a job claimed a moment ago is left alone');

$pdo->prepare('UPDATE ai_jobs SET locked_at = CURRENT_TIMESTAMP - INTERVAL \'30 minutes\' WHERE id = ?')
    ->execute([(int) $lost['id']]);

assert_same(1, $jobs->reapStale('sandbox', 900), 'a job whose worker stopped is reaped');
$reaped = $jobs->find($ctx, (int) $lost['id']);
assert_same('queued', $reaped['status'], 'and goes back on the queue');
assert_null($reaped['locked_by'], 'with the dead worker\'s lock cleared');
assert_same('WORKER_LOST', $reaped['error_code'], 'and a reason an operator can read');

$pdo->prepare('UPDATE ai_jobs SET status = \'running\', attempts = max_attempts, locked_at = CURRENT_TIMESTAMP - INTERVAL \'30 minutes\', locked_by = \'gone\' WHERE id = ?')
    ->execute([(int) $lost['id']]);
assert_same(1, $jobs->reapStale('sandbox', 900), 'a job with no attempts left is also reaped');
assert_same('failed', $jobs->find($ctx, (int) $lost['id'])['status'], 'but is failed rather than queued for a claim that can never happen');

// ---------------------------------------------------------------------------
// A reply that does not match its schema is never stored
// ---------------------------------------------------------------------------

$pdo->exec('TRUNCATE ai_jobs RESTART IDENTITY CASCADE');

$junk    = new ScriptedAiProvider([], 'Certainly! Here is the contract data you asked for.');
$junkJob = (new AiJobService($pdo, $junk))->enqueue($ctx, 'classify', ['n' => 'junk'], $contractId, $versionId);
$junkId  = (int) $junkJob['id'];

$worker = new AiJobService($pdo, $junk);
$worker->claim('sandbox', 1, 'worker-one');
$worker->process($junkId);

assert_count(2, $junk->calls, 'a malformed reply is retried exactly once, with a stricter instruction');
assert_contains('did not match the required format', $junk->calls[1]['messages'][2]['content'], 'and the retry quotes the problem back');

$junkResult = $jobs->find($ctx, $junkId);
assert_same('AI_SCHEMA_INVALID', $junkResult['error_code'], 'the job records that the model would not answer in shape');

$extractionCount = $pdo->query('SELECT COUNT(*) FROM ai_extractions')->fetchColumn();
assert_same(0, (int) $extractionCount, 'and nothing unvalidated reaches the extraction table');

$usage = $pdo->query('SELECT COUNT(*) FROM ai_usage_log WHERE success = FALSE')->fetchColumn();
assert_same(2, (int) $usage, 'both failed calls are still metered');

// ---------------------------------------------------------------------------
// The pipeline end to end
// ---------------------------------------------------------------------------

$pdo->exec('TRUNCATE ai_jobs RESTART IDENTITY CASCADE');

$provider = new ScriptedAiProvider($script());
$worker   = new AiJobService($pdo, $provider);
$job      = $worker->enqueue($ctx, 'extract', ['run' => 1], $contractId, $versionId);
$jobId    = (int) $job['id'];

$worker->claim('sandbox', 1, 'worker-one');
$worker->process($jobId);

$done = $jobs->find($ctx, $jobId);
assert_same('succeeded', $done['status'], 'the pipeline completes the job');
assert_same(5, count($provider->calls), 'one model call per stage');
assert_true(($done['prompt_tokens'] ?? 0) >= 5000, 'the job carries what every stage cost');

$stored = $pdo->prepare('SELECT * FROM ai_extractions WHERE contract_id = ? ORDER BY field_key');
$stored->execute([$contractId]);
$rows = $stored->fetchAll();
assert_same(19, count($rows), 'every extracted field is stored as its own reviewable row');

$byKey = [];
foreach ($rows as $row) {
    $byKey[(string) $row['field_key']] = $row;
}
assert_same('2026-01-01', $byKey['effective_date']['normalised_value'], 'a date arrives normalised');
assert_same('date', $byKey['effective_date']['value_type'], 'and typed');
assert_same('150000.00', $byKey['total_value']['normalised_value'], 'money is stored as a fixed-scale string');
assert_same('pending', $byKey['effective_date']['review_state'], 'and waits for a person to confirm it');
assert_not_null($byKey['effective_date']['source_excerpt'], 'with the wording it came from');
assert_same('Master Services Agreement', $byKey['document_type']['extracted_value'], 'the classification is a reviewable field too');

$clauses = $pdo->prepare('SELECT * FROM contract_clauses WHERE contract_id = ? ORDER BY id');
$clauses->execute([$contractId]);
$clauseRows = $clauses->fetchAll();
assert_same(2, count($clauseRows), 'clauses are written');
assert_same('ai_extracted', $clauseRows[0]['verification_state'], 'marked as unverified');
assert_true(ContractService::toBool($clauseRows[0]['is_ai_extracted']), 'and as AI extracted');

$obligation = $pdo->prepare('SELECT * FROM contract_obligations WHERE contract_id = ?');
$obligation->execute([$contractId]);
$obligationRows = $obligation->fetchAll();
assert_same(1, count($obligationRows), 'a suggested obligation is written');
assert_false(ContractService::toBool($obligationRows[0]['is_active']), 'but inactive until a person verifies it, so it cannot start alerting on its own');

$milestone = $pdo->prepare('SELECT COUNT(*) FROM contract_milestones WHERE contract_id = ?');
$milestone->execute([$contractId]);
assert_same(1, (int) $milestone->fetchColumn(), 'and a dated milestone');

// ---------------------------------------------------------------------------
// A human decision survives the next model reading
// ---------------------------------------------------------------------------

$extraction = new AiExtractionService($pdo, $provider);

$queue = $extraction->reviewQueue($ctx, ['contract_id' => $contractId], 50, 0);
assert_same(19, $queue['total'], 'the review queue holds everything that is still pending');
assert_true(
    $queue['items'][0]['confidence'] <= $queue['items'][1]['confidence'],
    'least certain first, because that is what a reviewer should see first'
);

$accepted = $extraction->acceptExtraction($ctx, (int) $byKey['effective_date']['id'], '2026-01-15');
assert_same('edited', $accepted['review_state'], 'a corrected value is recorded as edited, not accepted');
assert_same('2026-01-15', $accepted['accepted_value'], 'and keeps what the person actually said');

$pdo->prepare('UPDATE contract_clauses SET verification_state = \'human_verified\', verified_by = \'USER-A\' WHERE id = ?')
    ->execute([(int) $clauseRows[0]['id']]);

$rerunProvider = new ScriptedAiProvider($script());
$rerunWorker   = new AiJobService($pdo, $rerunProvider);
$rerun         = $rerunWorker->enqueue($ctx, 'extract', ['run' => 2], $contractId, $versionId);
$rerunWorker->claim('sandbox', 1, 'worker-one');
$rerunWorker->process((int) $rerun['id']);

$after = $pdo->prepare('SELECT * FROM ai_extractions WHERE contract_id = ? AND field_key = \'effective_date\'');
$after->execute([$contractId]);
$effectiveRows = $after->fetchAll();
assert_same(1, count($effectiveRows), 'a re-run does not queue a second opinion on a field somebody has settled');
assert_same('2026-01-15', $effectiveRows[0]['accepted_value'], 'and never overwrites the value a person confirmed');
assert_same('edited', $effectiveRows[0]['review_state'], 'which stays confirmed');

$verifiedClauses = $pdo->prepare('SELECT COUNT(*) FROM contract_clauses WHERE contract_id = ? AND verification_state = \'human_verified\'');
$verifiedClauses->execute([$contractId]);
assert_same(1, (int) $verifiedClauses->fetchColumn(), 'a verified clause survives the next extraction');

$totalClauses = $pdo->prepare('SELECT COUNT(*) FROM contract_clauses WHERE contract_id = ?');
$totalClauses->execute([$contractId]);
assert_same(3, (int) $totalClauses->fetchColumn(), 'while the unverified ones are replaced rather than duplicated');

// ---------------------------------------------------------------------------
// Applying what was verified
// ---------------------------------------------------------------------------

$applied = $extraction->applyVerified($ctx, $contractId);
assert_same('2026-01-15', $applied['applied']['effective_date'], 'the confirmed value is written to the contract');

$contractRow = $pdo->prepare('SELECT * FROM contracts WHERE id = ?');
$contractRow->execute([$contractId]);
$contract = $contractRow->fetch();
assert_same('2026-01-15', $contract['effective_date'], 'and the contract now says what the reviewer said');
assert_null($contract['counterparty_name'], 'a value nobody confirmed is not written');
assert_same('human_verified', $contract['verification_state'], 'and the record is marked as confirmed by a person');

// ---------------------------------------------------------------------------
// Summaries: regenerating must not destroy a reviewer's wording
// ---------------------------------------------------------------------------

$sections = [];
foreach (array_keys(ExtractionSchema::SUMMARY_SECTIONS) as $key) {
    $sections[$key] = [
        'content'        => 'What the contract says about ' . $key . '.',
        'confidence'     => 0.8,
        'source_page'    => 1,
        'source_excerpt' => 'Clause 1.1',
    ];
}
$sections['management_action_items'] = [
    ['action' => 'Diarise the notice deadline', 'why_it_matters' => 'The contract renews on its own.',
     'urgency' => 'before_renewal', 'confidence' => 0.9, 'source_page' => 4, 'source_excerpt' => 'sixty days notice'],
];

$summaryProvider = new ScriptedAiProvider(['contract_summary' => [json_encode(['sections' => $sections])]]);
$summaryWorker   = new AiJobService($pdo, $summaryProvider);
$summaryJob      = $summaryWorker->enqueue($ctx, 'summarize', ['run' => 1], $contractId, $versionId);

$summaryWorker->claim('sandbox', 1, 'worker-one');
$summaryWorker->process((int) $summaryJob['id']);

assert_same('succeeded', $jobs->find($ctx, (int) $summaryJob['id'])['status'], 'a summarise job completes through the queue');

$analysis = new AiAnalysisService($pdo, $summaryProvider);
$current  = $analysis->current($ctx, $contractId);
assert_not_null($current, 'and leaves a current summary');
assert_same(21, count($current['sections']), 'with every section the spec asks for');
assert_count(1, $current['management_actions'], 'and the action items pulled out where a dashboard can count them');
assert_contains('executive_summary', (string) $current['executive_summary'], 'the executive summary is lifted into its own column');

$analysis->editSummary($ctx, $contractId, ['liability' => 'Uncapped for data breach. Legal has flagged this.']);
$edited = $analysis->current($ctx, $contractId);
assert_contains('Legal has flagged', $edited['edited_sections']['liability']['content'], 'a reviewer\'s wording is stored');
assert_contains('What the contract says', $edited['sections']['liability']['content'], 'beside the model\'s, not over it');

$analysis->summarize($ctx, $contractId);
$regenerated = $analysis->current($ctx, $contractId);
assert_true((int) $regenerated['id'] !== (int) $edited['id'], 'regenerating writes a new summary');
assert_contains('Legal has flagged', $regenerated['edited_sections']['liability']['content'], 'and carries the edit forward rather than destroying it');
assert_same((int) $edited['id'], (int) $regenerated['edited_sections']['liability']['edited_from'], 'noting which generation it was written against');

$history = $analysis->history($ctx, $contractId);
assert_same(2, count($history), 'and the earlier reading is still there to compare against');

// ---------------------------------------------------------------------------
// A document with nothing to read
// ---------------------------------------------------------------------------

$scannedContract = $makeContract();
$scannedVersion  = $makeVersion($scannedContract, null, true);

$callsBeforeScan = count($provider->calls);
$scanJob = $worker->enqueue($ctx, 'extract', [], $scannedContract, $scannedVersion);
$worker->claim('sandbox', 1, 'worker-one');
$worker->process((int) $scanJob['id']);

$scanResult = $jobs->find($ctx, (int) $scanJob['id']);
assert_same('DOCUMENT_NOT_TEXT_SEARCHABLE', $scanResult['error_code'], 'a scan with no text layer fails with a reason, not an empty prompt');

assert_same($callsBeforeScan, count($provider->calls), 'and costs nothing, because the pipeline stopped before it called anything');

// ---------------------------------------------------------------------------
// Tenant scope
// ---------------------------------------------------------------------------

assert_null($jobs->find($otherCtx, $jobId), 'another company cannot read this company\'s job');
assert_count(0, $jobs->listForContract($otherCtx, $contractId), 'nor list its jobs');

$status = $jobs->statusFor($ctx, $contractId);
assert_true(isset($status['extract']), 'the status view reports the latest job of each kind');
assert_same('succeeded', $status['extract']['status'], 'with its outcome');

t_done('AiJobServiceTest');
