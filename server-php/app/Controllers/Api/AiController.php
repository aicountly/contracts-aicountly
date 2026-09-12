<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Ai\AiProviderFactory;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\AiAnalysisService;
use App\Services\AiExtractionService;
use App\Services\AiJobService;
use App\Services\AskContractService;
use App\Services\ContractService;
use App\Services\RenewalService;
use App\Support\Dates;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use PDO;
use PDOStatement;

/**
 * Everything the AI features are reached through.
 *
 * Three rules run across every action here and are worth stating once.
 *
 * Every action spends from one hourly budget, shared across all of them. These
 * calls cost real money against the company's provider quota, and a per-route
 * budget would simply mean a caller alternating routes never hits a limit.
 *
 * Every generating action refuses with 503 when no provider is configured,
 * naming Console as where that is fixed. The alternative — an empty summary, a
 * risk list with nothing in it — is indistinguishable from a clean contract,
 * which is the most dangerous answer this product could give.
 *
 * Every response carrying model output carries the disclaimer with it, in the
 * payload rather than as a header or a client-side constant, so an integration
 * that renders this API cannot present an AI reading as settled fact by
 * forgetting to add it.
 */
final class AiController extends BaseController
{
    /** How far ahead `insights()` looks for a notice deadline, unless asked otherwise. */
    private const DEFAULT_HORIZON_DAYS = 90;

    /** One import call queues at most this many documents. */
    private const MAX_IMPORT_FILES = 25;

    private const MAX_QUESTION_CHARS = 2000;

    // -----------------------------------------------------------------------
    // Status and jobs
    // -----------------------------------------------------------------------

    /**
     * What this deployment's AI is wired to.
     *
     * Readable with plain `contract.view` because every screen branches on it:
     * a user without `ai.use` still needs to know whether the buttons they
     * cannot press exist at all.
     */
    public function status(): void
    {
        $this->requirePermission(Permissions::CONTRACT_VIEW);
        $this->aiBudget();

        $status = AiProviderFactory::status();

        Response::success([
            'configured' => (bool) ($status['configured'] ?? false),
            'provider'   => $status['provider'] ?? null,
            'model'      => $status['model'] ?? null,
            'source'     => $status['source'] ?? null,
            'fallbacks'  => (int) ($status['fallbacks'] ?? 0),
            'message'    => $status['message'] ?? null,
            'limits'     => [
                'per_hour'     => Env::int('AI_RATE_LIMIT_PER_HOUR', 120),
                'ask_per_hour' => Env::int('AI_ASK_RATE_LIMIT_PER_HOUR', 60),
            ],
            'disclaimer' => SessionController::AI_DISCLAIMER,
        ]);
    }

