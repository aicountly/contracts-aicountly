<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\AmendmentService;
use App\Services\RenewalService;
use App\Services\TerminationService;
use App\Support\Permissions;

/**
 * Renewal, amendment and termination.
 *
 * Grouped because they share one rule: the original agreement is never
 * rewritten. Each of these records something *about* a contract, so "what did
 * we agree to, and when did it change" stays answerable years later.
 */
final class LifecycleController extends BaseController
{
    // --- Renewals -----------------------------------------------------------

    public function renewalPipeline(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $filters = [
            'bucket'     => Request::query('bucket'),
            'status'     => Request::query('status'),
            'owner_uuid' => Request::query('owner_uuid'),
            'q'          => Request::query('q'),
        ];

        $result = $this->run(fn () => $this->renewals()->pipeline($ctx, $filters, $page['per_page'], $page['offset']));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function renewalsForContract(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->renewals()->listForContract($ctx, $this->intId($id)));
    }

    public function ensureRenewalCycle(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::RENEWAL_MANAGE);

        $this->respond(fn () => $this->renewals()->ensureCycle($ctx, $this->intId($id)));
    }

    public function renewalDecision(?string $id = null): void
    {
        $ctx      = $this->requirePermission(Permissions::RENEWAL_MANAGE);
        $body     = $this->body();
        $decision = is_string($body['decision'] ?? null) ? strtolower(trim($body['decision'])) : '';

        if (! in_array($decision, ['renew', 'renegotiate', 'terminate', 'defer'], true)) {
            Response::validationError(['decision' => 'Choose renew, renegotiate, terminate or defer.']);
        }

        // Deciding to terminate at renewal is still a termination, and the
        // grant for that is deliberately separate from managing renewals.
        if ($decision === 'terminate' && ! $ctx->has(Permissions::CONTRACT_TERMINATE)) {
            Response::forbidden('Your Contracts role does not allow terminating a contract.');
        }

        $this->respond(fn () => $this->renewals()->recordDecision($ctx, $this->intId($id), $decision, $body));
    }

    // --- Amendments ---------------------------------------------------------

    public function amendmentsForContract(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->amendments()->listForContract($ctx, $this->intId($id)));
    }

    public function storeAmendment(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AMENDMENT_MANAGE);

        $this->respond(fn () => $this->amendments()->create($ctx, $this->intId($id), $this->body()), 201);
    }

    public function updateAmendment(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AMENDMENT_MANAGE);

        $this->respond(fn () => $this->amendments()->update($ctx, $this->intId($id), $this->body()));
    }

    public function destroyAmendment(?string $id = null): void
    {
        $ctx         = $this->requirePermission(Permissions::AMENDMENT_MANAGE);
        $amendmentId = $this->intId($id);

        $this->run(function () use ($ctx, $amendmentId): bool {
            $this->amendments()->delete($ctx, $amendmentId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    public function applyAmendment(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AMENDMENT_MANAGE);

        $this->respond(fn () => $this->amendments()->apply($ctx, $this->intId($id)));
    }

    public function effectivePosition(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->amendments()->effectivePosition($ctx, $this->intId($id)));
    }

    // --- Terminations -------------------------------------------------------

    public function terminationsForContract(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->terminations()->listForContract($ctx, $this->intId($id)));
    }

    public function storeTermination(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_TERMINATE);

        $this->respond(fn () => $this->terminations()->create($ctx, $this->intId($id), $this->body()), 201);
    }

    public function updateTermination(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_TERMINATE);

        $this->respond(fn () => $this->terminations()->update($ctx, $this->intId($id), $this->body()));
    }

    public function approveTermination(?string $id = null): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_TERMINATE);
        $note = $this->body()['note'] ?? null;

        $this->respond(fn () => $this->terminations()->approve(
            $ctx,
            $this->intId($id),
            is_string($note) ? mb_substr($note, 0, 1000) : null
        ));
    }

    public function issueTerminationNotice(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_TERMINATE);

        $this->respond(fn () => $this->terminations()->issueNotice($ctx, $this->intId($id), $this->body()));
    }

    public function completeTermination(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_TERMINATE);

        $this->respond(fn () => $this->terminations()->complete($ctx, $this->intId($id), $this->body()));
    }

    private function renewals(): RenewalService
    {
        return new RenewalService($this->db());
    }

    private function amendments(): AmendmentService
    {
        return new AmendmentService($this->db());
    }

    private function terminations(): TerminationService
    {
        return new TerminationService($this->db());
    }
}
