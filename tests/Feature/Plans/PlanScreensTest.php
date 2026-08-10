<?php

namespace Tests\Feature\Plans;

use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class PlanScreensTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create();
        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);

        Tenancy::set($this->tenant);

        $this->router = Router::factory()->online()->create();
    }

    #[Test]
    public function creating_a_plan_pushes_it_to_the_router(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::plans.form')
            ->set('name', '1 Hour')
            ->set('code', 'hotspot-1hr')
            ->set('price', '20')
            ->set('validity_amount', 1)
            ->set('validity_unit', 'hours')
            ->set('download_kbps', 5000)
            ->set('upload_kbps', 2000)
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::query()->sole();
        $this->assertSame('hotspot-1hr', $plan->code);

        $profile = $this->routers->for($this->router)->rows('/ip/hotspot/user/profile')[0];
        $this->assertSame('hotspot-1hr', $profile['name']);
        $this->assertSame('2000k/5000k', $profile['rate-limit']);
    }

    #[Test]
    public function a_code_that_routeros_would_reject_is_refused(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::plans.form')
            ->set('name', 'Bad')
            ->set('code', 'has spaces/and slashes')
            ->set('price', '10')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    #[Test]
    public function two_plans_in_one_isp_cannot_share_a_code(): void
    {
        Plan::factory()->create(['code' => 'taken']);

        $this->actingAs($this->owner);

        Livewire::test('pages::plans.form')
            ->set('name', 'Duplicate')
            ->set('code', 'taken')
            ->set('price', '10')
            ->call('save')
            ->assertHasErrors(['code']);
    }

    #[Test]
    public function another_isp_may_use_the_same_plan_code(): void
    {
        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => Plan::factory()->create(['code' => 'hotspot-1hr']));

        $this->actingAs($this->owner);

        Livewire::test('pages::plans.form')
            ->set('name', '1 Hour')
            ->set('code', 'hotspot-1hr')
            ->set('price', '20')
            ->call('save')
            ->assertHasNoErrors();
    }

    #[Test]
    public function the_code_is_suggested_from_the_name(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::plans.form')
            ->set('name', '24 Hour Unlimited')
            ->assertSet('code', '24-hour-unlimited');
    }

    #[Test]
    public function the_list_shows_only_this_isps_plans(): void
    {
        Plan::factory()->create(['name' => 'Our Package']);

        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => Plan::factory()->create(['name' => 'Their Package']));

        $this->actingAs($this->owner)
            ->get(route('plans.index'))
            ->assertOk()
            ->assertSee('Our Package')
            ->assertDontSee('Their Package');
    }

    #[Test]
    public function hotspot_and_pppoe_plans_are_listed_separately(): void
    {
        Plan::factory()->create(['name' => 'Hourly Voucher']);
        Plan::factory()->pppoe()->create(['name' => 'Home Fibre']);

        $this->actingAs($this->owner);

        Livewire::test('pages::plans.index')
            ->assertSee('Hourly Voucher')
            ->assertDontSee('Home Fibre')
            ->set('type', 'pppoe')
            ->assertSee('Home Fibre')
            ->assertDontSee('Hourly Voucher');
    }

    #[Test]
    public function an_operator_can_push_a_plan_from_the_list(): void
    {
        $plan = Plan::factory()->create(['code' => 'hourly']);

        $this->actingAs($this->owner);

        Livewire::test('pages::plans.index')->call('push', $plan->id);

        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user/profile'));
    }

    #[Test]
    public function a_billing_officer_can_read_plans_but_not_push_them(): void
    {
        $officer = User::factory()->forTenant($this->tenant)->create();
        $officer->assignRole(Roles::BILLING_OFFICER);

        $plan = Plan::factory()->create();

        $this->actingAs($officer)
            ->get(route('plans.index'))
            ->assertOk();

        Livewire::test('pages::plans.index')
            ->call('push', $plan->id)
            ->assertForbidden();
    }
}