    public function jobs(): void
    {
        $ctx  = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $page = Request::pagination(25, 100);

        $filters = [
            'status'      => Enums::coerce(Request::query('status'), Enums::AI_JOB_STATUSES),
            'kind'        => Enums::coerce(Request::query('kind'), Enums::AI_JOB_KINDS),
            'contract_id' => self::optionalId(Request::query('contract_id')),
        ];

        $result = $this->run(fn (): array => $this->jobService()->search(
            $ctx,
            array_filter($filters, static fn ($v): bool => $v !== null),
            $page['per_page'],
            $page['offset']
        ));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function job(?string $id = null): void
    {
        $ctx   = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $jobId = $this->intId($id);

        $job = $this->run(fn (): ?array => $this->jobService()->find($ctx, $jobId));
        if ($job === null) {
            Response::notFound('AI job not found.');
        }

        Response::success(self::withDisclaimer(['job' => $job]));
    }

    /**
     * Re-run a job that failed.
     *
     * Gated on a configured provider even though the work happens in the
     * worker: queueing a retry against a deployment with no credentials would
     * produce a job that fails again for the same reason, and the user would
     * have no way to tell that from a transient fault.
     */
    public function retryJob(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $this->requireProvider();

        $this->respond(fn (): array => self::withDisclaimer([
            'job' => $this->jobService()->retry($ctx, $this->intId($id)),
        ]));
    }

    // -----------------------------------------------------------------------
    // Extraction and its review queue
    // -----------------------------------------------------------------------

    public function reviewQueue(): void
    {
        $ctx  = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $page = Request::pagination(50, 200);

        $filters = [
            'review_state'   => Request::query('review_state'),
            'contract_id'    => self::optionalId(Request::query('contract_id')),
            'field_key'      => Request::query('field_key'),
            'max_confidence' => self::confidence(Request::query('max_confidence')),
        ];

        $result = $this->run(fn (): array => $this->extractionService()->reviewQueue(
            $ctx,
            array_filter($filters, static fn ($v): bool => $v !== null && $v !== ''),
            $page['per_page'],
            $page['offset']
        ));

        Response::paginated(
            $result['items'],
            $result['total'],
            $page['page'],
            $page['per_page'],
            ['disclaimer' => SessionController::AI_DISCLAIMER]
        );
    }

    public function acceptExtraction(?string $id = null): void
    {
        $ctx  = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $body = $this->body();

        // An empty string is a real correction ("this field is blank in the
        // document"), so absence rather than emptiness is what means "accept
        // the model's value unchanged".
        $value = array_key_exists('value', $body) && is_scalar($body['value'])
            ? mb_substr((string) $body['value'], 0, 4000)
            : null;

        $this->respond(fn (): array => self::withDisclaimer([
            'extraction' => $this->extractionService()->acceptExtraction($ctx, $this->intId($id), $value),
        ]));
    }

    public function rejectExtraction(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();

        $this->respond(fn (): array => self::withDisclaimer([
            'extraction' => $this->extractionService()->rejectExtraction($ctx, $this->intId($id)),
        ]));
    }

    /**
     * Write the values a reviewer confirmed onto the contract record.
     *
     * Needs `contract.edit` on top of `ai.use`: this is the one AI route that
     * changes the contract itself, and reviewing an extraction is a different
     * grant from editing the agreement.
     */
    public function applyVerified(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        if (! $ctx->has(Permissions::CONTRACT_EDIT)) {
            Response::forbidden('Applying verified values changes the contract record and needs the edit permission.');
        }
        $this->aiBudget();

        $this->respond(fn (): array => self::withDisclaimer(
            $this->extractionService()->applyVerified($ctx, $this->intId($id))
        ));
    }

    public function extract(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $this->requireProvider();

        $contractId = $this->intId($id);
        $versionId  = self::optionalId((string) ($this->body()['version_id'] ?? ''));

        $this->respond(fn (): array => self::withDisclaimer([
            'job' => $this->jobService()->enqueue($ctx, 'extract', [], $contractId, $versionId),
        ]), 202);
    }

    /**
     * Queue extraction for a batch of already-uploaded documents.
     *
     * Capped, and each file becomes its own job rather than one job over the
     * batch: a single bad document in a bulk import must not take the other
     * twenty-four down with it.
     */
    public function import(): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $this->requireProvider();

        $files = $this->body()['files'] ?? null;
        if (! is_array($files) || $files === []) {
            Response::validationError(['files' => 'List the documents to import.']);
        }
        if (count($files) > self::MAX_IMPORT_FILES) {
            Response::validationError([
                'files' => sprintf('Import at most %d documents at a time.', self::MAX_IMPORT_FILES),
            ]);
        }

        $requests = [];
        foreach (array_values($files) as $index => $file) {
            if (! is_array($file)) {
                Response::validationError(['files' => sprintf('Entry %d is not an object.', $index + 1)]);
            }

            $contractId = self::optionalId((string) ($file['contract_id'] ?? ''));
            $versionId  = self::optionalId((string) ($file['version_id'] ?? ''));

            if ($contractId === null && $versionId === null) {
                Response::validationError([
                    'files' => sprintf('Entry %d needs a contract_id or a version_id.', $index + 1),
                ]);
            }

            $requests[] = ['contract_id' => $contractId, 'version_id' => $versionId];
        }

        $this->respond(function () use ($ctx, $requests): array {
            $jobs = [];
            foreach ($requests as $request) {
                $jobs[] = $this->jobService()->enqueue(
                    $ctx,
                    'extract',
                    ['source' => 'import'],
                    $request['contract_id'],
                    $request['version_id']
                );
            }

            return self::withDisclaimer(['jobs' => $jobs, 'queued' => count($jobs)]);
        }, 202);
    }

