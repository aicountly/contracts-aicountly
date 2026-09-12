<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Response;
use App\Services\SignatureService;
use App\Signature\SignatureProviderFactory;
use App\Support\Permissions;

/**
 * Signature requests, and the one endpoint in this API that has no session.
 *
 * Everything a person does here needs `signature.act`, which is granted
 * narrowly: sending a contract out for signature and recording that it was
 * executed are the two actions that decide what the company is bound by.
 * Reading the list only needs `contract.view`, because knowing who has yet to
 * sign is ordinary contract information.
 *
 * `webhook()` is the exception and is documented as one at the route. A vendor
 * has no session to present, so it is authenticated by an HMAC over the exact
 * bytes it sent and by nothing else — see SignatureService::recordWebhook for
 * why the delivery is stored before it is acted on.
 */
final class SignatureController extends BaseController
{
    /** Beyond this a delivery is not a signature callback, and hashing it is free work. */
    private const MAX_WEBHOOK_BYTES = 1048576;

    public function index(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);

        $this->respond(fn (): array => [
            'items'    => $this->service()->listForContract($ctx, $contractId),
            'provider' => SignatureProviderFactory::status(),
        ]);
    }

    public function store(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::SIGNATURE_ACT);

        $this->respond(fn () => $this->service()->create($ctx, $this->intId($id), $this->body()), 201);
    }

    public function send(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::SIGNATURE_ACT);

        // Sending costs the company an envelope with a vendor and puts a
        // document in front of a counterparty; a loop that re-sends it is a
        // support call, not a bug report.
        $this->rateLimit('signature.send', 30, 3600);

        $this->respond(fn () => $this->service()->send($ctx, $this->intId($id)));
    }

    public function cancel(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::SIGNATURE_ACT);
        $body   = $this->body();
        $reason = isset($body['reason']) && is_string($body['reason'])
            ? mb_substr(trim($body['reason']), 0, 1000)
            : null;

        $this->respond(fn () => $this->service()->cancel($ctx, $this->intId($id), $reason));
    }

    public function markSigned(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::SIGNATURE_ACT);

        $this->respond(fn () => $this->service()->markSigned($ctx, $this->intId($id), $this->body()));
    }

    /**
     * A provider callback.
     *
     * UNAUTHENTICATED BY NECESSITY. The provider holds no session key and can
     * present no company context, so the usual gate cannot run and this is the
     * only action in the API without one. Three things stand in its place:
     *
     *   1. the delivery must carry a valid signature over its raw body,
     *   2. it is written to `signature_webhook_events` under
     *      `(provider, event_id)` before anything is applied,
     *   3. a repeat of an event we already hold returns 200 and does nothing.
     *
     * The reply is deliberately terse. A provider retries on anything that is
     * not a 2xx, and telling an unauthenticated caller which contract its
     * reference resolved to would make this endpoint an oracle over the whole
     * estate.
     *
     * @audit-unauthenticated the three guarantees above are what stand in for
     *                         the session check, and they are why this action
     *                         is exempt rather than unguarded.
     */
    public function webhook(?string $provider = null): void
    {
        $name = strtolower(trim((string) $provider));
        if ($name === '' || ! preg_match('/^[a-z0-9_-]{1,32}$/', $name)) {
            Response::notFound();
        }

        $raw = file_get_contents('php://input');
        $raw = is_string($raw) ? $raw : '';

        if (strlen($raw) > self::MAX_WEBHOOK_BYTES) {
            Response::error('PAYLOAD_TOO_LARGE', 'That delivery is too large to be a signature callback.', 413);
        }

        $service = SignatureService::make();
        if ($service === null) {
            // 503 rather than 500: the provider should retry this one, because
            // the delivery is fine and only this host is not.
            Response::error('DB_UNAVAILABLE', 'Storage is unavailable; retry this delivery.', 503);
        }

        $result = $this->run(fn (): array => $service->recordWebhook($name, $raw, self::headers()));

        // 200 for a duplicate as much as for a fresh event: a provider that
        // gets anything else will keep redelivering an event already applied.
        Response::success([
            'received'  => true,
            'duplicate' => $result['duplicate'],
            'applied'   => $result['applied'],
        ]);
    }

    /**
     * Inbound headers, lower-cased.
     *
     * Rebuilt from `$_SERVER` rather than taken from `getallheaders()` because
     * that function does not exist under every SAPI this deploys to, and a
     * signature check that silently sees no headers would fail every delivery.
     *
     * @return array<string,string>
     */
    private static function headers(): array
    {
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (! is_string($key) || ! is_string($value) || ! str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $out[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
        }

        return $out;
    }

    private function service(): SignatureService
    {
        return new SignatureService($this->db());
    }
}
