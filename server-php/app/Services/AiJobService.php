<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\AiCredentials;
use App\Ai\AiProviderFactory;
use App\Ai\AiResponseRepair;
use App\Ai\ContractsAiProvider;
use App\Ai\JsonSchemaValidator;
use App\Ai\Prompts\ContractPrompts;
use App\Core\Database;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Permissions;
use App\Support\TenantContext;
use PDO;
use PDOException;
use Throwable;

/**
 * The queue for AI work, and the meter every model call passes through.
 *
 * Two responsibilities that look separate and are not. AI work costs real money
 * per call, so it is queued rather than run inside a web request, and every call
 * has to leave a row saying what it cost and whether it worked. Routing the
 * calls through the same class that owns the queue is what makes the second
 * guarantee hold: a service cannot make a model call and forget to meter it,
 * because the only way to make one is `callValidated()`.
 *
 * What the queue guarantees:
 *
 *   - The same request does not get paid for twice. An idempotency key derived
 *     from the kind, the subject and a hash of the payload means a user who
 *     clicks Analyse three times gets one job and three references to it.
 *   - A job is served to one worker. `claim()` uses FOR UPDATE SKIP LOCKED, so
 *     two cron processes overlapping — which they will, because a slow AI job
 *     outlives its five-minute window — take different rows rather than the
 *     same row twice.
 *   - A failure is retried on a widening delay and then stops. Retrying a
 *     malformed contract forever bills a customer for the same failure every
 *     five minutes.
 *   - A worker that dies does not lose its job. The lock ages out and the row
 *     goes back on the queue; that is what `locked_at` is for.
 *
 * Every query filters `environment` AND `cmp_id` from the TenantContext, except
 * the worker-side methods, which are given the environment explicitly and act
 * on the queue as a whole because that is what a worker is.
 */
final class AiJobService
{
    /** Grows the delay between attempts: 60s, then 120s, then 240s. */
    private const BACKOFF_BASE_SECONDS = 30;

    /** Nothing waits longer than an hour. Beyond that a human should be looking at it, not the queue. */
    private const BACKOFF_CAP_SECONDS = 3600;

    /** Ceiling on a model reply we are willing to hold in memory and store. */
    private const MAX_OUTPUT_TOKENS = 8000;

    private AuditService $audit;

    private ActivityService $activity;

    /**
     * @param ContractsAiProvider|null $provider injected by tests; production resolves it
     *                                          from Console through the factory
     */
    public function __construct(private PDO $pdo, private ?ContractsAiProvider $provider = null)
    {
        $this->audit    = new AuditService($pdo);
        $this->activity = new ActivityService($pdo);
    }

    public static function make(): ?self
    {
        $pdo = Database::pdo();

        return $pdo === null ? null : new self($pdo);
    }

    // -----------------------------------------------------------------------
    // Enqueueing
    // -----------------------------------------------------------------------

