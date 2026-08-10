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
            return $next($request);
        }

        // A suspended or cancelled ISP keeps its data but cannot operate. Super admins still
        // need in, precisely so they can put the account back in order.
        abort_if(! $tenant->isOperational() && ! $user->isSuperAdmin(), 403, 'This ISP account is not active.');

        Tenancy::set($tenant);

        return $next($request);
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
