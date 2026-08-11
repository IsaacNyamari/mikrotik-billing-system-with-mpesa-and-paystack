<?php

namespace Tests\Feature\Payments;

use App\Enums\HotspotUserStatus;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\HotspotUser;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\PaystackConfiguration;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mpesa\PaymentCollector;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class PaystackTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected Plan $plan;

    protected string $secret = 'sk_test_abcdef1234567890';

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

    protected function configurePaystack(): PaystackConfiguration
    {
        $configuration = PaystackConfiguration::query()->create([
            'secret_key' => $this->secret,
            'billing_email' => 'demo-isp.test',
        ]);

        $this->tenant->payment_provider = PaymentProvider::Paystack->value;
        $this->tenant->save();

        return $configuration;
    }

    #[Test]
    public function an_isp_with_no_daraja_can_collect_through_paystack(): void
    {
        $this->configurePaystack();

        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => true,
            'data' => ['reference' => 'ISP-1-ABC123', 'id' => 99],
        ])]);

        $payment = app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentProvider::Paystack->value, $payment->provider);
        $this->assertSame('ISP-1-ABC123', $payment->checkout_request_id);
    }

    #[Test]
    public function the_charge_asks_for_a_mobile_money_prompt_not_a_hosted_page(): void
    {
        $this->configurePaystack();

        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => true, 'data' => ['reference' => 'ISP-1-ABC123'],
        ])]);

        app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            // A hosted checkout would need a walled-garden hole for every asset that page
            // loads; a mobile-money charge prompts the phone exactly as Daraja does.
            return $body['mobile_money']['provider'] === 'mpesa'
                && $body['mobile_money']['phone'] === '254712345678'
                // Paystack works in the currency's smallest unit.
                && $body['amount'] === 2000
                && $body['currency'] === 'KES';
        });
    }

    #[Test]
    public function an_email_is_made_up_for_a_walk_in_who_only_gave_a_phone_number(): void
    {
        $this->configurePaystack();

        Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'r']])]);

        app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        Http::assertSent(fn ($request): bool => $request->data()['email'] === '254712345678@demo-isp.test');
    }

    #[Test]
    public function a_full_address_is_used_for_every_charge(): void
    {
        // What an ISP naturally types into a field about email. Deriving from it would build
        // "2547…@billing@my-isp.co.ke", which Paystack rejects as an invalid email.
        $this->configurePaystack();
        PaystackConfiguration::query()->sole()->update(['billing_email' => 'billing@my-isp.co.ke']);

        Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'r']])]);

        app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        Http::assertSent(fn ($request): bool => $request->data()['email'] === 'billing@my-isp.co.ke');
    }

    #[Test]
    public function an_unusable_billing_email_names_the_field_instead_of_reaching_paystack(): void
    {
        $this->configurePaystack();
        PaystackConfiguration::query()->sole()->update(['billing_email' => 'not an email']);
        $this->tenant->update(['contact_email' => null]);
        Tenancy::set($this->tenant->fresh());

        Http::fake();

        $payment = app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertStringContainsString('billing email', (string) $payment->result_description);
        // Paystack is never troubled with something it will only reject.
        Http::assertNothingSent();
    }

    #[Test]
    public function the_isps_own_address_stands_in_when_none_is_configured(): void
    {
        $this->configurePaystack();
        PaystackConfiguration::query()->sole()->update(['billing_email' => null]);
        $this->tenant->update(['contact_email' => 'owner@my-isp.co.ke']);
        Tenancy::set($this->tenant->fresh());

        Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'r']])]);

        // Falling back beats failing the sale outright.
        app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        Http::assertSent(fn ($request): bool => $request->data()['email'] === 'owner@my-isp.co.ke');
    }

    #[Test]
    public function a_billing_email_that_is_neither_address_nor_domain_is_refused_on_the_form(): void
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner);

        Livewire::test('pages::settings.mpesa')
            ->set('paystack_secret_key', 'sk_test_liveonetwothree')
            ->set('paystack_billing_email', 'not an email')
            ->call('savePaystack')
            ->assertHasErrors(['paystack_billing_email']);
    }

    #[Test]
    public function a_refused_charge_is_reported_rather_than_thrown(): void
    {
        $this->configurePaystack();

        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => false, 'message' => 'Invalid phone number',
        ], 400)]);

        $payment = app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Invalid phone number', $payment->result_description);
    }

    #[Test]
    public function a_signed_webhook_grants_access(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment();

        $this->postSignedWebhook($this->successBody($payment->checkout_request_id))
            ->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        // Reported in cents, stored in shillings.
        $this->assertSame('20.00', $payment->amount);
        $this->assertNotNull($payment->hotspot_user_id);

        $access = HotspotUser::query()->sole();
        $this->assertSame(HotspotUserStatus::Active, $access->status);
        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function a_webhook_with_a_forged_signature_grants_nothing(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment();

        // This is the whole reason Paystack is safer than Daraja here: the body is signed, so
        // anyone who guesses the URL still cannot buy themselves free internet.
        $this->postJson(route('webhooks.paystack'), $this->successBody($payment->checkout_request_id), [
            'x-paystack-signature' => str_repeat('0', 128),
        ])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, HotspotUser::query()->count());
    }

    #[Test]
    public function a_webhook_with_no_signature_grants_nothing(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment();

        $this->postJson(route('webhooks.paystack'), $this->successBody($payment->checkout_request_id))
            ->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function a_repeated_webhook_does_not_grant_access_twice(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment();

        $body = $this->successBody($payment->checkout_request_id);

        $this->postSignedWebhook($body);
        $this->postSignedWebhook($body);
        $this->postSignedWebhook($body);

        $this->assertSame(1, HotspotUser::query()->count());
    }

    #[Test]
    public function a_failed_charge_event_grants_nothing(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment();

        $this->postSignedWebhook([
            'event' => 'charge.failed',
            'data' => [
                'reference' => $payment->checkout_request_id,
                'status' => 'failed',
                'gateway_response' => 'Insufficient funds',
                'amount' => 2000,
            ],
        ])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Insufficient funds', $payment->result_description);
        $this->assertSame(0, HotspotUser::query()->count());
    }

    #[Test]
    public function one_isp_cannot_settle_another_isps_paystack_payment(): void
    {
        $this->configurePaystack();
        $payment = $this->pendingPayment();

        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => PaystackConfiguration::query()->create([
            'secret_key' => 'sk_test_someoneelse999',
        ]));

        // Signed with the wrong ISP's key: the signature is checked against the key belonging
        // to whoever the payment is for.
        $body = $this->successBody($payment->checkout_request_id);
        $raw = json_encode($body);

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, 'sk_test_someoneelse999'),
        ], $raw)->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function the_chosen_provider_decides_who_collects(): void
    {
        // Both set up, so the choice has to be explicit rather than guessed.
        MpesaConfiguration::query()->create([
            'short_code' => '174379', 'consumer_key' => 'k', 'consumer_secret' => 's', 'passkey' => 'p',
        ]);
        $this->configurePaystack();

        $manager = app(PaymentGatewayManager::class);

        $this->assertSame(PaymentProvider::Paystack, $manager->active()?->provider());

        $this->tenant->payment_provider = PaymentProvider::Mpesa->value;
        $this->tenant->save();
        Tenancy::set($this->tenant->fresh());

        $this->assertSame(PaymentProvider::Mpesa, app(PaymentGatewayManager::class)->active()?->provider());
    }

    #[Test]
    public function an_isp_that_has_set_up_neither_collects_nothing(): void
    {
        $this->assertNull(app(PaymentGatewayManager::class)->active());
    }

    #[Test]
    public function an_isp_with_only_one_provider_needs_no_choice(): void
    {
        // They filled in one form and were never asked to pick.
        $this->configurePaystack();
        $this->tenant->payment_provider = null;
        $this->tenant->save();
        Tenancy::set($this->tenant->fresh());

        $this->assertSame(PaymentProvider::Paystack, app(PaymentGatewayManager::class)->active()?->provider());
    }

    #[Test]
    public function an_operator_can_set_paystack_up_and_switch_to_it(): void
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner);

        Livewire::test('pages::settings.mpesa')
            ->set('paystack_secret_key', 'sk_test_liveonetwothree')
            ->set('paystack_billing_email', 'billing@my-isp.co.ke')
            ->call('savePaystack')
            ->assertHasNoErrors()
            ->call('chooseProvider', PaymentProvider::Paystack->value);

        $this->assertSame('sk_test_liveonetwothree', PaystackConfiguration::query()->sole()->secret_key);
        $this->assertSame(PaymentProvider::Paystack->value, $this->tenant->fresh()->payment_provider);
    }

    #[Test]
    public function something_that_is_not_a_paystack_key_is_refused(): void
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner);

        Livewire::test('pages::settings.mpesa')
            ->set('paystack_secret_key', 'my-secret')
            ->call('savePaystack')
            ->assertHasErrors(['paystack_secret_key']);
    }

    #[Test]
    public function the_secret_key_is_encrypted_and_never_shown_again(): void
    {
        $this->configurePaystack();

        $stored = DB::table('paystack_configurations')->sole();
        $this->assertNotSame($this->secret, $stored->secret_key);

        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner)
            ->get(route('settings.mpesa'))
            ->assertOk()
            ->assertDontSee($this->secret);
    }

    protected function pendingPayment(): Payment
    {
        return Payment::query()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'provider' => PaymentProvider::Paystack->value,
            'phone' => '254712345678',
            'amount' => 20,
            'checkout_request_id' => 'ISP-1-ABC123',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function successBody(?string $reference): array
    {
        return [
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
                'status' => 'success',
                'amount' => 2000,
                'gateway_response' => 'Approved',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function postSignedWebhook(array $body): TestResponse
    {
        $raw = json_encode($body);

        return $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            // Signed over the raw body, exactly as Paystack does it.
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, $this->secret),
        ], $raw);
    }
}
