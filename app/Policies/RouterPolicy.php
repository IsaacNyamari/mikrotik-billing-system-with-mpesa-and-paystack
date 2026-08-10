<?php

namespace App\Policies;

use App\Models\Router;
use App\Models\User;
use App\Support\Authorization\Permissions;

class RouterPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_VIEW);
    }

    public function view(User $user, Router $router): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_VIEW)
            && $user->belongsToTenant($router->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_CREATE);
    }

    public function update(User $user, Router $router): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_UPDATE)
            && $user->belongsToTenant($router->tenant_id);
    }

    public function delete(User $user, Router $router): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_DELETE)
            && $user->belongsToTenant($router->tenant_id);
    }

    /**
     * Running commands against a live device is separate from editing its record: an operator
     * who may correct a typo in the router's name should not thereby be able to reconfigure it.
     */
    public function command(User $user, Router $router): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_COMMAND)
            && $user->belongsToTenant($router->tenant_id);
    }

    public function provision(User $user, Router $router): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_PROVISION)
            && $user->belongsToTenant($router->tenant_id);
    }
}
