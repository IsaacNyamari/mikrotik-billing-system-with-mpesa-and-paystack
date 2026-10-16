<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The first minutes of a freshly deployed platform.
 *
 * A super admin belongs to no ISP, so every tenant-scoped screen has nothing to scope to.
 * `ResolveTenant` diverts them to the ISP picker for exactly that reason — but it used to check
 * `Tenant::query()->exists()` first and skip the diversion when there were none, on the
 * reasoning that an empty picker is pointless. It is; the alternative it chose was worse. The
 * request carried on, the first scoped query threw TenantContextMissingException, and the first
 * thing the person who had just deployed the platform saw was a 500.
 *
 * Observed on production on 2026-10-03, within a minute of the admin account being created.
 */
class AFreshPlatformDoesNotThrowAtItsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->admin = User::factory()->create([
            'tenant_id' => null,
            'is_super_admin' => true,
            // The settings routes sit behind `verified`, and platform:admin marks the account
            // verified on creation — there is nobody to send a verification email to on a
            // platform that has just been deployed.
            'email_verified_at' => now(),
        ]);
    }

    #[Test]
    public function tenant_scoped_screens_divert_to_the_picker_rather_than_throwing(): void
    {
        $this->assertSame(0, Tenant::query()->count(), 'This test is about having no ISPs at all.');

        // Each of these queries a tenant-owned model as the page is built, so each was a 500.
        foreach (['routers.index', 'monitor.index', 'customers.index', 'plans.index', 'dashboard'] as $route) {
            $this->actingAs($this->admin)
                ->get(route($route))
                ->assertRedirect(route('platform.overview'));
        }
    }

    #[Test]
    public function the_picker_itself_renders_and_says_how_an_isp_comes_to_exist(): void
    {
        // The end of the redirect has to be somewhere useful, or this only moves the dead end.
        $this->actingAs($this->admin)
            ->get(route('tenants.select'))
            ->assertOk()
            ->assertSee('No ISPs exist yet')
            ->assertSee(route('register'));
    }

    #[Test]
    public function the_admins_own_account_screens_stay_reachable(): void
    {
        // Diverting these would lock an administrator out of their own settings on a platform
        // that, by definition, has nowhere else for them to go.
        foreach (['profile.edit', 'security.edit', 'appearance.edit'] as $route) {
            $this->actingAs($this->admin)->get(route($route))->assertOk();
        }
    }

    #[Test]
    public function the_platforms_own_screens_are_not_diverted(): void
    {
        // These deliberately span every ISP. Sending a super admin to pick one first would make
        // the platform's own billing reachable only by impersonating a tenant.
        $this->actingAs($this->admin)
            ->get(route('platform.subscriptions'))
            ->assertOk();
    }

    #[Test]
    public function an_ordinary_isp_user_is_never_diverted(): void
    {
        // They belong to exactly one ISP, so there is nothing to choose and the diversion must
        // not touch them. Checked against the billing screen because it is reachable whatever
        // the subscription is doing, which keeps this about tenancy rather than about money.
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('billing.index'))
            ->assertOk();
    }
}
