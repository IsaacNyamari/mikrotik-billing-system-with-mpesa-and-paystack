<?php

namespace Tests\Feature\Customers;

use App\Enums\HotspotUserStatus;
use App\Models\Customer;
use App\Models\HotspotUser;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Carbon\CarbonInterface;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An ISP selling HotSpot packages has paying people and no customer records.
 *
 * Observed in production: four settled payments, four HotSpot logins, and a Customers screen
 * showing an empty table — because a walk-in never becomes a `Customer`, which is correct and
 * was also useless. A true screen that tells the operator nothing about the people paying them
 * is still a screen that needs fixing.
 */
class HotspotBuyersAreVisibleTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Router $router;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $tenant = Tenant::factory()->create(['currency' => 'KES']);
        Tenancy::set($tenant);

        $this->owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->owner->assignRole(Roles::ISP_OWNER);

        $this->router = Router::factory()->create();
        $this->plan = Plan::factory()->create(['name' => '1 Hour', 'code' => 'hourly', 'price' => 10]);
    }

    #[Test]
    public function an_isp_with_no_accounts_but_paying_buyers_opens_on_the_buyers(): void
    {
        $this->buyer('254759900802', 'UHDI92J1YN');

        Livewire::actingAs($this->owner)
            ->test('pages::customers.index')
            // Nothing was chosen: the page worked out that an empty table was the wrong answer.
            ->assertSet('tab', '')
            ->assertSee('254759900802')
            ->assertSee('KES 10.00')
            ->assertDontSee('No customers match this view yet');
    }

    #[Test]
    public function the_code_a_customer_reads_out_is_on_their_purchase_history(): void
    {
        $this->buyer('254759900802', 'UHDI92J1YN');

        Livewire::actingAs($this->owner)
            ->test('pages::customers.index')
            ->call('viewBuyer', '254759900802')
            ->assertSee('UHDI92J1YN')
            ->assertSee('1 Hour');
    }

    #[Test]
    public function an_isp_that_manages_accounts_still_opens_on_them(): void
    {
        Customer::factory()->create(['name' => 'Wanjiku Holdings']);
        $this->buyer('254712345678', 'AAAA111122');

        Livewire::actingAs($this->owner)
            ->test('pages::customers.index')
            ->assertSee('Wanjiku Holdings');
    }

    #[Test]
    public function purchases_by_one_person_are_one_row_with_their_totals(): void
    {
        // Somebody who buys an hour every morning is one customer, not thirty.
        $this->buyer('254759900802', 'CODEONE111', 10, now()->subDays(2));
        $this->buyer('254759900802', 'CODETWO222', 10, now()->subDay());
        $this->buyer('254759900802', 'CODE333333', 30, now());

        $component = Livewire::actingAs($this->owner)->test('pages::customers.index');

        $buyers = $component->instance()->buyers();

        $this->assertCount(1, $buyers->items());
        $this->assertSame(3, (int) $buyers->items()[0]->packages);
        $this->assertSame(50.0, (float) $buyers->items()[0]->spent);
    }

    #[Test]
    public function a_spent_package_does_not_report_the_customer_as_online(): void
    {
        // The sweeper runs once a minute, so a row can still say "active" for a while after the
        // clock has run out. The clock is the authority here, not the status column.
        $this->buyer('254759900802', 'EXPIRED111', 10, now()->subHours(3), HotspotUserStatus::Active, now()->subHour());

        $buyers = Livewire::actingAs($this->owner)->test('pages::customers.index')->instance()->buyers();

        $this->assertSame(0, (int) $buyers->items()[0]->live);
    }

    #[Test]
    public function a_customer_who_is_online_is_shown_as_online(): void
    {
        $this->buyer('254759900802', 'LIVE111111', 10, now(), HotspotUserStatus::Active, now()->addHour());

        $buyers = Livewire::actingAs($this->owner)->test('pages::customers.index')->instance()->buyers();

        $this->assertSame(1, (int) $buyers->items()[0]->live);
    }

    #[Test]
    public function a_buyer_can_be_found_by_phone_or_by_code(): void
    {
        // Both of the things somebody says at a counter.
        $this->buyer('254759900802', 'FINDME1234');
        $this->buyer('254700000001', 'OTHERCODE1');

        $component = Livewire::actingAs($this->owner)->test('pages::customers.index')->call('showTab', 'buyers');

        $component->set('search', 'FINDME1234')->assertSee('254759900802')->assertDontSee('254700000001');
        $component->set('search', '254700000001')->assertSee('254700000001')->assertDontSee('254759900802');
    }

    #[Test]
    public function one_buyers_history_is_theirs_alone(): void
    {
        $this->buyer('254759900802', 'MINE111111');
        $this->buyer('254700000001', 'THEIRS1111');

        $component = Livewire::actingAs($this->owner)
            ->test('pages::customers.index')
            ->call('viewBuyer', '254759900802');

        $codes = $component->instance()->purchases()->pluck('username')->all();

        $this->assertSame(['MINE111111'], $codes);
    }

    #[Test]
    public function another_isps_buyers_are_never_shown(): void
    {
        $other = Tenant::factory()->create();

        Tenancy::forTenant($other, function (): void {
            $router = Router::factory()->create();
            $plan = Plan::factory()->create(['code' => 'theirs']);

            HotspotUser::factory()->create([
                'plan_id' => $plan->id,
                'router_id' => $router->id,
                'username' => 'THEIRBUYER',
                'password' => 'THEIRBUYER',
                'phone' => '254799999999',
            ]);
        });

        Livewire::actingAs($this->owner)
            ->test('pages::customers.index')
            ->call('showTab', 'buyers')
            ->assertDontSee('254799999999');
    }

    protected function buyer(
        string $phone,
        string $code,
        float $amount = 10,
        ?CarbonInterface $when = null,
        HotspotUserStatus $status = HotspotUserStatus::Active,
        ?CarbonInterface $expires = null,
    ): HotspotUser {
        $user = HotspotUser::factory()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'username' => $code,
            'password' => $code,
            'phone' => $phone,
            'amount' => $amount,
            'status' => $status,
            'expires_at' => $expires ?? now()->addHour(),
        ]);

        if ($when !== null) {
            $user->created_at = $when;
            $user->save();
        }

        return $user;
    }
}
