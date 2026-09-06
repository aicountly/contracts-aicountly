<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\ContractsAiProvider;
use App\Ai\PromptGuard;
use App\Ai\Prompts\ContractPrompts;
use App\Ai\Schemas\ExtractionSchema;
use App\Core\Database;
use App\Support\Dates;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use PDO;
use Throwable;

/**
 * Reading a contract document into structured data.
 *
 * The pipeline is staged, and the stages are the point. Each one is a separate
 * model call with its own schema, its output is written to the job before the
 * next one starts, and a job that dies halfway resumes from where it stopped
 * rather than paying for the work it already did. A single "analyse this
 * contract" call would be cheaper to write and would lose everything on any
 * failure, including the failure where one long reply is truncated at the token
 * limit and the last third of the extraction silently never existed.
 *
 *   1. Is there text at all. A scanned PDF with no OCR fails here with a code
 *      that says so. Sending an empty document to a model produces a confident
 *      extraction of nothing, which is the worst possible answer.
 *   2. Sanitise and chunk.
 *   3. Classify the document.
 *   4. The structured record: parties, dates, money, renewal, notice, law.
 *   5. Clauses, obligations, milestones.
 *   6. Validate every reply against its schema, with one repair and one retry.
 *      Nothing unvalidated is stored, ever — a half-parsed extraction that
 *      reaches the database is indistinguishable from a correct one.
 *   7. Write the results as suggestions: `ai_extractions` rows carrying a
 *      confidence and the excerpt they came from, clauses and obligations
 *      marked `is_ai_extracted` and `verification_state = 'ai_extracted'`.
 *   8. Never overwrite what a human has confirmed. A re-run replaces its own
 *      previous suggestions and leaves every accepted value, verified clause
 *      and verified obligation exactly as the person left it.
 *
 * Nothing here writes a value onto the contract record. That happens in
 * applyVerified(), after somebody has looked at it.
 */
final class AiExtractionService
{
    /** How much document goes into one clause-level call. Large enough that a clause is rarely split, small enough that a reply fits in the output budget. */
    private const CHUNK_CHARS = 40000;

    /** Chunks past this are not sent. A 300-page master agreement is a document-management problem, not something to spend twenty model calls on silently. */
    private const MAX_CHUNKS = 6;

    /** Field-level extractions a reviewer may push onto the contract record, and the column each one writes. */
    private const APPLY_COLUMNS = [
        'contract_title'     => 'title',
        'counterparty_name'  => 'counterparty_name',
        'effective_date'     => 'effective_date',
        'commencement_date'  => 'commencement_date',
        'execution_date'     => 'execution_date',
        'expiry_date'        => 'expiry_date',
        'renewal_type'       => 'renewal_type',
        'renewal_frequency'  => 'renewal_frequency',
        'auto_renewal'       => 'auto_renewal',
        'notice_period_days' => 'notice_period_days',
        'governing_law'      => 'governing_law',
        'jurisdiction'       => 'jurisdiction',
        'currency'           => 'currency',
        'total_value'        => 'total_value',
        'recurring_value'    => 'recurring_value',
        'payment_frequency'  => 'payment_frequency',
    ];

    /** Of those, the ones Finance owns. Mirrors the same list in ContractService::update. */
    private const COMMERCIAL_COLUMNS = ['currency', 'total_value', 'recurring_value', 'payment_frequency'];

    private AiJobService $jobs;

    private AuditService $audit;

    private ActivityService $activity;

