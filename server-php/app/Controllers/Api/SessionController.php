<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Ai\AiProviderFactory;
use App\Core\Env;
use App\Core\Response;
use App\Modules\Manage\ManageClient;
use App\Services\CompanyBootstrapService;
use App\Support\Permissions;
use Throwable;

/**
 * Who the caller is inside Contracts, and what this deployment can do.
 *
 * The SPA calls `/me` once per company and branches every screen on the result,
 * so this is also where a company first gets its default configuration: a user
 * opening Contracts for a company that has never used it should land on a
 * working product, not an empty one.
 */
final class SessionController extends BaseController
{
    public function me(): void
    {
        $ctx = $this->requireContext();

        // First touch for this company seeds contract types, clause categories,
        // the standard clause wording, risk rules and the default playbook.
        // Idempotent, so every subsequent call is a no-op.
        CompanyBootstrapService::make()?->ensure(
            $ctx->environment,
            $ctx->cmpId,
            ManageClient::summarise($ctx->company ?? [])['base_currency'] ?? null
        );

        Response::success([
            'uuid'        => $ctx->uuid,
            'cmp_id'      => $ctx->cmpId,
            'bo_id'       => $ctx->boId,
            'fy_id'       => $ctx->fyId,
            'environment' => $ctx->environment,
            'company'     => ManageClient::summarise($ctx->company ?? []),
            'roles'       => $ctx->roles,
            'permissions' => $ctx->permissions,
            'counts'      => $this->counts($ctx->environment, $ctx->cmpId, $ctx->uuid),
            'ai'          => $this->aiStatus(),
            'integrations' => $this->integrations(),
        ]);
    }

    /**
     * The badge numbers in the sidebar.
     *
     * One query per badge rather than one big join: each is an indexed count
     * over a different table, and a join across all four would be slower than
     * four seeks and much harder to reason about.
     *
     * @return array<string,int>
     */
    private function counts(string $environment, int $cmpId, string $uuid): array
    {
        $pdo = $this->db();

        $count = static function (string $sql, array $params) use ($pdo): int {
            try {
                $st = $pdo->prepare($sql);
                $st->execute($params);

                return (int) $st->fetchColumn();
            } catch (Throwable $e) {
                // A badge is not worth a 500. A missing table during a partial
                // deploy shows zero rather than breaking every screen.
                return 0;
            }
        };

        return [
            'approvals' => $count(
                'SELECT COUNT(*) FROM contract_approval_assignments
                 WHERE environment = ? AND cmp_id = ? AND approver_uuid = ? AND status = \'pending\'',
                [$environment, $cmpId, $uuid]
            ),
            'obligations' => $count(
                'SELECT COUNT(*) FROM obligation_occurrences o
                 JOIN contract_obligations ob ON ob.id = o.obligation_id
                 WHERE o.environment = ? AND o.cmp_id = ? AND o.status IN (\'due\', \'overdue\')
                   AND (ob.owner_uuid = ? OR ob.owner_uuid IS NULL)',
                [$environment, $cmpId, $uuid]
            ),
            'renewals' => $count(
                'SELECT COUNT(*) FROM contract_renewals
                 WHERE environment = ? AND cmp_id = ? AND status IN (\'review_due\', \'under_review\')',
                [$environment, $cmpId]
            ),
            'review_queue' => $count(
                'SELECT COUNT(DISTINCT contract_id) FROM ai_extractions
                 WHERE environment = ? AND cmp_id = ? AND review_state = \'pending\'',
                [$environment, $cmpId]
            ),
            'notifications' => $count(
                'SELECT COUNT(*) FROM contract_notifications
                 WHERE environment = ? AND cmp_id = ? AND recipient_uuid = ? AND read_at IS NULL',
                [$environment, $cmpId, $uuid]
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function aiStatus(): array
    {
        if (! class_exists(AiProviderFactory::class)) {
            return ['configured' => false, 'provider' => null, 'model' => null];
        }

        try {
            $status = AiProviderFactory::status();
        } catch (Throwable $e) {
            return ['configured' => false, 'provider' => null, 'model' => null];
        }

        return [
            'configured' => (bool) ($status['configured'] ?? false),
            'provider'   => $status['provider'] ?? null,
            'model'      => $status['model'] ?? null,
            'source'     => $status['source'] ?? null,
            'disclaimer' => self::AI_DISCLAIMER,
        ];
    }

    public const AI_DISCLAIMER = 'AI-generated contract analysis is provided for assistance and information. '
        . 'It does not constitute legal advice and should be reviewed by an authorized legal or professional '
        . 'reviewer before reliance.';

    /**
     * What this deployment is wired to.
     *
     * Reported honestly: a screen that says "Drive connected" when it is not
     * turns a clear configuration problem into a mysterious upload failure.
     *
     * @return array<string,array<string,mixed>>
     */
    private function integrations(): array
    {
        $driveConfigured = Env::get('DRIVE_API_BASE') !== '';
        $localAllowed    = Env::bool('CONTRACTS_ALLOW_LOCAL_STORAGE');

        return [
            'manage' => [
                'configured' => true,
                'detail'     => 'Company, branch and financial-year context.',
            ],
            'contacts' => [
                'configured' => true,
                'detail'     => 'Counterparty lookup.',
            ],
            'drive' => [
                'configured' => $driveConfigured,
                'detail'     => $driveConfigured
                    ? 'Documents are stored in AICOUNTLY Drive.'
                    : ($localAllowed
                        ? 'Drive is not configured; documents are stored on this server as a temporary fallback.'
                        : 'Drive is not configured and the local fallback is disabled, so uploads are unavailable.'),
                'provider'   => $driveConfigured ? 'drive' : ($localAllowed ? 'local' : 'none'),
            ],
            'console' => [
                'configured' => Env::get('CONSOLE_API_URL') !== '' && Env::get('CONSOLE_SERVICE_KEY') !== '',
                'detail'     => 'AI provider credentials.',
            ],
            'signature' => [
                'configured' => Env::get('SIGNATURE_PROVIDER') !== '',
                'detail'     => Env::get('SIGNATURE_PROVIDER') !== ''
                    ? 'External signature provider configured.'
                    : 'No signature provider configured. Contracts can still record an externally signed copy.',
            ],
            'email' => [
                'configured' => Env::bool('CONTRACTS_EMAIL_ENABLED'),
                'detail'     => Env::bool('CONTRACTS_EMAIL_ENABLED')
                    ? 'Email reminders are enabled.'
                    : 'Email is disabled; reminders appear in-app only.',
            ],
        ];
    }

    /** The role catalogue, for the settings screen. */
    public function roles(): void
    {
        $this->requirePermission(Permissions::SETTINGS_MANAGE);

        $roles = [];
        foreach (Permissions::roles() as $slug => $role) {
            $roles[] = [
                'slug'        => $slug,
                'label'       => $role['label'],
                'description' => $role['description'],
                'permissions' => $role['permissions'],
            ];
        }

        Response::success(['roles' => $roles, 'permissions' => Permissions::all()]);
    }
}
