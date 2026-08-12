<?php

namespace Tests\Feature\Portal;

use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PortalSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Mpesa\Testing\FakeMpesaGateway;
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
 * The shape of the portal: packages as tiles, one tap to choose, one to pay.
 */
class PortalLayoutTest extends TestCase
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

        $this->tenant = Tenant::factory()->create(['name' => 'Solforbs Networks']);
        Tenancy::set($this->tenant);

        $this->fakeRouters();
        $this->mpesa = new FakeMpesaGateway;
        $this->app->instance(MpesaGateway::class, $this->mpesa);

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
    public function packages_are_tiles_a_customer_can_price_at_a_glance(): void
    {
        $this->get($this->router->portalUrl())
            ->assertOk()
            // The duration on the badge, the money in the ISP's own words, and one instruction.
            ->assertSee('1 hour')
            ->assertSee('KES')
            ->assertSee('20')
            ->assertSee('Click Here To Connect')
            ->assertSee('Solforbs Networks Login Page');
    }

    #[Test]
    public function tapping_a_package_asks_for_the_number_and_nothing_else(): void
    {
        $component = Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key]);

        // Before choosing, the customer is not asked for anything.
        $component->assertDontSee('M-PESA number');

        $component->call('choose', $this->plan->id)
            ->assertSee('M-PESA number')
            ->assertSee('1 Hour');

        // Choosing must not have started a payment: nothing has been asked of Safaricom yet.
        $this->assertSame([], $this->mpesa->pushes);
        $this->assertSame(0, Payment::query()->count());
    }

    #[Test]
    public function a_customer_can_go_back_and_pick_a_different_package(): void
    {
        Plan::factory()->create(['name' => '1 Week', 'code' => 'weekly', 'price' => 130]);

        Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->call('choose', $this->plan->id)
            ->assertSee('M-PESA number')
            ->call('changePlan')
            ->assertDontSee('M-PESA number')
            ->assertSee('Click Here To Connect');
    }

    #[Test]
    public function the_whole_journey_is_two_taps_and_a_pin(): void
    {
        $component = Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->call('choose', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay')
            ->assertHasNoErrors()
            ->assertSee('Check your phone');

        $payment = Payment::query()->sole();

        $this->assertSame('254712345678', $payment->phone);
        $this->assertSame(20, $this->mpesa->lastPush()['amount']);

        $this->postJson(route('webhooks.mpesa', ['token' => MpesaConfiguration::query()->sole()->callback_token]), [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => 0,
                'ResultDesc' => 'Success',
                'CallbackMetadata' => ['Item' => [
                    ['Name' => 'Amount', 'Value' => 20],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => 'GDGDAHXS'],
                ]],
            ]],
        ])->assertOk();

        $component->call('checkPayment')->assertSee('GDGDAHXS');
    }

    #[Test]
    public function a_package_this_isp_does_not_sell_cannot_be_chosen_by_id(): void
    {
        $other = Tenant::factory()->create();
        $theirs = Tenancy::forTenant($other, fn (): Plan => Plan::factory()->create(['code' => 'theirs']));

        $withheld = Plan::factory()->create(['name' => 'Staff Only', 'code' => 'staff', 'is_public' => false]);

        $component = Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key]);

        // Read from the list the page actually offered, so neither another ISP's package nor
        // one withheld from sale can be reached by typing an id into the wire.
        $component->call('choose', $theirs->id)->assertDontSee('M-PESA number');
        $component->call('choose', $withheld->id)->assertDontSee('M-PESA number');
    }

    #[Test]
    public function a_paid_customer_is_connected_without_pressing_anything(): void
    {
        $component = Livewire::withQueryParams(['link-login-only' => 'http://10.10.10.1/login'])
            ->test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->call('choose', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay');

        $payment = Payment::query()->sole();

        $this->postJson(route('webhooks.mpesa', ['token' => MpesaConfiguration::query()->sole()->callback_token]), [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [['Name' => 'MpesaReceiptNumber', 'Value' => 'GDGDAHXS']]],
            ]],
        ]);

        $html = $component->call('checkPayment')->html();

        // Signed in by navigating to the router, not by posting a form to it. This page is
        // https and the router's login is plain http inside the network — Chrome refuses that
        // form submission, silently, which a customer who has just paid experiences as
        // nothing happening at all.
        $this->assertStringContainsString('http://10.10.10.1/login?username=GDGDAHXS', $html);
        $this->assertStringContainsString('window.location.href', $html);
        $this->assertStringNotContainsString('<form method="post" action="http://10.10.10.1/login"', $html);

        // And a link they can see, because a phone that drifted onto mobile data while paying
        // cannot reach the router at all — the automatic hop fails with nothing to show for it.
        $this->assertStringContainsString('Connect now', $html);
        $this->assertStringContainsString('Rejoin the wifi', $html);
    }

    #[Test]
    public function a_customer_who_arrived_without_the_hotspot_redirect_can_still_connect(): void
    {
        // A bookmark, a shared link, or a captive-portal helper that swallowed the parameters.
        // Showing somebody a code and no way to use it, after they have paid, is the worst
        // outcome this page has.
        $this->router->setup_options = ['hotspot_gateway' => '10.10.10.1'];
        $this->router->save();

        $component = Livewire::test('pages::portal.index', ['portalKey' => $this->router->portal_key])
            ->call('choose', $this->plan->id)
            ->set('phone', '0712345678')
            ->call('pay');

        $payment = Payment::query()->sole();

        $this->postJson(route('webhooks.mpesa', ['token' => MpesaConfiguration::query()->sole()->callback_token]), [
            'Body' => ['stkCallback' => [
                'CheckoutRequestID' => $payment->checkout_request_id,
                'ResultCode' => 0,
                'CallbackMetadata' => ['Item' => [['Name' => 'MpesaReceiptNumber', 'Value' => 'GDGDAHXS']]],
            ]],
        ]);

        // Rebuilt from the router's own gateway rather than a query parameter that never
        // arrived.
        $component->call('checkPayment')
            ->assertSee('http://10.10.10.1/login?username=GDGDAHXS', escape: false)
            ->assertSee('Connect now');
    }

    #[Test]
    public function an_isp_can_reword_and_relayout_the_whole_thing(): void
    {
        PortalSetting::query()->create([
            'layout' => PortalSetting::LAYOUT_LIST,
            'card_style' => 'plain',
            'primary_color' => '#e11d48',
            'accent_color' => '#7c3aed',
            'banner_text' => 'Karibu DJ Wi-Fi',
            'buy_button_text' => 'Bonyeza Hapa',
            'currency_label' => 'Ksh',
        ]);

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('deals deals--list', escape: false)
            ->assertSee('deal deal--plain', escape: false)
            ->assertSee('--accent: #7c3aed', escape: false)
            ->assertSee('Karibu DJ Wi-Fi')
            ->assertSee('Bonyeza Hapa')
            ->assertSee('Ksh');
    }

    #[Test]
    public function an_accent_nobody_chose_follows_the_brand_colour(): void
    {
        PortalSetting::query()->create(['primary_color' => '#e11d48']);

        // A page whose badge colour was picked at random would look like an accident.
        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('--accent: #92132f', escape: false);
    }

    #[Test]
    public function an_isp_that_wants_no_banner_gets_none(): void
    {
        PortalSetting::query()->create(['show_banner' => false]);

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertDontSee('Login Page')
            ->assertSee('Click Here To Connect');
    }

    #[Test]
    public function an_operator_sets_the_layout_from_the_settings_screen(): void
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner);

        Livewire::test('pages::settings.portal')
            ->set('layout', PortalSetting::LAYOUT_LIST)
            ->set('card_style', 'plain')
            ->set('accent_color', '#7c3aed')
            ->set('buy_button_text', 'Bonyeza Hapa')
            ->set('currency_label', 'Ksh')
            ->call('save')
            ->assertHasNoErrors();

        $setting = PortalSetting::query()->sole();

        $this->assertSame(PortalSetting::LAYOUT_LIST, $setting->layout);
        $this->assertSame('plain', $setting->card_style);
        $this->assertSame('#7c3aed', $setting->accent_color);
        $this->assertSame('Bonyeza Hapa', $setting->buy_button_text);
        $this->assertSame('Ksh', $setting->currency_label);
    }

    #[Test]
    public function an_accent_that_is_not_a_colour_is_refused(): void
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        // It is written straight into a stylesheet, so it is held to the same rule as the
        // brand colour.
        $this->actingAs($owner);

        Livewire::test('pages::settings.portal')
            ->set('accent_color', 'red; } body { display: none')
            ->call('save')
            ->assertHasErrors(['accent_color']);
    }

    #[Test]
    public function a_layout_that_does_not_exist_is_refused(): void
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner);

        Livewire::test('pages::settings.portal')
            ->set('layout', 'carousel')
            ->call('save')
            ->assertHasErrors(['layout']);
    }
}
