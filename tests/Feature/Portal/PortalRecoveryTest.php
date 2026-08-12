<?php

namespace Tests\Feature\Portal;

use App\Enums\HotspotUserStatus;
use App\Enums\PaymentStatus;
use App\Models\HotspotUser;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Mpesa\Testing\FakeMpesaGateway;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

/**
 * Getting somebody back online from their M-PESA code alone.
 *
 * Every one of these is a customer who has paid and is not connected, which is the situation
 * that costs an ISP a refund and a customer their patience. The rule the whole screen exists to
 * hold is that the code in their confirmation SMS is always enough — whatever went wrong
 * between the money moving and them being online, and whether or not they know what it was.
 */
class PortalRecoveryTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Solforbs Networks']);
        Tenancy::set($this->tenant);

        $this->fakeRouters();
        $this->app->instance(MpesaGateway::class, new FakeMpesaGateway);

        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create(['name' => '1 Hour', 'code' => 'hourly', 'price' => 20]);

        MpesaConfiguration::query()->create([
            'short_code' => '174379',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'passkey' => 'passkey',
        ]);
    }

    #[Test]
    public function the_way_back_in_is_a_field_rather_than_a_link_to_one(): void
    {
        // Whoever needs this has no internet and has already paid. Making them find and tap
        // something before they can start typing is one obstacle too many at the worst moment.
        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('Already paid?')
            ->assertSee('portal-code', escape: false)
            ->assertSee('wire:submit="recover"', escape: false);
    }

    #[Test]
    public function a_code_whose_login_was_never_written_to_the_router_puts_the_customer_online(): void
    {
        // The router was down when the money landed: the package exists here and nowhere else.
        $access = HotspotUser::factory()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'username' => 'QGR4TZ8LMN',
            'password' => 'QGR4TZ8LMN',
            'status' => HotspotUserStatus::Failed,
            'routeros_id' => null,
            'expires_at' => now()->addHour(),
        ]);

        $this->portal()
            ->set('code', 'QGR4TZ8LMN')
            ->call('recover')
            ->assertHasNoErrors()
            ->assertSee('QGR4TZ8LMN')
            ->assertSee('Connect now');

        $this->assertSame(HotspotUserStatus::Active, $access->fresh()->status);

        // And it is actually on the device now, not merely marked as such.
        $this->assertNotNull(
            $this->routers->for($this->router)->first('/ip/hotspot/user', ['name' => 'QGR4TZ8LMN']),
        );
    }

    #[Test]
    public function a_code_is_recognised_however_the_customer_types_it(): void
    {
        HotspotUser::factory()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'username' => 'QGR4TZ8LMN',
            'password' => 'QGR4TZ8LMN',
            'status' => HotspotUserStatus::Active,
            'expires_at' => now()->addHour(),
        ]);

        // Read off a phone, by somebody who is not thinking about capitals.
        $this->portal()
            ->set('code', '  qgr4tz8lmn ')
            ->call('recover')
            ->assertHasNoErrors()
            ->assertSee('QGR4TZ8LMN');
    }

    #[Test]
    public function money_that_was_confirmed_but_never_issued_a_login_issues_one_now(): void
    {
        // The exact case the sweeper exists for, reached by a customer who did not want to wait.
        $payment = Payment::query()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'phone' => '254712345678',
            'amount' => 20,
            'receipt' => 'RKT9DP2QWE',
        ]);

        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->save();

        $this->portal()
            ->set('code', 'RKT9DP2QWE')
            ->call('recover')
            ->assertHasNoErrors()
            ->assertSee('RKT9DP2QWE');

        $this->assertNotNull($payment->fresh()->hotspot_user_id);

        $this->assertNotNull(
            $this->routers->for($this->router)->first('/ip/hotspot/user', ['name' => 'RKT9DP2QWE']),
        );
    }

    #[Test]
    public function a_spent_package_is_not_resurrected(): void
    {
        HotspotUser::factory()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'username' => 'OLDCODE123',
            'password' => 'OLDCODE123',
            'status' => HotspotUserStatus::Expired,
            'expires_at' => now()->subDay(),
        ]);

        $this->portal()
            ->set('code', 'OLDCODE123')
            ->call('recover')
            ->assertHasErrors('code');

        // Nothing crept back onto the device.
        $this->assertNull(
            $this->routers->for($this->router)->first('/ip/hotspot/user', ['name' => 'OLDCODE123']),
        );
    }

    #[Test]
    public function a_code_from_another_isp_is_not_found(): void
    {
        $other = Tenant::factory()->create();

        Tenancy::forTenant($other, function (): void {
            $router = Router::factory()->online()->create();
            $plan = Plan::factory()->create(['code' => 'theirs']);

            HotspotUser::factory()->create([
                'plan_id' => $plan->id,
                'router_id' => $router->id,
                'username' => 'THEIRCODE1',
                'password' => 'THEIRCODE1',
                'status' => HotspotUserStatus::Active,
                'expires_at' => now()->addHour(),
            ]);
        });

        $this->portal()
            ->set('code', 'THEIRCODE1')
            ->call('recover')
            ->assertHasErrors('code')
            ->assertDontSee('Connect now');
    }

    #[Test]
    public function a_code_bought_at_a_different_router_is_not_found_here(): void
    {
        // Honest rather than helpful: recovering it here would sign them in to a device that
        // has never heard of them, which is worse than saying no.
        $elsewhere = Router::factory()->online()->create();

        HotspotUser::factory()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $elsewhere->id,
            'username' => 'BRANCH2ABC',
            'password' => 'BRANCH2ABC',
            'status' => HotspotUserStatus::Active,
            'expires_at' => now()->addHour(),
        ]);

        $this->portal()
            ->set('code', 'BRANCH2ABC')
            ->call('recover')
            ->assertHasErrors('code');
    }

    #[Test]
    public function a_cancelled_payment_says_so_rather_than_calling_the_code_unknown(): void
    {
        // Sending them off to hunt for a typo that is not there is the worst answer available.
        $payment = Payment::query()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'phone' => '254712345678',
            'amount' => 20,
            'receipt' => 'CANCELLED1',
        ]);

        $payment->status = PaymentStatus::Cancelled;
        $payment->save();

        $this->portal()
            ->set('code', 'CANCELLED1')
            ->call('recover')
            ->assertHasErrors('code')
            ->assertSee('cancelled');
    }

    #[Test]
    public function an_unknown_code_is_refused_plainly(): void
    {
        $this->portal()
            ->set('code', 'NOTHINGXYZ')
            ->call('recover')
            ->assertHasErrors('code')
            ->assertDontSee('Connect now');
    }

    #[Test]
    public function recovery_survives_the_router_being_down(): void
    {
        // Nobody is asked to pay again because a device is unreachable. They keep their code,
        // the page keeps trying, and the Connect button is withheld until the router has it —
        // sending them to a login the device has never heard of is a refusal for something
        // that is about to work.
        $this->routers->makeUnreachable($this->router);

        HotspotUser::factory()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'username' => 'ROUTERDOWN',
            'password' => 'ROUTERDOWN',
            'status' => HotspotUserStatus::Failed,
            'expires_at' => now()->addHour(),
        ]);

        $component = $this->portal()
            ->set('code', 'ROUTERDOWN')
            ->call('recover')
            ->assertHasNoErrors()
            ->assertSee('ROUTERDOWN')
            ->assertDontSee('Connect now');

        // And once the device answers, the same page finishes the job on its own.
        $this->routers->makeReachable($this->router);

        $component->call('retryAccess')->assertSee('Connect now');
    }

    #[Test]
    public function the_browser_is_told_to_follow_the_sign_in_link_rather_than_being_left_to_a_script(): void
    {
        // The success panel arrives through a polled update, which patches the DOM instead of
        // reloading it — and a <script> written into that patch is never executed. So the
        // automatic sign-in fired only on a page reload, which is not what a customer who has
        // just paid is doing: they watched a page carrying their code and nothing happened,
        // which is indistinguishable from the purchase having failed.
        HotspotUser::factory()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'username' => 'AUTOCONN01',
            'password' => 'AUTOCONN01',
            'status' => HotspotUserStatus::Failed,
            'expires_at' => now()->addHour(),
        ]);

        $this->portal()
            ->set('code', 'AUTOCONN01')
            ->call('recover')
            ->assertDispatched('portal-connect');
    }

    protected function portal(): Testable
    {
        return Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key]);
    }
}
