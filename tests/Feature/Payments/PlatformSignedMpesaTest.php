<?php

namespace Tests\Feature\Payments;

use App\Models\MpesaConfiguration;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Mpesa\PaymentCollector;
use App\Services\Mpesa\Testing\FakeMpesaGateway;
use App\Services\Payments\DarajaCredentials;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

/**
 * An ISP gives us a till number, not a Daraja app.
 *
 * Most of them have a till and nothing else. Obtaining consumer keys and a passkey is a
 * fortnight of Safaricom paperwork, and the ones who manage it usually arrive with something
 * pasted into the wrong field.
 */
class PlatformSignedMpesaTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected Plan $plan;

    protected FakeMpesaGateway $mpesa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->mpesa = new FakeMpesaGateway;
        $this->app->instance(MpesaGateway::class, $this->mpesa);

        // The platform's own Daraja app.
        config([
            'platform.mpesa.consumer_key' => 'platform-key',
            'platform.mpesa.consumer_secret' => 'platform-secret',
            'platform.mpesa.short_code' => '4123456',
            'platform.mpesa.passkey' => 'platform-passkey',
            'platform.mpesa.environment' => 'live',
        ]);

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create(['code' => 'hourly', 'price' => 20]);
    }

    #[Test]
    public function an_isp_needs_only_a_till_number(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test('pages::settings.mpesa')
            ->set('transaction_type', 'CustomerBuyGoodsOnline')
            ->set('collection_number', '567123')
            ->set('account_reference', 'KAWANGWARE')
            ->call('save')
            ->assertHasNoErrors();

        $configuration = MpesaConfiguration::query()->sole();

        $this->assertSame('567123', $configuration->collectionNumber());
        // Nothing was asked for and nothing was stored.
        $this->assertNull($configuration->consumer_key);
        $this->assertNull($configuration->passkey);
    }

    #[Test]
    public function the_request_is_signed_by_the_platform_and_paid_to_the_isp(): void
    {
        $this->tillIs('567123');

        app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        $configuration = $this->mpesa->lastConfiguration();

        // Daraja separates the two: BusinessShortCode is who the request authenticates as,
        // PartyB is who gets paid. That split is the whole arrangement.
        $this->assertSame('4123456', $configuration->short_code);
        $this->assertSame('567123', $configuration->partyB());
        $this->assertSame('platform-key', $configuration->consumer_key);
    }

    #[Test]
    public function the_callback_still_comes_back_to_the_right_isp(): void
    {
        $this->tillIs('567123');

        app(PaymentCollector::class)->collect($this->plan, $this->router, '0712345678');

        // Signed by the platform, but the result has to find the ISP it belongs to — the
        // token in the callback URL is what says which.
        $this->assertStringContainsString(
            MpesaConfiguration::query()->sole()->callback_token,
            (string) $this->mpesa->lastPush()['callback'],
        );
    }

    #[Test]
    public function an_isp_with_its_own_daraja_app_keeps_using_it(): void
    {
        // A working setup is not taken away because a better default arrived.
        $configuration = MpesaConfiguration::query()->create([
            'short_code' => '174379',
            'consumer_key' => 'their-key',
            'consumer_secret' => 'their-secret',
            'passkey' => 'their-passkey',
        ]);

        $resolved = app(DarajaCredentials::class)->resolve($configuration);

        $this->assertSame('their-key', $resolved->consumer_key);
        $this->assertSame('174379', $resolved->short_code);
    }

    #[Test]
    public function an_installation_with_no_platform_app_still_asks_for_credentials(): void
    {
        config(['platform.mpesa.consumer_key' => null]);

        $owner = $this->owner();

        // Nothing to sign with, so the honest answer is to ask — rather than accept a till
        // number and quietly fail every payment.
        Livewire::actingAs($owner)
            ->test('pages::settings.mpesa')
            ->set('collection_number', '567123')
            ->set('account_reference', 'KAWANGWARE')
            ->call('save')
            ->assertHasErrors(['consumer_key', 'consumer_secret', 'passkey']);
    }

    #[Test]
    public function something_that_is_not_a_till_number_is_refused(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test('pages::settings.mpesa')
            ->set('collection_number', 'my till')
            ->set('account_reference', 'KAWANGWARE')
            ->call('save')
            ->assertHasErrors(['collection_number']);
    }

    #[Test]
    public function the_screen_no_longer_asks_an_isp_for_a_consumer_key(): void
    {
        $this->actingAs($this->owner())
            ->get(route('settings.mpesa'))
            ->assertOk()
            ->assertSee('You only need your till or paybill number')
            ->assertDontSee('Consumer secret');
    }

    #[Test]
    public function one_isps_till_is_never_used_for_anothers_customers(): void
    {
        $this->tillIs('567123');

        $other = Tenant::factory()->create();

        Tenancy::forTenant($other, function (): void {
            MpesaConfiguration::query()->create(['short_code' => '999999', 'party_b' => '999999']);

            $router = Router::factory()->online()->create();
            $plan = Plan::factory()->create(['code' => 'theirs', 'price' => 20]);

            app(PaymentCollector::class)->collect($plan, $router, '0712345678');
        });

        $this->assertSame('999999', $this->mpesa->lastConfiguration()->partyB());
    }

    protected function tillIs(string $number): MpesaConfiguration
    {
        return MpesaConfiguration::query()->create([
            'short_code' => $number,
            'party_b' => $number,
            'transaction_type' => 'CustomerBuyGoodsOnline',
        ]);
    }

    protected function owner(): User
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        return $owner;
    }
}
