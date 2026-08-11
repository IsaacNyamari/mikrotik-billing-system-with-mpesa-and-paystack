<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\HotspotUser;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\PaystackConfiguration;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Mpesa\Testing\FakeMpesaGateway;
use App\Services\Payments\PaymentReconciler;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

/**
 * The portal asking the provider outright, rather than waiting to be told.
 *
 * A webhook only arrives if the provider can reach this installation. Everything here is about
 * the customer who has the confirmation SMS in their hand while no callback ever lands.
 */
class PaymentReconciliationTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create(['code' => 'hourly', 'price' => 20]);
    }

    #[Test]
    public function a_paystack_charge_the_customer_paid_settles_without_any_webhook(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        // No callback has been delivered and none ever will be — this installation is not
        // reachable from Paystack.
        $this->fakeVerification('success', 'Approved', $this->settledCharge());

        app(PaymentReconciler::class)->reconcile($payment);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('UHB6H1ZT6H', $payment->receipt);
        // Reported in cents, recorded in shillings.
        $this->assertSame('20.00', $payment->amount);
        $this->assertNotNull($payment->hotspot_user_id);

        // And the login is on the device, which is what actually puts them online.
        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function the_portal_shows_the_code_on_its_next_poll(): void
    {
        $this->configurePaystack();

        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => true, 'data' => ['reference' => 'ISP-1-ABC123'],
        ])]);

        $component = Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertSee('Check your phone');

        // The customer enters their PIN. Paystack knows; nobody has told us.
        $this->fakeVerification('success', 'Approved', $this->settledCharge());

        $component->call('checkPayment')
            ->assertSee('Payment received')
            ->assertSee('UHB6H1ZT6H');
    }

    #[Test]
    public function the_login_is_named_after_the_mpesa_code_not_the_customers_number(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        $this->fakeVerification('success', 'Approved', $this->settledCharge());

        app(PaymentReconciler::class)->reconcile($payment);

        $access = HotspotUser::query()->sole();

        // The code in the customer's confirmation SMS. Paystack also reports the payer's own
        // phone number on the charge, and naming their login after that would be both wrong
        // and a number that is theirs to keep private.
        $this->assertSame('UHB6H1ZT6H', $access->username);
        $this->assertStringNotContainsString('0711777992', $access->username);
    }

    #[Test]
    public function a_charge_paystack_refuses_outright_is_not_explained_to_the_customer(): void
    {
        $this->configurePaystack();

        // Verbatim from a live refusal: Paystack's own machinery failing, with guidance aimed
        // at the merchant. It says nothing a customer could act on.
        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => false,
            'message' => 'Charge attempted',
            'data' => ['reference' => 'ISP-1-ABC123', 'status' => 'failed', 'message' => 'Unable to process transaction'],
            'meta' => ['nextStep' => 'The customer needs to escalate to Paystack.'],
            'type' => 'api_error',
            'code' => 'unprocessed_transaction',
        ], 400)]);

        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertSee('try again in a moment')
            ->assertDontSee('Unable to process transaction');

        // The provider's exact words survive on the record for the ISP, who is the only one
        // who can escalate it.
        $this->assertSame('Unable to process transaction', Payment::query()->sole()->result_description);
    }

    #[Test]
    public function a_half_finished_setup_is_never_explained_to_a_walk_in(): void
    {
        $this->configurePaystack();
        PaystackConfiguration::query()->sole()->update(['billing_email' => 'not an email']);
        $this->tenant->update(['contact_email' => null]);
        Tenancy::set($this->tenant->fresh());

        Http::fake();

        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            // An instruction meant for the operator would tell a stranger on the wifi about
            // this ISP's own configuration.
            ->assertDontSee('billing email')
            ->assertDontSee('Settings')
            ->assertSee('ask at the counter');
    }

    /**
     * A settled mobile-money charge as Paystack really reports it.
     *
     * @return array<string, mixed>
     */
    protected function settledCharge(): array
    {
        return [
            'receipt_number' => 'UHB6H1ZT6H',
            'channel' => 'mobile_money',
            'authorization' => [
                'channel' => 'mobile_money',
                'bank' => 'M-PESA',
                'mobile_money_number' => '0711777992',
                'receiver_bank_account_number' => null,
            ],
        ];
    }

    #[Test]
    public function a_customer_who_declines_is_told_they_cancelled(): void
    {
        $this->configurePaystack();

        Http::fake([
            'api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'ISP-1-ABC123']]),
        ]);

        $component = Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->set('selectedPlanId', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay');

        // The customer presses cancel. Paystack has no status of its own for this: a cancelled
        // charge is a failed one whose wording says the customer refused it.
        $this->fakeVerification('failed', 'Transaction cancelled by user');

        $component->call('checkPayment')
            ->assertSee('Payment cancelled')
            ->assertSee('Nothing was charged')
            ->assertSee('Try again');

        $this->assertSame(PaymentStatus::Cancelled, Payment::query()->sole()->status);
    }

    #[Test]
    public function a_charge_that_genuinely_failed_keeps_the_providers_own_words(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        $this->fakeVerification('failed', 'Insufficient funds');

        app(PaymentReconciler::class)->reconcile($payment);

        $payment->refresh();

        // Worth repeating verbatim: it tells the customer what to do about it.
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Insufficient funds', $payment->result_description);
        $this->assertSame(0, HotspotUser::query()->count());
    }

    #[Test]
    public function a_prompt_still_on_the_customers_phone_settles_nothing(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        $this->fakeVerification('pending', 'Pending');

        app(PaymentReconciler::class)->reconcile($payment);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, HotspotUser::query()->count());
    }

    #[Test]
    public function a_provider_that_cannot_be_reached_settles_nothing(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        // An outage says nothing about the customer, and must never be read as a refusal.
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false], 500)]);

        app(PaymentReconciler::class)->reconcile($payment);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function verification_and_a_webhook_arriving_together_issue_one_login(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        $this->fakeVerification('success', 'Approved', $this->settledCharge());

        app(PaymentReconciler::class)->reconcile($payment);

        // The callback turns up late, as Paystack's retries do.
        $body = [
            'event' => 'charge.success',
            'data' => array_merge([
                'reference' => $payment->checkout_request_id,
                'status' => 'success',
                'amount' => 2000,
            ], $this->settledCharge()),
        ];
        $raw = json_encode($body);

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', (string) $raw, 'sk_test_abcdef1234567890'),
        ], (string) $raw)->assertOk();

        $this->assertSame(1, HotspotUser::query()->count());
        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function safaricom_is_asked_only_after_its_callback_has_had_its_chance(): void
    {
        $this->configureDaraja();
        $mpesa = $this->fakeDaraja();
        $mpesa->answerQueryWithCode(0);

        $payment = $this->pendingPayment(PaymentProvider::Mpesa);

        // Daraja's query reports whether the money moved but not the M-PESA code, and that code
        // is what the customer's login is named after. Settling from it immediately would name
        // the login something they have never seen.
        app(PaymentReconciler::class)->reconcile($payment);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);

        $payment->created_at = now()->subMinute();
        $payment->save();

        app(PaymentReconciler::class)->reconcile($payment);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
    }

    #[Test]
    public function safaricoms_cancellation_code_is_read_as_a_cancellation(): void
    {
        $this->configureDaraja();
        $mpesa = $this->fakeDaraja();
        $mpesa->answerQueryWithCode(1032, 'Request cancelled by user');

        $payment = $this->pendingPayment(PaymentProvider::Mpesa);
        $payment->created_at = now()->subMinute();
        $payment->save();

        app(PaymentReconciler::class)->reconcile($payment);

        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);
    }

    #[Test]
    public function a_prompt_daraja_is_still_processing_settles_nothing(): void
    {
        $this->configureDaraja();
        $this->fakeDaraja();

        $payment = $this->pendingPayment(PaymentProvider::Mpesa);
        $payment->created_at = now()->subMinute();
        $payment->save();

        app(PaymentReconciler::class)->reconcile($payment);

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function the_scheduled_sweep_catches_up_a_customer_who_closed_the_page(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        $this->fakeVerification('success', 'Approved', $this->settledCharge());

        // Nobody is polling: the tab is gone. The money moved anyway.
        Tenancy::forget();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertNotNull($payment->hotspot_user_id);
    }

    #[Test]
    public function the_sweep_gives_up_on_a_prompt_that_expired_long_ago(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);
        $payment->created_at = now()->subHour();
        $payment->save();

        $this->fakeVerification('pending', 'Pending');

        Tenancy::forget();

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(PaymentStatus::Abandoned, $payment->fresh()->status);
    }

    #[Test]
    public function one_isps_payment_is_never_reconciled_as_another(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment(PaymentProvider::Paystack);

        $other = Tenant::factory()->create();

        $this->fakeVerification('success', 'Approved');

        // Reconciling as the wrong ISP would use the wrong credentials and, worse, provision
        // onto the wrong ISP's router.
        Tenancy::forTenant($other, fn () => app(PaymentReconciler::class)->reconcile($payment));

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function a_paystack_isp_is_offered_the_pay_button(): void
    {
        // Checking for an M-PESA row would tell this ISP's customers that payments are not set
        // up, while they demonstrably are.
        $this->configurePaystack();

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertDontSee('Payments are not set up yet')
            ->assertSee('Click Here To Connect');
    }

    protected function configurePaystack(): PaystackConfiguration
    {
        $configuration = PaystackConfiguration::query()->create([
            'secret_key' => 'sk_test_abcdef1234567890',
            'billing_email' => 'demo-isp.test',
        ]);

        $this->tenant->payment_provider = PaymentProvider::Paystack->value;
        $this->tenant->save();
        Tenancy::set($this->tenant->fresh());

        return $configuration;
    }

    protected function configureDaraja(): MpesaConfiguration
    {
        $configuration = MpesaConfiguration::query()->create([
            'short_code' => '174379',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'passkey' => 'passkey',
        ]);

        $this->tenant->payment_provider = PaymentProvider::Mpesa->value;
        $this->tenant->save();
        Tenancy::set($this->tenant->fresh());

        return $configuration;
    }

    protected function fakeDaraja(): FakeMpesaGateway
    {
        $gateway = new FakeMpesaGateway;
        $this->app->instance(MpesaGateway::class, $gateway);

        return $gateway;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function fakeVerification(string $status, ?string $response = null, array $extra = []): void
    {
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => array_merge([
                'reference' => 'ISP-1-ABC123',
                'status' => $status,
                'amount' => 2000,
                'gateway_response' => $response,
            ], $extra),
        ])]);
    }

    protected function pendingPayment(PaymentProvider $provider): Payment
    {
        return Payment::query()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'provider' => $provider->value,
            'phone' => '254712345678',
            'amount' => 20,
            'checkout_request_id' => 'ISP-1-ABC123',
        ]);
    }
}