    public function __construct(private PDO $pdo, ?ContractsAiProvider $provider = null)
    {
        $this->jobs     = new AiJobService($pdo, $provider);
        $this->audit    = new AuditService($pdo);
        $this->activity = new ActivityService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // The pipeline
    // -----------------------------------------------------------------------

    /**
     * Run one extraction job.
     *
     * Returns the job's result rather than throwing on an expected failure: a
     * scanned document with no OCR is not an exception, it is an answer the
     * user needs to see. Unexpected failures do throw, and the worker's own
     * catch turns those into a retry.
     *
     * @return array<string,mixed>
     */
    public function run(TenantContext $ctx, int $jobId): array
    {
        $job        = $this->jobs->findOrFail($ctx, $jobId);
        $contractId = $job['contract_id'] ?? null;

        if ($contractId === null) {
            return $this->failJob($jobId, 'CONTRACT_REQUIRED', 'This extraction job has no contract to work on.');
        }

        $contract = $this->contractOrNull($ctx, (int) $contractId);
        if ($contract === null) {
            return $this->failJob($jobId, 'CONTRACT_MISSING', 'The contract this job was queued for no longer exists.');
        }

        $version = $this->resolveVersion($ctx, (int) $contractId, $job['version_id'] ?? null);

        // Stage 1. Everything downstream assumes there is something to read.
        $text = $version === null ? '' : trim((string) ($version['extracted_text'] ?? ''));
        if ($text === '') {
            return $this->failJob(
                $jobId,
                $version !== null && ContractService::toBool($version['is_scanned'] ?? false)
                    ? 'DOCUMENT_NOT_TEXT_SEARCHABLE'
                    : 'DOCUMENT_TEXT_UNAVAILABLE',
                $version === null
                    ? 'This contract has no uploaded document to analyse.'
                    : (ContractService::toBool($version['is_scanned'] ?? false)
                        ? 'This document is a scan with no text layer. Run OCR on it before analysing it.'
                        : 'The text of this document has not been extracted yet. Analysis will be possible once it has.')
            );
        }

        $provider = $this->jobs->providerOrFail();
        $payload  = is_array($job['payload'] ?? null) ? $job['payload'] : [];

        // Stage 2. Sanitising here rather than only inside PromptGuard means
        // the chunk boundaries are computed on the text the model will actually
        // see, so a chunk cannot come out over budget after neutralisation.
        $clean  = PromptGuard::sanitise($text, self::CHUNK_CHARS * self::MAX_CHUNKS);
        $chunks = self::chunk($clean);

        $state    = is_array($job['result'] ?? null) ? $job['result'] : [];
        $versionId = $version === null ? null : (int) $version['id'];
        $stages   = self::stagesFor((string) $job['kind'], $payload);

        foreach ($stages as $stage) {
            // Resumed: this stage's reply is already on the job from an earlier
            // attempt, and re-running it would pay for the same answer twice.
            if (isset($state[$stage])) {
                continue;
            }

            try {
                $state[$stage] = $this->runStage($ctx, $provider, $stage, $chunks, $contract, (int) $contractId, $jobId);
            } catch (DomainException $e) {
                $this->jobs->recordProgress($jobId, $state);

                return $this->failJob($jobId, $e->errorCode, $e->getMessage());
            }

            $this->jobs->recordProgress($jobId, $state);
        }

        $written = Database::transaction($this->pdo, fn (PDO $pdo): array => $this->persist(
            $ctx,
            (int) $contractId,
            $versionId,
            $jobId,
            $state
        ));

        $result = ['stages' => array_keys($state), 'written' => $written] + $state;
        $this->jobs->complete($jobId, $result);

        $this->activity->record($ctx, (int) $contractId, 'ai.extraction.completed', sprintf(
            'AI read the document: %d fields, %d clauses, %d suggested obligations',
            $written['extractions'],
            $written['clauses'],
            $written['obligations']
        ), ['job_id' => $jobId]);

        return $result;
    }

    /**
     * Which stages a job kind runs.
     *
     * The kinds are the units a caller asks for — "just classify this", "redo
     * the clauses" — and mapping them here keeps the queue's vocabulary and the
     * pipeline's from drifting apart. `skip` in the payload drops stages from
     * whatever that produced, which is what makes a stage skippable without a
     * new job kind for every combination.
     *
     * @param  array<string,mixed> $payload
     * @return list<string>
     */
    public static function stagesFor(string $kind, array $payload = []): array
    {
        $stages = match ($kind) {
            'classify'    => ['classify'],
            'clauses'     => ['clauses'],
            'obligations' => ['obligations', 'milestones'],
            default       => ['classify', 'fields', 'clauses', 'obligations', 'milestones'],
        };

        $skip = is_array($payload['skip'] ?? null) ? array_map('strval', $payload['skip']) : [];

        return array_values(array_filter($stages, static fn (string $s): bool => ! in_array($s, $skip, true)));
    }

    /**
     * @param  list<string>        $chunks
     * @param  array<string,mixed> $contract
     * @return array<string,mixed>
     */
    private function runStage(
        TenantContext $ctx,
        ContractsAiProvider $provider,
        string $stage,
        array $chunks,
        array $contract,
        int $contractId,
        int $jobId
    ): array {
        $meta = ['contract_id' => $contractId, 'job_id' => $jobId];

        return match ($stage) {
            // The document type is settled in the opening pages, and paying to
            // send an eighty-page agreement to answer it is money spent for no
            // extra accuracy.
            'classify' => (array) $this->jobs->callValidated(
                $ctx,
                $provider,
                ContractPrompts::classifyContract($chunks[0] ?? '', $this->contractTypeNames($ctx)),
                ExtractionSchema::classification(),
                'classify',
                $meta + ['schema_name' => 'contract_classification']
            )['value'],

            // One call over the whole document: a renewal term in an annexure
            // and an effective date on page one belong in the same answer, and
            // merging per-chunk field extractions would mean choosing between
            // two dates without knowing which chunk was authoritative.
            'fields' => (array) $this->jobs->callValidated(
                $ctx,
                $provider,
                ContractPrompts::extractContractData(implode("\n\n", $chunks), 'contract document'),
                ExtractionSchema::contractData(),
                'extract',
                $meta + ['schema_name' => 'contract_data']
            )['value'],

            'clauses' => ['clauses' => $this->perChunk(
                $ctx,
                $provider,
                $chunks,
                fn (string $chunk): array => ContractPrompts::extractClauses($chunk, $this->clauseCategoryNames($ctx)),
                ExtractionSchema::clauses(),
                'clauses',
                'clauses',
                $meta
            )],

            'obligations' => ['obligations' => $this->perChunk(
                $ctx,
                $provider,
                $chunks,
                static fn (string $chunk): array => ContractPrompts::extractObligations($chunk),
                ExtractionSchema::obligations(),
                'obligations',
                'obligations',
                $meta
            )],

            'milestones' => ['milestones' => $this->perChunk(
                $ctx,
                $provider,
                $chunks,
                static fn (string $chunk): array => ContractPrompts::extractMilestones($chunk),
                ExtractionSchema::milestones(),
                'obligations',
                'milestones',
                $meta
            )],

            default => [],
        };
    }

    /**
     * Run one prompt over every chunk and concatenate the lists.
     *
     * @param  list<string>                      $chunks
     * @param  callable(string): list<array{role: string, content: string}> $prompt
     * @param  array<string,mixed>               $schema
     * @param  array<string,mixed>               $meta
     * @return list<array<string,mixed>>
     */
    private function perChunk(
        TenantContext $ctx,
        ContractsAiProvider $provider,
        array $chunks,
        callable $prompt,
        array $schema,
        string $operation,
        string $key,
        array $meta
    ): array {
        $out = [];

        foreach ($chunks as $index => $chunk) {
            $value = $this->jobs->callValidated(
                $ctx,
                $provider,
                $prompt($chunk),
                $schema,
                $operation,
                $meta + ['schema_name' => $key]
            )['value'];

            foreach ((is_array($value) && is_array($value[$key] ?? null)) ? $value[$key] : [] as $item) {
                if (is_array($item)) {
                    // The chunk index travels with the item so a later reader
                    // can tell roughly where in the document it came from even
                    // when the model gave no page number.
                    $item['chunk_index'] = $index;
                    $out[]               = $item;
                }
            }
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Stage 7 — writing suggestions
    // -----------------------------------------------------------------------

    /**
     * @param  array<string,mixed> $state
     * @return array{extractions: int, clauses: int, obligations: int, milestones: int, preserved: int}
     */
    private function persist(TenantContext $ctx, int $contractId, ?int $versionId, int $jobId, array $state): array
    {
        $written = ['extractions' => 0, 'clauses' => 0, 'obligations' => 0, 'milestones' => 0, 'preserved' => 0];

        $fields = [];
        if (isset($state['fields']['fields']) && is_array($state['fields']['fields'])) {
            $fields = $state['fields']['fields'];
        }
        if (isset($state['classify']['document_type']) && is_array($state['classify']['document_type'])) {
            $fields['document_type'] = $state['classify']['document_type'];
        }

        $held = $this->humanHeldFieldKeys($ctx, $contractId);

        foreach ($fields as $key => $field) {
            if (! is_string($key) || ! is_array($field)) {
                continue;
            }

            // Stage 8. A reviewer has already decided this field; a fresh model
            // reading of the same document does not get to undo that, and
            // inserting a competing pending row would put the same field in the
            // review queue twice with two different answers.
            if (in_array($key, $held, true)) {
                $written['preserved']++;
                continue;
            }

            $this->replaceExtraction($ctx, $contractId, $versionId, $jobId, $key, $field);
            $written['extractions']++;
        }

        if (isset($state['clauses']['clauses'])) {
            $written['clauses'] = $this->writeClauses($ctx, $contractId, $versionId, (array) $state['clauses']['clauses']);
        }
        if (isset($state['obligations']['obligations'])) {
            $written['obligations'] = $this->writeObligations($ctx, $contractId, (array) $state['obligations']['obligations']);
        }
        if (isset($state['milestones']['milestones'])) {
            $written['milestones'] = $this->writeMilestones($ctx, $contractId, (array) $state['milestones']['milestones']);
        }

        return $written;
    }

    /**
     * Field keys on this contract that a person has already ruled on.
     *
     * @return list<string>
     */
    private function humanHeldFieldKeys(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT DISTINCT field_key FROM ai_extractions
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
               AND review_state IN (\'accepted\', \'edited\')'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        return array_map(static fn (array $r): string => (string) $r['field_key'], $st->fetchAll() ?: []);
    }

    /**
     * Replace this field's outstanding suggestion with the new one.
     *
     * Only `pending` and `rejected` rows are cleared. Rejected goes too: a
     * reviewer who threw away a wrong value is asking for a better one, not for
     * the refusal to be permanent.
     *
     * @param array<string,mixed> $field
     */
    private function replaceExtraction(
        TenantContext $ctx,
        int $contractId,
        ?int $versionId,
        int $jobId,
        string $key,
        array $field
    ): void {
        $this->pdo->prepare(
            'DELETE FROM ai_extractions
             WHERE environment = ? AND cmp_id = ? AND contract_id = ? AND field_key = ?
               AND review_state IN (\'pending\', \'rejected\')'
        )->execute([$ctx->environment, $ctx->cmpId, $contractId, $key]);

        $type  = ExtractionSchema::CONTRACT_FIELDS[$key] ?? 'text';
        $value = $field['value'] ?? null;

        $this->pdo->prepare(
            'INSERT INTO ai_extractions
             (job_id, environment, cmp_id, contract_id, version_id, field_key, field_label,
              extracted_value, normalised_value, value_type, confidence, source_page, source_excerpt,
              review_state)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')'
        )->execute([
            $jobId,
            $ctx->environment,
            $ctx->cmpId,
            $contractId,
            $versionId,
            mb_substr($key, 0, 64),
            mb_substr(Enums::label($key), 0, 160),
            self::asText($value),
            self::normalise($value, $type),
            $type,
            self::confidence($field['confidence'] ?? null),
            self::pageNumber($field['source_page'] ?? null),
            self::excerpt($field['source_excerpt'] ?? null),
        ]);
    }

    /**
     * @param  list<array<string,mixed>>|array<mixed> $clauses
     */
    private function writeClauses(TenantContext $ctx, int $contractId, ?int $versionId, array $clauses): int
    {
        // Only this pipeline's own untouched output is cleared. A clause a
        // reviewer verified or edited is theirs; a re-run adds to the record
        // rather than resetting it.
        $this->pdo->prepare(
            'DELETE FROM contract_clauses
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
               AND is_ai_extracted = TRUE AND verification_state = \'ai_extracted\''
        )->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        $categories = $this->clauseCategoryIds($ctx);
        $insert     = $this->pdo->prepare(
            'INSERT INTO contract_clauses
             (environment, cmp_id, contract_id, version_id, category_id, clause_number, heading,
              body_text, source_page, source_excerpt, is_ai_extracted, ai_confidence, verification_state)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, \'ai_extracted\')'
        );

        $count = 0;
        foreach ($clauses as $clause) {
            if (! is_array($clause)) {
                continue;
            }
            $body = trim((string) ($clause['body_text'] ?? ''));
            if ($body === '') {
                continue;
            }

            $category = strtolower(trim((string) ($clause['category'] ?? '')));

            $insert->execute([
                $ctx->environment,
                $ctx->cmpId,
                $contractId,
                $versionId,
                $categories[$category] ?? null,
                self::clip($clause['clause_number'] ?? null, 48),
                self::clip($clause['heading'] ?? null, 255),
                mb_substr($body, 0, 20000),
                self::pageNumber($clause['source_page'] ?? null),
                self::excerpt($clause['source_excerpt'] ?? null),
                self::confidence($clause['confidence'] ?? null),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param list<array<string,mixed>>|array<mixed> $obligations
     */
    private function writeObligations(TenantContext $ctx, int $contractId, array $obligations): int
    {
        $this->pdo->prepare(
            'DELETE FROM contract_obligations
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
               AND is_ai_extracted = TRUE AND verification_state = \'ai_extracted\''
        )->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO contract_obligations
             (environment, cmp_id, contract_id, obligation_type, title, description,
              responsible_party, frequency, first_due_date, start_date, amount, currency,
              evidence_required, is_ai_extracted, ai_confidence, verification_state, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?, \'ai_extracted\', FALSE, ?)'
        );

        $count = 0;
        foreach ($obligations as $obligation) {
            if (! is_array($obligation)) {
                continue;
            }
            $title = trim((string) ($obligation['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $frequency = Enums::coerce($obligation['frequency'] ?? null, Enums::OBLIGATION_FREQUENCIES, 'one_time');
            // A custom frequency needs an interval the prompt never asks for,
            // and the CHECK constraint would refuse the row. One-time is the
            // honest fallback: a reviewer sets the real cycle when they verify
            // it, and an obligation that fires once is visible, where one that
            // was silently dropped is not.
            if ($frequency === 'custom') {
                $frequency = 'one_time';
            }

            $due = self::dateOrNull($obligation['first_due_date'] ?? null);

            $insert->execute([
                $ctx->environment,
                $ctx->cmpId,
                $contractId,
                self::clip($obligation['obligation_type'] ?? null, 48) ?? 'general',
                mb_substr($title, 0, 255),
                self::clip($obligation['description'] ?? null, 20000),
                Enums::coerce($obligation['responsible_party'] ?? null, Enums::OBLIGATION_RESPONSIBLE, 'company'),
                $frequency,
                $due,
                $due,
                self::money($obligation['amount'] ?? null),
                self::currencyCode($obligation['currency'] ?? null),
                ($obligation['evidence_required'] ?? false) === true ? 'true' : 'false',
                self::confidence($obligation['confidence'] ?? null),
                $ctx->uuid,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param list<array<string,mixed>>|array<mixed> $milestones
     */
    private function writeMilestones(TenantContext $ctx, int $contractId, array $milestones): int
    {
        // Milestones carry no verification state of their own, so `pending` is
        // what stands in for "nobody has touched it": one somebody has moved to
        // in_progress or completed is a record of work and is left alone.
        $this->pdo->prepare(
            'DELETE FROM contract_milestones
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
               AND is_ai_extracted = TRUE AND status = \'pending\''
        )->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        $insert = $this->pdo->prepare(
            'INSERT INTO contract_milestones
             (environment, cmp_id, contract_id, title, description, milestone_type, due_date,
              amount, currency, is_ai_extracted, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, TRUE, ?)'
        );

        $count = 0;
        foreach ($milestones as $milestone) {
            if (! is_array($milestone)) {
                continue;
            }
            $title = trim((string) ($milestone['title'] ?? ''));
            $due   = self::dateOrNull($milestone['due_date'] ?? null);

            // due_date is NOT NULL in the schema and rightly so: a milestone is
            // a date. The prompt says to omit an undated one; this is the belt
            // to that brace.
            if ($title === '' || $due === null) {
                continue;
            }

            $insert->execute([
                $ctx->environment,
                $ctx->cmpId,
                $contractId,
                mb_substr($title, 0, 255),
                self::clip($milestone['description'] ?? null, 20000),
                self::clip($milestone['milestone_type'] ?? null, 48) ?? 'general',
                $due,
                self::money($milestone['amount'] ?? null),
                self::currencyCode($milestone['currency'] ?? null),
                $ctx->uuid,
            ]);
            $count++;
        }

        return $count;
    }

    // -----------------------------------------------------------------------
    // Review
    // -----------------------------------------------------------------------

    /**
     * The queue of extracted values waiting for somebody to look at them.
     *
     * Ordered by confidence ascending: the least certain value is the one most
     * worth a person's attention, and a queue sorted by date puts it wherever
     * the document happened to be uploaded.
     *
     * @param  array<string,mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int}
     */
    public function reviewQueue(TenantContext $ctx, array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = ['e.environment = :env', 'e.cmp_id = :cmp'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        $state = Enums::coerce($filters['review_state'] ?? 'pending', ['pending', 'accepted', 'edited', 'rejected'], 'pending');
        if (($filters['review_state'] ?? null) !== 'all') {
            $clauses[]              = 'e.review_state = :state';
            $params['state']        = $state;
        }

        if (! empty($filters['contract_id'])) {
            $clauses[]              = 'e.contract_id = :contract';
            $params['contract']     = (int) $filters['contract_id'];
        }
        if (! empty($filters['field_key'])) {
            $clauses[]              = 'e.field_key = :field';
            $params['field']        = (string) $filters['field_key'];
        }
        if (isset($filters['max_confidence']) && is_numeric($filters['max_confidence'])) {
            $clauses[]              = '(e.confidence IS NULL OR e.confidence <= :maxconf)';
            $params['maxconf']      = (string) $filters['max_confidence'];
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $countSt = $this->pdo->prepare("SELECT COUNT(*) FROM ai_extractions e {$where}");
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $st = $this->pdo->prepare(
            "SELECT e.*, c.contract_number, c.title AS contract_title
             FROM ai_extractions e
             JOIN contracts c ON c.id = e.contract_id
             {$where}
             ORDER BY e.confidence ASC NULLS FIRST, e.contract_id, e.id
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $st->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
        $st->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $st->execute();

        return [
            'items' => array_map(static fn (array $r): array => self::hydrateExtraction($r), $st->fetchAll() ?: []),
            'total' => $total,
        ];
    }

    /**
     * Confirm an extracted value, optionally correcting it first.
     *
     * A correction is stored as `edited` rather than `accepted` so the record
     * keeps the difference between "the model was right" and "the model was
     * close" — which is the only signal this product has for whether its
     * extraction is getting better or worse.
     *
     * @return array<string,mixed>
     */
    public function acceptExtraction(TenantContext $ctx, int $extractionId, ?string $editedValue = null): array
    {
        $row = $this->extractionOrFail($ctx, $extractionId);

        $original = (string) ($row['normalised_value'] ?? $row['extracted_value'] ?? '');
        $edited   = $editedValue === null ? null : trim($editedValue);
        $accepted = $edited ?? $original;
        $state    = ($edited !== null && $edited !== $original) ? 'edited' : 'accepted';

        $this->pdo->prepare(
            'UPDATE ai_extractions
             SET review_state = ?, accepted_value = ?, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$state, $accepted, $ctx->uuid, $extractionId, $ctx->environment, $ctx->cmpId]);

        $this->audit->log(
            $ctx,
            'ai_extraction',
            $extractionId,
            'ai.extraction.' . $state,
            (int) $row['contract_id'],
            [(string) $row['field_key'] => ['from' => $original, 'to' => $accepted]]
        );

        return $this->extractionOrFail($ctx, $extractionId);
    }

    /** @return array<string,mixed> */
    public function rejectExtraction(TenantContext $ctx, int $extractionId): array
    {
        $row = $this->extractionOrFail($ctx, $extractionId);

        $this->pdo->prepare(
            'UPDATE ai_extractions
             SET review_state = \'rejected\', accepted_value = NULL, reviewed_by = ?, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$ctx->uuid, $extractionId, $ctx->environment, $ctx->cmpId]);

        $this->audit->log(
            $ctx,
            'ai_extraction',
            $extractionId,
            'ai.extraction.rejected',
            (int) $row['contract_id'],
            [(string) $row['field_key'] => ['from' => $row['extracted_value'], 'to' => null]]
        );

        return $this->extractionOrFail($ctx, $extractionId);
    }

    /**
     * Write the values a reviewer accepted onto the contract itself.
     *
     * This is the only path by which an AI reading reaches the contract record,
     * and it only carries values a person confirmed. The contract's
     * verification_state becomes human_verified for the same reason: after this
     * runs, the record is what somebody said it was, not what a model read.
     *
     * @return array{applied: array<string,mixed>, skipped: array<string,string>, contract: array<string,mixed>}
     */
    public function applyVerified(TenantContext $ctx, int $contractId): array
    {
        $contract = $this->contractOrNull($ctx, $contractId);
        if ($contract === null) {
            throw DomainException::notFound('Contract not found.');
        }

        $st = $this->pdo->prepare(
            'SELECT DISTINCT ON (field_key) field_key, accepted_value, normalised_value, value_type, review_state
             FROM ai_extractions
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
               AND review_state IN (\'accepted\', \'edited\')
             ORDER BY field_key, reviewed_at DESC, id DESC'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        $applied  = [];
        $skipped  = [];
        $canMoney = $ctx->has(Permissions::COMMERCIALS_EDIT);

        foreach ($st->fetchAll() ?: [] as $row) {
            $key    = (string) $row['field_key'];
            $column = self::APPLY_COLUMNS[$key] ?? null;

            if ($column === null) {
                $skipped[$key] = 'This field is not one that is written onto the contract record.';
                continue;
            }
            if (in_array($column, self::COMMERCIAL_COLUMNS, true) && ! $canMoney) {
                // The same split ContractService::update enforces: seeing a
                // value and being allowed to change it are different rights.
                $skipped[$key] = 'Commercial terms need the commercials permission.';
                continue;
            }

            $value = $row['accepted_value'] ?? $row['normalised_value'];
            if ($value === null || $value === '') {
                $skipped[$key] = 'The accepted value is empty.';
                continue;
            }

            $applied[$column] = self::forColumn($column, (string) $value);
        }

        // A pair of dates that fails ck_contracts_dates would abort the whole
        // apply at the database. Catching it here means the reviewer gets the
        // fifteen fields that were fine plus a reason for the one that was not.
        $effective = $applied['effective_date'] ?? $contract['effective_date'] ?? null;
        $expiry    = $applied['expiry_date'] ?? $contract['expiry_date'] ?? null;
        if (is_string($effective) && is_string($expiry) && $expiry < $effective) {
            unset($applied['expiry_date'], $applied['effective_date']);
            $skipped['expiry_date'] = 'The extracted expiry date is before the effective date; both were left alone.';
        }

        if ($applied === []) {
            return ['applied' => [], 'skipped' => $skipped, 'contract' => $contract];
        }

        $updated = Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $contract, $applied): array {
            $sets   = [];
            $params = [];
            foreach ($applied as $column => $value) {
                $sets[]                 = "{$column} = :{$column}";
                $params[$column]        = $value;
            }

            // notice_deadline is derived, never extracted: it is expiry minus
            // the notice period, and letting a model supply it directly would
            // put two sources of truth on one deadline.
            $expiry = $applied['expiry_date'] ?? $contract['expiry_date'] ?? null;
            $notice = $applied['notice_period_days'] ?? $contract['notice_period_days'] ?? null;
            $sets[]                    = 'notice_deadline = :notice_deadline';
            $params['notice_deadline'] = Dates::noticeDeadline(
                is_string($expiry) ? $expiry : null,
                $notice === null ? null : (int) $notice
            );

            $sets[]                       = 'verification_state = :verification';
            $params['verification']       = 'human_verified';
            $sets[]                       = 'updated_by = :actor';
            $params['actor']              = $ctx->uuid;
            $params['id']                 = $contractId;
            $params['env']                = $ctx->environment;
            $params['cmp']                = $ctx->cmpId;

            $pdo->prepare(
                'UPDATE contracts SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND environment = :env AND cmp_id = :cmp'
            )->execute($params);

            $after = $this->contractOrNull($ctx, $contractId) ?? $contract;

            $this->audit->logChanges(
                $ctx,
                'contract',
                $contractId,
                $contract,
                $after,
                array_keys($applied),
                $contractId,
                'ai.extraction.applied'
            );
            $this->activity->record($ctx, $contractId, 'ai.extraction.applied', sprintf(
                '%d verified value(s) from the document applied to the contract',
                count($applied)
            ), ['fields' => array_keys($applied)]);

            return $after;
        });

        return ['applied' => $applied, 'skipped' => $skipped, 'contract' => $updated];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function failJob(int $jobId, string $code, string $message): array
    {
        $this->jobs->fail($jobId, $code, $message);

        return ['status' => 'failed', 'error_code' => $code, 'error_message' => $message];
    }

    /** @return array<string,mixed>|null */
    private function contractOrNull(TenantContext $ctx, int $contractId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * The document version to read.
     *
     * Joined through contract_documents rather than trusting the version's own
     * tenant columns alone: the version the job names must belong to this
     * contract, or a job payload could point the extraction at a document from
     * a different contract in the same company.
     *
     * @return array<string,mixed>|null
     */
    private function resolveVersion(TenantContext $ctx, int $contractId, ?int $versionId): ?array
    {
        $sql = 'SELECT v.* FROM contract_document_versions v
                JOIN contract_documents d ON d.id = v.document_id
                WHERE d.contract_id = :contract AND v.environment = :env AND v.cmp_id = :cmp';

        $params = ['contract' => $contractId, 'env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        if ($versionId !== null) {
            $sql .= ' AND v.id = :version';
            $params['version'] = $versionId;
        }

        // Newest first when the job did not name one: the latest version is
        // what the contract currently says.
        $sql .= ' ORDER BY v.created_at DESC, v.id DESC LIMIT 1';

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function extractionOrFail(TenantContext $ctx, int $extractionId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ai_extractions WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$extractionId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Extracted value not found.');
        }

        return self::hydrateExtraction($row);
    }

    /** @return list<string> */
    private function contractTypeNames(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT name FROM contract_types WHERE environment = ? AND cmp_id = ? AND is_active = TRUE ORDER BY name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        return array_map(static fn (array $r): string => (string) $r['name'], $st->fetchAll() ?: []);
    }

    /** @return list<string> */
    private function clauseCategoryNames(TenantContext $ctx): array
    {
        return array_keys($this->clauseCategoryIds($ctx));
    }

    /**
     * Category name (lowercased) to id, so a model answering with a name can be
     * filed against the company's own category row.
     *
     * @return array<string,int>
     */
    private function clauseCategoryIds(TenantContext $ctx): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, name FROM clause_categories WHERE environment = ? AND cmp_id = ? ORDER BY sort_order, name'
        );
        $st->execute([$ctx->environment, $ctx->cmpId]);

        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[strtolower(trim((string) $row['name']))] = (int) $row['id'];
        }

        return $out;
    }

    /**
     * Split text on paragraph boundaries into chunks no larger than the budget.
     *
     * Paragraph boundaries rather than a character count so a clause is not cut
     * in half between two calls, which would give the model two fragments
     * neither of which says what the clause requires.
     *
     * @return list<string>
     */
    private static function chunk(string $text, int $size = self::CHUNK_CHARS, int $max = self::MAX_CHUNKS): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $size) {
            return [$text];
        }

        $chunks    = [];
        $current   = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [$text] as $paragraph) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $size) {
                $chunks[] = $current;
                $current  = '';
                if (count($chunks) >= $max) {
                    return $chunks;
                }
            }

            // One paragraph over the whole budget is a table or a wall of OCR
            // output; it is cut on characters because there is no better
            // boundary inside it.
            if (mb_strlen($paragraph) > $size) {
                foreach (self::splitHard($paragraph, $size) as $piece) {
                    $chunks[] = $piece;
                    if (count($chunks) >= $max) {
                        return $chunks;
                    }
                }
                continue;
            }

            $current .= ($current === '' ? '' : "\n\n") . $paragraph;
        }

        if (trim($current) !== '' && count($chunks) < $max) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** @return list<string> */
    private static function splitHard(string $text, int $size): array
    {
        $out = [];
        $len = mb_strlen($text);
        for ($i = 0; $i < $len; $i += $size) {
            $out[] = mb_substr($text, $i, $size);
        }

        return $out;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrateExtraction(array $row): array
    {
        foreach (['id', 'job_id', 'cmp_id', 'contract_id', 'version_id', 'source_page'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if (isset($row['confidence'])) {
            $row['confidence'] = (float) $row['confidence'];
        }

        return $row;
    }

    private static function asText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, 20000);
        }

        return mb_substr((string) json_encode($value, JSON_UNESCAPED_SLASHES), 0, 20000);
    }

    /**
     * The value in the form the rest of the product stores it.
     *
     * Money becomes a fixed-scale string here rather than staying a float: a
     * contract value that has been through a float is a contract value with a
     * rounding error in it.
     */
    private static function normalise(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'date'     => self::dateOrNull($value),
            'currency' => self::money($value),
            'number'   => is_numeric($value) ? (string) (int) $value : null,
            'boolean'  => is_bool($value) ? ($value ? 'true' : 'false') : null,
            default    => self::asText($value),
        };
    }

    /** The value in the form its contract column expects. */
    private static function forColumn(string $column, string $value): string
    {
        return match ($column) {
            'auto_renewal'       => in_array(strtolower($value), ['1', 't', 'true', 'yes', 'on'], true) ? 'true' : 'false',
            'currency'           => strtoupper(mb_substr($value, 0, 3)),
            'notice_period_days' => (string) max(0, min(3650, (int) $value)),
            default              => $value,
        };
    }

    private static function money(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private static function dateOrNull(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && Dates::parse($value) !== null
            ? $value
            : null;
    }

    private static function currencyCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $upper = strtoupper(trim($value));

        return preg_match('/^[A-Z]{3}$/', $upper) === 1 ? $upper : null;
    }

    private static function confidence(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        // NUMERIC(4,3) with a 0-1 CHECK: a model that answers 1.4 gets clamped
        // rather than aborting the whole write.
        return number_format(max(0.0, min(1.0, (float) $value)), 3, '.', '');
    }

    private static function pageNumber(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }
        $page = (int) $value;

        return $page > 0 && $page <= 32000 ? $page : null;
    }

    private static function excerpt(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, ContractPrompts::MAX_EXCERPT_CHARS);
    }

    private static function clip(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