    // -----------------------------------------------------------------------
    // Summary
    // -----------------------------------------------------------------------

    /**
     * Queue a fresh summary, and hand back the one already on file meanwhile.
     *
     * Both, rather than one or the other, because the screen has something to
     * show while the job runs and the reader can see it is the previous
     * reading rather than the one they just asked for.
     */
    public function summarize(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $this->requireProvider();

        $contractId = $this->intId($id);

        $this->respond(fn (): array => self::withDisclaimer([
            'job'     => $this->jobService()->enqueue($ctx, 'summarize', [], $contractId),
            'summary' => $this->analysisService()->summary($ctx, $contractId),
        ]), 202);
    }

    public function summary(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $contractId = $this->intId($id);

        $summary = $this->run(fn (): ?array => $this->analysisService()->summary($ctx, $contractId));
        if ($summary === null) {
            // Not an empty object: "no summary yet" and "a summary that found
            // nothing" would otherwise render the same, and only one of them
            // means "press the button".
            Response::notFound('No AI summary has been generated for this contract yet.');
        }

        Response::success(self::withDisclaimer(['summary' => $summary]));
    }

    /**
     * A reviewer's own wording for one or more summary sections.
     *
     * Not gated on a configured provider: correcting what the model said must
     * keep working after the AI credential is removed, or a company that turns
     * AI off is left with its last generated summary and no way to fix it.
     */
    public function editSummary(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $contractId = $this->intId($id);

        $sections = $this->body()['sections'] ?? null;
        if (! is_array($sections) || $sections === []) {
            Response::validationError(['sections' => 'Send the sections to replace, keyed by section name.']);
        }

        $clean = [];
        foreach ($sections as $key => $content) {
            if (is_string($key) && is_string($content)) {
                $clean[$key] = mb_substr($content, 0, 8000);
            }
        }

        if ($clean === []) {
            Response::validationError(['sections' => 'Each section must be given as text.']);
        }

        $this->respond(fn (): array => self::withDisclaimer([
            'summary' => $this->analysisService()->editSummary($ctx, $contractId, $clean),
        ]));
    }

    // -----------------------------------------------------------------------
    // Ask
    // -----------------------------------------------------------------------

    /**
     * One grounded question about one contract.
     *
     * Carries a second budget of its own on top of the shared one. Ask is the
     * endpoint a user can sit and hammer — every other AI route is a deliberate
     * action on a document — and it is the one where a runaway client turns
     * into a provider bill nobody approved.
     */
    public function ask(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $this->rateLimit('ai.ask', Env::int('AI_ASK_RATE_LIMIT_PER_HOUR', 60), 3600);
        $this->requireProvider();

        $contractId = $this->intId($id);
        $body       = $this->body();

        $question = is_string($body['question'] ?? null) ? trim($body['question']) : '';
        if ($question === '') {
            Response::validationError(['question' => 'Ask a question about this contract.']);
        }
        if (mb_strlen($question) > self::MAX_QUESTION_CHARS) {
            Response::validationError([
                'question' => sprintf('Keep the question under %d characters.', self::MAX_QUESTION_CHARS),
            ]);
        }

        $conversationId = self::optionalId((string) ($body['conversation_id'] ?? ''));

        $answer = $this->run(fn (): array => $this->askService()->ask($ctx, $contractId, $question, $conversationId));

        Response::success([
            'answer'          => $answer['answer'] ?? '',
            'citations'       => $answer['citations'] ?? [],
            'grounded'        => (bool) ($answer['grounded'] ?? false),
            'conversation_id' => (int) ($answer['conversation_id'] ?? 0),
            'message_id'      => isset($answer['message_id']) ? (int) $answer['message_id'] : null,
            'disclaimer'      => SessionController::AI_DISCLAIMER,
        ]);
    }

