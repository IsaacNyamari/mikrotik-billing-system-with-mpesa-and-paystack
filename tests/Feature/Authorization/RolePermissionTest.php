<?php

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    #[Test]
    public function the_catalogue_and_the_seeded_permissions_agree(): void
    {
        $this->assertEqualsCanonicalizing(
            Permissions::all(),
            Permission::query()->pluck('name')->all(),
        );
    }

    #[Test]
    public function a_role_grants_its_permissions_to_its_users(): void
    {
        $user = User::factory()->forTenant(Tenant::factory()->create())->create();
        $user->assignRole(Roles::BILLING_OFFICER);

        $this->assertTrue($user->hasPermissionTo(Permissions::INVOICES_VOID));
        $this->assertTrue($user->hasPermissionTo(Permissions::PAYMENTS_RECONCILE));
        $this->assertFalse($user->hasPermissionTo(Permissions::ROUTERS_PROVISION));
    }

    #[Test]
    public function a_read_only_user_cannot_change_anything(): void
    {
        $user = User::factory()->forTenant(Tenant::factory()->create())->create();
        $user->assignRole(Roles::READ_ONLY);

        $this->assertTrue($user->hasPermissionTo(Permissions::REPORTS_VIEW_REVENUE));
        $this->assertFalse($user->hasPermissionTo(Permissions::INVOICES_CREATE));
        $this->assertFalse($user->hasPermissionTo(Permissions::CUSTOMERS_UPDATE));
        $this->assertFalse($user->hasPermissionTo(Permissions::ROUTERS_COMMAND));
    }

    #[Test]
    public function a_network_engineer_runs_the_network_but_not_the_books(): void
    {
        $user = User::factory()->forTenant(Tenant::factory()->create())->create();
        $user->assignRole(Roles::NETWORK_ENGINEER);

        $this->assertTrue($user->hasPermissionTo(Permissions::ROUTERS_PROVISION));
        $this->assertTrue($user->hasPermissionTo(Permissions::RADIUS_DISCONNECT_SESSION));
        $this->assertFalse($user->hasPermissionTo(Permissions::PAYMENTS_REFUND));
        $this->assertFalse($user->hasPermissionTo(Permissions::INVOICES_VOID));
    }

    #[Test]
    public function a_super_admin_passes_every_gate_without_holding_a_role(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->assertTrue($user->hasPermissionTo(Permissions::TENANTS_SUSPEND));
        $this->assertTrue($user->can(Permissions::ROUTERS_PROVISION));
        $this->assertTrue($user->can(Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS));
    }

    #[Test]
    public function a_deactivated_user_holds_nothing_whatever_their_roles_say(): void
    {
        $user = User::factory()
            ->forTenant(Tenant::factory()->create())
            ->inactive()
            ->create();

        $user->assignRole(Roles::ISP_OWNER);

        $this->assertFalse($user->hasPermissionTo(Permissions::CUSTOMERS_VIEW));
        $this->assertFalse($user->can(Permissions::CUSTOMERS_VIEW));
    }

    #[Test]
    public function a_deactivated_super_admin_loses_the_gate_bypass(): void
    {
        $user = User::factory()->superAdmin()->inactive()->create();

        $this->assertFalse($user->can(Permissions::TENANTS_SUSPEND));
    }

    #[Test]
    public function permissions_reach_the_gate_by_name(): void
    {
        $user = User::factory()->forTenant(Tenant::factory()->create())->create();
        $user->assignRole(Roles::SUPPORT_AGENT);

        $this->assertTrue($user->can(Permissions::CUSTOMERS_CREATE));
        $this->assertFalse($user->can(Permissions::CUSTOMERS_DELETE));
    }

    #[Test]
    public function a_user_cannot_be_given_a_role_belonging_to_another_isp(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Role::factory()->forTenant($tenantB)->create(['name' => 'b_only_role']);

        $user = User::factory()->forTenant($tenantA)->create();

        $this->expectException(ModelNotFoundException::class);

        $user->assignRole('b_only_role');
    }

    #[Test]
    public function a_user_can_be_given_a_role_their_own_isp_defined(): void
    {
        $tenant = Tenant::factory()->create();
        Role::factory()->forTenant($tenant)->create(['name' => 'field_technician']);

        $user = User::factory()->forTenant($tenant)->create();
        $user->assignRole('field_technician');

        $this->assertTrue($user->hasRole('field_technician'));
    }

    #[Test]
    public function removing_a_role_takes_its_permissions_away(): void
    {
        $user = User::factory()->forTenant(Tenant::factory()->create())->create();
        $user->assignRole(Roles::BILLING_OFFICER);

        $this->assertTrue($user->hasPermissionTo(Permissions::INVOICES_VOID));

        $user->removeRole(Roles::BILLING_OFFICER);

        $this->assertFalse($user->hasPermissionTo(Permissions::INVOICES_VOID));
    }

    #[Test]
    public function the_platform_role_is_not_on_offer_to_an_isp(): void
    {
        $this->assertNotContains(
            Roles::PLATFORM_SUPER_ADMIN,
            Roles::assignableByTenant(),
        );
    }
}
