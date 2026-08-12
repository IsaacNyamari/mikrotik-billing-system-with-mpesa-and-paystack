<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Mpesa\DarajaGateway;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\LiveRouterOsConnector;
use App\Services\RouterOs\PooledRouterOsConnector;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One tenant per request, job or command.
        $this->app->singleton(TenantContext::class);

        // Nothing in the application constructs a RouterOS socket itself; tests swap this
        // binding for an in-memory device.
        //
        // Scoped rather than bound, because the pool is the point: one connection per router
        // for the life of a request or job, so issuing a HotSpot login pays for one TCP
        // handshake and one RouterOS login down the tunnel instead of two. Scoped also means
        // the pool is thrown away between jobs under Octane, which is what keeps a socket from
        // outliving the router it was opened to.
        $this->app->scoped(
            RouterOsConnector::class,
            fn ($app): RouterOsConnector => new PooledRouterOsConnector($app->make(LiveRouterOsConnector::class)),
        );

        // Same idea for Safaricom: tests swap this rather than reaching Daraja.
        $this->app->bind(MpesaGateway::class, DarajaGateway::class);
    }

    public function boot(): void
    {
        $this->registerSuperAdminBypass();
        $this->registerPermissionGates();
    }

    /**
     * A platform super admin passes every gate without needing the permission rows.
     */
    protected function registerSuperAdminBypass(): void
    {
        Gate::before(function (Authorizable $user): ?bool {
            return $user instanceof User && $user->isSuperAdmin() && $user->is_active ? true : null;
        });
    }

    /**
     * Expose every permission in the catalogue as a gate ability, so policies and Blade can
     * check them by name: $user->can('routers.provision').
     *
     * The abilities come from the catalogue rather than the permissions table on purpose. A
     * database read here would make gate registration depend on boot order — an unseeded or
     * not-yet-migrated database would silently define nothing, and every can() call would fail
     * closed with no indication why.
     */
    protected function registerPermissionGates(): void
    {
        foreach (Permissions::all() as $name) {
            Gate::define($name, fn (User $user): bool => $user->hasPermissionTo($name));
        }
    }
}
