<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The authenticated caller plus the company they are acting for.
 *
 * Constructed only by BaseController::requireContext(), which has already
 * asked the portal whether the session is live and asked Manage whether this
 * user may act for this company. Nothing downstream re-checks that, so nothing
 * downstream may construct one of these from request input.
 */
final class TenantContext
{
    /**
     * @param array<string,mixed>|null $company raw Manage payload
     * @param list<string>             $permissions resolved permission slugs
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $sesKey,
        public readonly int $cmpId,
        public readonly int $fyId,
        public readonly int $boId,
        public readonly string $environment,
        public readonly ?array $company = null,
        public readonly array $permissions = [],
        public readonly array $roles = [],
    ) {
    }

    public function has(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @param list<string> $permissions */
    public function hasAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->has($permission)) {
                return true;
            }
        }

        return false;
    }

    public function companyName(): string
    {
        if (! is_array($this->company)) {
            return '';
        }

        foreach (['legal_name', 'company_name', 'cmp_name', 'name'] as $key) {
            if (isset($this->company[$key]) && is_string($this->company[$key]) && trim($this->company[$key]) !== '') {
                return trim($this->company[$key]);
            }
        }

        return '';
    }

    public function currency(): string
    {
        if (is_array($this->company)) {
            foreach (['currency', 'base_currency', 'currency_code'] as $key) {
                if (isset($this->company[$key]) && is_string($this->company[$key]) && trim($this->company[$key]) !== '') {
                    return strtoupper(trim($this->company[$key]));
                }
            }
        }

        return 'INR';
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'uuid'        => $this->uuid,
            'cmp_id'      => $this->cmpId,
            'fy_id'       => $this->fyId,
            'bo_id'       => $this->boId,
            'environment' => $this->environment,
            'permissions' => $this->permissions,
            'roles'       => $this->roles,
        ];
    }
}
