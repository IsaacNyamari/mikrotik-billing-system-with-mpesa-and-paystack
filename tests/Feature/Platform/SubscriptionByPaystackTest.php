<?php

namespace Tests\Feature\Platform;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\TenantPayment;
use App\Models\User;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\SubscriptionCollector;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An ISP paying the platform its setup fee through Paystack.
 *
 * Distinct from an ISP collecting from its own customers: different account, different table,
 * different money. This is the platform's own income.
 */
class SubscriptionByPaystackTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected string $secret = 'sk_test_platformkey1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        config([
            'platform.paystack.enabled' => true,
            'platform.paystack.secret_key' => $this->secret,
            'platform.paystack.currency' => 'KES',
        ]);

        $this->tenant = Tenant::factory()->unpaid()->create(['contact_email' => 'owner@kawangware.co.ke']);
        Tenancy::set($this->tenant);

        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);
    }

    #[Test]
    public function the_setup_fee_is_charged_through_the_platforms_own_paystack(): void
    {
        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => true, 'data' => ['reference' => 'INV-2608-ABC-1', 'status' => 'pay_offline'],
        ])]);

        $invoice = app(PlatformBilling::class)->onboardingInvoice($this->tenant);
        $payment = app(SubscriptionCollector::class)->collect($invoice, '0712345678');

        $this->assertSame(PaymentProvider::Paystack->value, $payment->provider);
        $this->assertSame('INV-2608-ABC-1', $payment->checkout_request_id);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            // The platform's key signs it, and KES goes over in cents.
            return $request->hasHeader('Authorization', 'Bearer '.$this->secret)
                && $body['amount'] === 200000
                && $body['mobile_money']['provider'] === 'mpesa';
        });
    }

    #[Test]
    public function the_isps_own_address_is_what_paystack_bills(): void
    {
        Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['reference' => 'r']])]);

        app(SubscriptionCollector::class)->collect(
            app(PlatformBilling::class)->onboardingInvoice($this->tenant),
            '0712345678',
        );

        Http::assertSent(fn ($request): bool => $request->data()['email'] === 'owner@kawangware.co.ke');
    }

    #[Test]
    public function a_settled_charge_opens_the_system(): void
    {
        $payment = $this->pendingPayment();

        $this->postSignedWebhook([
            'event' => 'charge.success',
            'data' => [
                'reference' => $payment->checkout_request_id,
                'status' => 'success',
                'amount' => 200000,
                'receipt_number' => 'UHB6H1ZT6H',
                'gateway_response' => 'Approved',
            ],
        ])->assertOk();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('UHB6H1ZT6H', $payment->receipt);
        $this->assertSame('2000.00', $payment->amount);

        $subscription = $this->tenant->billing()->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertTrue($subscription->onboardingIsPaid());

        // And the console opens, which is the whole point of the fee.
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function a_forged_webhook_opens_nothing(): void
    {
        $payment = $this->pendingPayment();

        $this->postJson(route('webhooks.platform-paystack'), [
            'event' => 'charge.success',
            'data' => ['reference' => $payment->checkout_request_id, 'status' => 'success'],
        ], ['x-paystack-signature' => str_repeat('0', 128)])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertFalse($this->tenant->billing()->refresh()->onboardingIsPaid());
    }

    #[Test]
    public function the_page_asks_paystack_rather_than_waiting_to_be_told(): void
    {
        $payment = $this->pendingPayment();

        // A webhook only arrives if Paystack can reach this installation. An ISP holding a
        // confirmation while their console stays locked will pay a second time.
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'reference' => $payment->checkout_request_id,
                'status' => 'success',
                'amount' => 200000,
                'receipt_number' => 'UHB6H1ZT6H',
            ],
        ])]);

        app(SubscriptionCollector::class)->reconcile($payment);

        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertTrue($this->tenant->billing()->refresh()->onboardingIsPaid());
    }

    #[Test]
    public function a_repeated_webhook_does_not_credit_the_invoice_twice(): void
    {
        $payment = $this->pendingPayment();

        $body = [
            'event' => 'charge.success',
            'data' => [
                'reference' => $payment->checkout_request_id,
                'status' => 'success',
                'amount' => 200000,
                'receipt_number' => 'UHB6H1ZT6H',
            ],
        ];

        $this->postSignedWebhook($body);
        $this->postSignedWebhook($body);
        $this->postSignedWebhook($body);

        $this->assertSame(1, TenantPayment::query()->where('status', PaymentStatus::Paid)->count());
    }

    #[Test]
    public function a_cancelled_prompt_leaves_them_owing_it(): void
    {
        $payment = $this->pendingPayment();

        $this->postSignedWebhook([
            'event' => 'charge.failed',
            'data' => [
                'reference' => $payment->checkout_request_id,
                'status' => 'failed',
                'gateway_response' => 'Request Cancelled by user.',
            ],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Cancelled, $payment->fresh()->status);
        $this->assertFalse($this->tenant->billing()->refresh()->onboardingIsPaid());
    }

    #[Test]
    public function an_isp_can_pay_the_setup_fee_from_the_billing_screen(): void
    {
        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => true, 'data' => ['reference' => 'INV-1', 'status' => 'pay_offline'],
        ])]);

        Livewire::actingAs($this->owner)
            ->test('pages::billing.index')
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertHasNoErrors()
            ->assertSee('Check your phone');

        $this->assertSame(1, TenantPayment::query()->count());
    }

    protected function pendingPayment(): TenantPayment
    {
        Http::fake(['api.paystack.co/charge' => Http::response([
            'status' => true, 'data' => ['reference' => 'INV-2608-ABC-1', 'status' => 'pay_offline'],
        ])]);

        return app(SubscriptionCollector::class)->collect(
            app(PlatformBilling::class)->onboardingInvoice($this->tenant),
            '0712345678',
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function postSignedWebhook(array $body): TestResponse
    {
        $raw = (string) json_encode($body);

        return $this->call('POST', route('webhooks.platform-paystack'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            // Signed over the raw body with the platform's key, exactly as Paystack does it.
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $raw, $this->secret),
        ], $raw);
    }
}