    public function conversations(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $contractId = $this->intId($id);
        $page       = Request::pagination(25, 100);

        $result = $this->run(fn (): array => $this->askService()->conversations(
            $ctx,
            $contractId,
            $page['per_page'],
            $page['offset']
        ));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function messages(?string $id = null): void
    {
        $ctx  = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $page = Request::pagination(100, 500);

        $messages = $this->run(fn (): array => $this->askService()->messages(
            $ctx,
            $this->intId($id),
            $page['per_page'],
            $page['offset']
        ));

        Response::success(self::withDisclaimer([
            'items'    => $messages,
            'page'     => $page['page'],
            'per_page' => $page['per_page'],
        ]));
    }

    // -----------------------------------------------------------------------
    // Renewal advice
    // -----------------------------------------------------------------------

    /**
     * What to do about a renewal.
     *
     * Two routes reach this action with two different ids —
     * `/ai/contracts/{id}/renewal-advice` names a contract and
     * `/renewals/{id}/recommend` names a cycle — so the matched route template
     * decides which. Reading the id and guessing from whether it resolves would
     * be an id oracle across two tables.
     *
     * Reached through the renewal cycle, the advice is also written onto that
     * cycle as a recommendation from `ai`, which is what puts it in front of
     * whoever decides and marks where it came from.
     */
    public function renewalAdvice(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_USE);
        $this->aiBudget();
        $this->requireProvider();

        $isRenewalCycle = str_contains(Router::matchedRoute(), '/renewals/');
        $recordId       = $this->intId($id);

        if (! $isRenewalCycle) {
            $this->respond(fn (): array => self::withDisclaimer(
                $this->analysisService()->renewalAdvice($ctx, $recordId)
            ));

            return;
        }

        if (! $ctx->has(Permissions::RENEWAL_MANAGE)) {
            Response::forbidden('Recording a recommendation on a renewal cycle needs the renewal permission.');
        }

        $this->respond(function () use ($ctx, $recordId): array {
            $renewals = new RenewalService($this->db());
            $renewal  = $renewals->findOrFail($ctx, $recordId);

            $advice = $this->analysisService()->renewalAdvice($ctx, (int) $renewal['contract_id']);
            $inner  = is_array($advice['advice'] ?? null) ? $advice['advice'] : [];

            // The model's vocabulary and the renewal table's are not the same
            // list. Mapping here rather than widening the CHECK constraint
            // keeps a provider swap from being able to introduce a new
            // recommendation value nobody has designed a screen for.
            $recommendation = match ((string) ($inner['recommendation'] ?? '')) {
                'renew'          => 'renew',
                'renegotiate'    => 'renegotiate',
                'terminate'      => 'terminate',
                default          => 'review_manually',
            };

            return self::withDisclaimer([
                'renewal' => $renewals->setRecommendation(
                    $ctx,
                    $recordId,
                    $recommendation,
                    (string) ($inner['rationale'] ?? 'No rationale was returned.'),
                    'ai'
                ),
                'advice'  => $advice,
            ]);
        });
    }

    // -----------------------------------------------------------------------
    // Insights
    // -----------------------------------------------------------------------

    /**
     * One ranked list of what needs doing across the portfolio.
     *
     * Four questions the product already knows the answer to, asked together
     * and ordered by how much a delay costs: a notice deadline that has passed
     * on an auto-renewing contract has already renewed the agreement, an
     * overdue payment obligation is a breach that is accruing, and a critical
     * risk finding is a term somebody agreed to and nobody has looked at.
     *
     * Readable with `contract.view` because it is a reading of stored records,
     * not a model call — but it surfaces AI-detected findings, so it carries
     * the disclaimer like anything else that does.
     */
    public function insights(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $this->aiBudget();

        $horizon = self::clampInt(Request::query('horizon_days'), self::DEFAULT_HORIZON_DAYS, 1, 365);
        $limit   = self::clampInt(Request::query('limit'), 25, 1, 100);

        $items = array_merge(
            $this->noticeInsights($ctx, $horizon),
            $this->obligationInsights($ctx),
            $this->riskInsights($ctx),
            $this->deviationInsights($ctx)
        );

        // Ranked by cost of delay, then by how soon the date bites. A stable
        // secondary key matters: two findings of equal priority flipping order
        // between refreshes makes the list look like it is changing when it is
        // not.
        usort($items, static function (array $a, array $b): int {
            return [$b['priority'], $a['due_date'] ?? '9999-12-31', $a['reference']['id']]
                <=> [$a['priority'], $b['due_date'] ?? '9999-12-31', $b['reference']['id']];
        });

        $counts = [];
        foreach ($items as $item) {
            $counts[$item['type']] = ($counts[$item['type']] ?? 0) + 1;
        }

        Response::success(self::withDisclaimer([
            'items'        => array_slice($items, 0, $limit),
            'total'        => count($items),
            'counts'       => $counts,
            'horizon_days' => $horizon,
            'as_at'        => Dates::today(),
        ]));
    }

