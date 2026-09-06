<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Signature\SignatureProvider;
use App\Signature\SignatureProviderFactory;
use App\Support\Dates;
use App\Support\DomainException;
use App\Support\Enums;
use App\Support\Environment;
use App\Support\TenantContext;
use App\Support\Validator;
use PDO;
use Throwable;

/**
 * Who has to sign, what they have done about it, and when the agreement was
 * executed.
 *
 * The record lives here whether or not a vendor is involved. That is the point:
 * `ManualProvider` sends nothing, and a company using it still gets the list of
 * outstanding signatories, the execution date and the evidence reference —
 * which is the part anybody ever audits. A vendor only adds delivery.
 *
 * Two boundaries are load-bearing.
 *
 * The first is that the contract's own status is never written here. Execution
 * moves a contract to active through ContractService::changeStatus, so the
 * transition graph, the audit row, the obligation generation and the renewal
 * cycle all happen exactly as they do for every other route into that state.
 * An UPDATE on contracts.status would skip all four and nothing downstream
 * would know it had.
 *
 * The second is that an inbound webhook may move signature state and may not
 * execute a contract. A provider callback is unauthenticated by nature — it is
 * trusted because of an HMAC over its body and nothing else — and making a
 * contract legally live in the system is the most consequential thing this
 * product does. A person holding `signature.act` confirms that, against the
 * signed copy they are looking at.
 */
final class SignatureService
{
    /** Request states that are still in play, and so still block a second envelope. */
    private const OPEN_STATUSES = ['draft', 'sent', 'viewed', 'partially_signed'];

    /** Request states that can no longer be recalled or re-driven. */
    private const CLOSED_STATUSES = ['signed', 'completed', 'declined', 'cancelled', 'expired'];

    private const MAX_SIGNERS = 25;

    private AuditService $audit;

    private ActivityService $activity;

