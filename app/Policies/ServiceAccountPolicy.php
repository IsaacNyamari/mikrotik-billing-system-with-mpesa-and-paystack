<?php

namespace App\Policies;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ServiceAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::SERVICE_ACCOUNTS_VIEW);
    }

    public function view(User $user, ServiceAccount $account): bool
    {
        return $user->hasPermissionTo(Permissions::SERVICE_ACCOUNTS_VIEW)
            && $user->belongsToTenant($account->tenant_id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo(Permissions::SERVICE_ACCOUNTS_CREATE);
    }

    public function update(User $user, ServiceAccount $account): bool
    {
        return $user->hasPermissionTo(Permissions::SERVICE_ACCOUNTS_UPDATE)
            && $user->belongsToTenant($account->tenant_id);
    }

    /**
     * Suspending or restoring a service takes someone offline, so it is a separate capability
     * from ordinary edits — a support agent may update details without being able to cut
     * anyone's connection.
     */
    public function changeStatus(User $user, ServiceAccount $account): bool
    {
        return $user->hasPermissionTo(Permissions::SERVICE_ACCOUNTS_SUSPEND)
            && $user->belongsToTenant($account->tenant_id);
    }

    /**
     * The network password is a live credential; seeing it is not part of ordinary viewing.
     */
    public function viewSecret(User $user, ServiceAccount $account): bool
    {
        return $user->hasPermissionTo(Permissions::SERVICE_ACCOUNTS_UPDATE)
            && $user->belongsToTenant($account->tenant_id);
    }
}
