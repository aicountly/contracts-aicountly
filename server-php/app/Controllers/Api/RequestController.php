<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ContractRequestService;
use App\Support\Enums;
use App\Support\Permissions;

/**
 * The contract request resource — the intake queue ahead of a draft.
 *
 * Raising a request and reviewing one are separate grants, which is the point
 * of the whole queue: the person who wants the agreement is not the person who
 * decides it should exist. Converting needs the grant to create a contract,
 * because that is what conversion produces.
 */
final class RequestController extends BaseController
{
    public function index(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $result = $this->run(fn () => $this->service()->search(
            $ctx,
            $this->filters(),
            $page['per_page'],
            $page['offset']
        ));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function store(): void
    {
        $ctx = $this->requirePermission(Permissions::REQUEST_CREATE);

        $this->respond(fn () => $this->service()->create($ctx, $this->body()), 201);
    }

    public function show(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $requestId = $this->intId($id);

        $this->respond(function () use ($ctx, $requestId): array {
            $service = $this->service();
            $request = $service->findOrFail($ctx, $requestId);

            // The timeline ships with the record: the request screen is a
            // single panel, and a second round trip for a list this short buys
            // nothing.
            $request['activity'] = $service->activityFor($ctx, $requestId, 50);

            return $request;
        });
    }

    public function update(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::REQUEST_CREATE);

        $this->respond(fn () => $this->service()->update($ctx, $this->intId($id), $this->body()));
    }

    public function submit(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::REQUEST_CREATE);

        $this->respond(fn () => $this->service()->submit($ctx, $this->intId($id)));
    }

    public function decision(?string $id = null): void
    {
        $ctx  = $this->requirePermission(Permissions::REQUEST_REVIEW);
        $body = $this->body();

        $decision = Enums::coerce($body['decision'] ?? null, ContractRequestService::decisions());
        if ($decision === null) {
            Response::validationError([
                'decision' => 'Choose one of: ' . implode(', ', ContractRequestService::decisions()) . '.',
            ]);
        }

        $this->respond(fn () => $this->service()->decide($ctx, $this->intId($id), $decision, $body));
    }

    /**
     * Turn an approved request into a contract.
     *
     * Rate-limited because each call that gets through allocates a contract
     * number: a client retrying a slow response would otherwise burn the
     * company's numbering sequence.
     */
    public function convert(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_CREATE);
        $this->rateLimit('requests.convert', 20, 300);

        $this->respond(fn () => $this->service()->convert($ctx, $this->intId($id), $this->body()), 201);
    }

    /** @return array<string,mixed> */
    private function filters(): array
    {
        $statuses = array_values(array_filter(
            Request::queryList('status'),
            static fn (string $s): bool => Enums::isValid($s, Enums::REQUEST_STATUSES)
        ));

        return [
            'q'                => Request::query('q'),
            'status'           => $statuses,
            'requester'        => Request::query('requester'),
            'reviewer'         => Request::query('reviewer'),
            'contract_type_id' => Request::query('contract_type_id'),
            'department_id'    => Request::query('department_id'),
            'required_by'      => self::date(Request::query('required_by')),
            'sort'             => Request::query('sort'),
            'dir'              => Request::query('dir'),
        ];
    }

    private static function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    private function service(): ContractRequestService
    {
        return new ContractRequestService($this->db());
    }
}
