<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Bring the permissions table in line with the catalogue. Safe to run repeatedly.
     */
    public function run(): void
    {
        foreach (Permissions::groups() as $group => $permissions) {
            foreach ($permissions as $name => $label) {
                Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['group' => $group, 'label' => $label],
                );
            }
        }

        // Permissions dropped from the catalogue lose their grants along with the row.
        Permission::query()->whereNotIn('name', Permissions::all())->delete();
    }
}
