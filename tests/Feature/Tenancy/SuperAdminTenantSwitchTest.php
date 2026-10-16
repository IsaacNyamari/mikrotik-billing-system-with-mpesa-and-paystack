<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveTenant;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SuperAdminTenantSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected Tenant $first;

    protected Tenant $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->superAdmin = User::factory()->superAdmin()->create();
        $this->first = Tenant::factory()->create(['name' => 'Alpha Networks']);
        $this->second = Tenant::factory()->create(['name' => 'Beta Networks']);
    }

    #[Test]
    public function a_tenant_scoped_screen_sends_an_unscoped_super_admin_to_choose_an_isp(): void
    {
        // Rather than a stack trace, which is what a missing choice used to produce.
        $this->actingAs($this->superAdmin)
            ->get(route('reports.earnings'))
            ->assertRedirect(route('platform.overview'));
    }

    #[Test]
    public function choosing_an_isp_makes_the_scoped_screens_work(): void
    {
        $this->actingAs($this->superAdmin);

        Livewire::test('pages::tenants.select')
            ->call('choose', $this->first->id)
            ->assertRedirect(route('dashboard'));

        $this->get(route('reports.earnings'))->assertOk();
    }

    #[Test]
    public function the_chosen_isp_is_the_one_whose_data_is_shown(): void
    {
        Tenancy::forTenant($this->first, fn () => Plan::factory()->create(['name' => 'Alpha Package', 'code' => 'alpha']));
        Tenancy::forTenant($this->second, fn () => Plan::factory()->create(['name' => 'Beta Package', 'code' => 'beta']));

        $this->actingAs($this->superAdmin)
            ->withSession([ResolveTenant::IMPERSONATION_KEY => $this->second->id])
            ->get(route('plans.index'))
            ->assertOk()
            ->assertSee('Beta Package')
            ->assertDontSee('Alpha Package');
    }

    #[Test]
    public function leaving_an_isp_returns_the_admin_to_the_platform_view(): void
    {
        $this->actingAs($this->superAdmin)
            ->withSession([ResolveTenant::IMPERSONATION_KEY => $this->first->id]);

        Livewire::test('pages::tenants.select')
            ->assertSet('currentTenantId', $this->first->id)
            ->call('clear')
            ->assertSet('currentTenantId', null);
    }

    #[Test]
    public function an_ordinary_operator_has_no_isp_to_choose(): void
    {
        $owner = User::factory()->forTenant($this->first)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        // They already belong to one, so the screen is not theirs.
        $this->actingAs($owner)
            ->get(route('tenants.select'))
            ->assertForbidden();
    }

    #[Test]
    public function an_operator_is_never_redirected_because_their_tenant_always_resolves(): void
    {
        $owner = User::factory()->forTenant($this->first)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner)
            ->get(route('reports.earnings'))
            ->assertOk();
    }

    #[Test]
    public function the_sidebar_names_the_isp_the_admin_is_working_in(): void
    {
        $this->actingAs($this->superAdmin)
            ->withSession([ResolveTenant::IMPERSONATION_KEY => $this->second->id])
            ->get(route('plans.index'))
            ->assertOk()
            // Changing the wrong ISP's settings is far too easy without this on screen.
            ->assertSee('Beta Networks');
    }
}
