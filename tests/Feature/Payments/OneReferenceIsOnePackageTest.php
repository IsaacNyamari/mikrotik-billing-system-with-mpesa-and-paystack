<?php

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Models\HotspotUser;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Mpesa\CallbackProcessor;
use App\Services\RouterOs\HotspotUserProvisioner;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

/**
 * One payment reference buys one package, however many times we are asked.
 *
 * Observed in production: a Paystack webhook and the portal's own three-second poll both reached
 * grantAccess() for the same payment inside the same second. Both read hotspot_user_id as null,
 * because neither had written yet, and both went on to issue. The loser hit the unique index on
 * (router, username) and the customer — who had paid ten shillings a moment earlier — was shown
 * a 500 page.
 *
 * The provisioner's idempotency was real but lived only on the device: it checked whether the
 * router already had the user. Nothing checked whether the database already had the purchase.
 */
class OneReferenceIsOnePackageTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Router $router;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::set(Tenant::factory()->create());

        $this->fakeRouters();

        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create(['code' => 'hourly', 'price' => 10]);
    }

    #[Test]
    public function issuing_the_same_reference_twice_does_not_create_a_second_package(): void
    {
        $provisioner = app(HotspotUserProvisioner::class);

        $first = $provisioner->issue($this->plan, $this->router, 'UHDI92J1YN');
        $second = $provisioner->issue($this->plan, $this->router, 'UHDI92J1YN');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, HotspotUser::query()->where('username', 'UHDI92J1YN')->count());
    }

    #[Test]
    public function two_confirmations_of_one_payment_do_not_collide(): void
    {
        // The webhook and the poll, as they actually arrived.
        $payment = $this->paidPayment('UHDI92J1YN');

        $processor = app(CallbackProcessor::class);

        $processor->grantAccess($payment);

        // The second caller is holding the copy it loaded before the first one wrote — which is
        // the whole of the bug. It must not throw, and it must not issue a second package.
        $stale = Payment::query()->find($payment->id);
        $stale->hotspot_user_id = null;

        $processor->grantAccess($stale);

        $this->assertSame(1, HotspotUser::query()->where('username', 'UHDI92J1YN')->count());
        $this->assertNotNull($payment->fresh()->hotspot_user_id);
    }

    #[Test]
    public function the_customer_keeps_the_package_the_first_confirmation_bought(): void
    {
        $payment = $this->paidPayment('UHDI92J1YN');

        $processor = app(CallbackProcessor::class);
        $processor->grantAccess($payment);

        $issued = $payment->fresh()->hotspot_user_id;

        $processor->grantAccess($payment->fresh());

        $this->assertSame($issued, $payment->fresh()->hotspot_user_id);

        // And the device holds one login, not two — RouterOS would have refused the second
        // anyway, which is how this stayed hidden for as long as it did.
        $this->assertCount(
            1,
            $this->routers->for($this->router)->get('/ip/hotspot/user', ['name' => 'UHDI92J1YN']),
        );
    }

    protected function paidPayment(string $receipt): Payment
    {
        $payment = Payment::query()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'phone' => '254759900802',
            'amount' => 10,
            'receipt' => $receipt,
        ]);

        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->save();

        return $payment;
    }
}
