<?php

namespace Tests\Feature\Reports;

use App\Enums\PaymentStatus;
use App\Models\MpesaConfiguration;
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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class EarningsAndMpesaSettingsTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected Router $router;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create(['currency' => 'KES']);
        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);

        Tenancy::set($this->tenant);

        $this->router = Router::factory()->online()->create(['name' => 'Nairobi 01']);
        $this->plan = Plan::factory()->create(['name' => '1 Hour', 'code' => 'hourly', 'price' => 20]);
    }

    #[Test]
    public function earnings_count_only_confirmed_payments(): void
    {
        $this->paid(20);
        $this->paid(50);
        // Neither of these is money in the bank.
        $this->payment(PaymentStatus::Pending, 100);
        $this->payment(PaymentStatus::Failed, 200);

        $this->actingAs($this->owner);

        Livewire::test('pages::reports.earnings')
            ->assertSet('range', '30')
            ->assertSee('70.00');
    }

    #[Test]
    public function the_completion_rate_counts_every_attempt(): void
    {
        $this->paid(20);
        $this->payment(PaymentStatus::Failed, 20);
        $this->payment(PaymentStatus::Abandoned, 20);
        $this->payment(PaymentStatus::Pending, 20);

        $this->actingAs($this->owner);

        // One of four attempts turned into money.
        Livewire::test('pages::reports.earnings')->assertSee('25%');
    }

    #[Test]
    public function payments_outside_the_range_are_excluded(): void
    {
        $recent = $this->paid(20);

        $old = $this->paid(500);
        $old->paid_at = now()->subDays(40);
        $old->created_at = now()->subDays(40);
        $old->save();

        $this->actingAs($this->owner);

        Livewire::test('pages::reports.earnings')
            ->assertSee('20.00')
            ->assertDontSee('500.00')
            // Widening the window brings it back.
            ->set('range', '90')
            ->assertSee('520.00');
    }

    #[Test]
    public function one_isps_earnings_never_include_another_isps(): void
    {
        $this->paid(20);

        $other = Tenant::factory()->create();

        Tenancy::forTenant($other, function (): void {
            $router = Router::factory()->create();
            $plan = Plan::factory()->create(['name' => 'Rival Plan', 'code' => 'rival']);

            Payment::query()->create([
                'plan_id' => $plan->id,
                'router_id' => $router->id,
                'phone' => '254799999999',
                'amount' => 9999,
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'receipt' => 'RIVAL123',
            ]);
        });

        $this->actingAs($this->owner);

        Livewire::test('pages::reports.earnings')
            ->assertSee('20.00')
            ->assertDontSee('9,999.00')
            ->assertDontSee('Rival Plan');
    }

    #[Test]
    public function earnings_can_be_narrowed_to_one_router(): void
    {
        $this->paid(20);

        $second = Router::factory()->online()->create(['name' => 'Mombasa 02']);
        $onSecond = $this->paid(300);
        $onSecond->router_id = $second->id;
        $onSecond->save();

        $this->actingAs($this->owner);

        Livewire::test('pages::reports.earnings')
            ->assertSee('320.00')
            ->set('routerId', (string) $second->id)
            ->assertSee('300.00');
    }

    #[Test]
    public function a_quiet_day_is_shown_as_zero_rather_than_missing(): void
    {
        $this->paid(20);

        $this->actingAs($this->owner);

        // Seven days requested means seven bars, however few of them earned anything.
        Livewire::test('pages::reports.earnings')
            ->set('range', '7')
            ->assertCount('daily', 7);
    }

    #[Test]
    public function a_support_agent_cannot_see_the_books(): void
    {
        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        $this->actingAs($agent)
            ->get(route('reports.earnings'))
            ->assertForbidden();
    }

    #[Test]
    public function a_billing_officer_can(): void
    {
        $officer = User::factory()->forTenant($this->tenant)->create();
        $officer->assignRole(Roles::BILLING_OFFICER);

        $this->actingAs($officer)
            ->get(route('reports.earnings'))
            ->assertOk();
    }

    #[Test]
    public function an_owner_can_set_up_mpesa_and_is_given_a_callback_url(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::settings.mpesa')
            ->set('collection_number', '174379')
            ->set('consumer_key', 'the-key')
            ->set('consumer_secret', 'the-secret')
            ->set('passkey', 'the-passkey')
            ->call('save')
            ->assertHasNoErrors();

        $configuration = MpesaConfiguration::query()->sole();

        $this->assertSame('174379', $configuration->short_code);
        $this->assertSame('the-key', $configuration->consumer_key);
        $this->assertNotEmpty($configuration->callback_token);
        $this->assertStringContainsString($configuration->callback_token, $configuration->callbackUrl());
    }

    #[Test]
    public function credentials_are_encrypted_at_rest(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::settings.mpesa')
            ->set('collection_number', '174379')
            ->set('consumer_key', 'the-key')
            ->set('consumer_secret', 'the-secret')
            ->set('passkey', 'the-passkey')
            ->call('save');

        $stored = DB::table('mpesa_configurations')->sole();

        $this->assertNotSame('the-secret', $stored->consumer_secret);
        $this->assertNotSame('the-passkey', $stored->passkey);
    }

    #[Test]
    public function leaving_a_credential_blank_keeps_the_stored_one(): void
    {
        $configuration = MpesaConfiguration::query()->create([
            'short_code' => '174379',
            'consumer_key' => 'original-key',
            'consumer_secret' => 'original-secret',
            'passkey' => 'original-passkey',
        ]);

        $this->actingAs($this->owner);

        Livewire::test('pages::settings.mpesa')
            ->set('collection_number', '555555')
            ->call('save')
            ->assertHasNoErrors();

        $configuration->refresh();

        $this->assertSame('555555', $configuration->short_code);
        $this->assertSame('original-secret', $configuration->consumer_secret);
    }

    #[Test]
    public function stored_credentials_are_never_sent_to_the_browser(): void
    {
        MpesaConfiguration::query()->create([
            'short_code' => '174379',
            'consumer_key' => 'do-not-leak-key',
            'consumer_secret' => 'do-not-leak-secret',
            'passkey' => 'do-not-leak-passkey',
        ]);

        $this->actingAs($this->owner)
            ->get(route('settings.mpesa'))
            ->assertOk()
            ->assertDontSee('do-not-leak-key')
            ->assertDontSee('do-not-leak-secret')
            ->assertDontSee('do-not-leak-passkey');
    }

    #[Test]
    public function a_network_engineer_cannot_touch_payment_credentials(): void
    {
        $engineer = User::factory()->forTenant($this->tenant)->create();
        $engineer->assignRole(Roles::NETWORK_ENGINEER);

        $this->actingAs($engineer)
            ->get(route('settings.mpesa'))
            ->assertForbidden();
    }

    #[Test]
    public function each_isp_gets_its_own_callback_token(): void
    {
        $ours = MpesaConfiguration::query()->create([
            'short_code' => '111111',
            'consumer_key' => 'k',
            'consumer_secret' => 's',
            'passkey' => 'p',
        ]);

        $other = Tenant::factory()->create();

        $theirs = Tenancy::forTenant($other, fn (): MpesaConfiguration => MpesaConfiguration::query()->create([
            'short_code' => '222222',
            'consumer_key' => 'k',
            'consumer_secret' => 's',
            'passkey' => 'p',
        ]));

        $this->assertNotSame($ours->callback_token, $theirs->callback_token);
    }

    protected function paid(float $amount): Payment
    {
        return $this->payment(PaymentStatus::Paid, $amount);
    }

    protected function payment(PaymentStatus $status, float $amount): Payment
    {
        $payment = Payment::query()->create([
            'plan_id' => $this->plan->id,
            'router_id' => $this->router->id,
            'phone' => '2547'.fake()->numerify('########'),
            'amount' => $amount,
            'receipt' => strtoupper(fake()->bothify('??######')),
        ]);

        $payment->status = $status;

        if ($status === PaymentStatus::Paid) {
            $payment->paid_at = now();
        }

        $payment->save();

        return $payment;
    }
}
