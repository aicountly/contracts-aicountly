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
use App\Support\TenantContext;
use PDO;

/**
 * The management view of a contract: what it says, section by section, and what
 * the renewal decision looks like.
 *
 * Summaries are versioned rather than overwritten. Regenerating one after a new
 * version of the document is uploaded is a normal thing to do, and a reviewer
 * who has rewritten the Liability paragraph in their own words must not lose it
 * because somebody pressed the button again. The model's own output lives in
 * `sections` and a human's wording in `edited_sections`, so neither can destroy
 * the other, and only one row per contract is `is_current`.
 *
 * The section list itself is in ExtractionSchema, where the prompt and the
 * schema both read it.
 */
final class AiAnalysisService
{
    /** How much of the document a summary call sends. Beyond this the summary is of the front of the contract, and it says so. */
    private const SUMMARY_CHARS = 90000;

    /** Contract columns worth putting in front of the model as established fact. */
    private const CONTEXT_COLUMNS = [
        'contract_number', 'title', 'counterparty_name', 'status', 'effective_date',
        'commencement_date', 'expiry_date', 'notice_deadline', 'notice_period_days',
        'renewal_type', 'renewal_frequency', 'auto_renewal', 'currency', 'total_value',
        'recurring_value', 'payment_frequency', 'governing_law', 'jurisdiction',
    ];

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

