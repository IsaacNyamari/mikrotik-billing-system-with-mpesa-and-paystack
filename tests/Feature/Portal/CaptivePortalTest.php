<?php

namespace Tests\Feature\Portal;

use App\Enums\PaymentStatus;
use App\Enums\TenantStatus;
use App\Models\HotspotUser;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Mpesa\StkPushResult;
use App\Services\Mpesa\Testing\FakeMpesaGateway;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class CaptivePortalTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected Plan $plan;

    protected FakeMpesaGateway $mpesa;

    protected MpesaConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['name' => 'Solforbs Networks']);
        Tenancy::set($this->tenant);

        $this->fakeRouters();
        $this->mpesa = new FakeMpesaGateway;
        $this->app->instance(MpesaGateway::class, $this->mpesa);

        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create([
            'name' => '1 Hour',
            'code' => 'hourly',
            'price' => 20,
        ]);

        $this->configuration = MpesaConfiguration::query()->create([
            'short_code' => '174379',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'passkey' => 'passkey',
        ]);
    }

    #[Test]
    public function a_walk_in_sees_the_isps_packages_without_signing_in(): void
    {
        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('Solforbs Networks')
            ->assertSee('1 Hour');
    }

    #[Test]
    public function an_unknown_portal_key_is_not_found(): void
    {
        $this->get(route('portal', ['portalKey' => 'nonsense']))->assertNotFound();
    }

    #[Test]
    public function a_portal_shows_only_its_own_isps_packages(): void
    {
        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => Plan::factory()->create(['name' => 'Rival Package']));

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('1 Hour')
            ->assertDontSee('Rival Package');
    }

    #[Test]
    public function a_plan_withheld_from_the_portal_is_not_on_sale(): void
    {
        Plan::factory()->create(['name' => 'Staff Only', 'code' => 'staff', 'is_public' => false]);

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertDontSee('Staff Only');
    }

    #[Test]
    public function a_suspended_isp_cannot_sell(): void
    {
        $this->tenant->update(['status' => TenantStatus::Suspended]);

        $this->get($this->router->portalUrl())->assertForbidden();
    }

    #[Test]
    public function choosing_a_package_prompts_the_customers_phone(): void
    {
        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertHasNoErrors();

        $payment = Payment::query()->sole();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        // Typed as 07…, sent to Safaricom as 2547….
        $this->assertSame('254712345678', $payment->phone);
        $this->assertSame(20, $this->mpesa->lastPush()['amount']);
    }

    #[Test]
    public function no_access_is_granted_merely_because_the_prompt_was_accepted(): void
    {
        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay');

        // The customer has not entered their PIN yet.
        $this->assertSame(0, HotspotUser::query()->count());
        $this->assertSame([], $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function a_rubbish_phone_number_is_refused_before_safaricom_is_troubled(): void
    {
        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '12345')
            ->call('pay')
            ->assertHasErrors(['phone']);

        $this->assertSame([], $this->mpesa->pushes);
    }

    #[Test]
    public function a_customer_cannot_buy_another_isps_package(): void
    {
        $other = Tenant::factory()->create();
        $foreignPlan = Tenancy::forTenant($other, fn (): Plan => Plan::factory()->create(['code' => 'theirs']));

        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $foreignPlan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertHasErrors(['selectedPlanId']);
    }

    #[Test]
    public function an_isp_that_has_not_set_up_mpesa_says_so_rather_than_erroring(): void
    {
        $this->configuration->delete();

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('Payments are not set up yet')
            // Offering a button that cannot work would only waste the customer's time.
            ->assertDontSee('Pay with M-PESA');
    }

    #[Test]
    public function a_customer_never_sees_a_stack_trace_when_the_gateway_breaks(): void
    {
        // Whatever goes wrong behind the scenes, a person standing in a shop gets something
        // they can act on.
        $this->app->bind(MpesaGateway::class, fn () => new class implements MpesaGateway
        {
            public function stkPush(
                MpesaConfiguration $configuration,
                string $phone,
                int $amount,
                string $accountReference,
                string $description,
                string $callbackUrl,
            ): StkPushResult {
                throw new \RuntimeException('Daraja is on fire');
            }

            public function query(MpesaConfiguration $configuration, string $checkoutRequestId): array
            {
                return [];
            }
        });

        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertHasErrors('payment')
            ->assertDontSee('Daraja is on fire')
            ->assertSee('ask at the counter');
    }

    #[Test]
    public function a_declined_prompt_is_reported_back(): void
    {
        $this->mpesa->rejectNext('Unable to lock subscriber');

        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            // The prompt never reached them, so the provider's own words describe our problem,
            // not theirs. They get something they can act on instead.
            ->assertSee('try again in a moment')
            ->assertDontSee('Unable to lock subscriber');

        $payment = Payment::query()->sole();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        // Kept in full for the ISP, who is the one who can do something about it.
        $this->assertSame('Unable to lock subscriber', $payment->result_description);
    }

    #[Test]
    public function the_whole_journey_from_walking_in_to_being_online(): void
    {
        // 1. The customer joins the wifi and is redirected here by the HotSpot.
        $component = Livewire::test('pages::portal.index', [
            'portalKey' => $this->router->portal_key,
        ]);

        // 2. They pick a package and pay.
        $component->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertSee('Check your phone');

        $payment = Payment::query()->sole();

        // 3. Safaricom confirms.
        $this->postJson(route('webhooks.mpesa', ['token' => $this->configuration->callback_token]), [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => 0,
                'ResultDesc' => 'Success',
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 20],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'GDGDAHXS'],
                    ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ]],
            ]],
        ])->assertOk();

        // 4. The portal picks the confirmation up and shows them their code.
        $component->call('checkPayment')
            ->assertSee('Payment received')
            ->assertSee('GDGDAHXS');

        // 5. And the login exists on the router, ready for them to connect.
        $row = $this->routers->for($this->router)->rows('/ip/hotspot/user')[0];

        $this->assertSame('GDGDAHXS', $row['name']);
        $this->assertSame('hourly', $row['profile']);
        $this->assertSame('3600s', $row['limit-uptime']);
    }

    #[Test]
    public function waiting_too_long_stops_the_spinner(): void
    {
        $component = Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay');

        // The customer never picked up their phone. Assigned rather than mass-updated:
        // created_at is not fillable, so update() would quietly do nothing.
        $payment = Payment::query()->sole();
        $payment->created_at = now()->subMinutes((int) config('mpesa.abandon_after_minutes') + 1);
        $payment->save();

        $component->call('checkPayment');

        $this->assertSame(PaymentStatus::Abandoned, Payment::query()->sole()->status);
    }

    #[Test]
    public function the_hotspot_redirect_details_are_carried_through_to_the_payment(): void
    {
        $this->get($this->router->portalUrl().'?'.http_build_query([
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'ip' => '10.10.10.55',
            'link-login-only' => 'http://10.10.10.1/login',
        ]))->assertOk();

        Livewire::withQueryParams([
            'mac' => 'AA:BB:CC:DD:EE:FF',
            'ip' => '10.10.10.55',
            'link-login-only' => 'http://10.10.10.1/login',
        ])
            ->test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay');

        $payment = Payment::query()->sole();

        // Needed so the ISP can tie a payment to the device that made it.
        $this->assertSame('AA:BB:CC:DD:EE:FF', $payment->client_mac);
        $this->assertSame('10.10.10.55', $payment->client_ip);
    }
}
