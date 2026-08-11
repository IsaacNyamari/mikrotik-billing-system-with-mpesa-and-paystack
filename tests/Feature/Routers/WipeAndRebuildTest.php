<?php

namespace Tests\Feature\Routers;

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
    public function the_wipe_choice_is_remembered_and_never_the_default(): void
    {
        $fresh = Router::factory()->create(['slug' => 'fresh']);

        // Erasing a router is never something that happens because nobody chose otherwise.
        $this->assertStringNotContainsString(
            '/system/reset-configuration',
            app(RouterSetupScript::class)->for($fresh),
        );

        $this->wipeScript();

        $this->assertTrue($this->router->fresh()->setupOptions()['wipe']);
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

    protected function wipeScript(): string
    {
        return app(RouterSetupScript::class)->for($this->router, [
            'wipe' => true,
            'lan_interface' => 'ether3',
            'wan_interface' => 'ether1',
        ]);
    }
}
