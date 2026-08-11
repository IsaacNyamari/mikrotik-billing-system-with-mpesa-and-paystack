<?php

namespace Tests\Feature\Routers;

use App\Models\PortalSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\Provisioning\RouterSetupScript;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

/**
 * The wifi customers connect to, and the PPPoE server that answers fixed lines.
 *
 * A HotSpot with no wireless network is one nobody can find, and PPP secrets with no server
 * are credentials nothing ever asks for.
 */
class WirelessAndPppoeTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        config([
            'wireguard.enabled' => true,
            'wireguard.public_key' => 'c2VydmVycHVibGlja2V5MDAwMDAwMDAwMDAwMDAwMDAwMD0=',
        ]);

        $this->tenant = Tenant::factory()->create(['name' => 'Kawangware Wifi']);
        Tenancy::set($this->tenant);

        $this->router = Router::factory()->create(['slug' => 'kwa-mwaura']);
    }

    #[Test]
    public function the_wifi_is_named_after_the_isp_by_default(): void
    {
        $script = $this->script();

        // A wifi list is not the place to meet "MikroTik".
        $this->assertStringContainsString('ssid="Kawangware Wifi"', $script);
    }

    #[Test]
    public function the_isps_own_brand_name_wins_over_the_tenant_name(): void
    {
        PortalSetting::query()->create(['brand_name' => 'Pro Spots']);

        $this->assertStringContainsString('ssid="Pro Spots"', $this->script());
    }

    #[Test]
    public function an_operator_can_name_the_network_themselves(): void
    {
        $this->assertStringContainsString('ssid="Kawangware Free Wifi"', $this->script(['ssid' => 'Kawangware Free Wifi']));
    }

    #[Test]
    public function the_network_is_open_because_the_hotspot_does_the_checking(): void
    {
        $script = $this->script();

        // A wifi password would turn a customer away before they could buy anything.
        $this->assertStringContainsString('mode=none', $script);
        $this->assertStringContainsString('security.authentication-types=""', $script);
    }

    #[Test]
    public function it_works_on_both_radio_stacks(): void
    {
        $script = $this->script();

        // Older boards have /interface/wireless, newer ones the wifi package, and the
        // commands are not interchangeable. The device is asked rather than guessed at.
        $this->assertStringContainsString('/interface/wireless find', $script);
        $this->assertStringContainsString('/interface/wifi find', $script);
    }

    #[Test]
    public function the_radio_joins_the_hotspot_bridge(): void
    {
        $script = $this->script();

        // Otherwise the SSID appears and hands out nothing.
        $this->assertMatchesRegularExpression('/bridge="bridge-hotspot" interface="wlan1"/', $script);
    }

    #[Test]
    public function an_ssid_cannot_close_the_string_it_sits_in(): void
    {
        $script = $this->script(['ssid' => 'Free" ; /user add name=backdoor group=full ; :put "']);

        // The quotes it would need to break out are gone, and so is everything that reads
        // like a command — a script nobody can skim is a script nobody can check.
        $this->assertStringNotContainsString('name=backdoor', $script);
        $this->assertStringNotContainsString('group=full', $script);
        $this->assertStringNotContainsString('/user add', $script);

        // And exactly one user is still created: the platform's own.
        $this->assertSame(1, substr_count($script, 'add name="platform"'));
    }

    #[Test]
    public function a_long_ssid_is_cut_to_what_the_standard_allows(): void
    {
        $script = $this->script(['ssid' => str_repeat('A', 60)]);

        // RouterOS refuses anything past 32 bytes outright.
        preg_match('/ssid="([^"]*)"/', $script, $matches);
        $this->assertLessThanOrEqual(32, strlen($matches[1] ?? ''));
    }

    #[Test]
    public function a_router_with_separate_access_points_can_skip_the_wifi(): void
    {
        $this->assertStringNotContainsString('ap-bridge', $this->script(['with_wireless' => false]));
    }

    #[Test]
    public function pppoe_is_off_unless_it_is_asked_for(): void
    {
        // Most HotSpot routers have no fixed-line subscribers at all.
        $this->assertStringNotContainsString('/interface/pppoe-server', $this->script());
    }

    #[Test]
    public function a_pppoe_server_gives_the_secrets_something_to_answer(): void
    {
        $script = $this->script(['with_pppoe' => true]);

        $this->assertStringContainsString('/interface/pppoe-server/server', $script);
        $this->assertStringContainsString('service-name="isp"', $script);
        $this->assertStringContainsString('authentication=pap,chap,mschap1,mschap2', $script);

        // A line needs an address to be given, and a way out once it has one.
        $this->assertStringContainsString('/ppp/profile', $script);
        $this->assertStringContainsString('remote-address=', $script);
        $this->assertStringContainsString('action=masquerade comment="platform-pppoe"', $script);
    }

    #[Test]
    public function pppoe_runs_on_its_own_port_by_default(): void
    {
        $script = $this->script(['with_pppoe' => true]);

        // A HotSpot and a PPPoE server on one segment both answer the same customer, and the
        // subscriber ends up at a captive portal.
        $this->assertStringContainsString('interface="ether4"', $script);
    }

    #[Test]
    public function the_choices_are_remembered_for_next_time(): void
    {
        $this->script(['ssid' => 'Pro Spots', 'with_pppoe' => true, 'pppoe_interface' => 'ether5']);

        $options = $this->router->fresh()->setupOptions();

        $this->assertSame('Pro Spots', $options['ssid']);
        $this->assertTrue($options['with_pppoe']);
        $this->assertSame('ether5', $options['pppoe_interface']);
    }

    #[Test]
    public function a_wiped_router_comes_back_with_the_wifi_and_pppoe_too(): void
    {
        $this->script(['wipe' => true, 'ssid' => 'Pro Spots', 'with_pppoe' => true]);

        $file = app(RouterSetupScript::class)->configurationFile($this->router->fresh());

        // A reset leaves the radio off and nameless. A rebuild that stopped at the HotSpot
        // would leave the ISP with nothing customers could join.
        $this->assertStringContainsString('ssid="Pro Spots"', $file);
        $this->assertStringContainsString('/interface/pppoe-server/server', $file);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function script(array $options = []): string
    {
        return app(RouterSetupScript::class)->for($this->router, $options + ['lan_interface' => 'ether3']);
    }
}
