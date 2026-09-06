<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\MilestoneService;
use App\Services\ObligationService;
use App\Support\Enums;
use App\Support\Permissions;

/**
 * Obligations, their occurrences and contractual milestones.
 *
 * The split matters: an obligation is the rule ("submit a quarterly SLA
 * report"), an occurrence is one instance of it falling due. The portfolio
 * queue lists occurrences, because that is what someone has to act on.
 */
final class ObligationController extends BaseController
{
    /** The portfolio queue — occurrences across every contract. */
    public function index(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $filters = [
            'status'             => Enums::coerce(Request::query('status'), Enums::OBLIGATION_STATUSES),
            'due_from'           => self::date(Request::query('due_from')),
            'due_to'             => self::date(Request::query('due_to')),
            'contract_id'        => Request::query('contract_id'),
            'owner_uuid'         => Request::query('owner_uuid'),
            'responsible_party'  => Enums::coerce(Request::query('responsible_party'), Enums::OBLIGATION_RESPONSIBLE),
            'overdue_only'       => Request::query('overdue_only') === '1',
            'mine_only'          => Request::query('mine_only') === '1',
        ];

        $result = $this->run(
            fn () => $this->obligations()->listOccurrences($ctx, $filters, $page['per_page'], $page['offset'])
        );

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function forContract(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->obligations()->listForContract($ctx, $this->intId($id)));
    }

    public function store(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::OBLIGATION_MANAGE);

        $this->respond(fn () => $this->obligations()->create($ctx, $this->intId($id), $this->body()), 201);
    }

    public function update(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::OBLIGATION_MANAGE);

        $this->respond(fn () => $this->obligations()->update($ctx, $this->intId($id), $this->body()));
    }

    public function destroy(?string $id = null): void
    {
        $ctx          = $this->requirePermission(Permissions::OBLIGATION_MANAGE);
        $obligationId = $this->intId($id);

        $this->run(function () use ($ctx, $obligationId): bool {
            $this->obligations()->delete($ctx, $obligationId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    public function generate(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::OBLIGATION_MANAGE);

        $this->respond(fn () => ['generated' => $this->obligations()->generateOccurrences($ctx, $this->intId($id))]);
    }

    public function completeOccurrence(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::OBLIGATION_MANAGE);

        $this->respond(fn () => $this->obligations()->completeOccurrence($ctx, $this->intId($id), $this->body()));
    }

    public function occurrenceStatus(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::OBLIGATION_MANAGE);
        $body   = $this->body();
        $status = Enums::coerce($body['status'] ?? null, Enums::OBLIGATION_STATUSES);

        if ($status === null) {
            Response::validationError(['status' => 'Choose a valid obligation status.']);
        }

        $note = isset($body['note']) && is_string($body['note']) ? mb_substr(trim($body['note']), 0, 1000) : null;

        $this->respond(fn () => $this->obligations()->updateOccurrenceStatus($ctx, $this->intId($id), $status, $note));
    }

    // --- Milestones ---------------------------------------------------------

    public function milestones(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->milestoneService()->listForContract($ctx, $this->intId($id)));
    }

    public function storeMilestone(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::OBLIGATION_MANAGE);

        $this->respond(fn () => $this->milestoneService()->create($ctx, $this->intId($id), $this->body()), 201);
    }

    public function updateMilestone(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::OBLIGATION_MANAGE);

        $this->respond(fn () => $this->milestoneService()->update($ctx, $this->intId($id), $this->body()));
    }

    public function destroyMilestone(?string $id = null): void
    {
        $ctx         = $this->requirePermission(Permissions::OBLIGATION_MANAGE);
        $milestoneId = $this->intId($id);

        $this->run(function () use ($ctx, $milestoneId): bool {
            $this->milestoneService()->delete($ctx, $milestoneId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    public function completeMilestone(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::OBLIGATION_MANAGE);

        $this->respond(fn () => $this->milestoneService()->complete($ctx, $this->intId($id), $this->body()));
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function obligations(): ObligationService
    {
        return new ObligationService($this->db());
    }

    private function milestoneService(): MilestoneService
    {
        return new MilestoneService($this->db());
    }
}
