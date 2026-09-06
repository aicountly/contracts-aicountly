<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\Permissions;
use Throwable;

/**
 * Which Contracts roles a user holds in a company.
 *
 * Two things grant a role:
 *
 *   1. An explicit row in `contract_user_roles` — the normal path.
 *   2. Being the company owner in Manage — the bootstrap path. Without it the
 *      first person to open Contracts for a new company would have no way to
 *      grant themselves anything, and the product would be unusable until
 *      someone edited the database by hand.
 *
 * A user with neither falls back to the company's configured `default_role`,
 * which ships as `read_only`. Defaulting to something permissive here would
 * mean every company employee could approve contracts on day one.
 */
final class RoleService
{
    /** @var array<string, list<string>> */
    private static array $memo = [];

    /**
     * @param array<string,mixed>|null $company the Manage payload, for owner detection
     * @return list<string> role slugs
     */
    public static function rolesFor(string $environment, int $cmpId, string $uuid, ?array $company = null): array
    {
        $memoKey = $environment . '|' . $cmpId . '|' . $uuid;
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }

        $roles = [];

        if ($company !== null && self::isCompanyOwner($company, $uuid)) {
            $roles[] = 'contract_admin';
        }

        $pdo = Database::pdo();
        if ($pdo !== null) {
            try {
                $st = $pdo->prepare(
                    'SELECT role_slug FROM contract_user_roles
                     WHERE environment = ? AND cmp_id = ? AND user_uuid = ?'
                );
                $st->execute([$environment, $cmpId, $uuid]);
                foreach ($st->fetchAll() as $row) {
                    $slug = (string) $row['role_slug'];
                    if (Permissions::isKnownRole($slug)) {
                        $roles[] = $slug;
                    }
                }
            } catch (Throwable $e) {
                // A role lookup that cannot run must not silently grant access.
                // Leaving $roles as-is means the default below applies.
            }
        }

        if ($roles === []) {
            $roles[] = self::defaultRole($environment, $cmpId);
        }

        return self::$memo[$memoKey] = array_values(array_unique($roles));
    }

    public static function defaultRole(string $environment, int $cmpId): string
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return 'read_only';
        }

        try {
            $st = $pdo->prepare(
                'SELECT default_role FROM contract_settings WHERE environment = ? AND cmp_id = ? LIMIT 1'
            );
            $st->execute([$environment, $cmpId]);
            $slug = $st->fetchColumn();
        } catch (Throwable $e) {
            return 'read_only';
        }

        return is_string($slug) && Permissions::isKnownRole($slug) ? $slug : 'read_only';
    }

    /**
     * Grant a role. Idempotent, so re-inviting someone is not an error.
     */
    public static function grant(string $environment, int $cmpId, string $uuid, string $roleSlug, ?string $grantedBy = null): bool
    {
        if (! Permissions::isKnownRole($roleSlug)) {
            return false;
        }

        $pdo = Database::pdo();
        if ($pdo === null) {
            return false;
        }

        $st = $pdo->prepare(
            'INSERT INTO contract_user_roles (environment, cmp_id, user_uuid, role_slug, granted_by)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (environment, cmp_id, user_uuid, role_slug) DO NOTHING'
        );
        $st->execute([$environment, $cmpId, $uuid, $roleSlug, $grantedBy]);

        unset(self::$memo[$environment . '|' . $cmpId . '|' . $uuid]);

        return true;
    }

    public static function revoke(string $environment, int $cmpId, string $uuid, string $roleSlug): bool
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return false;
        }

        $st = $pdo->prepare(
            'DELETE FROM contract_user_roles
             WHERE environment = ? AND cmp_id = ? AND user_uuid = ? AND role_slug = ?'
        );
        $st->execute([$environment, $cmpId, $uuid, $roleSlug]);

        unset(self::$memo[$environment . '|' . $cmpId . '|' . $uuid]);

        return $st->rowCount() > 0;
    }

    /**
     * Everyone with an explicit grant in this company.
     *
     * @return list<array{user_uuid: string, roles: list<string>}>
     */
    public static function listGrants(string $environment, int $cmpId): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return [];
        }

        $st = $pdo->prepare(
            'SELECT user_uuid, role_slug, created_at FROM contract_user_roles
             WHERE environment = ? AND cmp_id = ? ORDER BY user_uuid, role_slug'
        );
        $st->execute([$environment, $cmpId]);

        $grouped = [];
        foreach ($st->fetchAll() as $row) {
            $uuid = (string) $row['user_uuid'];
            $grouped[$uuid] ??= ['user_uuid' => $uuid, 'roles' => [], 'granted_at' => $row['created_at']];
            $grouped[$uuid]['roles'][] = (string) $row['role_slug'];
        }

        return array_values($grouped);
    }

    /**
     * Users holding a role, for approval routing by role.
     *
     * @return list<string>
     */
    public static function usersWithRole(string $environment, int $cmpId, string $roleSlug): array
    {
        $pdo = Database::pdo();
        if ($pdo === null) {
            return [];
        }

        $st = $pdo->prepare(
            'SELECT DISTINCT user_uuid FROM contract_user_roles
             WHERE environment = ? AND cmp_id = ? AND role_slug = ?'
        );
        $st->execute([$environment, $cmpId, $roleSlug]);

        return array_map(static fn (array $r): string => (string) $r['user_uuid'], $st->fetchAll());
    }

    /** @param array<string,mixed> $company */
    private static function isCompanyOwner(array $company, string $uuid): bool
    {
        if ($uuid === '') {
            return false;
        }

        foreach (['owner_uuid', 'uuid_aictly', 'created_by_uuid', 'user_uuid', 'owner'] as $key) {
            if (isset($company[$key]) && is_string($company[$key]) && trim($company[$key]) === $uuid) {
                return true;
            }
        }

        return false;
    }

    /** @internal tests only */
    public static function resetMemo(): void
    {
        self::$memo = [];
    }
}