    /**
     * @param SignatureProvider|null $provider overrides `SIGNATURE_PROVIDER`. The seam
     *        exists for tests: the shipped provider deliberately rejects every
     *        webhook, so the delivery path can only be exercised through one
     *        that accepts a known signature.
     */
    public function __construct(private PDO $pdo, private ?SignatureProvider $provider = null)
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
    // Reading
    // -----------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public function listForContract(TenantContext $ctx, int $contractId): array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM signature_requests
             WHERE contract_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY created_at DESC, id DESC'
        );
        $st->execute([$contractId, $ctx->environment, $ctx->cmpId]);

        $rows = $st->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->hydrate($ctx, $row), $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(TenantContext $ctx, int $id): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM signature_requests WHERE id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$id, $ctx->environment, $ctx->cmpId]);
        $row = $st->fetch();

        return is_array($row) ? $this->hydrate($ctx, $row) : null;
    }

    /** @return array<string,mixed> */
    public function findOrFail(TenantContext $ctx, int $id): array
    {
        $row = $this->find($ctx, $id);
        if ($row === null) {
            throw DomainException::notFound('Signature request not found.');
        }

        return $row;
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    /**
     * Open a signature request against a contract.
     *
     * @param  array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function create(TenantContext $ctx, int $contractId, array $body): array
    {
        $contract = (new ContractService($this->pdo))->findOrFail($ctx, $contractId);

        if (in_array((string) $contract['status'], Enums::CLOSED_STATUSES, true)) {
            throw DomainException::conflict(
                'This contract is ' . Enums::label((string) $contract['status']) . ' and cannot be sent for signature.',
                'CONTRACT_NOT_SIGNABLE'
            );
        }

        // One envelope at a time. Two open requests against one contract is how
        // two different drafts get signed by two different people, and the
        // record afterwards cannot say which document the company is bound by.
        $open = $this->pdo->prepare(
            "SELECT id FROM signature_requests
             WHERE contract_id = ? AND environment = ? AND cmp_id = ? AND status IN (" . self::placeholders(self::OPEN_STATUSES) . ")
             LIMIT 1"
        );
        $open->execute(array_merge([$contractId, $ctx->environment, $ctx->cmpId], self::OPEN_STATUSES));
        if ($open->fetchColumn() !== false) {
            throw DomainException::conflict(
                'This contract already has a signature request in progress. Cancel it before starting another.',
                'SIGNATURE_ALREADY_OPEN'
            );
        }

        $v       = new Validator($body);
        $subject = $v->optionalString('subject', 255) ?? ('Signature request: ' . (string) $contract['title']);
        $message = $v->optionalText('message', 4000);
        $expires = $v->optionalDate('expires_at');
        $sequent = $v->optionalBool('is_sequential', true) ?? true;
        $versionId = $v->optionalId('document_version_id');
        $signers = $this->validateSigners($ctx, $contractId, $v->optionalArray('signers', self::MAX_SIGNERS), $v);

        if ($signers === []) {
            $v->fail('signers', 'List at least one signatory.');
        }
        if ($versionId !== null && ! $this->versionBelongsToContract($ctx, $contractId, $versionId)) {
            $v->fail('document_version_id', 'That document version does not belong to this contract.');
        }
        if ($expires !== null && Dates::isPast($expires)) {
            $v->fail('expires_at', 'The expiry date is already in the past.');
        }
        $v->assert();

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $contractId, $subject, $message, $expires, $sequent, $versionId, $signers): array {
            $st = $pdo->prepare(
                'INSERT INTO signature_requests
                 (environment, cmp_id, contract_id, document_version_id, provider, status,
                  is_sequential, subject, message, expires_at, created_by)
                 VALUES (:env, :cmp, :contract, :version, :provider, \'draft\',
                         :sequential, :subject, :message, :expires, :actor)
                 RETURNING id'
            );
            $st->execute([
                'env'        => $ctx->environment,
                'cmp'        => $ctx->cmpId,
                'contract'   => $contractId,
                'version'    => $versionId,
                'provider'   => SignatureProviderFactory::configuredName(),
                'sequential' => $sequent ? 'true' : 'false',
                'subject'    => $subject,
                'message'    => $message,
                // Stored as end-of-day: an expiry of "31 March" that lapsed at
                // midnight would give the last signatory no time on the day the
                // screen told them they had.
                'expires'    => $expires === null ? null : $expires . ' 23:59:59',
                'actor'      => $ctx->uuid,
            ]);

            $requestId = (int) $st->fetchColumn();
            $this->insertSigners($ctx, $requestId, $signers);

            $this->audit->log($ctx, 'signature_request', $requestId, 'signature.created', $contractId, [
                'signers' => ['from' => null, 'to' => count($signers)],
            ]);
            $this->activity->record($ctx, $contractId, 'signature.created', sprintf(
                'Signature request prepared for %d signatory(ies)',
                count($signers)
            ));

            return $this->findOrFail($ctx, $requestId);
        });
    }

    /**
     * Hand the request to the provider and start the clock.
     *
     * @return array<string,mixed>
     */
    public function send(TenantContext $ctx, int $id): array
    {
        $request = $this->findOrFail($ctx, $id);

        if ((string) $request['status'] !== 'draft') {
            throw DomainException::conflict(
                'Only a draft signature request can be sent.',
                'SIGNATURE_ALREADY_SENT'
            );
        }
        if ($request['signers'] === []) {
            throw DomainException::conflict('Add at least one signatory before sending.', 'SIGNATURE_NO_SIGNERS');
        }

        $provider = $this->provider ?? SignatureProviderFactory::default();
        if (! $provider->isConfigured()) {
            throw DomainException::unavailable(
                sprintf('The "%s" signature provider is not fully configured on this deployment.', $provider->name()),
                'SIGNATURE_PROVIDER_UNCONFIGURED'
            );
        }

        $outcome = $provider->send($request, $request['signers']);

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $request, $provider, $outcome): array {
            $contractId = (int) $request['contract_id'];

            $pdo->prepare(
                'UPDATE signature_requests
                 SET provider = ?, provider_reference = ?, status = \'sent\',
                     sent_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([
                $provider->name(),
                $outcome['provider_reference'] ?? null,
                $id,
                $ctx->environment,
                $ctx->cmpId,
            ]);

            // A sequential envelope only notifies the first signatory; marking
            // them all "sent" would show three people as waiting on a document
            // two of them cannot see yet.
            $refs = is_array($outcome['signers'] ?? null) ? $outcome['signers'] : [];
            foreach ($request['signers'] as $index => $signer) {
                if ($request['is_sequential'] === true && $index > 0) {
                    continue;
                }
                $pdo->prepare(
                    'UPDATE signature_signers
                     SET status = \'sent\', sent_at = CURRENT_TIMESTAMP, provider_signer_ref = COALESCE(?, provider_signer_ref)
                     WHERE id = ? AND request_id = ? AND environment = ? AND cmp_id = ?'
                )->execute([
                    isset($refs[(int) $signer['id']]) ? (string) $refs[(int) $signer['id']] : null,
                    (int) $signer['id'],
                    $id,
                    $ctx->environment,
                    $ctx->cmpId,
                ]);
            }

            $this->setSigningStatus($ctx, $contractId, 'sent');
            $this->moveContract($ctx, $contractId, 'awaiting_signature', 'Sent for signature');

            $this->audit->log($ctx, 'signature_request', $id, 'signature.sent', $contractId, [
                'status'   => ['from' => 'draft', 'to' => 'sent'],
                'provider' => ['from' => $request['provider'], 'to' => $provider->name()],
            ]);
            $this->activity->record($ctx, $contractId, 'signature.sent', (string) $outcome['detail'], [
                'provider'  => $provider->name(),
                'delivered' => (bool) ($outcome['delivered'] ?? false),
            ]);

            $result = $this->findOrFail($ctx, $id);
            // Carried on the response rather than inferred from the provider
            // name, so the screen can say "nothing was emailed" in the same
            // words the provider used.
            $result['delivery'] = [
                'delivered' => (bool) ($outcome['delivered'] ?? false),
                'detail'    => (string) $outcome['detail'],
            ];

            return $result;
        });
    }

    /**
     * Withdraw a request that has not been completed.
     *
     * @return array<string,mixed>
     */
    public function cancel(TenantContext $ctx, int $id, ?string $reason = null): array
    {
        $request = $this->findOrFail($ctx, $id);
        $status  = (string) $request['status'];

        if (in_array($status, self::CLOSED_STATUSES, true)) {
            throw DomainException::conflict(
                sprintf('A %s signature request cannot be cancelled.', str_replace('_', ' ', $status)),
                'SIGNATURE_NOT_CANCELLABLE'
            );
        }

        $reference = (string) ($request['provider_reference'] ?? '');
        if ($reference !== '') {
            $provider = $this->provider ?? SignatureProviderFactory::for((string) $request['provider']);
            // A vendor that refuses the recall is worth knowing about, but it is
            // not a reason to leave the request open in our own record: the
            // company has decided not to sign this document.
            if ($provider !== null && ! $provider->cancel($reference)) {
                $this->activity->record($ctx, (int) $request['contract_id'], 'signature.cancel.provider_refused',
                    'The signature provider did not confirm the recall; the request was cancelled here anyway.');
            }
        }

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $request, $status, $reason): array {
            $contractId = (int) $request['contract_id'];

            $pdo->prepare(
                'UPDATE signature_requests
                 SET status = \'cancelled\', cancelled_at = CURRENT_TIMESTAMP,
                     decline_reason = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$reason, $id, $ctx->environment, $ctx->cmpId]);

            $pdo->prepare(
                'UPDATE signature_signers SET status = \'expired\'
                 WHERE request_id = ? AND environment = ? AND cmp_id = ? AND status IN (\'pending\', \'sent\', \'viewed\')'
            )->execute([$id, $ctx->environment, $ctx->cmpId]);

            $this->setSigningStatus($ctx, $contractId, 'cancelled');

            $this->audit->log($ctx, 'signature_request', $id, 'signature.cancelled', $contractId, [
                'status' => ['from' => $status, 'to' => 'cancelled'],
            ]);
            $this->activity->record($ctx, $contractId, 'signature.cancelled', 'Signature request cancelled',
                array_filter(['reason' => $reason]));

            return $this->findOrFail($ctx, $id);
        });
    }

    /**
     * Record that the agreement was signed.
     *
     * This is the execution event: it dates the agreement, names the people who
     * signed it, and hands the contract to ContractService::changeStatus so
     * going active runs the same path it would from any other route — audit
     * row, obligation generation, renewal cycle.
     *
     * @param  array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function markSigned(TenantContext $ctx, int $id, array $body): array
    {
        $request = $this->findOrFail($ctx, $id);
        $status  = (string) $request['status'];

        if (in_array($status, ['completed', 'cancelled', 'declined'], true)) {
            throw DomainException::conflict(
                sprintf('This signature request is already %s.', $status),
                'SIGNATURE_ALREADY_CLOSED'
            );
        }

        $contractId = (int) $request['contract_id'];

        $v         = new Validator($body);
        $execution = $v->requiredDate('execution_date');
        $evidence  = $v->optionalString('evidence_reference', 255);
        $documentId = $v->optionalId('executed_document_id');
        $signed    = $v->optionalArray('signers', self::MAX_SIGNERS);

        if ($execution !== '' && Dates::daysUntil($execution) > 0) {
            $v->fail('execution_date', 'A contract cannot be executed on a future date.');
        }
        if ($documentId !== null && ! $this->documentBelongsToContract($ctx, $contractId, $documentId)) {
            $v->fail('executed_document_id', 'That document does not belong to this contract.');
        }

        $updates = $this->validateSignedEntries($request, $signed, $execution, $v);
        $v->assert();

        return Database::transaction($this->pdo, function (PDO $pdo) use ($ctx, $id, $request, $status, $contractId, $execution, $evidence, $documentId, $updates): array {
            foreach ($updates['existing'] as $signerId => $signedAt) {
                $pdo->prepare(
                    'UPDATE signature_signers
                     SET status = \'signed\', signed_at = ?
                     WHERE id = ? AND request_id = ? AND environment = ? AND cmp_id = ?'
                )->execute([$signedAt, $signerId, $id, $ctx->environment, $ctx->cmpId]);
            }

            if ($updates['added'] !== []) {
                // Signatories nobody listed up front. The manual path routinely
                // discovers who actually signed only when the executed copy
                // comes back, and dropping them would leave the evidence record
                // naming fewer people than the document does.
                $this->insertSigners($ctx, $id, $updates['added'], 'signed', $execution);
            }

            if ($updates['existing'] === [] && $updates['added'] === []) {
                // "Mark signed" with no signatory detail means the whole
                // envelope was executed. Naming some but not others means
                // exactly that, and the ones left out stay as they are — the
                // evidence record has to be able to say who did not sign.
                $pdo->prepare(
                    'UPDATE signature_signers SET status = \'signed\', signed_at = COALESCE(signed_at, ?)
                     WHERE request_id = ? AND environment = ? AND cmp_id = ? AND status <> \'declined\''
                )->execute([$execution, $id, $ctx->environment, $ctx->cmpId]);
            }

            $pdo->prepare(
                'UPDATE signature_requests
                 SET status = \'completed\', completed_at = CURRENT_TIMESTAMP,
                     executed_document_id = COALESCE(?, executed_document_id),
                     evidence_reference = COALESCE(?, evidence_reference),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$documentId, $evidence, $id, $ctx->environment, $ctx->cmpId]);

            $pdo->prepare(
                'UPDATE contracts
                 SET execution_date = ?, signing_status = \'completed\',
                     updated_by = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND environment = ? AND cmp_id = ?'
            )->execute([$execution, $ctx->uuid, $contractId, $ctx->environment, $ctx->cmpId]);

            $this->audit->log($ctx, 'signature_request', $id, 'signature.executed', $contractId, [
                'status'         => ['from' => $status, 'to' => 'completed'],
                'execution_date' => ['from' => null, 'to' => $execution],
            ]);
            $this->activity->record($ctx, $contractId, 'signature.executed', 'Contract executed on ' . $execution, [
                'signatories' => count($updates['existing']) + count($updates['added']),
            ]);

            $moved  = $this->moveContract($ctx, $contractId, 'active', 'Executed on ' . $execution);
            $result = $this->findOrFail($ctx, $id);

            $result['execution'] = [
                'execution_date'   => $execution,
                'contract_activated' => $moved,
            ];

            return $result;
        });
    }

    // -----------------------------------------------------------------------
    // Inbound provider callbacks
    // -----------------------------------------------------------------------

    /**
     * Record one provider delivery and, if it is new, act on it.
     *
     * The order is the whole design. The delivery is stored under
     * `(provider, event_id)` and committed *before* anything is applied, so a
     * retry — and every provider retries, none guarantees exactly-once — loses
     * the insert to the unique index and returns without touching a thing. A
     * failed apply leaves `processed_at` NULL, which is what
     * `idx_signature_webhooks_unprocessed` is for: an operator can see the
     * delivery that arrived and did not land, rather than having to infer it
     * from a state that never changed.
     *
     * There is no TenantContext here, and there must not be one: the caller is
     * the vendor, authenticated by an HMAC over the body and nothing more. The
     * environment and company come from the request row the event names, which
     * an authenticated user created.
     *
     * @param  array<string,string> $headers
     * @return array{stored: bool, duplicate: bool, applied: bool, event_type: string, request_id: ?int, status: ?string}
     */
    public function recordWebhook(string $providerName, string $rawBody, array $headers): array
    {
        $provider = $this->provider ?? SignatureProviderFactory::for($providerName);
        if ($provider === null) {
            throw DomainException::notFound('Unknown signature provider.');
        }

        if (! $provider->verifyWebhook($rawBody, $headers)) {
            throw new DomainException(
                'The delivery signature did not verify against SIGNATURE_WEBHOOK_SECRET.',
                'WEBHOOK_SIGNATURE_INVALID',
                401
            );
        }

        $decoded = json_decode($rawBody, true);
        $event   = $provider->parseWebhook(is_array($decoded) ? $decoded : []);

        // jsonb refuses a malformed body, and losing the delivery we are
        // arguing about is the worst possible outcome of a vendor dispute, so a
        // body that is not JSON is wrapped rather than dropped.
        $payload = json_last_error() === JSON_ERROR_NONE && $rawBody !== ''
            ? $rawBody
            : (string) json_encode(['unparsed' => mb_substr($rawBody, 0, 10000)], JSON_UNESCAPED_SLASHES);

        $eventId = trim((string) ($event['event_id'] ?? ''));
        if ($eventId === '') {
            // A vendor that does not number its deliveries still gets
            // exactly-once semantics: an identical retried body hashes the same.
            $eventId = 'sha256:' . hash('sha256', $rawBody);
        }
        $eventId = mb_substr($eventId, 0, 160);

        $reference = trim((string) ($event['provider_reference'] ?? ''));
        $request   = $reference === '' ? null : $this->requestByReference($provider->name(), $reference);

        $stored = $this->storeDelivery(
            $provider->name(),
            $eventId,
            $request,
            (string) ($event['event_type'] ?? 'unknown'),
            $payload
        );

        if ($stored === null) {
            return [
                'stored'     => true,
                'duplicate'  => true,
                'applied'    => false,
                'event_type' => (string) ($event['event_type'] ?? 'unknown'),
                'request_id' => $request === null ? null : (int) $request['id'],
                'status'     => $request === null ? null : (string) $request['status'],
            ];
        }

        if ($request === null) {
            $this->closeDelivery($stored, 'No signature request matches provider reference "' . $reference . '".');

            return [
                'stored'     => true,
                'duplicate'  => false,
                'applied'    => false,
                'event_type' => (string) ($event['event_type'] ?? 'unknown'),
                'request_id' => null,
                'status'     => null,
            ];
        }

        try {
            $applied = Database::transaction($this->pdo, fn (): bool => $this->applyEvent($request, $event));
            $this->closeDelivery($stored, null);
        } catch (Throwable $e) {
            $this->closeDelivery($stored, $e->getMessage(), false);

            throw $e;
        }

        $after = $this->requestRow((int) $request['id']);

        return [
            'stored'     => true,
            'duplicate'  => false,
            'applied'    => $applied,
            'event_type' => (string) ($event['event_type'] ?? 'unknown'),
            'request_id' => (int) $request['id'],
            'status'     => $after === null ? null : (string) $after['status'],
        ];
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Insert the delivery, or report that it was already here.
     *
     * @param  array<string,mixed>|null $request
     * @return int|null the new row id, or null when this delivery is a repeat
     */
    private function storeDelivery(string $provider, string $eventId, ?array $request, string $eventType, string $payload): ?int
    {
        $st = $this->pdo->prepare(
            'INSERT INTO signature_webhook_events
             (environment, provider, event_id, request_id, event_type, payload, signature_valid)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, TRUE)
             ON CONFLICT (provider, event_id) DO NOTHING
             RETURNING id'
        );
        $st->execute([
            $request === null ? Environment::resolve() : (string) $request['environment'],
            $provider,
            $eventId,
            $request === null ? null : (int) $request['id'],
            mb_substr($eventType, 0, 64),
            // The body is stored as sent. A vendor's dispute is settled by what
            // it actually delivered, not by our reading of it.
            $payload,
        ]);

        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function closeDelivery(int $eventRowId, ?string $error, bool $processed = true): void
    {
        try {
            $this->pdo->prepare(
                'UPDATE signature_webhook_events SET processed_at = ?, error_message = ? WHERE id = ?'
            )->execute([
                $processed ? gmdate('Y-m-d H:i:s') : null,
                $error === null ? null : mb_substr($error, 0, 2000),
                $eventRowId,
            ]);
        } catch (Throwable $e) {
            error_log('[contracts][signature] could not close webhook delivery ' . $eventRowId . ': ' . $e->getMessage());
        }
    }

    /**
     * Apply one parsed event to the signature record.
     *
     * Signer and request state only. Completion is recorded on the request and
     * on `contracts.signing_status`; it does not make the contract active —
     * see the class docblock.
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $event
     */
    private function applyEvent(array $request, array $event): bool
    {
        $requestId = (int) $request['id'];
        $type      = (string) ($event['event_type'] ?? '');
        $occurred  = $this->timestamp($event['occurred_at'] ?? null);

        $signerId = $this->signerFor($requestId, $event);

        $applied = match ($type) {
            'viewed'  => $this->stampSigner($requestId, $signerId, 'viewed', 'viewed_at', $occurred)
                         && $this->advanceRequest($requestId, 'viewed', ['sent']),
            'signed'  => $this->stampSigner($requestId, $signerId, 'signed', 'signed_at', $occurred)
                         && $this->settleAfterSignature($request),
            'completed' => $this->completeRequest($request, $occurred),
            'declined' => $this->declineRequest($request, $signerId, (string) ($event['decline_reason'] ?? ''), $occurred),
            'expired' => $this->advanceRequest($requestId, 'expired', self::OPEN_STATUSES),
            default   => false,
        };

        if ($applied) {
            $this->activity->recordSystem(
                (string) $request['environment'],
                (int) $request['cmp_id'],
                (int) $request['contract_id'],
                'signature.webhook.' . $type,
                'Signature provider reported: ' . str_replace('_', ' ', $type)
            );
        }

        return $applied;
    }

    /** @param array<string,mixed> $event */
    private function signerFor(int $requestId, array $event): ?int
    {
        $ref   = trim((string) ($event['signer_reference'] ?? ''));
        $email = trim((string) ($event['signer_email'] ?? ''));

        if ($ref !== '') {
            $st = $this->pdo->prepare(
                'SELECT id FROM signature_signers WHERE request_id = ? AND provider_signer_ref = ? LIMIT 1'
            );
            $st->execute([$requestId, $ref]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        if ($email !== '') {
            $st = $this->pdo->prepare(
                'SELECT id FROM signature_signers WHERE request_id = ? AND lower(email) = lower(?) ORDER BY signer_order, id LIMIT 1'
            );
            $st->execute([$requestId, $email]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        return null;
    }

    private function stampSigner(int $requestId, ?int $signerId, string $status, string $column, ?string $at): bool
    {
        if ($signerId === null) {
            // The envelope moved but we cannot say who moved it. Recorded at
            // the request level rather than guessed at a signer, because
            // attributing a signature to the wrong person is worse than not
            // attributing it at all.
            return true;
        }

        $this->pdo->prepare(
            "UPDATE signature_signers SET status = ?, {$column} = COALESCE({$column}, ?)
             WHERE id = ? AND request_id = ?"
        )->execute([$status, $at ?? date('Y-m-d H:i:s'), $signerId, $requestId]);

        return true;
    }

    /** @param list<string> $from */
    private function advanceRequest(int $requestId, string $status, array $from): bool
    {
        $st = $this->pdo->prepare(
            "UPDATE signature_requests SET status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status IN (" . self::placeholders($from) . ")"
        );
        $st->execute(array_merge([$status, $requestId], $from));

        return $st->rowCount() > 0;
    }

    /** @param array<string,mixed> $request */
    private function settleAfterSignature(array $request): bool
    {
        $requestId = (int) $request['id'];

        $st = $this->pdo->prepare(
            'SELECT COUNT(*) FILTER (WHERE status <> \'signed\') AS outstanding FROM signature_signers WHERE request_id = ?'
        );
        $st->execute([$requestId]);
        $outstanding = (int) $st->fetchColumn();

        if ($outstanding > 0) {
            // A sequential envelope hands the document to the next person as
            // soon as the previous one signs; without this they never receive it.
            if (ContractService::toBool($request['is_sequential'] ?? false)) {
                $this->pdo->prepare(
                    'UPDATE signature_signers SET status = \'sent\', sent_at = COALESCE(sent_at, CURRENT_TIMESTAMP)
                     WHERE id = (SELECT id FROM signature_signers
                                 WHERE request_id = ? AND status = \'pending\'
                                 ORDER BY signer_order, id LIMIT 1)'
                )->execute([$requestId]);
            }

            return $this->advanceRequest($requestId, 'partially_signed', ['sent', 'viewed', 'partially_signed']);
        }

        return $this->advanceRequest($requestId, 'signed', ['sent', 'viewed', 'partially_signed']);
    }

    /** @param array<string,mixed> $request */
    private function completeRequest(array $request, ?string $at): bool
    {
        $requestId = (int) $request['id'];

        $st = $this->pdo->prepare(
            'UPDATE signature_requests
             SET status = \'completed\', completed_at = COALESCE(completed_at, ?), updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status <> \'completed\''
        );
        $st->execute([$at ?? date('Y-m-d H:i:s'), $requestId]);

        if ($st->rowCount() === 0) {
            return false;
        }

        $this->pdo->prepare(
            'UPDATE signature_signers SET status = \'signed\', signed_at = COALESCE(signed_at, ?)
             WHERE request_id = ? AND status <> \'declined\''
        )->execute([$at ?? date('Y-m-d H:i:s'), $requestId]);

        // The contract's signing_status, not its status: the vendor has told us
        // the envelope is complete, and a person still confirms execution.
        $this->pdo->prepare(
            'UPDATE contracts SET signing_status = \'signed\', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([(int) $request['contract_id'], (string) $request['environment'], (int) $request['cmp_id']]);

        return true;
    }

    /** @param array<string,mixed> $request */
    private function declineRequest(array $request, ?int $signerId, string $reason, ?string $at): bool
    {
        $requestId = (int) $request['id'];

        if ($signerId !== null) {
            $this->pdo->prepare(
                'UPDATE signature_signers
                 SET status = \'declined\', declined_at = COALESCE(declined_at, ?), decline_reason = ?
                 WHERE id = ? AND request_id = ?'
            )->execute([$at ?? date('Y-m-d H:i:s'), $reason === '' ? null : $reason, $signerId, $requestId]);
        }

        $st = $this->pdo->prepare(
            "UPDATE signature_requests
             SET status = 'declined', decline_reason = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status IN (" . self::placeholders(self::OPEN_STATUSES) . ")"
        );
        $st->execute(array_merge([$reason === '' ? null : $reason, $requestId], self::OPEN_STATUSES));

        if ($st->rowCount() === 0) {
            return false;
        }

        $this->pdo->prepare(
            'UPDATE contracts SET signing_status = \'declined\', updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([(int) $request['contract_id'], (string) $request['environment'], (int) $request['cmp_id']]);

        return true;
    }

    /**
     * The request a vendor's own reference names.
     *
     * @audit-unscoped Webhook path: the caller is the provider, so there is no
     *                 TenantContext to scope by — resolving which tenant the
     *                 delivery belongs to is the whole purpose of this lookup.
     *                 The reference is vendor-issued and paired with the
     *                 provider slug, and every value read off the row
     *                 afterwards (environment, cmp_id) comes from the row
     *                 itself rather than from the delivery.
     *
     * @return array<string,mixed>|null the raw row
     */
    private function requestByReference(string $provider, string $reference): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM signature_requests WHERE provider = ? AND provider_reference = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$provider, $reference]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Re-read a request by id after a webhook applied to it.
     *
     * @audit-unscoped Webhook path: the id came from a row this class just
     *                 resolved, never from the delivery, and there is no
     *                 TenantContext on this path to scope by.
     *
     * @return array<string,mixed>|null
     */
    private function requestRow(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM signature_requests WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Move the contract, but only where the lifecycle graph allows it.
     *
     * Guarded rather than attempted blindly: recording an executed signature
     * must not fail because the contract sits in a state the signature does not
     * control. The caller is told whether the move happened.
     */
    private function moveContract(TenantContext $ctx, int $contractId, string $to, string $note): bool
    {
        $contracts = new ContractService($this->pdo);
        $current   = (string) $contracts->findOrFail($ctx, $contractId)['status'];

        if ($current === $to || ! ContractService::transitionAllowed($current, $to)) {
            return false;
        }

        $contracts->changeStatus($ctx, $contractId, $to, $note);

        return true;
    }

    private function setSigningStatus(TenantContext $ctx, int $contractId, string $signingStatus): void
    {
        $this->pdo->prepare(
            'UPDATE contracts SET signing_status = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND environment = ? AND cmp_id = ?'
        )->execute([$signingStatus, $ctx->uuid, $contractId, $ctx->environment, $ctx->cmpId]);
    }

    /**
     * @param  list<mixed> $raw
     * @return list<array<string,mixed>>
     */
    private function validateSigners(TenantContext $ctx, int $contractId, array $raw, Validator $v): array
    {
        $out = [];

        foreach ($raw as $index => $entry) {
            if (! is_array($entry)) {
                $v->fail('signers', 'Each signatory must be an object.');
                continue;
            }

            $inner = new Validator($entry);
            $name  = $inner->requiredString('name', 160);
            if ($inner->failed()) {
                $v->fail('signers', sprintf('Signatory %d needs a name.', $index + 1));
                continue;
            }

            $partyId = $inner->optionalId('party_id');
            if ($partyId !== null && ! $this->partyBelongsToContract($ctx, $contractId, $partyId)) {
                $v->fail('signers', sprintf('Signatory %d refers to a party on another contract.', $index + 1));
                continue;
            }

            $out[] = [
                'name'         => $name,
                'email'        => $inner->optionalEmail('email'),
                'phone'        => $inner->optionalString('phone', 48),
                'designation'  => $inner->optionalString('designation', 160),
                'side'         => $inner->optionalEnum('side', ['company', 'counterparty', 'witness', 'other'], 'counterparty') ?? 'counterparty',
                'signer_order' => $inner->optionalInt('signer_order', 1, 99, $index + 1) ?? ($index + 1),
                'party_id'     => $partyId,
            ];

            foreach ($inner->errors() as $field => $message) {
                $v->fail('signers', sprintf('Signatory %d: %s (%s)', $index + 1, $message, $field));
            }
        }

        // Sorted here rather than at read time so that the stored order is the
        // signing order a sequential envelope actually follows.
        usort($out, static fn (array $a, array $b): int => $a['signer_order'] <=> $b['signer_order']);

        return $out;
    }

    /**
     * Split the `signers` of a mark-signed call into updates and additions.
     *
     * @param  array<string,mixed> $request
     * @param  list<mixed>         $entries
     * @return array{existing: array<int,string>, added: list<array<string,mixed>>}
     */
    private function validateSignedEntries(array $request, array $entries, string $execution, Validator $v): array
    {
        $known = [];
        foreach ($request['signers'] as $signer) {
            $known[(int) $signer['id']] = true;
        }

        $existing = [];
        $added    = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                $v->fail('signers', 'Each signatory must be an object.');
                continue;
            }

            $inner = new Validator($entry);
            $id    = $inner->optionalId('id');
            $at    = $inner->optionalDate('signed_at', $execution) ?? $execution;

            if ($id !== null) {
                if (! isset($known[$id])) {
                    $v->fail('signers', sprintf('Signatory %d is not on this signature request.', $index + 1));
                    continue;
                }
                $existing[$id] = $at;
                continue;
            }

            $name = $inner->requiredString('name', 160);
            if ($inner->failed()) {
                $v->fail('signers', sprintf('Signatory %d needs an id or a name.', $index + 1));
                continue;
            }

            $added[] = [
                'name'         => $name,
                'email'        => $inner->optionalEmail('email'),
                'phone'        => null,
                'designation'  => $inner->optionalString('designation', 160),
                'side'         => $inner->optionalEnum('side', ['company', 'counterparty', 'witness', 'other'], 'counterparty') ?? 'counterparty',
                'signer_order' => count($known) + $index + 1,
                'party_id'     => null,
                'signed_at'    => $at,
            ];
        }

        return ['existing' => $existing, 'added' => $added];
    }

    /** @param list<array<string,mixed>> $signers */
    private function insertSigners(TenantContext $ctx, int $requestId, array $signers, string $status = 'pending', ?string $signedAt = null): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO signature_signers
             (request_id, environment, cmp_id, party_id, signer_order, name, email, phone, designation, side, status, signed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($signers as $signer) {
            $st->execute([
                $requestId,
                $ctx->environment,
                $ctx->cmpId,
                $signer['party_id'] ?? null,
                $signer['signer_order'],
                $signer['name'],
                $signer['email'] ?? null,
                $signer['phone'] ?? null,
                $signer['designation'] ?? null,
                $signer['side'],
                $status,
                $status === 'signed' ? ($signer['signed_at'] ?? $signedAt) : null,
            ]);
        }
    }

    private function partyBelongsToContract(TenantContext $ctx, int $contractId, int $partyId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_parties WHERE id = ? AND contract_id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$partyId, $contractId, $ctx->environment, $ctx->cmpId]);

        return $st->fetchColumn() !== false;
    }

    private function versionBelongsToContract(TenantContext $ctx, int $contractId, int $versionId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_document_versions v
             JOIN contract_documents d ON d.id = v.document_id
             WHERE v.id = ? AND d.contract_id = ? AND v.environment = ? AND v.cmp_id = ? LIMIT 1'
        );
        $st->execute([$versionId, $contractId, $ctx->environment, $ctx->cmpId]);

        return $st->fetchColumn() !== false;
    }

    private function documentBelongsToContract(TenantContext $ctx, int $contractId, int $documentId): bool
    {
        $st = $this->pdo->prepare(
            'SELECT 1 FROM contract_documents WHERE id = ? AND contract_id = ? AND environment = ? AND cmp_id = ? LIMIT 1'
        );
        $st->execute([$documentId, $contractId, $ctx->environment, $ctx->cmpId]);

        return $st->fetchColumn() !== false;
    }

    /**
     * `?, ?, ?` for a fixed status list.
     *
     * Every call site passes one of this class's own constants, never caller
     * input, and the values still travel as bound parameters — the only thing
     * interpolated is the count.
     *
     * @param list<string> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }

    /** Normalise a provider's timestamp to something PostgreSQL will take, or null. */
    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $ts = strtotime($value);

        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(TenantContext $ctx, array $row): array
    {
        $row['id']            = (int) $row['id'];
        $row['contract_id']   = (int) $row['contract_id'];
        $row['is_sequential'] = ContractService::toBool($row['is_sequential']);

        $st = $this->pdo->prepare(
            'SELECT id, party_id, signer_order, name, email, phone, designation, side, status,
                    sent_at, viewed_at, signed_at, declined_at, decline_reason, provider_signer_ref
             FROM signature_signers
             WHERE request_id = ? AND environment = ? AND cmp_id = ?
             ORDER BY signer_order, id'
        );
        $st->execute([$row['id'], $ctx->environment, $ctx->cmpId]);

        $row['signers'] = array_map(static function (array $signer): array {
            $signer['id']           = (int) $signer['id'];
            $signer['signer_order'] = (int) $signer['signer_order'];

            return $signer;
        }, $st->fetchAll() ?: []);

        $row['outstanding'] = count(array_filter(
            $row['signers'],
            static fn (array $s): bool => ! in_array((string) $s['status'], ['signed', 'declined'], true)
        ));

        return $row;
    }
}