    /**
     * Notice windows that have closed or are about to.
     *
     * @return list<array<string,mixed>>
     */
    private function noticeInsights(TenantContext $ctx, int $horizon): array
    {
        [$scope, $params] = $this->visibility($ctx);

        $st = $this->db()->prepare(
            "SELECT c.id AS contract_id, c.contract_number, c.title AS contract_title,
                    c.counterparty_name, c.notice_deadline, c.expiry_date, c.auto_renewal, c.renewal_type
             FROM contracts c
             WHERE c.environment = :env AND c.cmp_id = :cmp
               AND c.archived_at IS NULL
               AND c.status IN ('active', 'renewal_review')
               AND c.notice_deadline IS NOT NULL
               AND c.notice_deadline <= CURRENT_DATE + make_interval(days => :horizon)
               {$scope}
             ORDER BY c.notice_deadline
             LIMIT 100"
        );
        $this->bind($st, array_merge($params, [
            'env'     => $ctx->environment,
            'cmp'     => $ctx->cmpId,
            'horizon' => $horizon,
        ]));
        $st->execute();

        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $days = Dates::daysUntil((string) $row['notice_deadline']);
            $auto = ContractService::toBool($row['auto_renewal']);
            $past = $days !== null && $days < 0;

            $out[] = [
                'type'     => 'notice_deadline',
                // A missed window on an auto-renewing contract is the most
                // expensive thing in this list: the agreement has already
                // renewed and the company is committed for another term.
                'priority' => $past ? ($auto ? 98 : 90) : (int) round(86 - min(25, ($days ?? 0) / max(1, $horizon) * 25)),
                'severity' => $past ? 'critical' : ($days !== null && $days <= 14 ? 'high' : 'medium'),
                'title'    => $past
                    ? 'Notice window has closed'
                    : sprintf('Notice due in %d day(s)', (int) $days),
                'detail'   => $auto
                    ? 'This contract renews automatically unless notice is served.'
                    : 'Serve or waive notice before the deadline to keep the renewal decision open.',
                'contract_id'     => (int) $row['contract_id'],
                'contract_number' => $row['contract_number'],
                'contract_title'  => $row['contract_title'],
                'counterparty'    => $row['counterparty_name'],
                'due_date'        => $row['notice_deadline'],
                'reference'       => ['type' => 'contract', 'id' => (int) $row['contract_id']],
                'href'            => '/contracts/' . (int) $row['contract_id'] . '/renewals',
            ];
        }

