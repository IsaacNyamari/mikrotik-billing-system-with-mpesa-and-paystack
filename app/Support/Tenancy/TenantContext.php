<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * Holds the ISP whose data the current request, job or command may touch.
 *
 * Everything tenant-owned reads its ownership from here, so this object is the single place
 * that decides what "the current tenant" means. It is registered as a singleton.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    /**
     * Depth of nested withoutScope() calls. Anything above zero means the caller has
     * deliberately asked to operate across every tenant.
     */
    protected int $bypassDepth = 0;

    /**
     * Bind the given tenant as the current one.
     */
    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    /**
     * Clear the current tenant.
     */
    public function forget(): void
    {
        $this->tenant = null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    /**
     * Whether the caller is currently operating across every tenant on purpose.
     */
    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /**
     * Whether tenant scoping should be enforced right now.
     */
    public function shouldScope(): bool
    {
        return $this->has() && ! $this->isBypassed();
    }

    /**
     * Run the callback with tenant scoping switched off.
     *
     * This is the platform-wide escape hatch: super admin screens, seeders and cross-tenant
     * reporting. Reach for forTenant() instead whenever the work belongs to one ISP.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutScope(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }

    /**
     * Run the callback as the given tenant, restoring the previous context afterwards.
     *
     * Any bypass in effect is suspended for the duration, so nesting this inside
     * withoutScope() still scopes to the tenant being impersonated.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function forTenant(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = $this->tenant;
        $previousBypass = $this->bypassDepth;

        $this->tenant = $tenant;
        $this->bypassDepth = 0;

        try {
            return $callback();
        } finally {
            $this->tenant = $previousTenant;
            $this->bypassDepth = $previousBypass;
        }
    }
}
