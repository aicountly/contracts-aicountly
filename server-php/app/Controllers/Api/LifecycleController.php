<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\AmendmentService;
use App\Services\RenewalService;
use App\Services\TerminationService;
use App\Support\Enums;
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

    /**
     * The amendment register — every amendment across the portfolio.
     *
     * Joined to the contract because the question this screen answers is "what
     * has been changed, and to what", not "what has changed about this one
     * agreement" — that is the contract's own Amendments tab.
     */
    public function amendmentRegister(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);
        $pdo  = $this->db();

        $clauses = ['a.environment = :env', 'a.cmp_id = :cmp', 'c.archived_at IS NULL'];
        $params  = ['env' => $ctx->environment, 'cmp' => $ctx->cmpId];

        $status = Enums::coerce(Request::query('status'), Enums::AMENDMENT_STATUSES);
        if ($status !== null) {
            $clauses[]        = 'a.status = :status';
            $params['status'] = $status;
        }

        $contractId = Request::query('contract_id');
        if ($contractId !== null && ctype_digit($contractId)) {
            $clauses[]             = 'a.contract_id = :contract_id';
            $params['contract_id'] = (int) $contractId;
        }

        if (! $ctx->has(Permissions::CONTRACT_VIEW_ALL)) {
            $clauses[]       = '(c.owner_uuid = :self OR c.created_by = :self2)';
            $params['self']  = $ctx->uuid;
            $params['self2'] = $ctx->uuid;
        }

        $where = 'WHERE ' . implode(' AND ', $clauses);

        $countSt = $pdo->prepare(
            "SELECT COUNT(*) FROM contract_amendments a JOIN contracts c ON c.id = a.contract_id {$where}"
        );
        $countSt->execute($params);
        $total = (int) $countSt->fetchColumn();

        $st = $pdo->prepare(
            "SELECT a.id, a.uuid, a.contract_id, a.amendment_no, a.title, a.description,
                    a.effective_date, a.execution_date, a.status, a.applied_at, a.created_at,
                    c.contract_number, c.title AS contract_title, c.counterparty_name,
                    c.status AS contract_status
             FROM contract_amendments a
             JOIN contracts c ON c.id = a.contract_id
             {$where}
             ORDER BY a.effective_date DESC NULLS LAST, a.id DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $key => $value) {
            $st->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->bindValue(':lim', $page['per_page'], \PDO::PARAM_INT);
        $st->bindValue(':off', $page['offset'], \PDO::PARAM_INT);
        $st->execute();

        Response::paginated($st->fetchAll() ?: [], $total, $page['page'], $page['per_page']);
    }

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
