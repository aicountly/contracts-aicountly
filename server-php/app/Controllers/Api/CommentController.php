<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\CommentService;
use App\Support\Permissions;

/**
 * Discussion on a contract.
 *
 * Commenting is gated on CONTRACT_VIEW rather than CONTRACT_EDIT on purpose: a
 * reviewer's whole job is to say what is wrong with a draft without being able
 * to change it, and requiring edit rights to leave a note would either silence
 * them or hand them the pen.
 */
final class CommentController extends BaseController
{
    public function index(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);

        $this->respond(fn () => $this->service()->listForContract($ctx, $contractId, [
            'subject_type'    => Request::query('subject_type'),
            'subject_id'      => Request::query('subject_id'),
            'unresolved_only' => Request::query('unresolved_only') === '1',
        ]));
    }

    public function store(?string $id = null): void
    {
        $ctx        = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $contractId = $this->intId($id);

        // A comment is cheap to write and expensive to moderate; the cap turns
        // a runaway client or a bored user into a 429 rather than a thread
        // nobody can read.
        $this->rateLimit('comments.write', 60, 300);

        $this->respond(fn () => $this->service()->create($ctx, $contractId, $this->body()), 201);
    }

    public function update(?string $id = null): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn () => $this->service()->update($ctx, $this->intId($id), $this->body()));
    }

    public function destroy(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $commentId = $this->intId($id);

        $this->run(function () use ($ctx, $commentId): bool {
            $this->service()->delete($ctx, $commentId);

            return true;
        });

        Response::success(['deleted' => true]);
    }

    public function resolve(?string $id = null): void
    {
        $ctx       = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $commentId = $this->intId($id);
        $resolved  = (bool) ($this->body()['resolved'] ?? true);

        $this->respond(fn () => $this->service()->resolve($ctx, $commentId, $resolved));
    }

    private function service(): CommentService
    {
        return new CommentService($this->db());
    }
}
