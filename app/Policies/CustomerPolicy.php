<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Tenant isolation is already guaranteed by the global scope, so these checks are about
 * capability. The ownership check stays as a second line of defence for the cases where a
 * model reaches a policy without having come through a scoped query.
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::CUSTOMERS_VIEW);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo(Permissions::CUSTOMERS_VIEW)
            && $user->belongsToTenant($customer->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::CUSTOMERS_CREATE);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo(Permissions::CUSTOMERS_UPDATE)
            && $user->belongsToTenant($customer->tenant_id);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasPermissionTo(Permissions::CUSTOMERS_DELETE)
            && $user->belongsToTenant($customer->tenant_id);
    }
}
