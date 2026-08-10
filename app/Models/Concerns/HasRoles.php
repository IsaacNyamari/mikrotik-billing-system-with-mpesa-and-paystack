<?php

namespace App\Models\Concerns;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * Role and permission resolution for back-office operators.
 *
 * @property bool $is_super_admin
 * @property int|null $tenant_id
 */
trait HasRoles
{
    /**
     * Permission names resolved for this user, memoised for the lifetime of the instance.
     *
     * @var Collection<int, string>|null
     */
    protected ?Collection $resolvedPermissions = null;

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * Whether the user holds any of the given roles.
     */
    public function hasRole(Role|string ...$roles): bool
    {
        $names = array_map(
            fn (Role|string $role): string => $role instanceof Role ? $role->name : $role,
            $roles,
        );

        return $this->roles->whereIn('name', $names)->isNotEmpty();
    }

    /**
     * Every permission name granted through this user's roles.
     *
     * @return Collection<int, string>
     */
    public function permissions(): Collection
    {
        return $this->resolvedPermissions ??= $this->roles
            ->loadMissing('permissions')
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('name'))
            ->unique()
            ->values();
    }

    /**
     * Whether the user may perform the given action.
     *
     * A platform super admin holds everything by definition; a deactivated user holds nothing,
     * whatever their roles say.
     */
    public function hasPermissionTo(Permission|string $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        $name = $permission instanceof Permission ? $permission->name : $permission;

        return $this->permissions()->contains($name);
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Give the user a role, which must be one their ISP is allowed to use.
     */
    public function assignRole(Role|string $role): void
    {
        $role = $role instanceof Role ? $role : $this->resolveRole($role);

        $this->roles()->syncWithoutDetaching([$role->id]);
        $this->forgetResolvedPermissions();
    }

    public function removeRole(Role|string $role): void
    {
        $role = $role instanceof Role ? $role : $this->resolveRole($role);

        $this->roles()->detach($role->id);
        $this->forgetResolvedPermissions();
    }

    public function forgetResolvedPermissions(): void
    {
        $this->resolvedPermissions = null;
        $this->unsetRelation('roles');
    }

    protected function resolveRole(string $name): Role
    {
        return Role::query()
            ->where('name', $name)
            ->availableTo($this->tenant_id)
            ->firstOrFail();
    }
}
