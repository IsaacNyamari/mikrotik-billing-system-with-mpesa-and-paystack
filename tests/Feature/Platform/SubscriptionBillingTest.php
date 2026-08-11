<?php

namespace Tests\Feature\Platform;

use App\Enums\PaymentStatus;
use App\Enums\PlanType;
use App\Enums\ServiceType;
use App\Enums\SubscriptionStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Platform\BillingCalculator;
use App\Services\Platform\PlatformBilling;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

/**
 * What an ISP owes the platform, and what happens when they have not paid it.
 */
class SubscriptionBillingTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->unpaid()->create();
        Tenancy::set($this->tenant);

        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);
    }

    #[Test]
    public function a_new_isp_starts_owing_the_setup_fee_and_cannot_get_in(): void
    {
        $subscription = $this->tenant->billing();

        $this->assertSame(SubscriptionStatus::Pending, $subscription->status);
        $this->assertSame('2000.00', $subscription->onboarding_fee);
        $this->assertFalse($subscription->permitsAccess());

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('billing.index'));
    }

    #[Test]
    public function the_page_where_they_pay_is_never_locked(): void
    {
        // An ISP shut out of the billing screen can only get back in by telephoning somebody,
        // which turns every late payment into support work.
        $this->actingAs($this->owner)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('setup fee');
    }

    #[Test]
    public function paying_the_setup_fee_opens_the_system(): void
    {
        $billing = app(PlatformBilling::class);

        $invoice = $billing->onboardingInvoice($this->tenant);
        $this->assertSame('2000.00', $invoice->total);

        $billing->markPaid($invoice);

        $subscription = $this->tenant->billing()->refresh();

        $this->assertTrue($subscription->onboardingIsPaid());
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function the_monthly_bill_is_commission_on_money_that_actually_moved(): void
    {
        $this->activate();

        $router = Router::factory()->online()->create();
        $plan = Plan::factory()->create(['code' => 'hourly', 'price' => 20, 'type' => PlanType::Hotspot]);

        $lastMonth = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

        // Three sales that settled, one the customer cancelled.
        $this->payment($plan, $router, 100, PaymentStatus::Paid, $lastMonth->addDays(2));
        $this->payment($plan, $router, 250, PaymentStatus::Paid, $lastMonth->addDays(9));
        $this->payment($plan, $router, 650, PaymentStatus::Paid, $lastMonth->addDays(20));
        $this->payment($plan, $router, 500, PaymentStatus::Cancelled, $lastMonth->addDays(4));

        $charge = app(BillingCalculator::class)->forTenant($this->tenant, $lastMonth, $lastMonth->endOfMonth());

        // 1,000 collected — the cancelled one is not money and is not commissionable.
        $this->assertSame(1000.0, $charge->hotspotCollected);
        $this->assertSame(50.0, $charge->hotspotAmount);
    }

    #[Test]
    public function pppoe_is_billed_per_active_line(): void
    {
        $this->activate();

        $router = Router::factory()->online()->create();
        $plan = Plan::factory()->create(['code' => 'home', 'price' => 2500, 'type' => PlanType::Pppoe]);

        $this->line($plan, $router, 'active');
        $this->line($plan, $router, 'active');
        $this->line($plan, $router, 'active');
        // Not carrying anyone, so not billed.
        $this->line($plan, $router, 'suspended');
        $this->line($plan, $router, 'terminated');

        $charge = app(BillingCalculator::class)->forTenant($this->tenant);

        $this->assertSame(3, $charge->pppoeLines);
        $this->assertSame(45.0, $charge->pppoeAmount);
    }

    #[Test]
    public function each_isp_is_billed_at_its_own_agreed_rate(): void
    {
        $this->activate();

        $subscription = $this->tenant->billing();
        $subscription->hotspot_commission_rate = 3.0;
        $subscription->save();

        $router = Router::factory()->online()->create();
        $plan = Plan::factory()->create(['code' => 'hourly', 'price' => 20]);

        $lastMonth = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $this->payment($plan, $router, 10000, PaymentStatus::Paid, $lastMonth->addDay());

        $charge = app(BillingCalculator::class)->forTenant($this->tenant, $lastMonth, $lastMonth->endOfMonth());

        // Discounted to 3%, not the 5% default.
        $this->assertSame(300.0, $charge->hotspotAmount);
    }

    #[Test]
    public function a_month_with_no_sales_and_no_lines_raises_no_invoice(): void
    {
        $this->activate();

        // An invoice for nothing is a bill that can fall overdue and cost an ISP the console
        // for owing zero.
        $this->assertNull(app(PlatformBilling::class)->invoiceForPeriod($this->tenant));
    }

    #[Test]
    public function billing_the_same_month_twice_raises_one_invoice(): void
    {
        $this->activate();
        $this->sellSomethingLastMonth();

        $billing = app(PlatformBilling::class);
        $lastMonth = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();

        $first = $billing->invoiceForPeriod($this->tenant, $lastMonth);
        $second = $billing->invoiceForPeriod($this->tenant, $lastMonth);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, TenantInvoice::query()->where('kind', TenantInvoice::KIND_USAGE)->count());
    }

    #[Test]
    public function an_unpaid_bill_costs_the_console_only_once_grace_has_run_out(): void
    {
        $this->activate();
        $this->sellSomethingLastMonth();

        $invoice = app(PlatformBilling::class)->invoiceForPeriod($this->tenant);
        $this->assertNotNull($invoice);

        // Overdue, but still inside grace: an ISP losing the console the morning a payment is
        // late costs their subscribers service over a matter of hours.
        $invoice->due_at = now()->subDay();
        $invoice->save();

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        $invoice->due_at = now()->subDays((int) config('platform.terms.grace_days') + 1);
        $invoice->save();

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertRedirect(route('billing.index'));
    }

    #[Test]
    public function paying_the_overdue_bill_restores_access_at_once(): void
    {
        $this->activate();
        $this->sellSomethingLastMonth();

        $invoice = app(PlatformBilling::class)->invoiceForPeriod($this->tenant);
        $invoice->due_at = now()->subDays(30);
        $invoice->save();

        $this->assertFalse($this->tenant->billing()->refresh()->permitsAccess());

        app(PlatformBilling::class)->markPaid($invoice);

        // An ISP who has just settled and is still locked out will assume it failed and pay
        // again.
        $this->assertTrue($this->tenant->billing()->refresh()->permitsAccess());
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
    }

    #[Test]
    public function the_captive_portal_keeps_selling_while_the_isp_owes_us(): void
    {
        $router = Router::factory()->online()->create();
        Plan::factory()->create(['code' => 'hourly', 'price' => 20]);

        // The ISP has not paid the platform a shilling. Their customers are not a party to
        // that argument, and one who bought an hour of wifi should still get it.
        $this->assertFalse($this->tenant->billing()->permitsAccess());

        $this->get($router->portalUrl())->assertOk();
    }

    #[Test]
    public function a_super_admin_is_never_locked_out_by_an_isps_debt(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'tenant_id' => null]);
        $admin->assignRole(Roles::PLATFORM_SUPER_ADMIN);

        // They are the people who put a lapsed account back in order.
        $this->actingAs($admin)->get(route('platform.subscriptions'))->assertOk();
    }

    #[Test]
    public function an_ordinary_operator_cannot_see_every_isps_billing(): void
    {
        $this->activate();

        $this->actingAs($this->owner)
            ->get(route('platform.subscriptions'))
            ->assertForbidden();
    }

    #[Test]
    public function a_rate_outside_the_published_range_is_refused(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'tenant_id' => null]);
        $admin->assignRole(Roles::PLATFORM_SUPER_ADMIN);

        $this->actingAs($admin);

        // A typo here bills somebody the wrong amount every month until a human notices.
        Livewire::test('pages::platform.subscriptions')
            ->call('edit', $this->tenant->id)
            ->set('rate', '25')
            ->call('saveRate')
            ->assertHasErrors(['rate']);

        Livewire::test('pages::platform.subscriptions')
            ->call('edit', $this->tenant->id)
            ->set('rate', '4')
            ->call('saveRate')
            ->assertHasNoErrors();

        $this->assertSame('4.00', $this->tenant->billing()->refresh()->hotspot_commission_rate);
    }

    #[Test]
    public function one_isps_bill_is_never_built_from_anothers_takings(): void
    {
        $this->activate();

        $other = Tenant::factory()->create();

        Tenancy::forTenant($other, function (): void {
            $router = Router::factory()->online()->create();
            $plan = Plan::factory()->create(['code' => 'theirs', 'price' => 20]);

            Payment::query()->create([
                'plan_id' => $plan->id,
                'router_id' => $router->id,
                'phone' => '254712345678',
                'amount' => 50000,
            ])->forceFill([
                'status' => PaymentStatus::Paid,
                'paid_at' => CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDay(),
            ])->save();
        });

        $charge = app(BillingCalculator::class)->forTenant($this->tenant);

        $this->assertSame(0.0, $charge->hotspotCollected);
        $this->assertSame(0.0, $charge->hotspotAmount);
    }

    #[Test]
    public function a_granted_trial_lets_an_isp_in_without_paying(): void
    {
        $subscription = $this->tenant->billing();
        $subscription->access_granted_until = now()->addDays(14);
        $subscription->save();

        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();

        // And it expires on its own rather than needing to be taken away.
        $subscription->access_granted_until = now()->subDay();
        $subscription->save();

        $this->actingAs($this->owner)->get(route('dashboard'))->assertRedirect(route('billing.index'));
    }

    protected function activate(): TenantSubscription
    {
        $subscription = $this->tenant->billing();
        $subscription->status = SubscriptionStatus::Active;
        $subscription->onboarding_paid_at = now();
        $subscription->save();

        return $subscription;
    }

    protected function sellSomethingLastMonth(): void
    {
        $router = Router::factory()->online()->create();
        $plan = Plan::factory()->create(['code' => 'hourly', 'price' => 20]);

        $this->payment($plan, $router, 4000, PaymentStatus::Paid, CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDay());
    }

    protected function payment(Plan $plan, Router $router, float $amount, PaymentStatus $status, CarbonImmutable $at): Payment
    {
        $payment = Payment::query()->create([
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'phone' => '254712345678',
            'amount' => $amount,
        ]);

        // Status and paid_at are set by the payment flow rather than by input, so they are
        // deliberately not fillable.
        $payment->forceFill([
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Paid ? $at : null,
        ])->save();

        return $payment;
    }

    protected function line(Plan $plan, Router $router, string $status): ServiceAccount
    {
        $customer = Customer::factory()->create();

        $account = ServiceAccount::factory()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'type' => ServiceType::Pppoe,
        ]);

        $account->forceFill(['status' => $status])->save();

        return $account;
    }
}
