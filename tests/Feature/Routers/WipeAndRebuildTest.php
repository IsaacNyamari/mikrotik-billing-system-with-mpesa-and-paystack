<?php

namespace Tests\Feature\Routers;

use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Provisioning\RouterSetupScript;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

/**
 * Wiping a router and rebuilding it from nothing.
 *
 * The most destructive thing this platform can be asked to do, and the one where a mistake
 * costs somebody a drive across town with a laptop.
 */
class WipeAndRebuildTest extends TestCase
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
            'wireguard.endpoint' => 'isp.solforbs.com',
            'app.url' => 'https://isp.solforbs.com',
        ]);

        // The URL generator takes its root at boot, so setting config alone would leave the
        // generated link pointing at whatever APP_URL the developer happens to have.
        URL::forceRootUrl('https://isp.solforbs.com');
        URL::forceScheme('https');

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->router = Router::factory()->create(['name' => 'Kwa Mwaura', 'slug' => 'kwa-mwaura']);
    }

    #[Test]
    public function the_paste_downloads_the_configuration_before_it_resets(): void
    {
        $script = $this->wipeScript();

        // reset-configuration reboots immediately, so anything after it in the paste is never
        // read. The configuration has to already be a file on the device.
        $fetchAt = strpos($script, '/tool/fetch');
        $resetAt = strpos($script, '/system/reset-configuration');

        $this->assertNotFalse($fetchAt);
        $this->assertNotFalse($resetAt);
        $this->assertLessThan($resetAt, $fetchAt);

        $this->assertStringContainsString('run-after-reset=platform-setup.rsc', $script);
        $this->assertStringContainsString('no-defaults=yes', $script);

        // RouterOS rejects a fetch whose declared mode disagrees with the URL.
        $this->assertStringContainsString('mode=https', $script);
        $this->assertStringContainsString('url="https://isp.solforbs.com/provision/', $script);
    }

    #[Test]
    public function it_refuses_to_reset_when_the_download_failed(): void
    {
        // Resetting without the file would leave the router wiped, unconfigured and
        // unreachable — the exact outcome this whole feature exists to avoid.
        $this->assertStringContainsString(':error', $this->wipeScript());
        $this->assertStringContainsString('/file find name="platform-setup.rsc"', $this->wipeScript());
    }

    #[Test]
    public function the_rebuilt_router_is_given_a_way_back_to_the_internet(): void
    {
        $this->wipeScript();

        $file = app(RouterSetupScript::class)->configurationFile($this->router->fresh());

        // A reset leaves no DHCP client and no NAT. Without these the router comes back with
        // no route out, never reaches us, and is only recoverable by walking to it.
        $this->assertStringContainsString('/ip/dhcp-client', $file);
        $this->assertStringContainsString('interface="ether1"', $file);
        $this->assertStringContainsString('action=masquerade', $file);
    }

    #[Test]
    public function the_rebuilt_router_is_not_left_open_to_the_internet(): void
    {
        $this->wipeScript();

        $file = app(RouterSetupScript::class)->configurationFile($this->router->fresh());

        // A wiped MikroTik has no firewall at all, so the stock protection has to be put back
        // explicitly or the device is answering the whole internet.
        $this->assertStringContainsString('chain=input connection-state=invalid action=drop', $file);
        $this->assertStringContainsString('set telnet disabled=yes', $file);
        $this->assertStringContainsString('set www disabled=yes', $file);
    }

    #[Test]
    public function the_rebuilt_router_still_gets_the_tunnel_and_the_hotspot(): void
    {
        $this->wipeScript();

        $file = app(RouterSetupScript::class)->configurationFile($this->router->fresh());

        $this->assertStringContainsString('/interface/wireguard', $file);
        $this->assertStringContainsString('persistent-keepalive', $file);
        $this->assertStringContainsString('/ip/hotspot', $file);
        $this->assertStringContainsString('platform-portal', $file);
    }

    #[Test]
    public function the_router_can_fetch_its_configuration_once(): void
    {
        $this->wipeScript();

        $token = $this->router->fresh()->provision_token;

        $this->get(route('routers.provision-script', ['token' => $token]))
            ->assertOk()
            ->assertHeader('content-type', 'text/plain; charset=utf-8')
            ->assertSee('/interface/wireguard', escape: false);

        // Single-use, as the specification requires of a provisioning token. It is public by
        // necessity — the router has no credentials yet — so the token is the only guard.
        $this->get(route('routers.provision-script', ['token' => $token]))
            ->assertNotFound();
    }

    #[Test]
    public function an_expired_link_hands_out_nothing(): void
    {
        $this->wipeScript();

        $router = $this->router->fresh();
        $router->provision_token_expires_at = now()->subMinute();
        $router->save();

        $this->get(route('routers.provision-script', ['token' => $router->provision_token]))
            ->assertNotFound();
    }

    #[Test]
    public function a_guessed_token_hands_out_nothing(): void
    {
        $this->get(route('routers.provision-script', ['token' => str_repeat('a', 48)]))
            ->assertNotFound();
    }

    #[Test]
    public function regenerating_the_script_keeps_the_link_the_operator_copied(): void
    {
        $this->wipeScript();
        $first = $this->router->fresh()->provision_token;

        // The setup screen rebuilds the script on every render. Minting a fresh token each
        // time killed the link the operator had already copied — found in production, as a
        // router asking for a token the database had replaced minutes earlier while its owner
        // was walking to the device.
        $this->wipeScript();
        $this->wipeScript();

        $this->assertSame($first, $this->router->fresh()->provision_token);
        $this->get(route('routers.provision-script', ['token' => $first]))->assertOk();
    }

    #[Test]
    public function a_spent_link_is_replaced_rather_than_reused(): void
    {
        $this->wipeScript();
        $spent = $this->router->fresh()->provision_token;

        $this->get(route('routers.provision-script', ['token' => $spent]))->assertOk();

        // Single-use, as the specification requires — so the next script has to carry a new
        // one or it would be born broken.
        $this->wipeScript();

        $this->assertNotSame($spent, $this->router->fresh()->provision_token);
        $this->get(route('routers.provision-script', ['token' => $this->router->fresh()->provision_token]))->assertOk();
    }

    #[Test]
    public function wiping_is_the_default_and_keeping_the_configuration_has_to_be_chosen(): void
    {
        $fresh = Router::factory()->create(['slug' => 'fresh']);

        // Every router is built from nothing unless someone asks to keep what is there.
        $this->assertStringContainsString('/system/reset-configuration', app(RouterSetupScript::class)->for($fresh));

        $kept = Router::factory()->create(['slug' => 'kept']);
        $this->assertStringNotContainsString('/system/reset-configuration', app(RouterSetupScript::class)->for($kept, ['wipe' => false]));
        $this->assertFalse($kept->fresh()->setupOptions()['wipe']);
    }

    #[Test]
    public function the_way_in_is_built_before_anything_that_can_fail(): void
    {
        $file = $this->configuration();

        $access = strpos($file, '/tool/mac-server/mac-winbox set allowed-interface-list=all');
        $this->assertNotFalse($access);
        $this->assertStringContainsString('/interface/bridge add name="bridge-tech"', $file);
        $this->assertStringContainsString('address=192.168.88.1/24 interface="bridge-tech"', $file);

        // Before the uplink, the tunnel and the HotSpot, any of which may fail on some board.
        $this->assertLessThan(strpos($file, 'the uplink on ether1'), $access);
        $this->assertLessThan(strpos($file, '/interface/wireguard'), $access);
        $this->assertLessThan(strpos($file, '/ip/hotspot'), $access);

        // A missing port is logged, never a reason to stop.
        $this->assertStringNotContainsString(':error', $file);
    }

    #[Test]
    public function radio_commands_cannot_stop_the_file(): void
    {
        $file = $this->configuration();

        // A board without one radio package does not recognise that package's menu, and an
        // unrecognised command anywhere in a run-after-reset file stops all of it.
        foreach (explode("\n", $file) as $line) {
            if (str_contains($line, '/interface/wifi') || str_contains($line, '/interface/wireless')) {
                $this->assertStringContainsString('[[:parse', $line);
            }
        }
    }

    #[Test]
    public function the_paste_resets_without_stopping_to_ask(): void
    {
        $script = $this->wipeScript();

        $this->assertStringContainsString('/system/script run platform-reset', $script);
        $this->assertStringNotContainsString("\n/system/reset-configuration", $script);
    }

    #[Test]
    public function only_someone_who_can_provision_may_wipe_a_router(): void
    {
        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        $this->actingAs($agent)
            ->get(route('routers.setup', $this->router))
            ->assertForbidden();
    }

    #[Test]
    public function nothing_from_the_internet_reaches_the_rebuilt_router(): void
    {
        $file = $this->configuration();

        // The hole this closes: no final drop meant Winbox and SSH answered the WAN, on an
        // admin account a reset had put back to a blank password.
        $this->assertStringContainsString('add name=LAN include=all exclude=WAN', $file);
        $this->assertStringContainsString('add list=WAN interface="ether1"', $file);
        $this->assertStringContainsString('chain=input in-interface-list=!LAN action=drop', $file);
        $this->assertStringContainsString('connection-nat-state=!dstnat in-interface-list=WAN action=drop', $file);
        $this->assertStringContainsString('out-interface-list=WAN action=masquerade', $file);

        // Layer-2 access is deliberately left on every port: it is the way in that survives
        // every other mistake, and the strong ISP administrator password is what guards it.
        $this->assertStringContainsString('/tool/mac-server set allowed-interface-list=all', $file);
        $this->assertStringContainsString('/tool/mac-server/mac-winbox set allowed-interface-list=all', $file);
        $this->assertStringContainsString('discover-interface-list=all', $file);
        $this->assertStringContainsString('set www-ssl disabled=yes', $file);

        // The final drop comes after the accepts, or it would drop those too.
        $this->assertLessThan(
            strpos($file, 'in-interface-list=!LAN action=drop'),
            strpos($file, 'connection-state=established,related action=accept'),
        );
    }

    #[Test]
    public function customers_are_not_offered_a_winbox_login(): void
    {
        $file = $this->configuration();

        $this->assertStringContainsString('dst-port=8291,22 src-address=10.10.10.0/24 action=drop', $file);
    }

    #[Test]
    public function the_stock_admin_is_replaced_by_an_administrator_of_the_isps_own(): void
    {
        $file = $this->configuration();
        $router = $this->router->fresh();

        $this->assertSame('isp-admin', $router->admin_username);
        $this->assertSame(20, strlen((string) $router->admin_password));
        $this->assertStringContainsString('add name="isp-admin" group=full password="'.$router->admin_password.'"', $file);
        $this->assertStringContainsString('/user disable [find name="admin"]', $file);

        // Encrypted at rest, like every other router secret.
        $raw = $router->getConnection()->table('routers')->where('id', $router->id)->value('admin_password');
        $this->assertNotSame($router->admin_password, $raw);
    }

    #[Test]
    public function the_administrator_password_does_not_change_each_time_the_page_renders(): void
    {
        $this->wipeScript();
        $first = $this->router->fresh()->admin_password;

        $this->wipeScript();
        $this->wipeScript();

        // A password that changed on every render would never match the one the router was
        // actually built with.
        $this->assertSame($first, $this->router->fresh()->admin_password);
    }

    #[Test]
    public function a_router_rebuilt_without_an_administrator_password_is_given_one(): void
    {
        $this->wipeScript();

        $router = $this->router->fresh();
        $router->admin_password = null;
        $router->save();

        $file = app(RouterSetupScript::class)->configurationFile($router);

        $this->assertNotEmpty($router->fresh()->admin_password);
        $this->assertStringNotContainsString('password=""', $file);
    }

    #[Test]
    public function a_static_uplink_is_written_with_its_route_and_dns(): void
    {
        $file = $this->configuration([
            'wan_mode' => 'static',
            'wan_address' => '196.201.1.10/29',
            'wan_gateway' => '196.201.1.9',
            'wan_dns' => '9.9.9.9, 149.112.112.112',
        ]);

        // Inside a deferred block, so the quotes arrive escaped once.
        $this->assertStringContainsString('/ip/address add address=196.201.1.10/29 interface=\\"ether1\\" comment=\\"Uplink\\"', $file);
        $this->assertStringContainsString('/ip/route add dst-address=0.0.0.0/0 gateway=196.201.1.9 comment=\\"Uplink\\"', $file);
        $this->assertStringContainsString('set servers=9.9.9.9,149.112.112.112', $file);
        $this->assertStringNotContainsString('/ip/dhcp-client', $file);
    }

    #[Test]
    public function an_incomplete_static_uplink_is_refused_before_any_link_is_issued(): void
    {
        try {
            $this->wipeScript(['wan_mode' => 'static', 'wan_address' => '196.201.1.10', 'wan_gateway' => '']);
            $this->fail('An incomplete static uplink produced a wipe script.');
        } catch (\InvalidArgumentException) {
            // Expected: a router built from it would come back with no way out.
        }

        $this->assertNull($this->router->fresh()->provision_token);
    }

    #[Test]
    public function a_pppoe_uplink_dials_out_and_its_password_is_kept_encrypted(): void
    {
        $file = $this->configuration([
            'wan_mode' => 'pppoe',
            'wan_pppoe_user' => 'kwamwaura@fibre',
            'wan_pppoe_password' => 'pa"ss$word\\x',
        ]);

        // The three characters that mean something inside a RouterOS string are escaped, so
        // the password is the password and not the end of the command.
        // Escaped twice: once for the RouterOS string, once more for the deferred block it
        // sits inside, which RouterOS unescapes before parsing.
        $this->assertStringContainsString('user=\\"kwamwaura@fibre\\" password=\\"pa\\\\\\"ss\\\\\\$word\\\\\\\\x\\"', $file);
        $this->assertStringContainsString('add name=\\"pppoe-uplink\\" interface=\\"ether1\\"', $file);
        $this->assertStringContainsString('add list=WAN interface=\\"pppoe-uplink\\"', $file);

        $router = $this->router->fresh();
        $this->assertSame('pa"ss$word\\x', $router->wan_pppoe_password);
        $this->assertArrayNotHasKey('wan_pppoe_password', $router->setup_options);
    }

    #[Test]
    public function a_pppoe_uplink_without_a_password_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->wipeScript(['wan_mode' => 'pppoe', 'wan_pppoe_user' => 'kwamwaura@fibre']);
    }

    #[Test]
    public function the_rebuild_waits_for_the_network_instead_of_guessing(): void
    {
        $file = $this->configuration();

        $this->assertStringNotContainsString(':delay 10s;', $file);
        $this->assertStringContainsString('[/interface find name="ether1"]', $file);
        $this->assertStringContainsString('find where active and dst-address="0.0.0.0/0"', $file);
        $this->assertStringContainsString(':resolve "isp.solforbs.com"', $file);
        $this->assertStringContainsString('/system/ntp/client set enabled=yes', $file);

        // Nobody watches a run-after-reset script; the log is the only account of it.
        $this->assertStringContainsString(':log info "platform-setup: starting"', $file);
        $this->assertStringContainsString(':log info "platform-setup: complete"', $file);

        // The local variables are RouterOS's, not PHP's.
        $this->assertStringContainsString('$waited < 60', $file);
    }

    #[Test]
    public function the_platform_may_reboot_the_router(): void
    {
        $this->assertStringContainsString('policy=read,write,test,api,sensitive,ftp,reboot', $this->configuration());
    }

    #[Test]
    public function viewing_the_administrator_password_is_audited(): void
    {
        $this->wipeScript();

        $engineer = User::factory()->forTenant($this->tenant)->create();
        $engineer->assignRole(Roles::NETWORK_ENGINEER);
        $this->actingAs($engineer);

        $password = (string) $this->router->fresh()->admin_password;

        Livewire::test('pages::routers.setup', ['router' => $this->router])
            ->assertDontSee($password)
            ->call('revealAdminPassword')
            ->assertSee($password);

        $this->assertSame(1, AuditLog::query()->where('event', 'router.admin_password_revealed')->count());
    }

    #[Test]
    public function the_pppoe_password_typed_on_the_page_is_stored_and_not_kept_in_the_page(): void
    {
        $engineer = User::factory()->forTenant($this->tenant)->create();
        $engineer->assignRole(Roles::NETWORK_ENGINEER);
        $this->actingAs($engineer);

        Livewire::test('pages::routers.setup', ['router' => $this->router])
            ->set('wipe', true)
            ->set('wanMode', 'pppoe')
            ->set('wanPppoeUser', 'kwamwaura@fibre')
            ->set('wanPppoePassword', 'upstream-secret')
            ->assertSet('wanPppoePassword', '')
            ->assertSee('run-after-reset');

        $this->assertSame('upstream-secret', $this->router->fresh()->wan_pppoe_password);
    }

    #[Test]
    public function the_page_explains_an_incomplete_uplink_instead_of_showing_a_script(): void
    {
        $engineer = User::factory()->forTenant($this->tenant)->create();
        $engineer->assignRole(Roles::NETWORK_ENGINEER);
        $this->actingAs($engineer);

        Livewire::test('pages::routers.setup', ['router' => $this->router])
            ->set('wipe', true)
            ->set('wanMode', 'static')
            ->assertSee('No script until the uplink is complete')
            ->assertDontSee('run-after-reset');
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function configuration(array $options = []): string
    {
        $this->wipeScript($options);

        return app(RouterSetupScript::class)->configurationFile($this->router->fresh());
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function wipeScript(array $options = []): string
    {
        return app(RouterSetupScript::class)->for($this->router, array_merge([
            'wipe' => true,
            'lan_interface' => 'ether3',
            'wan_interface' => 'ether1',
        ], $options));
    }
}
