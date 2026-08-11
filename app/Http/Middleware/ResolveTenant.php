<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's ISP as the current tenant for the rest of the request.
 *
 * A platform super admin has no ISP of their own, so they stay unscoped unless they have
 * explicitly switched into a tenant, which is recorded in the session.
 */
class ResolveTenant
{
    public const IMPERSONATION_KEY = 'tenancy.impersonated_tenant_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $tenant = $user->isSuperAdmin()
            ? $this->impersonatedTenant($request)
            : $user->tenant;

        if ($tenant === null) {
            // Clear rather than leave alone: this user has no ISP, and inheriting whatever
            // was resolved earlier in the process would show them another ISP's data. A
            // fresh container makes that unlikely in production, but "unlikely" is not a
            // guarantee worth resting tenant isolation on.
            Tenancy::forget();

            // A super admin belongs to no ISP, so every tenant-scoped screen would throw for
            // them. Sending them to pick one here is far better than letting the query fail
            // deep inside a view, where it is too late to do anything graceful.
            if ($user->isSuperAdmin() && $this->shouldChooseTenant($request)) {
                return redirect()->route('tenants.select');
            }

            return $next($request);
        }

        // A suspended or cancelled ISP keeps its data but cannot operate. Super admins still
        // need in, precisely so they can put the account back in order.
        abort_if(! $tenant->isOperational() && ! $user->isSuperAdmin(), 403, 'This ISP account is not active.');

        Tenancy::set($tenant);

        return $next($request);
    }

    /**
     * Whether this request should be diverted to the ISP picker.
     *
     * Only ordinary page loads are diverted: redirecting a Livewire update or a background
     * request would replace part of a page with a redirect the browser cannot act on. The
     * account screens stay reachable so an admin is never locked out of their own settings.
     */
    protected function shouldChooseTenant(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        if ($request->routeIs('tenants.select', 'logout', 'profile.edit', 'appearance.edit', 'security.edit')) {
            return false;
        }

        // Nothing to choose from on a fresh install.
        return Tenant::query()->exists();
    }

    protected function impersonatedTenant(Request $request): ?Tenant
    {
        $tenantId = $request->session()->get(self::IMPERSONATION_KEY);

        if (! is_numeric($tenantId)) {
            return null;
        }

        return Tenant::query()->find((int) $tenantId);
    }
}
