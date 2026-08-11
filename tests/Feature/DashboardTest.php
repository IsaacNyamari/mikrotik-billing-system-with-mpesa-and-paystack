<?php

namespace Tests\Feature;

use App\Enums\HotspotUserStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\HotspotUser;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create(['name' => 'Solforbs Networks']);
        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);

        Tenancy::set($this->tenant);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Solforbs Networks');
    }

    public function test_a_brand_new_isp_is_told_what_to_set_up(): void
    {
        // Nothing configured, so figures would all be zero and say nothing useful.
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Getting started')
            ->assertSee('Add your MikroTik');
    }

    public function test_a_working_isp_sees_figures_rather_than_setup_steps(): void
    {
        Router::factory()->online()->create();
        Plan::factory()->create(['code' => 'hourly']);

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Getting started');
    }

    public function test_todays_takings_are_shown(): void
    {
        $router = Router::factory()->online()->create();
        $plan = Plan::factory()->create(['code' => 'hourly']);

        $payment = Payment::query()->create([
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'phone' => '254712345678',
            'amount' => 250,
            'receipt' => 'QGH7XY12ZK',
        ]);
        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->save();

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('250.00');
    }

    public function test_a_customer_who_paid_but_is_not_online_is_flagged(): void
    {
        $router = Router::factory()->online()->create();
        $plan = Plan::factory()->create(['code' => 'hourly']);

        HotspotUser::factory()->create([
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'status' => HotspotUserStatus::Failed,
        ]);

        // Nothing else on the page matters more than someone who has paid and has no access.
        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('paid but is not online');
    }

    public function test_a_support_agent_is_not_shown_revenue(): void
    {
        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        Router::factory()->online()->create();
        Plan::factory()->create(['code' => 'hourly']);
        Customer::factory()->create();

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            // They can see subscribers, which is their job.
            ->assertSee('Subscribers')
            ->assertDontSee('Recent payments')
            ->assertDontSee('Last 30 days');
    }

    public function test_an_account_attached_to_no_isp_is_told_so_rather_than_erroring(): void
    {
        // Not a super admin, so nothing redirects them, and every panel would otherwise
        // throw under strict tenant scoping.
        $orphan = User::factory()->create();

        $this->actingAs($orphan)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('No ISP linked to this account');
    }

    public function test_the_dashboard_shows_only_this_isps_data(): void
    {
        Router::factory()->online()->create(['name' => 'Our Router']);

        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => Router::factory()->online()->create(['name' => 'Rival Router']));

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Our Router')
            ->assertDontSee('Rival Router');
    }
}
