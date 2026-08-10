<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;
use App\Support\Authorization\Permissions;

class PlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::PLANS_VIEW);
    }

    public function view(User $user, Plan $plan): bool
    {
        return $user->hasPermissionTo(Permissions::PLANS_VIEW)
            && $user->belongsToTenant($plan->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::PLANS_CREATE);
    }

    public function update(User $user, Plan $plan): bool
    {
        return $user->hasPermissionTo(Permissions::PLANS_UPDATE)
            && $user->belongsToTenant($plan->tenant_id);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $user->hasPermissionTo(Permissions::PLANS_DELETE)
            && $user->belongsToTenant($plan->tenant_id);
    }

    /**
     * Pushing a plan rewrites a profile on a live router, which reshapes traffic for everyone
     * already on it. That is a network operation, not a pricing one.
     */
    public function push(User $user, Plan $plan): bool
    {
        return $user->hasPermissionTo(Permissions::ROUTERS_PROVISION)
            && $user->belongsToTenant($plan->tenant_id);
    }
}
