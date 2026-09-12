<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;
use App\Support\Permissions;

/**
 * The caller's own notification inbox.
 *
 * Every action here is scoped to the signed-in user by the service, not by a
 * recipient the caller supplies: a notification names the contract it is about,
 * so being able to read someone else's inbox would be a way to learn which
 * agreements they are working on.
 */
final class NotificationController extends BaseController
{
    public function index(): void
    {
        $ctx  = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $page = Request::pagination(25, 100);

        $service = new NotificationService($this->db());

        $result = $this->run(fn () => $service->listFor(
            $ctx,
            ['unread_only' => Request::query('unread_only') === '1'],
            $page['per_page'],
            $page['offset']
        ));

        // The unread count rides along with the page: the bell badge and the
        // list are the same screen, and asking for them separately would double
        // the requests for one render.
        Response::paginated(
            $result['items'],
            $result['total'],
            $page['page'],
            $page['per_page'],
            ['unread' => $this->run(fn (): int => $service->unreadCount($ctx))]
        );
    }

    public function read(?string $id = null): void
    {
        $ctx            = $this->requirePermission(Permissions::CONTRACT_VIEW);
        $notificationId = $this->intId($id);

        $marked = $this->run(fn (): bool => (new NotificationService($this->db()))->markRead($ctx, $notificationId));

        if (! $marked) {
            Response::notFound('Notification not found.');
        }

        Response::success(['read' => true]);
    }

    public function readAll(): void
    {
        $ctx = $this->requirePermission(Permissions::CONTRACT_VIEW);

        $this->respond(fn (): array => ['read' => (new NotificationService($this->db()))->markAllRead($ctx)]);
    }
}
