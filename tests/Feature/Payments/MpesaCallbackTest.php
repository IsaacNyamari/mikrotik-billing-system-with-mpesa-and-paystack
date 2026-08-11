<?php

namespace Tests\Feature\Payments;

use App\Enums\HotspotUserStatus;
use App\Enums\PaymentStatus;
use App\Models\HotspotUser;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class MpesaCallbackTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected Plan $plan;

    protected MpesaConfiguration $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->fakeRouters();
        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create(['code' => 'hourly', 'price' => 20]);

        $this->configuration = MpesaConfiguration::query()->create([
            'short_code' => '174379',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'passkey' => 'passkey',
        ]);
    }

    #[Test]
    public function a_successful_callback_confirms_the_payment_and_grants_access(): void
    {
        $payment = $this->pendingPayment();

        $this->postCallback($payment->checkout_request_id, 0, 'QGH7XY12ZK')
            ->assertOk()
            ->assertJson(['ResultCode' => 0]);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame('QGH7XY12ZK', $payment->receipt);
        $this->assertNotNull($payment->paid_at);
        $this->assertNotNull($payment->hotspot_user_id);
    }

    #[Test]
    public function the_mpesa_receipt_becomes_the_login(): void
    {
        $payment = $this->pendingPayment();

        $this->postCallback($payment->checkout_request_id, 0, 'GDGDAHXS12');

        $access = HotspotUser::query()->sole();

        $this->assertSame('GDGDAHXS12', $access->username);
        $this->assertSame('GDGDAHXS12', $access->password);
        $this->assertSame(HotspotUserStatus::Active, $access->status);

        $row = $this->routers->for($this->router)->rows('/ip/hotspot/user')[0];
        $this->assertSame('GDGDAHXS12', $row['name']);
    }

    #[Test]
    public function a_repeated_callback_does_not_grant_access_twice(): void
    {
        $payment = $this->pendingPayment();

        // Safaricom retries until it gets a 200, and sometimes after.
        $this->postCallback($payment->checkout_request_id, 0, 'QGH7XY12ZK');
        $this->postCallback($payment->checkout_request_id, 0, 'QGH7XY12ZK');
        $this->postCallback($payment->checkout_request_id, 0, 'QGH7XY12ZK');

        $this->assertSame(1, HotspotUser::query()->count());
        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function a_cancelled_payment_grants_nothing(): void
    {
        $payment = $this->pendingPayment();

        $this->postCallback($payment->checkout_request_id, 1032, null, 'Request cancelled by user');

        $payment->refresh();

        // Recorded as a cancellation rather than a failure: the customer pressed cancel and
        // knows it, so this is what the portal has to be able to tell them.
        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
        $this->assertSame(1032, $payment->result_code);
        $this->assertNull($payment->hotspot_user_id);
        $this->assertSame(0, HotspotUser::query()->count());
    }

    #[Test]
    public function the_amount_actually_paid_is_recorded_over_the_amount_asked_for(): void
    {
        $payment = $this->pendingPayment();

        $this->postCallback($payment->checkout_request_id, 0, 'QGH7XY12ZK', metadata: [
            ['Name' => 'Amount', 'Value' => 50],
            ['Name' => 'MpesaReceiptNumber', 'Value' => 'QGH7XY12ZK'],
            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
        ]);

        $payment->refresh();

        $this->assertSame('50.00', $payment->amount);
        $this->assertSame('254712345678', $payment->phone);
    }

    #[Test]
    public function a_callback_with_an_unknown_token_changes_nothing(): void
    {
        $payment = $this->pendingPayment();

        $this->postJson(route('webhooks.mpesa', ['token' => 'not-a-real-token']), [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => 0,
            ]],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, HotspotUser::query()->count());
    }

    #[Test]
    public function a_callback_for_an_unknown_payment_is_ignored(): void
    {
        $this->postCallback('ws_CO_NEVER_SEEN_THIS', 0, 'QGH7XY12ZK')->assertOk();

        $this->assertSame(0, HotspotUser::query()->count());
    }

    #[Test]
    public function one_isp_cannot_settle_another_isps_payment(): void
    {
        $payment = $this->pendingPayment();

        $other = Tenant::factory()->create();

        $theirToken = Tenancy::forTenant($other, fn (): string => MpesaConfiguration::query()->create([
            'short_code' => '999999',
            'consumer_key' => 'k',
            'consumer_secret' => 's',
            'passkey' => 'p',
        ])->callback_token);

        // A genuine token, but for the wrong ISP: the payment must stay untouched.
        $this->postJson(route('webhooks.mpesa', ['token' => $theirToken]), [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [['Name' => 'MpesaReceiptNumber', 'Value' => 'STOLEN123']]],
            ]],
        ])->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
    }

    #[Test]
    public function a_malformed_callback_is_acknowledged_without_blowing_up(): void
    {
        $this->postJson(route('webhooks.mpesa', ['token' => $this->configuration->callback_token]), [
            'nonsense' => true,
        ])->assertOk()->assertJson(['ResultCode' => 0]);
    }

    #[Test]
    public function a_payment_confirmed_while_the_router_is_down_is_still_recorded(): void
    {
        $payment = $this->pendingPayment();

        $this->routers->makeUnreachable($this->router);

        $this->postCallback($payment->checkout_request_id, 0, 'PAIDNOROUTE')->assertOk();

        $payment->refresh();

        // The money moved, so the payment stands even though provisioning could not.
        $this->assertSame(PaymentStatus::Paid, $payment->status);

        $access = HotspotUser::query()->sole();
        $this->assertSame(HotspotUserStatus::Failed, $access->status);

        // And the sweeper's retry is what eventually gets them online.
        $this->routers->makeReachable($this->router);
        $this->artisan('hotspot:sweep --retry');

        $this->assertSame(HotspotUserStatus::Active, $access->fresh()->status);
    }

    protected function pendingPayment(): Payment
    {
        return Payment::query()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'phone' => '254712345678',
            'amount' => 20,
            'checkout_request_id' => 'ws_CO_TESTREQUEST123',
            'merchant_request_id' => 'MERCHANT123',
        ]);
    }

    /**
     * @param  array<int, array{Name: string, Value: mixed}>|null  $metadata
     */
    protected function postCallback(
        string $checkoutRequestId,
        int $resultCode,
        ?string $receipt = null,
        string $description = 'The service request is processed successfully.',
        ?array $metadata = null,
    ): TestResponse {
        $callback = [
            'MerchantRequestID' => 'MERCHANT123',
            'CheckoutRequestID' => $checkoutRequestId,
            'ResultCode' => $resultCode,
            'ResultDesc' => $description,
        ];

        if ($resultCode === 0) {
            $callback['CallbackMetadata'] = ['Item' => $metadata ?? [
                ['Name' => 'Amount', 'Value' => 20],
                ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                ['Name' => 'PhoneNumber', 'Value' => 254712345678],
            ]];
        }

        return $this->postJson(
            route('webhooks.mpesa', ['token' => $this->configuration->callback_token]),
            ['Body' => ['stkCallback' => $callback]],
        );
    }
}
