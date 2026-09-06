<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ClauseService;
use App\Services\PlaybookService;
use App\Support\Enums;
use App\Support\Permissions;

/**
 * The clause library, the clauses standing in one contract, and the playbook
 * they are measured against.
 *
 * One controller for the three because they are one question asked in three
 * places: what wording do we want (the library), what wording did we get
 * (contract_clauses), and what is the gap (clause_deviations). Splitting them
 * would put a deviation on the far side of the product from the wording it is
 * about.
 */
final class ClauseController extends BaseController
{
    // -----------------------------------------------------------------------
    // Clauses standing in one contract
    // -----------------------------------------------------------------------

    public function forContract(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->clauses()->listForContract($ctx, $this->intId($id)));
    }

    /**
     * Add a clause to a contract, either as free wording or by taking the
     * library's.
     *
     * A `library_clause_id` goes through attachToContract so the copy records
     * where it came from; pasting the same words in as free text would lose the
     * link the deviation report reads to say "this is our standard clause,
     * unchanged".
     *
     * The grant is either one: maintaining the clauses of a contract is part of
     * editing it, and a legal reviewer who owns the library keeps the ability
     * even on a contract someone else edits.
     */
    public function storeForContract(?string $id = null): void
    {
        $ctx        = $this->requireAnyPermission([Permissions::CONTRACT_EDIT, Permissions::CLAUSE_MANAGE]);
        $contractId = $this->intId($id);
        $body       = $this->body();
        $libraryId  = self::optionalId($body['library_clause_id'] ?? null);

        $this->respond(
            fn () => $libraryId !== null
                ? $this->clauses()->attachToContract($ctx, $contractId, $libraryId)
                : $this->clauses()->createForContract($ctx, $contractId, $body),
            201
        );
    }

    public function updateForContract(?string $id = null): void
    {
        $ctx = $this->requireAnyPermission([Permissions::CONTRACT_EDIT, Permissions::CLAUSE_MANAGE]);

        $this->respond(fn () => $this->clauses()->updateForContract($ctx, $this->intId($id), $this->body()));
    }

    public function destroyForContract(?string $id = null): void
    {
        $ctx      = $this->requireAnyPermission([Permissions::CONTRACT_EDIT, Permissions::CLAUSE_MANAGE]);
        $clauseId = $this->intId($id);

        $this->run(function () use ($ctx, $clauseId): bool {
            $this->clauses()->deleteForContract($ctx, $clauseId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    // -----------------------------------------------------------------------
    // Deviations from the playbook
    // -----------------------------------------------------------------------

    public function deviations(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_RISK_VIEW);

        $filters = array_filter([
            'review_status' => Enums::coerce(Request::query('review_status'), PlaybookService::REVIEW_STATUSES),
            'severity'      => Enums::coerce(Request::query('severity'), Enums::RISK_SEVERITIES),
            'open_only'     => Request::query('open_only') === '1' ? true : null,
        ], static fn ($v): bool => $v !== null);

        $this->respond(fn () => $this->playbook()->deviations($ctx, $this->intId($id), $filters));
    }

    /**
     * Re-measure a contract against the playbook.
     *
     * A POST and rate-limited even though it reads like a refresh: it writes
     * deviation rows, and re-running it on every page view would churn the
     * negotiation panel for nothing.
     */
    public function evaluateDeviations(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::AI_RISK_VIEW);
        $this->rateLimit('deviations.evaluate', 30, 300);

        $this->respond(fn () => $this->playbook()->evaluate($ctx, $this->intId($id)));
    }

    /**
     * Record what a negotiator decided about one deviation.
     *
     * Deliberately not the grant that lets someone read deviations: accepting a
     * departure from the playbook settles a negotiating position, which is not
     * something a read-only reviewer should be able to do.
     */
    public function reviewDeviation(?string $id = null): void
    {
        $ctx  = $this->requireAnyPermission([Permissions::CONTRACT_EDIT, Permissions::PLAYBOOK_MANAGE]);
        $body = $this->body();

        $status = Enums::coerce($body['status'] ?? null, PlaybookService::REVIEW_STATUSES);
        if ($status === null) {
            Response::validationError(['status' => 'Choose ' . implode(', ', PlaybookService::REVIEW_STATUSES) . '.']);
        }

        $notes = isset($body['notes']) && is_string($body['notes'])
            ? mb_substr(trim($body['notes']), 0, 4000)
            : null;

        $this->respond(fn () => $this->playbook()->reviewDeviation($ctx, $this->intId($id), $status, $notes));
    }

    // -----------------------------------------------------------------------
    // The clause library
    // -----------------------------------------------------------------------

    /**
     * The category list a clause form needs.
     *
     * Readable by anyone who can see a contract: the categories are the
     * vocabulary of the whole product, and hiding them would leave a drafter
     * with an empty dropdown.
     */
    public function categories(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->clauses()->categories($ctx));
    }

    /** Standard wording is reference material for anyone drafting; changing it is not. */
    public function index(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $filters = [
            'q'               => Request::query('q'),
            'category_id'     => self::optionalId(Request::query('category_id')),
            'approval_status' => Enums::coerce(Request::query('approval_status'), Enums::CLAUSE_APPROVAL_STATUSES),
            'risk'            => Enums::coerce(Request::query('risk'), Enums::RISK_LEVELS),
            'archived'        => Request::query('archived'),
        ];

        $result = $this->run(fn () => $this->clauses()->search($ctx, $filters, $page['per_page'], $page['offset']));

        Response::paginated($result['items'], $result['total'], $page['page'], $page['per_page']);
    }

    public function store(): void
    {
        $ctx = $this->requirePermission(Permissions::CLAUSE_MANAGE);

        $this->respond(fn () => $this->clauses()->create($ctx, $this->body()), 201);
    }

    public function update(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CLAUSE_MANAGE);

        $this->respond(fn () => $this->clauses()->update($ctx, $this->intId($id), $this->body()));
    }

    public function destroy(?string $id = null): void
    {
        $ctx      = $this->requirePermission(Permissions::CLAUSE_MANAGE);
        $clauseId = $this->intId($id);

        $this->run(function () use ($ctx, $clauseId): bool {
            $this->clauses()->delete($ctx, $clauseId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    /** Superseded wording, because a contract was reviewed against the text as it stood then. */
    public function versions(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->clauses()->versions($ctx, $this->intId($id)));
    }

    // -----------------------------------------------------------------------
    // Playbooks and their rules
    // -----------------------------------------------------------------------

    public function playbooks(): void
    {
        $ctx = $this->requirePermission(Permissions::PLAYBOOK_MANAGE);

        $this->respond(fn () => $this->playbook()->playbooks($ctx));
    }

    public function playbookRules(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::PLAYBOOK_MANAGE);

        $this->respond(fn () => $this->playbook()->rules($ctx, $this->intId($id)));
    }

    public function storePlaybookRule(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::PLAYBOOK_MANAGE);

        $this->respond(fn () => $this->playbook()->createRule($ctx, $this->intId($id), $this->body()), 201);
    }

    public function updatePlaybookRule(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::PLAYBOOK_MANAGE);

        $this->respond(fn () => $this->playbook()->updateRule($ctx, $this->intId($id), $this->body()));
    }

    public function destroyPlaybookRule(?string $id = null): void
    {
        $ctx    = $this->requirePermission(Permissions::PLAYBOOK_MANAGE);
        $ruleId = $this->intId($id);

        $this->run(function () use ($ctx, $ruleId): bool {
            $this->playbook()->deleteRule($ctx, $ruleId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    /**
     * A positive id from a query string or a request body, or null.
     *
     * Unlike intId() a bad value here is not a 404 — the id is one optional
     * field among many, so a filter the caller mistyped drops out rather than
     * turning the whole request into "not found".
     */
    private static function optionalId(mixed $value): ?int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,19}$/', trim($value)))) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function clauses(): ClauseService
    {
        return new ClauseService($this->db());
    }

    private function playbook(): PlaybookService
    {
        return new PlaybookService($this->db());
    }
}