    /**
     * Queue one piece of AI work, or hand back the job that is already doing it.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed> the job row, new or existing
     */
    public function enqueue(
        TenantContext $ctx,
        string $kind,
        array $payload = [],
        ?int $contractId = null,
        ?int $versionId = null,
        ?string $idempotencyKey = null
    ): array {
        if (! Enums::isValid($kind, Enums::AI_JOB_KINDS)) {
            throw DomainException::badRequest('Unknown AI job kind.', 'UNKNOWN_JOB_KIND');
        }

        if ($contractId !== null) {
            $this->assertContractBelongsToTenant($ctx, $contractId);
        }
        if ($versionId !== null) {
            $this->assertVersionBelongsToTenant($ctx, $versionId);
        }

        $key = $idempotencyKey ?? self::idempotencyKey($kind, $contractId, $versionId, $payload);

        try {
            return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $kind, $payload, $contractId, $versionId, $key): array {
                $existing = $this->findByKey($ctx, $key);
                if ($existing !== null) {
                    return $this->reviveIfSpent($ctx, $existing);
                }

                $st = $pdo->prepare(
                    'INSERT INTO ai_jobs
                     (environment, cmp_id, kind, contract_id, version_id, payload,
                      status, idempotency_key, requested_by)
                     VALUES (:env, :cmp, :kind, :contract, :version, :payload::jsonb,
                             \'queued\', :key, :actor)
                     RETURNING id'
                );
                $st->execute([
                    'env'      => $ctx->environment,
                    'cmp'      => $ctx->cmpId,
                    'kind'     => $kind,
                    'contract' => $contractId,
                    'version'  => $versionId,
                    'payload'  => json_encode($payload, JSON_UNESCAPED_SLASHES),
                    'key'      => $key,
                    'actor'    => $ctx->uuid,
                ]);

                $id  = (int) $st->fetchColumn();
                $job = $this->find($ctx, $id);
                if ($job === null) {
                    throw new DomainException('The AI job was created but could not be read back.', 'ENQUEUE_FAILED', 500);
                }

                if ($contractId !== null) {
                    $this->activity->record($ctx, $contractId, 'ai.job.queued', sprintf('AI %s queued', Enums::label($kind)), [
                        'job_id' => $id,
                        'kind'   => $kind,
                    ]);
                }

                return $job;
            });
        } catch (PDOException $e) {
            // Two identical requests arriving together: one wins the unique
            // index and the other lands here. The loser wants the winner's job,
            // not an error — that is the whole point of the key.
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            $existing = $this->findByKey($ctx, $key);
            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * The default key: same kind, same subject, same input means the same
     * answer, so it is the same job.
     *
     * The payload is hashed rather than serialised into the key because a
     * payload can carry a whole question and the column is 96 characters.
     *
     * @param array<string,mixed> $payload
     */
    public static function idempotencyKey(string $kind, ?int $contractId, ?int $versionId, array $payload): string
    {
        // Sorted so that two callers building the same payload in a different
        // order do not pay twice for the identical request.
        $normalised = $payload;
        self::ksortRecursive($normalised);

        return hash('sha256', implode('|', [
            $kind,
            (string) $contractId,
            (string) $versionId,
            hash('sha256', json_encode($normalised, JSON_UNESCAPED_SLASHES) ?: ''),
        ]));
    }

    // -----------------------------------------------------------------------
    // The worker side
    // -----------------------------------------------------------------------

    /**
     * Take up to $limit queued jobs for this worker.
     *
     * SKIP LOCKED rather than a status flag set in a second statement: between
     * the SELECT and the UPDATE of a two-statement claim, another worker reads
     * the same row. That window is small, and a queue is exactly the place
     * where small windows are hit constantly.
     *
     * @return list<array<string,mixed>>
     */
    public function claim(string $environment, int $limit, string $workerId): array
    {
        $limit = max(1, min(100, $limit));

        $st = $this->pdo->prepare(
            'UPDATE ai_jobs j
             SET status = \'running\',
                 locked_at = CURRENT_TIMESTAMP,
                 locked_by = :worker,
                 attempts = j.attempts + 1,
                 started_at = COALESCE(j.started_at, CURRENT_TIMESTAMP),
                 updated_at = CURRENT_TIMESTAMP
             FROM (
                 SELECT id FROM ai_jobs
                 WHERE environment = :env
                   AND status = \'queued\'
                   AND attempts < max_attempts
                   AND (next_attempt_at IS NULL OR next_attempt_at <= CURRENT_TIMESTAMP)
                 ORDER BY created_at, id
                 LIMIT :lim
                 FOR UPDATE SKIP LOCKED
             ) AS picked
             WHERE j.id = picked.id
             RETURNING j.*'
        );
        $st->bindValue(':worker', mb_substr($workerId, 0, 64));
        $st->bindValue(':env', $environment);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->execute();

        return array_map(fn (array $r): array => self::hydrate($r), $st->fetchAll() ?: []);
    }

    /**
     * @param array<string,mixed> $result
     * @param array{provider?: ?string, model?: ?string, prompt_tokens?: ?int, output_tokens?: ?int, latency_ms?: ?int} $usage
     */
    public function complete(int $jobId, array $result, array $usage = []): void
    {
        // COALESCE on every usage column: callValidated() has already added the
        // tokens of each call to the row, and a caller that does not repeat
        // them here must not blank the running total.
        $this->pdo->prepare(
            'UPDATE ai_jobs
             SET status = \'succeeded\',
                 result = :result::jsonb,
                 provider = COALESCE(:provider, provider),
                 model = COALESCE(:model, model),
                 prompt_tokens = COALESCE(:pt, prompt_tokens),
                 output_tokens = COALESCE(:ot, output_tokens),
                 latency_ms = COALESCE(:ms, latency_ms),
                 error_code = NULL,
                 error_message = NULL,
                 next_attempt_at = NULL,
                 locked_at = NULL,
                 locked_by = NULL,
                 completed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'running\''
        )->execute([
            'result'   => json_encode($result, JSON_UNESCAPED_SLASHES),
            'provider' => $usage['provider'] ?? null,
            'model'    => $usage['model'] ?? null,
            'pt'       => $usage['prompt_tokens'] ?? null,
            'ot'       => $usage['output_tokens'] ?? null,
            'ms'       => $usage['latency_ms'] ?? null,
            'id'       => $jobId,
        ]);
    }

    /**
     * Record a failed attempt and decide whether there will be another.
     *
     * The error is written whichever way that goes. A job that is going to be
     * retried in four minutes still has to be able to say why it is waiting,
     * or an operator watching the queue sees only that nothing is happening.
     *
     * Guarded on `status = 'running'` so a caller that fails a job the worker
     * has already failed — cron's catch-all around a service that reported the
     * failure itself — does not spend a second attempt on it.
     */
    public function fail(int $jobId, string $code, string $message): void
    {
        $st = $this->pdo->prepare('SELECT attempts, max_attempts, kind, contract_id, environment, cmp_id FROM ai_jobs WHERE id = ? AND status = \'running\'');
        $st->execute([$jobId]);
        $job = $st->fetch();

        if (! is_array($job)) {
            return;
        }

        $attempts = (int) $job['attempts'];
        $spent    = $attempts >= (int) $job['max_attempts'];

        $this->pdo->prepare(
            'UPDATE ai_jobs
             SET status = :status,
                 error_code = :code,
                 error_message = :message,
                 locked_at = NULL,
                 locked_by = NULL,
                 next_attempt_at = CASE WHEN :queued THEN CURRENT_TIMESTAMP + make_interval(secs => :secs) ELSE NULL END,
                 completed_at = CASE WHEN :queued2 THEN NULL ELSE CURRENT_TIMESTAMP END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND status = \'running\''
        )->execute([
            'status'  => $spent ? 'failed' : 'queued',
            'code'    => mb_substr($code, 0, 64),
            'message' => mb_substr($message, 0, 2000),
            'queued'  => $spent ? 'false' : 'true',
            'queued2' => $spent ? 'false' : 'true',
            'secs'    => self::backoffSeconds($attempts),
            'id'      => $jobId,
        ]);
    }

    /**
     * How long a job waits before its next attempt.
     *
     * Deterministic, with no jitter. Jitter spreads a thundering herd, and this
     * queue never has one — a company runs a handful of AI jobs a day, and the
     * cost of two of them retrying in the same second is nothing. A predictable
     * delay is worth more here: it can be asserted in a test and explained to
     * an operator watching a job that is waiting.
     */
    public static function backoffSeconds(int $attempts): int
    {
        $delay = self::BACKOFF_BASE_SECONDS * (2 ** max(1, min(12, $attempts)));

        return (int) min($delay, self::BACKOFF_CAP_SECONDS);
    }

    /**
     * Return jobs whose worker died back to the queue.
     *
     * A crashed worker leaves `locked_at` set and nothing else happens to the
     * row — no exception was thrown anywhere that could have failed it. Without
     * this the job sits in `running` forever and the user's analysis never
     * arrives and never errors, which is the worst of both.
     *
     * @return int jobs released or given up on
     */
    public function reapStale(string $environment, int $staleSeconds): int
    {
        $staleSeconds = max(60, $staleSeconds);

        $released = $this->pdo->prepare(
            'UPDATE ai_jobs
             SET status = \'queued\',
                 locked_at = NULL,
                 locked_by = NULL,
                 error_code = \'WORKER_LOST\',
                 error_message = \'The worker holding this job stopped without reporting a result.\',
                 next_attempt_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE environment = :env
               AND status = \'running\'
               AND locked_at IS NOT NULL
               AND locked_at < CURRENT_TIMESTAMP - make_interval(secs => :secs)
               AND attempts < max_attempts'
        );
        $released->execute(['env' => $environment, 'secs' => $staleSeconds]);

        // A job whose attempts are already spent must not go back on the queue:
        // claim() would never pick it up again and it would sit in `queued`
        // looking like work that is about to happen.
        $abandoned = $this->pdo->prepare(
            'UPDATE ai_jobs
             SET status = \'failed\',
                 locked_at = NULL,
                 locked_by = NULL,
                 error_code = \'WORKER_LOST\',
                 error_message = \'The worker holding this job stopped, and no attempts remain.\',
                 completed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE environment = :env
               AND status = \'running\'
               AND locked_at IS NOT NULL
               AND locked_at < CURRENT_TIMESTAMP - make_interval(secs => :secs)
               AND attempts >= max_attempts'
        );
        $abandoned->execute(['env' => $environment, 'secs' => $staleSeconds]);

        return $released->rowCount() + $abandoned->rowCount();
    }

    /**
     * Run one claimed job to completion.
     *
     * The entry point cron uses. It takes an id rather than a TenantContext
     * because a worker has no session: the tenant is whatever the job row says
     * it is, which is also the only tenant whose data the job may touch.
     *
     * @return array<string,mixed> the job's result
     */
    public function process(int $jobId): array
    {
        $job = $this->findRaw($jobId);
        if ($job === null) {
            throw DomainException::notFound('AI job not found.');
        }

        $ctx  = self::contextForJob($job);
        $kind = (string) $job['kind'];

        return match ($kind) {
            'extract', 'classify', 'clauses', 'obligations'
                => (new AiExtractionService($this->pdo, $this->provider))->run($ctx, $jobId),
            'summarize'      => $this->runAnalysis($ctx, $job, 'summarize'),
            'renewal_advice' => $this->runAnalysis($ctx, $job, 'renewal_advice'),
            default          => $this->refuseKind($jobId, $kind),
        };
    }

    // -----------------------------------------------------------------------
    // The metered model call
    // -----------------------------------------------------------------------

    /**
     * One model call, checked against its schema, with the money accounted for.
     *
     * The recovery ladder is deliberately short and deliberately in this order:
     *
     *   1. Decode the reply. AiResponseRepair strips a markdown fence and the
     *      sentence a model puts before its JSON — punctuation problems around
     *      intact data, not worth a second paid call.
     *   2. Validate. A reply that decodes but does not match the schema is not
     *      usable: storing part of it would put a half-read extraction in front
     *      of somebody as a contract term.
     *   3. One retry, quoting the validator's own errors back. Models fix a
     *      named shape problem reliably and invent a new answer rarely.
     *   4. Fail, loudly, with the errors attached.
     *
     * There is no third attempt. Two failures on a schema this specific mean
     * the document or the model is wrong in a way another call will not fix,
     * and each attempt is billed.
     *
     * @param  list<array{role: string, content: string}> $messages
     * @param  array<string,mixed>                        $schema
     * @param  array{contract_id?: ?int, job_id?: ?int, schema_name?: string, max_tokens?: int, temperature?: float} $meta
     * @return array{value: mixed, provider: string, model: string, prompt_tokens: ?int, output_tokens: ?int, latency_ms: int}
     */
    public function callValidated(
        TenantContext $ctx,
        ContractsAiProvider $provider,
        array $messages,
        array $schema,
        string $operation,
        array $meta = []
    ): array {
        $options = [
            'max_tokens'  => $meta['max_tokens'] ?? self::MAX_OUTPUT_TOKENS,
            // Zero, everywhere. Two people analysing the same contract on the
            // same day should see the same extraction, and a creative reading
            // of an indemnity clause is not a feature.
            'temperature' => $meta['temperature'] ?? 0.0,
            'json_schema' => $schema,
            'schema_name' => $meta['schema_name'] ?? $operation,
        ];

        $attempt = $this->attemptCall($ctx, $provider, $messages, $schema, $operation, $meta, $options);
        if ($attempt['errors'] === []) {
            return $attempt['call'] + ['value' => $attempt['value']];
        }

        $retryMessages = ContractPrompts::stricterRetry($messages, $attempt['errors']);
        $retry         = $this->attemptCall($ctx, $provider, $retryMessages, $schema, $operation, $meta, $options);
        if ($retry['errors'] === []) {
            return $retry['call'] + ['value' => $retry['value']];
        }

        throw new DomainException(
            'The AI response did not match the required format: ' . implode(' ', array_slice($retry['errors'], 0, 4)),
            'AI_SCHEMA_INVALID',
            502
        );
    }

    /**
     * One attempt: call, meter, decode, validate.
     *
     * @param  list<array{role: string, content: string}> $messages
     * @param  array<string,mixed>                        $schema
     * @param  array<string,mixed>                        $meta
     * @param  array<string,mixed>                        $options
     * @return array{value: mixed, errors: list<string>, call: array<string,mixed>}
     */
    private function attemptCall(
        TenantContext $ctx,
        ContractsAiProvider $provider,
        array $messages,
        array $schema,
        string $operation,
        array $meta,
        array $options
    ): array {
        $startedAt  = microtime(true);
        $contractId = $meta['contract_id'] ?? null;
        $jobId      = $meta['job_id'] ?? null;

        try {
            $reply = $provider->complete($messages, $options);
        } catch (Throwable $e) {
            // Logged before it is rethrown: a call that failed still cost the
            // provider's time and is the first thing an operator looks for
            // when a company reports that AI stopped working.
            $this->recordUsage($ctx, [
                'operation'     => $operation,
                'provider'      => $provider->name(),
                'contract_id'   => $contractId,
                'job_id'        => $jobId,
                'success'       => false,
                'error_code'    => $e instanceof DomainException ? $e->errorCode : 'PROVIDER_FAILED',
                'error_message' => $e->getMessage(),
                'latency_ms'    => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $e;
        }

        $latency = (int) round((microtime(true) - $startedAt) * 1000);
        $call    = [
            'provider'      => $provider->name(),
            'model'         => (string) ($reply['model'] ?? ''),
            'prompt_tokens' => $reply['prompt_tokens'] ?? null,
            'output_tokens' => $reply['output_tokens'] ?? null,
            'latency_ms'    => $latency,
        ];

        $decoded = json_decode((string) ($reply['text'] ?? ''), true);
        if (! is_array($decoded)) {
            $decoded = AiResponseRepair::decode((string) ($reply['text'] ?? ''));
        }

        $result = $decoded === null
            ? ['valid' => false, 'errors' => ['$: the reply was not JSON.'], 'value' => null]
            : JsonSchemaValidator::validate($decoded, $schema);

        $this->recordUsage($ctx, array_merge($call, [
            'operation'     => $operation,
            'contract_id'   => $contractId,
            'job_id'        => $jobId,
            'success'       => $result['valid'],
            'error_code'    => $result['valid'] ? null : 'AI_SCHEMA_INVALID',
            'error_message' => $result['valid'] ? null : implode(' ', array_slice($result['errors'], 0, 3)),
        ]));

        if ($jobId !== null) {
            $this->addUsageToJob((int) $jobId, $call);
        }

        return [
            'value'  => $result['value'],
            'errors' => $result['valid'] ? [] : $result['errors'],
            'call'   => $call,
        ];
    }

    /**
     * Write one row to the local usage log and tell Console about it.
     *
     * Never throws. A telemetry insert that fails is an operational problem;
     * losing the user's contract analysis because of one is a worse problem,
     * and the same trade AuditService makes for the same reason.
     *
     * @param array<string,mixed> $event
     */
    public function recordUsage(TenantContext $ctx, array $event): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO ai_usage_log
                 (environment, cmp_id, user_uuid, contract_id, job_id, operation, provider, model,
                  credential_source, prompt_tokens, output_tokens, latency_ms, success,
                  error_code, error_message)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx->environment,
                $ctx->cmpId,
                $ctx->uuid,
                $event['contract_id'] ?? null,
                $event['job_id'] ?? null,
                mb_substr((string) ($event['operation'] ?? 'unknown'), 0, 32),
                isset($event['provider']) ? mb_substr((string) $event['provider'], 0, 32) : null,
                isset($event['model']) && $event['model'] !== '' ? mb_substr((string) $event['model'], 0, 96) : null,
                isset($event['credential_source']) ? mb_substr((string) $event['credential_source'], 0, 16) : null,
                $event['prompt_tokens'] ?? null,
                $event['output_tokens'] ?? null,
                $event['latency_ms'] ?? null,
                ($event['success'] ?? true) ? 'true' : 'false',
                isset($event['error_code']) && $event['error_code'] !== null ? mb_substr((string) $event['error_code'], 0, 64) : null,
                // Truncated hard, and never the prompt: the error text can echo
                // part of a model reply, and a reply is derived from the
                // customer's contract.
                isset($event['error_message']) && $event['error_message'] !== null ? mb_substr((string) $event['error_message'], 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            error_log('[contracts][ai] failed to write usage row: ' . $e->getMessage());
        }

        AiCredentials::reportUsage([
            'module'        => AiProviderFactory::DEFAULT_MODULE,
            'operation'     => (string) ($event['operation'] ?? 'unknown'),
            'provider'      => $event['provider'] ?? null,
            'model'         => $event['model'] ?? null,
            'prompt_tokens' => $event['prompt_tokens'] ?? null,
            'output_tokens' => $event['output_tokens'] ?? null,
            'latency_ms'    => $event['latency_ms'] ?? null,
            'success'       => (bool) ($event['success'] ?? true),
            'error_code'    => $event['error_code'] ?? null,
            'environment'   => $ctx->environment,
            'cmp_id'        => $ctx->cmpId,
        ]);
    }

    /**
     * The provider this run will use.
     *
     * Throws rather than returning null: by the time a job is running, a
     * deployment with no AI configured has already had its chance to say so on
     * /api/health, and an empty extraction is indistinguishable from a contract
     * that says nothing.
     */
    public function providerOrFail(): ContractsAiProvider
    {
        $provider = $this->provider ?? AiProviderFactory::forModule();
        if ($provider === null) {
            throw DomainException::unavailable(
                'No AI provider is configured for this deployment.',
                'AI_NOT_CONFIGURED'
            );
        }

        return $provider;
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function find(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ai_jobs WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed> @throws DomainException */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $row = $this->find($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('AI job not found.');
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function listForContract(TenantContext $ctx, int $contractId, int $limit = 50, int $offset = 0): array
    {
        $st = $this->pdo->prepare(
            'SELECT id, uuid, kind, status, attempts, max_attempts, provider, model,
                    prompt_tokens, output_tokens, latency_ms, error_code, error_message,
                    version_id, requested_by, next_attempt_at, started_at, completed_at, created_at
             FROM ai_jobs
             WHERE environment = :env AND cmp_id = :cmp AND contract_id = :contract
             ORDER BY created_at DESC, id DESC
             LIMIT :lim OFFSET :off'
        );
        $st->bindValue(':env', $ctx->environment);
        $st->bindValue(':cmp', $ctx->cmpId, PDO::PARAM_INT);
        $st->bindValue(':contract', $contractId, PDO::PARAM_INT);
        $st->bindValue(':lim', max(1, min(200, $limit)), PDO::PARAM_INT);
        $st->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $st->execute();

        return array_map(fn (array $r): array => self::hydrate($r), $st->fetchAll() ?: []);
    }

    /**
     * The latest job of each kind for a contract.
     *
     * What a screen needs to answer "is the analysis running, done or broken"
     * without listing a year of history to find out.
     *
     * @return array<string,array<string,mixed>>
     */
    public function statusFor(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT DISTINCT ON (kind)
                    kind, id, status, attempts, max_attempts, error_code, error_message,
                    next_attempt_at, started_at, completed_at, created_at
             FROM ai_jobs
             WHERE environment = ? AND cmp_id = ? AND contract_id = ?
             ORDER BY kind, created_at DESC, id DESC'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $contractId]);

        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $row['id']           = (int) $row['id'];
            $row['attempts']     = (int) $row['attempts'];
            $row['max_attempts'] = (int) $row['max_attempts'];
            $out[(string) $row['kind']] = $row;
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /** @return array<string,mixed>|null the raw row, unscoped — worker use only */
    private function findRaw(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ai_jobs WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /** @return array<string,mixed>|null */
    private function findByKey(TenantContext $ctx, string $key): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM ai_jobs
             WHERE environment = ? AND cmp_id = ? AND idempotency_key = ?
             LIMIT 1'
        );
        $st->execute([$ctx->environment, $ctx->cmpId, $key]);
        $row = $st->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Put a job that has stopped back on the queue instead of refusing the
     * request.
     *
     * A queued, running or succeeded job is handed straight back — that is the
     * idempotency guarantee. A failed or cancelled one is different: the user
     * asking again is asking for a retry, and the alternative is a contract
     * whose analysis can never be attempted again because the key of the
     * failure is permanent. The same row is reused, so this is still one job
     * and one bill for one request.
     *
     * @param  array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function reviveIfSpent(TenantContext $ctx, array $job): array
    {
        if (! in_array((string) $job['status'], ['failed', 'cancelled'], true)) {
            return $job;
        }

        $this->pdo->prepare(
            'UPDATE ai_jobs
             SET status = \'queued\', attempts = 0, error_code = NULL, error_message = NULL,
                 next_attempt_at = NULL, locked_at = NULL, locked_by = NULL,
                 started_at = NULL, completed_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([(int) $job['id'], $ctx->environment, $ctx->cmpId]);

        return $this->find($ctx, (int) $job['id']) ?? $job;
    }

    /**
     * Add one call's tokens to the job's running total.
     *
     * A staged pipeline makes several calls per job, so the job's cost is the
     * sum of them. Written per call rather than at the end so that a job which
     * fails on its last stage still shows what it spent getting there.
     *
     * @param array<string,mixed> $call
     */
    private function addUsageToJob(int $jobId, array $call): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE ai_jobs
                 SET provider = COALESCE(:provider, provider),
                     model = COALESCE(:model, model),
                     prompt_tokens = COALESCE(prompt_tokens, 0) + COALESCE(:pt, 0),
                     output_tokens = COALESCE(output_tokens, 0) + COALESCE(:ot, 0),
                     latency_ms = COALESCE(latency_ms, 0) + COALESCE(:ms, 0),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            )->execute([
                'provider' => $call['provider'] ?? null,
                'model'    => ($call['model'] ?? '') !== '' ? $call['model'] : null,
                'pt'       => $call['prompt_tokens'] ?? null,
                'ot'       => $call['output_tokens'] ?? null,
                'ms'       => $call['latency_ms'] ?? null,
                'id'       => $jobId,
            ]);
        } catch (Throwable $e) {
            error_log('[contracts][ai] failed to add usage to job ' . $jobId . ': ' . $e->getMessage());
        }
    }

    /**
     * The tenant a worker acts for.
     *
     * Built from the job row, never from anything a worker was told. The
     * permission set is the full one because the check that mattered happened
     * when a signed-in user enqueued the work; a worker has no session to check
     * against, and giving it an empty set would simply stop every job.
     *
     * @param array<string,mixed> $job
     */
    private static function contextForJob(array $job): TenantContext
    {
        return new TenantContext(
            uuid: (string) ($job['requested_by'] ?? 'system'),
            sesKey: '',
            cmpId: (int) $job['cmp_id'],
            fyId: 0,
            boId: 0,
            environment: (string) $job['environment'],
            company: null,
            permissions: Permissions::all(),
            roles: [],
        );
    }

    /**
     * @param  array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function runAnalysis(TenantContext $ctx, array $job, string $kind): array
    {
        $contractId = $job['contract_id'] ?? null;
        if ($contractId === null) {
            $this->fail((int) $job['id'], 'CONTRACT_REQUIRED', 'This job kind needs a contract to work on.');

            return ['status' => 'failed', 'error_code' => 'CONTRACT_REQUIRED'];
        }

        $analysis = new AiAnalysisService($this->pdo, $this->provider);
        $jobId    = (int) $job['id'];

        try {
            $result = $kind === 'summarize'
                ? $analysis->summarize($ctx, (int) $contractId, $jobId)
                : $analysis->renewalAdvice($ctx, (int) $contractId, $jobId);
        } catch (DomainException $e) {
            $this->fail($jobId, $e->errorCode, $e->getMessage());

            return ['status' => 'failed', 'error_code' => $e->errorCode, 'error_message' => $e->getMessage()];
        }

        $this->complete($jobId, $result);

        return $result;
    }

    /** @return array<string,mixed> */
    private function refuseKind(int $jobId, string $kind): array
    {
        // Deliberately a terminal failure rather than a silent skip. A kind
        // nothing handles is a queue that fills up with work that will never
        // be done, and a job in `failed` with this code says which service is
        // missing rather than leaving an operator to guess.
        $this->fail($jobId, 'JOB_KIND_NOT_HANDLED', "No worker in this build handles the '{$kind}' job kind.");

        return ['status' => 'failed', 'error_code' => 'JOB_KIND_NOT_HANDLED'];
    }

    private function assertContractBelongsToTenant(TenantContext $ctx, int $contractId): void
    {
        $st = $this->pdo->prepare('SELECT 1 FROM contracts WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1');
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw DomainException::notFound('Contract not found.');
        }
    }

    private function assertVersionBelongsToTenant(TenantContext $ctx, int $versionId): void
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_document_versions WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$versionId, $ctx->environment, $ctx->cmpId]);

        if ($st->fetchColumn() === false) {
            throw DomainException::notFound('Document version not found.');
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        foreach (['payload', 'result'] as $key) {
            if (isset($row[$key]) && is_string($row[$key])) {
                $decoded   = json_decode($row[$key], true);
                $row[$key] = is_array($decoded) ? $decoded : [];
            }
        }

        foreach (['id', 'cmp_id', 'contract_id', 'version_id', 'attempts', 'max_attempts',
                  'prompt_tokens', 'output_tokens', 'latency_ms'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }

        return $row;
    }

    /** @param array<mixed> $array */
    private static function ksortRecursive(array &$array): void
    {
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
        unset($value);

        ksort($array);
    }
}
