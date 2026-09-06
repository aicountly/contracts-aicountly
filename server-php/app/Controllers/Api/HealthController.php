<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Ai\AiProviderFactory;
use App\Core\Database;
use App\Core\Env;
use App\Core\Response;
use App\Support\Environment;
use Throwable;

/**
 * Liveness and configuration reporting.
 *
 * Unauthenticated, because it is what a deploy check and an uptime monitor
 * call. That is exactly why it reports *whether* things are configured and
 * never *what they are configured with* — no host, no database name, no key,
 * no key prefix.
 */
final class HealthController
{
    public function index(): void
    {
        $database = Database::isAvailable();

        $payload = [
            'status'      => $database ? 'ok' : 'degraded',
            'app'         => 'Aicountly Contracts',
            'environment' => Environment::resolve(),
            'time'        => gmdate('c'),
            'checks'      => [
                'database' => [
                    'ok'      => $database,
                    'message' => $database ? null : Database::unavailableMessage(),
                ],
                'migrations' => $this->migrationCheck(),
                'ai'         => $this->aiCheck(),
                'drive'      => [
                    'ok'       => Env::get('DRIVE_API_BASE') !== '' || Env::bool('CONTRACTS_ALLOW_LOCAL_STORAGE'),
                    'provider' => Env::get('DRIVE_API_BASE') !== '' ? 'drive' : (Env::bool('CONTRACTS_ALLOW_LOCAL_STORAGE') ? 'local' : 'none'),
                ],
                'contacts' => ['ok' => true, 'configured' => Env::get('CONTACTS_API_BASE') !== '' || true],
            ],
        ];

        // 200 even when degraded: an uptime monitor should see that the app is
        // answering, and a deploy check reads the body. A 503 here would page
        // someone for a missing optional integration.
        Response::success($payload);
    }

    /** @return array<string,mixed> */
    private function migrationCheck(): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return ['ok' => false, 'applied' => 0, 'message' => 'Database unavailable.'];
        }

        try {
            $applied = (int) $pdo->query('SELECT COUNT(*) FROM contracts_migration')->fetchColumn();
        } catch (Throwable $e) {
            return [
                'ok'      => false,
                'applied' => 0,
                'message' => 'Run `php database/migrate.php` on the server — the schema is not installed.',
            ];
        }

        $expected = count(glob(Env::apiRoot() . '/database/migrations/*.sql') ?: []);

        return [
            'ok'       => $applied >= $expected && $expected > 0,
            'applied'  => $applied,
            'expected' => $expected,
            'message'  => $applied < $expected
                ? 'Pending migrations. Run `php database/migrate.php`.'
                : null,
        ];
    }

    /** @return array<string,mixed> */
    private function aiCheck(): array
    {
        if (! class_exists(AiProviderFactory::class)) {
            return ['ok' => false, 'configured' => false, 'message' => 'AI layer not installed.'];
        }

        try {
            $status = AiProviderFactory::status();
        } catch (Throwable $e) {
            return ['ok' => false, 'configured' => false, 'message' => 'AI configuration could not be read.'];
        }

        return [
            'ok'         => (bool) ($status['configured'] ?? false),
            'configured' => (bool) ($status['configured'] ?? false),
            'provider'   => $status['provider'] ?? null,
            'model'      => $status['model'] ?? null,
            'source'     => $status['source'] ?? null,
            'message'    => ($status['configured'] ?? false)
                ? null
                : 'No AI provider is configured. Add this product in Console → AI Connected Accounts.',
        ];
    }
}
