<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\TemplateService;
use App\Support\Enums;
use App\Support\Permissions;

/**
 * Contract templates and the merge variables they may reference.
 *
 * Reading a template is a drafting activity and writing one is configuration,
 * so the two are granted separately: everyone who can raise a contract needs
 * the template list, and almost nobody should be able to change the wording it
 * produces.
 */
final class TemplateController extends BaseController
{
    public function index(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $filters = [
            'q'                => Request::query('q'),
            'status'           => Enums::coerce(Request::query('status'), Enums::TEMPLATE_STATUSES),
            'contract_type_id' => self::optionalId(Request::query('contract_type_id')),
            'archived'         => Request::query('archived'),
        ];

        $result = $this->run(fn () => $this->templates()->search($ctx, $filters, $page['per_page'], $page['offset']));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function store(): void
    {
        $ctx = $this->requirePermission(Permissions::TEMPLATE_MANAGE);

        $this->respond(fn () => $this->templates()->create($ctx, $this->body()), 201);
    }

    public function show(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->templates()->find($ctx, $this->intId($id)));
    }

    public function update(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::TEMPLATE_MANAGE);

        $this->respond(fn () => $this->templates()->update($ctx, $this->intId($id), $this->body()));
    }

    public function destroy(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::TEMPLATE_MANAGE);
        $templateId = $this->intId($id);

        $this->run(function () use ($ctx, $templateId): bool {
            $this->templates()->delete($ctx, $templateId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    /**
     * Render the template, optionally against a real contract's data.
     *
     * A POST because the contract to merge in is chosen in the body, not
     * because anything is stored. `contract_id` is passed through as an id and
     * resolved by the service under the caller's tenant, so a preview cannot be
     * used to read another company's contract into a page of HTML.
     */
    public function preview(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $templateId = $this->intId($id);
        $contractId = self::optionalId($this->body()['contract_id'] ?? null);

        $this->respond(fn () => $this->templates()->preview($ctx, $templateId, $contractId));
    }

    /** Creating the contract is the grant that matters here, not reading the template. */
    public function createContract(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_CREATE);

        $this->respond(fn () => $this->templates()->createContractFromTemplate($ctx, $this->intId($id), $this->body()), 201);
    }

    /**
     * The variable registry a template editor offers as autocomplete.
     *
     * Readable with CONTRACT_VIEW because the same list labels the merge fields
     * on a preview; the values behind the keys are resolved per contract and
     * are never in this payload.
     */
    public function variables(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->templates()->variables($ctx));
    }

    /**
     * A positive id from a query string or a request body, or null.
     *
     * Not intId(): these are optional fields, so a mistyped one drops out
     * rather than turning the request into a 404 about the wrong resource.
     */
    private static function optionalId(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,19}$/', trim($value)))) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function templates(): TemplateService
    {
        return new TemplateService($this->db());
    }
}
