<?php

namespace App\Support\Tenancy\Facades;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void set(Tenant $tenant)
 * @method static void forget()
 * @method static Tenant|null tenant()
 * @method static int|null id()
 * @method static bool has()
 * @method static bool isBypassed()
 * @method static bool shouldScope()
 * @method static mixed withoutScope(Closure $callback)
 * @method static mixed forTenant(Tenant $tenant, Closure $callback)
 *
 * @see TenantContext
 */
class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenantContext::class;
    }
}