    /**
     * Generate the section-by-section summary and make it the current one.
     *
     * @return array<string,mixed> the stored summary row
     */
    public function summarize(TenantContext $ctx, int $contractId, ?int $jobId = null): array
    {
        $contract = $this->contractOrFail($ctx, $contractId);
        $version  = $this->latestVersion($ctx, $contractId);
        $text     = $version === null ? '' : trim((string) ($version['extracted_text'] ?? ''));

        if ($text === '') {
            throw DomainException::conflict(
                'This contract has no readable document text to summarise.',
                'DOCUMENT_TEXT_UNAVAILABLE'
            );
        }

        $reply = $this->jobs->callValidated(
            $ctx,
            $this->jobs->providerOrFail(),
            ContractPrompts::summarizeContract(
                PromptGuard::sanitise($text, self::SUMMARY_CHARS),
                $this->context($contract)
            ),
            ExtractionSchema::summary(),
            'summarize',
            ['contract_id' => $contractId, 'job_id' => $jobId, 'schema_name' => 'contract_summary']
        );

        $sections = is_array($reply['value']['sections'] ?? null) ? $reply['value']['sections'] : [];
        $actions  = is_array($sections['management_action_items'] ?? null) ? $sections['management_action_items'] : [];

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $version, $jobId, $sections, $actions, $reply): array {
            $previous = $this->current($ctx, $contractId);

            // Only one row per contract may be current — a partial unique index
            // enforces it — so the old one steps down before the new one goes
            // in. It is not deleted: the earlier reading is what a reviewer
            // compares against when the wording of a renewal changes.
            $pdo->prepare(
                'UPDATE ai_contract_summaries SET is_current = FALSE
                 WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND is_current'
            )->execute([$contractId, $ctx->environment, $ctx->cmpId]);

            $st = $pdo->prepare(
                'INSERT INTO ai_contract_summaries
                 (environment, cmp_id, contract_id, version_id, job_id, sections, edited_sections,
                  executive_summary, management_actions, provider, model, is_current, edited_by, edited_at)
                 VALUES (:env, :cmp, :contract, :version, :job, :sections::jsonb, :edited::jsonb,
                         :exec, :actions::jsonb, :provider, :model, TRUE, :edited_by, :edited_at)
                 RETURNING id'
            );
            $st->execute([
                'env'      => $ctx->environment,
                'cmp'      => $ctx->cmpId,
                'contract' => $contractId,
                'version'  => $version === null ? null : (int) $version['id'],
                'job'      => $jobId,
                'sections' => json_encode($sections, JSON_UNESCAPED_SLASHES),
                // Carried forward with the id of the generation it was written
                // against. An edit that predates this reading is still the
                // reviewer's words and is worth keeping in front of them; the
                // provenance is what lets the screen say it is older than the
                // section beside it.
                'edited'     => json_encode($previous['edited_sections'] ?? null, JSON_UNESCAPED_SLASHES),
                'exec'       => self::sectionText($sections, 'executive_summary'),
                'actions'    => json_encode(array_values($actions), JSON_UNESCAPED_SLASHES),
                'provider'   => mb_substr((string) $reply['provider'], 0, 32),
                'model'      => mb_substr((string) $reply['model'], 0, 96),
                'edited_by'  => $previous['edited_by'] ?? null,
                'edited_at'  => $previous['edited_at'] ?? null,
            ]);

            $this->activity->record($ctx, $contractId, 'ai.summary.generated', 'AI summary generated', [
                'summary_id' => (int) $st->fetchColumn(),
                'job_id'     => $jobId,
            ]);

            return $this->current($ctx, $contractId) ?? [];
        });
    }

    /**
     * Replace the wording of one or more sections with a reviewer's own.
     *
     * The edit is stored beside the model's text rather than over it. What a
     * person publishes and what a model produced are different claims, and a
     * summary that cannot tell them apart cannot be audited.
     *
     * @param  array<string,string> $sections section key to replacement prose
     * @return array<string,mixed>
     */
    public function editSummary(TenantContext $ctx, int $contractId, array $sections): array
    {
        $current = $this->current($ctx, $contractId);
        if ($current === null) {
            throw DomainException::notFound('This contract has no AI summary to edit.');
        }

        $edited = is_array($current['edited_sections'] ?? null) ? $current['edited_sections'] : [];
        $known  = ExtractionSchema::SUMMARY_SECTIONS;
        $stored = [];

        foreach ($sections as $key => $content) {
            if (! is_string($key) || ! isset($known[$key]) || ! is_string($content)) {
                continue;
            }

            $edited[$key] = [
                'content'     => mb_substr(trim($content), 0, 8000),
                'edited_by'   => $ctx->uuid,
                'edited_at'   => Dates::today(),
                'edited_from' => (int) $current['id'],
            ];
            $stored[] = $key;
        }

        if ($stored === []) {
            throw DomainException::badRequest('No known summary section was given to edit.');
        }

        $this->pdo->prepare(
            'UPDATE ai_contract_summaries
             SET edited_sections = :edited::jsonb, edited_by = :actor, edited_at = CURRENT_TIMESTAMP
             WHERE id = :id AND environment = :env AND cmp_id = :cmp'
        )->execute([
            'edited' => json_encode($edited, JSON_UNESCAPED_SLASHES),
            'actor'  => $ctx->uuid,
            'id'     => (int) $current['id'],
            'env'    => $ctx->environment,
            'cmp'    => $ctx->cmpId,
        ]);

        $this->audit->log($ctx, 'ai_summary', (int) $current['id'], 'ai.summary.edited', $contractId, [
            'sections' => ['from' => null, 'to' => implode(', ', $stored)],
        ]);
        $this->activity->record($ctx, $contractId, 'ai.summary.edited', 'AI summary edited by a reviewer', [
            'sections' => $stored,
        ]);

        return $this->current($ctx, $contractId) ?? [];
    }

    /**
     * What the renewal decision on this contract looks like.
     *
     * Not stored in a table of its own: advice is a reading of the record at a
     * moment, and the record moves. It goes back to the caller, and when it was
     * produced by a queued job it is kept in that job's result, which is dated
     * and can be shown as "as at" rather than as a standing fact.
     *
     * @return array<string,mixed>
     */
    public function renewalAdvice(TenantContext $ctx, int $contractId, ?int $jobId = null): array
    {
        $contract = $this->contractOrFail($ctx, $contractId);
        $version  = $this->latestVersion($ctx, $contractId);

        $facts                   = $this->context($contract);
        $facts['days_to_expiry'] = Dates::daysUntil($contract['expiry_date'] ?? null);
        $facts['days_to_notice'] = Dates::daysUntil($contract['notice_deadline'] ?? null);

        $reply = $this->jobs->callValidated(
            $ctx,
            $this->jobs->providerOrFail(),
            ContractPrompts::renewalRecommendation(
                $facts,
                $version === null ? '' : PromptGuard::sanitise((string) ($version['extracted_text'] ?? ''), self::SUMMARY_CHARS)
            ),
            ExtractionSchema::renewalRecommendation(),
            'renewal_advice',
            ['contract_id' => $contractId, 'job_id' => $jobId, 'schema_name' => 'renewal_advice']
        );

        $advice = is_array($reply['value']) ? $reply['value'] : [];

        $this->activity->record($ctx, $contractId, 'ai.renewal.advice', 'AI renewal options prepared', [
            'recommendation' => $advice['recommendation'] ?? null,
            'job_id'         => $jobId,
        ]);

        return [
            'advice'     => $advice,
            'as_at'      => Dates::today(),
            'provider'   => $reply['provider'],
            'model'      => $reply['model'],
            'contract_id' => $contractId,
        ];
    }

    /**
     * The summary a screen should show, or null when none has been generated.
     *
     * @return array<string,mixed>|null
     */
    public function current(TenantContext $ctx, int $contractId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ai_contract_summaries
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND is_current
             LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Earlier generations, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function history(TenantContext $ctx, int $contractId, int $limit = 10): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, version_id, job_id, provider, model, is_current, edited_by, edited_at, created_at
             FROM ai_contract_summaries
             WHERE contract_id = :contract AND environment = :env AND cmp_id = :cmp
             ORDER BY created_at DESC, id DESC
             LIMIT :lim'
        );
        $st->bindValue(':contract', $contractId, PDO::PARAM_INT);
        $st->bindValue(':env', $ctx->environment);
        $st->bindValue(':cmp', $ctx->cmpId, PDO::PARAM_INT);
        $st->bindValue(':lim', max(1, min(50, $limit)), PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll() ?: [];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function contractOrFail(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare('SELECT * FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1');
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        if (! is_array($row)) {
            throw DomainException::notFound('Contract not found.');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function latestVersion(TenantContext $ctx, int $contractId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT v.* FROM contract_document_versions v
             JOIN contract_documents d ON d.id = v.document_id
             WHERE d.contract_id = ? AND v.environment = ? AND v.cmp_id = ?
             ORDER BY v.created_at DESC, v.id DESC
             LIMIT 1'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param  array<string,mixed> $contract
     * @return array<string,mixed>
     */
    private function context(array $contract): array
    {
        $out = [];
        foreach (self::CONTEXT_COLUMNS as $column) {
            if (isset($contract[$column]) && $contract[$column] !== '') {
                $out[$column] = $contract[$column];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $sections */
    private static function sectionText(array $sections, string $key): ?string
    {
        $content = $sections[$key]['content'] ?? null;

        return is_string($content) && trim($content) !== '' ? mb_substr(trim($content), 0, 8000) : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        foreach (['sections', 'edited_sections', 'management_actions'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $decoded   = json_decode($row[$key], true);
                $row[$key] = is_array($decoded) ? $decoded : null;
            }
        }

        foreach (['id', 'cmp_id', 'contract_id', 'version_id', 'job_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        if (array_key_exists('is_current', $row)) {
            $row['is_current'] = ContractService::toBool($row['is_current']);
        }

        return $row;
    }
}
