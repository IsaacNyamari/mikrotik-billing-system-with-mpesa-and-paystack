<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Create the platform-defined roles and sync their permissions. Safe to run repeatedly.
     *
     * These roles carry a null tenant_id: they are shared by every ISP rather than copied
     * into each one.
     */
    public function run(): void
    {
        $permissionIds = Permission::query()->pluck('id', 'name');

        foreach (Roles::definitions() as $name => $definition) {
            $role = Role::query()->updateOrCreate(
                ['tenant_id' => null, 'name' => $name],
                [
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'is_system' => true,
                ],
            );

            $names = $definition['permissions'] === ['*']
                ? Permissions::all()
                : $definition['permissions'];

            $role->permissions()->sync(
                $permissionIds->only($names)->values()->all(),
            );
        }
    }
}