        return $out;
    }

    /**
     * Obligations past their due date.
     *
     * Both the ones the nightly sweep has already reclassified and the ones it
     * has not reached yet, because a payment that fell due this morning is
     * overdue whether or not a cron has run since.
     *
     * @return list<array<string,mixed>>
     */
    private function obligationInsights(TenantContext $ctx): array
    {
        [$scope, $params] = $this->visibility($ctx);

        $st = $this->db()->prepare(
            "SELECT o.id, o.contract_id, o.due_date, o.status, o.amount,
                    ob.title, ob.responsible_party, ob.owner_uuid, ob.currency,
                    c.contract_number, c.title AS contract_title, c.counterparty_name
             FROM obligation_occurrences o
             JOIN contract_obligations ob ON ob.id = o.obligation_id
             JOIN contracts c ON c.id = o.contract_id
             WHERE o.environment = :env AND o.cmp_id = :cmp
               AND c.archived_at IS NULL
               AND ob.is_active
               AND (o.status = 'overdue' OR (o.status IN ('upcoming', 'due') AND o.due_date < CURRENT_DATE))
               {$scope}
             ORDER BY o.due_date
             LIMIT 100"
        );
        $this->bind($st, array_merge($params, ['env' => $ctx->environment, 'cmp' => $ctx->cmpId]));
        $st->execute();

        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $late = abs((int) (Dates::daysUntil((string) $row['due_date']) ?? 0));

            $out[] = [
                'type'     => 'obligation_overdue',
                'priority' => 80 + (int) min(15, intdiv($late, 3)),
                'severity' => $late >= 30 ? 'critical' : ($late >= 7 ? 'high' : 'medium'),
                'title'    => sprintf('%s is %d day(s) overdue', (string) $row['title'], $late),
                'detail'   => sprintf(
                    'Responsibility: %s.%s',
                    Enums::label((string) $row['responsible_party']),
                    $row['amount'] === null ? '' : sprintf(' Amount %s %s.', (string) ($row['currency'] ?? ''), (string) $row['amount'])
                ),
                'contract_id'     => (int) $row['contract_id'],
                'contract_number' => $row['contract_number'],
                'contract_title'  => $row['contract_title'],
                'counterparty'    => $row['counterparty_name'],
                'due_date'        => $row['due_date'],
                'reference'       => ['type' => 'obligation_occurrence', 'id' => (int) $row['id']],
                'href'            => '/contracts/' . (int) $row['contract_id'] . '/obligations',
            ];
        }

        return $out;
    }

    /**
     * Open high and critical risk findings.
     *
     * @return list<array<string,mixed>>
     */
    private function riskInsights(TenantContext $ctx): array
    {
        [$scope, $params] = $this->visibility($ctx);

        $st = $this->db()->prepare(
            "SELECT f.id, f.contract_id, f.severity, f.risk_category, f.title, f.detail,
                    f.recommendation, f.detected_by, f.created_at,
                    c.contract_number, c.title AS contract_title, c.counterparty_name
             FROM contract_risk_findings f
             JOIN contracts c ON c.id = f.contract_id
             WHERE f.environment = :env AND f.cmp_id = :cmp
               AND f.review_status = 'open'
               AND f.severity IN ('high', 'critical')
               AND c.archived_at IS NULL
               AND c.status NOT IN ('terminated', 'cancelled', 'archived')
               {$scope}
             ORDER BY CASE f.severity WHEN 'critical' THEN 1 ELSE 2 END, f.created_at DESC
             LIMIT 100"
        );
        $this->bind($st, array_merge($params, ['env' => $ctx->environment, 'cmp' => $ctx->cmpId]));
        $st->execute();

        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[] = [
                'type'     => 'risk_finding',
                'priority' => (string) $row['severity'] === 'critical' ? 92 : 74,
                'severity' => (string) $row['severity'],
                'title'    => (string) $row['title'],
                'detail'   => (string) ($row['recommendation'] ?? $row['detail'] ?? ''),
                'category' => Enums::label((string) $row['risk_category']),
                'detected_by'     => $row['detected_by'],
                'contract_id'     => (int) $row['contract_id'],
                'contract_number' => $row['contract_number'],
                'contract_title'  => $row['contract_title'],
                'counterparty'    => $row['counterparty_name'],
                'due_date'        => null,
                'reference'       => ['type' => 'risk_finding', 'id' => (int) $row['id']],
                'href'            => '/contracts/' . (int) $row['contract_id'] . '/risks',
            ];
        }

        return $out;
    }

    /**
     * Clause wording that departs from the playbook and nobody has ruled on.
     *
     * @return list<array<string,mixed>>
     */
    private function deviationInsights(TenantContext $ctx): array
    {
        [$scope, $params] = $this->visibility($ctx);

        $st = $this->db()->prepare(
            "SELECT d.id, d.contract_id, d.severity, d.deviation_summary, d.recommendation,
                    d.detected_by, d.created_at, cat.name AS category_name,
                    c.contract_number, c.title AS contract_title, c.counterparty_name
             FROM clause_deviations d
             JOIN contracts c ON c.id = d.contract_id
             LEFT JOIN clause_categories cat ON cat.id = d.category_id
             WHERE d.environment = :env AND d.cmp_id = :cmp
               AND d.review_status = 'open'
               AND c.archived_at IS NULL
               AND c.status NOT IN ('terminated', 'cancelled', 'archived')
               {$scope}
             ORDER BY CASE d.severity
                        WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3
                        WHEN 'low' THEN 4 ELSE 5 END,
                      d.created_at DESC
             LIMIT 100"
        );
        $this->bind($st, array_merge($params, ['env' => $ctx->environment, 'cmp' => $ctx->cmpId]));
        $st->execute();

        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[] = [
                'type'     => 'clause_deviation',
                'priority' => match ((string) $row['severity']) {
                    'critical' => 78,
                    'high'     => 66,
                    'medium'   => 48,
                    'low'      => 32,
                    default    => 20,
                },
                'severity' => (string) $row['severity'],
                'title'    => $row['category_name'] === null
                    ? 'Clause wording departs from the playbook'
                    : sprintf('%s clause departs from the playbook', (string) $row['category_name']),
                'detail'   => (string) ($row['recommendation'] ?? $row['deviation_summary']),
                'detected_by'     => $row['detected_by'],
                'contract_id'     => (int) $row['contract_id'],
                'contract_number' => $row['contract_number'],
                'contract_title'  => $row['contract_title'],
                'counterparty'    => $row['counterparty_name'],
                'due_date'        => null,
                'reference'       => ['type' => 'clause_deviation', 'id' => (int) $row['id']],
                'href'            => '/contracts/' . (int) $row['contract_id'] . '/clauses',
            ];
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Shared guards and helpers
    // -----------------------------------------------------------------------

    /**
     * Spend one unit of this caller's hourly AI budget.
     *
     * One bucket for every AI route rather than one per route: the budget
     * exists because these calls cost the company money at a provider, and a
     * caller who could reset the count by alternating endpoints would not be
     * limited by anything.
     */
    private function aiBudget(): void
    {
        $this->rateLimit('ai', Env::int('AI_RATE_LIMIT_PER_HOUR', 120), 3600);
    }

    /**
     * Refuse a generating call on a deployment with no provider.
     *
     * Names Console because that is where the credential lives — Contracts
     * never stores an API key — and an error that says "AI is unavailable"
     * without saying where to fix it produces a support ticket instead of a
     * configuration change.
     */
    private function requireProvider(): void
    {
        $status = AiProviderFactory::status();
        if (($status['configured'] ?? false) === true) {
            return;
        }

        $detail = is_string($status['message'] ?? null) && $status['message'] !== '' ? ' ' . $status['message'] : '';

        Response::error(
            'AI_NOT_CONFIGURED',
            'AI is not configured for this deployment. Set the Contracts AI provider and model in AICOUNTLY Console, '
            . 'then try again.' . $detail,
            503
        );
    }

    /**
     * Restrict a portfolio-wide read to what this caller may see.
     *
     * Mirrors ContractService's own visibility rule. Two placeholders for one
     * value because PDO with emulation off will not bind a named parameter
     * twice.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function visibility(TenantContext $ctx): array
    {
        if ($ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            return ['', []];
        }

        return [
            'AND (c.owner_uuid = :self OR c.created_by = :self2)',
            ['self' => $ctx->uuid, 'self2' => $ctx->uuid],
        ];
    }

    /** @param array<string,mixed> $params */
    private function bind(PDOStatement $st, array $params): void
    {
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function withDisclaimer(array $payload): array
    {
        $payload['disclaimer'] = SessionController::AI_DISCLAIMER;

        return $payload;
    }

    private static function optionalId(?string $raw): ?int
    {
        if ($raw === null || ! preg_match('/^\d{1,19}$/', $raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id < 1 ? null : $id;
    }

    private static function confidence(?string $raw): ?string
    {
        return $raw !== null && preg_match('/^(0(\.\d{1,3})?|1(\.0{1,3})?)$/', $raw) ? $raw : null;
    }

    private static function clampInt(?string $raw, int $default, int $min, int $max): int
    {
        if ($raw === null || ! preg_match('/^\d{1,6}$/', $raw)) {
            return $default;
        }

        return max($min, min($max, (int) $raw));
    }

    private function jobService(): AiJobService
    {
        return new AiJobService($this->db());
    }

    private function extractionService(): AiExtractionService
    {
        return new AiExtractionService($this->db());
    }

    private function analysisService(): AiAnalysisService
    {
        return new AiAnalysisService($this->db());
    }

    private function askService(): AskContractService
    {
        return new AskContractService($this->db());
    }
}
