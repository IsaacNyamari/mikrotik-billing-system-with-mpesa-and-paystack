<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Roles;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Deliberately no WithoutModelEvents: tenant ownership and UUID generation both hang off
    // model events, so suppressing them here would seed records that could never exist at
    // runtime.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            RoleSeeder::class,
        ]);

        $superAdmin = User::query()->firstOrCreate(
            ['email' => 'admin@isp.solforbs.com'],
            [
                'name' => 'Platform Super Admin',
                'password' => 'password',
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $superAdmin->assignRole(Roles::PLATFORM_SUPER_ADMIN);

        if (! app()->isLocal()) {
            return;
        }

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'demo-isp'],
            [
                'name' => 'Demo ISP',
                'status' => TenantStatus::Active,
                'contact_name' => 'Demo Owner',
                'contact_email' => 'owner@demo-isp.test',
                'contact_phone' => '254700000000',
                'activated_at' => now(),
            ],
        );

        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@demo-isp.test'],
            [
                'tenant_id' => $tenant->id,
                'name' => 'Demo Owner',
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );

        $owner->assignRole(Roles::ISP_OWNER);
    }
}
