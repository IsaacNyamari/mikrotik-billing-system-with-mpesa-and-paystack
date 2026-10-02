<?php

namespace Tests\Feature\Routers;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Provisioning\RouterSetupScript;
use App\Services\Provisioning\TunnelAllocator;
use App\Services\Provisioning\WireGuardKeypair;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The script an operator pastes into a router that nobody can reach.
 *
 * It is the only channel that always works, so what it contains has to be right the first
 * time — there is no second attempt over the API to correct it.
 */
class RouterSetupScriptTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        config([
            'wireguard.public_key' => 'c2VydmVycHVibGlja2V5MDAwMDAwMDAwMDAwMDAwMDAwMD0=',
            'wireguard.endpoint' => 'isp.solforbs.com',
            'wireguard.listen_port' => 51820,
            'app.url' => 'https://isp.solforbs.com',
        ]);

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->router = Router::factory()->create(['name' => 'Kwa Mwaura', 'slug' => 'kwa-mwaura']);
    }

    #[Test]
    public function the_router_dials_out_rather_than_being_dialled(): void
    {
        $script = $this->script();

        // The whole point: no public address, no port forwarded, and a keepalive that stops
        // the NAT mapping expiring the moment the router goes quiet.
        $this->assertStringContainsString('endpoint-address=isp.solforbs.com', $script);
        $this->assertStringContainsString('endpoint-port=51820', $script);
        $this->assertStringContainsString(
            'persistent-keepalive='.config('wireguard.keepalive_seconds').'s',
            $script,
        );
    }

    #[Test]
    public function the_tunnel_is_configured_to_stay_awake_and_to_answer_quickly(): void
    {
        $script = $this->script();

        // A tunnel that has to be rebuilt is what a customer experiences as a slow portal, so
        // the keepalive has to be inside both the usual NAT timeout and WireGuard's own two
        // minute rekey. Asserted as a bound rather than a number: the value may be tuned, but
        // going above 30 would quietly reintroduce the delay this is here to remove.
        $this->assertLessThanOrEqual(30, (int) config('wireguard.keepalive_seconds'));

        // Under RouterOS's 1420 default, so a PPPoE line or a mobile uplink cannot fragment
        // management traffic — which fails as a command that hangs rather than one that errors.
        $this->assertStringContainsString('mtu='.config('wireguard.mtu'), $script);
        $this->assertLessThan(1420, (int) config('wireguard.mtu'));

        // Management traffic leaves mangle before any marking rule sees it, so a queue tree an
        // ISP adds later cannot put a provisioning command behind a customer's download.
        $this->assertStringContainsString('comment="platform-management"', $script);

        // The tunnel is nudged awake at the end of the paste rather than waiting for the first
        // keepalive, so the platform can reach the router the moment the operator is finished.
        $this->assertStringContainsString('/ping address=10.8.0.1', $script);
    }

    #[Test]
    public function the_api_is_reachable_down_the_tunnel_and_nowhere_else(): void
    {
        $script = $this->script();

        // The specification is emphatic: never expose the API publicly when a management
        // tunnel is available.
        $this->assertStringContainsString('set api address=10.8.0.0/24', $script);
        $this->assertStringContainsString('src-address=10.8.0.1 \\', $script);
        $this->assertStringContainsString('action=drop comment="platform-api-drop"', $script);
        $this->assertStringContainsString('set api-ssl disabled=yes', $script);
    }

    #[Test]
    public function the_api_user_can_write_the_portal_pages_but_not_make_itself_an_admin(): void
    {
        $script = $this->script();

        // RouterOS ties file writing to the ftp policy, and /tool/fetch is how the captive
        // portal's login page reaches the device. Without it the portal install fails with
        // "cannot open file: permission denied" — found on real hardware, not in theory.
        $this->assertMatchesRegularExpression('/policy=[a-z,]*\bftp\b/', $script);

        // But not "policy", which would let this account create users with any rights at all.
        $this->assertDoesNotMatchRegularExpression('/policy=[a-z,]*\bpolicy\b/', $script);
    }

    #[Test]
    public function a_script_generated_from_a_developer_machine_says_so(): void
    {
        config(['app.url' => 'http://192.168.0.100:8000']);

        // The difference between a laptop and the live platform is invisible once the script
        // has been copied somewhere else, so it has to be written into the script itself.
        $this->assertStringContainsString('WARNING', app(RouterSetupScript::class)->for($this->router));

        config(['app.url' => 'https://isp.solforbs.com']);

        $this->assertStringNotContainsString('WARNING', app(RouterSetupScript::class)->for($this->router));
    }

    #[Test]
    public function the_platform_starts_talking_to_the_router_over_the_tunnel(): void
    {
        $this->script();

        $this->router->refresh();

        // Not the address an operator typed when they added it: that one is unreachable from
        // here, which is the problem this solves.
        $this->assertSame($this->router->wg_address, $this->router->host);
        $this->assertTrue($this->router->reachedOverTunnel());
    }

    #[Test]
    public function the_customer_can_reach_the_page_where_they_pay(): void
    {
        $script = $this->script();

        // Without the walled garden a customer is redirected to a portal they cannot load,
        // which to them is simply broken wifi.
        $this->assertStringContainsString('add dst-host="isp.solforbs.com" action=allow', $script);
        $this->assertStringContainsString('login-by=http-chap,http-pap', $script);

        // The HotSpot's HTTP proxy walled garden only covers port 80, so an https portal
        // needs the IP-level rule as well or it is refused outright.
        $this->assertStringContainsString('/ip/hotspot/walled-garden/ip', $script);
        $this->assertStringContainsString('comment="platform-portal"', $script);
    }

    #[Test]
    public function the_router_is_given_the_login_page_that_hands_customers_to_the_portal(): void
    {
        $script = $this->script();

        // Without it the router serves MikroTik's own page, asking for a username and
        // password the customer has never been given.
        $this->assertStringContainsString('dst-path=hotspot/login.html', $script);
        $this->assertStringContainsString('dst-path=hotspot/alogin.html', $script);
        $this->assertStringContainsString('html-directory=hotspot', $script);

        // Carrying this router's own portal key, so a script copied from one device can never
        // point another at the wrong ISP's packages.
        $this->assertStringContainsString($this->router->portal_key, $script);
    }

    #[Test]
    public function the_script_carries_credentials_the_platform_actually_holds(): void
    {
        $script = $this->script();

        $this->router->refresh();

        // A script whose password differs from the stored one produces a router the platform
        // cannot log in to — and no error until the first provisioning attempt fails.
        $this->assertStringContainsString('password="'.$this->router->api_password.'"', $script);
        $this->assertStringContainsString('private-key="'.$this->router->wg_private_key.'"', $script);
    }

    #[Test]
    public function the_tunnel_private_key_is_encrypted_at_rest(): void
    {
        $this->script();

        $stored = DB::table('routers')->where('id', $this->router->id)->sole();

        // It is the router's identity on the management network — as sensitive as the API
        // password sitting beside it.
        $this->assertNotSame($this->router->fresh()->wg_private_key, $stored->wg_private_key);
    }

    #[Test]
    public function generating_twice_keeps_the_same_tunnel(): void
    {
        $this->script();
        $address = $this->router->fresh()->wg_address;
        $key = $this->router->fresh()->wg_private_key;

        $this->script();

        // An operator who regenerates a script must get the same tunnel back, or every router
        // they have already deployed would need re-pasting.
        $this->assertSame($address, $this->router->fresh()->wg_address);
        $this->assertSame($key, $this->router->fresh()->wg_private_key);
    }

    #[Test]
    public function rotating_issues_new_keys_but_keeps_the_address(): void
    {
        $this->script();

        $address = $this->router->fresh()->wg_address;
        $key = $this->router->fresh()->wg_private_key;

        app(TunnelAllocator::class)->rotate($this->router);

        $this->assertNotSame($key, $this->router->fresh()->wg_private_key);
        // The address is what everything else refers to, so it stays.
        $this->assertSame($address, $this->router->fresh()->wg_address);
        $this->assertNull($this->router->fresh()->wg_configured_at);
    }

    #[Test]
    public function every_router_gets_its_own_address_across_every_isp(): void
    {
        $this->script();

        $other = Tenant::factory()->create();

        // Read as that ISP throughout: the tenancy guard refuses to touch another tenant's
        // router from this one's context, which is the protection working rather than an
        // obstacle to route around.
        $theirAddress = Tenancy::forTenant($other, function (): ?string {
            $router = Router::factory()->create(['slug' => 'theirs']);

            return app(TunnelAllocator::class)->ensure($router)->wg_address;
        });

        // One tunnel serves the whole platform. Two routers on one address would point
        // provisioning at whichever answered first.
        $this->assertNotSame($this->router->fresh()->wg_address, $theirAddress);
        $this->assertNotNull($theirAddress);
    }

    #[Test]
    public function an_address_belonging_to_a_deleted_router_is_not_reissued(): void
    {
        $this->script();

        $address = $this->router->fresh()->wg_address;
        $this->router->delete();

        $replacement = Router::factory()->create(['slug' => 'replacement']);
        app(TunnelAllocator::class)->ensure($replacement);

        // The deleted router's address is still written into a device somewhere until
        // somebody goes and removes it.
        $this->assertNotSame($address, $replacement->fresh()->wg_address);
    }

    #[Test]
    public function a_keypair_is_a_valid_curve25519_pair(): void
    {
        $pair = WireGuardKeypair::generate();

        $this->assertSame(32, strlen((string) base64_decode($pair->privateKey, true)));
        $this->assertSame(32, strlen((string) base64_decode($pair->publicKey, true)));
        // Clamping is the part everyone forgets, and an unclamped key never completes a
        // handshake. Deriving the public half again proves the stored private one is usable.
        $this->assertSame($pair->publicKey, WireGuardKeypair::publicKeyFor($pair->privateKey));
    }

    #[Test]
    public function a_router_with_a_network_already_built_can_skip_the_hotspot(): void
    {
        $script = app(RouterSetupScript::class)->for($this->router, ['with_hotspot' => false]);

        $this->assertStringContainsString('/interface/wireguard', $script);
        // Rebuilding a bridge on a working network is how a router loses its own uplink.
        $this->assertStringNotContainsString('/interface/bridge', $script);
    }

    #[Test]
    public function an_interface_name_cannot_smuggle_anything_into_the_script(): void
    {
        $script = app(RouterSetupScript::class)->for($this->router, [
            'lan_interface' => 'ether3" ; /user add name=backdoor password=x group=full ; :put "',
        ]);

        // Names are written inside RouterOS quotes, so everything that could close one or
        // start a second command is stripped. What survives is an inert name.
        $this->assertStringNotContainsString('name=backdoor', $script);
        $this->assertStringNotContainsString('group=full', $script);

        // And exactly one user is created — the platform's own.
        $this->assertSame(1, substr_count($script, 'add name="platform"'));
    }

    #[Test]
    public function the_setup_screen_needs_the_right_to_provision(): void
    {
        $owner = User::factory()->forTenant($this->tenant)->create();
        $owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($owner)
            ->get(route('routers.setup', $this->router))
            ->assertOk()
            ->assertSee('endpoint-address=isp.solforbs.com');

        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        // The script hands out a key and a password: reading it is provisioning.
        $this->actingAs($agent)
            ->get(route('routers.setup', $this->router))
            ->assertForbidden();
    }

    #[Test]
    public function one_isp_cannot_read_anothers_setup_script(): void
    {
        $other = Tenant::factory()->create();
        $intruder = User::factory()->forTenant($other)->create();
        $intruder->assignRole(Roles::ISP_OWNER);

        $this->actingAs($intruder)
            ->get(route('routers.setup', $this->router))
            ->assertNotFound();
    }

    #[Test]
    public function the_server_side_peers_are_rendered_for_every_router(): void
    {
        $this->script();

        // WireGuard is mutual: a router that knows about us gets nowhere unless we also know
        // about it.
        $this->artisan('wireguard:peers')
            ->expectsOutputToContain((string) $this->router->fresh()->wg_public_key)
            ->assertSuccessful();
    }

    #[Test]
    public function the_api_is_closed_to_everything_but_the_tunnel_by_default(): void
    {
        // The allowance below exists for a router on a developer's desk. A platform serving
        // real ISPs must never emit it: those routers sit on somebody else's LAN, and whoever
        // else is on that LAN is not us.
        config(['wireguard.local_api_subnet' => '']);

        $script = $this->script();

        $this->assertStringContainsString('set api address=10.8.0.0/24 port=8728', $script);

        // The comment itself still appears, in the line that removes any allowance granted by
        // an earlier run — regenerating without one has to revoke it. What must be absent is
        // the rule that grants it.
        $this->assertStringNotContainsString('action=accept comment="platform-api-allow-local"', $script);
        $this->assertStringContainsString('remove [find comment="platform-api-allow-local"]', $script);
    }

    #[Test]
    public function a_bench_machine_can_be_let_in_alongside_the_tunnel(): void
    {
        // Pasting the production script onto a test device locks this machine out of it: the
        // API stops answering and the connection times out rather than being refused, because
        // the packets are dropped. That contradicts proving every workflow locally first.
        config(['wireguard.local_api_subnet' => '192.168.0.0/24']);

        $script = $this->script();

        // All three have to agree, or the device refuses in a different way each time and
        // looks broken rather than closed.
        $this->assertStringContainsString('set api address=10.8.0.0/24,192.168.0.0/24 port=8728', $script);
        $this->assertStringContainsString('address=10.8.0.0/24,192.168.0.0/24 comment="Platform. Do not remove."', $script);
        $this->assertStringContainsString('dst-port=8728 src-address=192.168.0.0/24', $script);
        $this->assertStringContainsString('comment="platform-api-allow-local"', $script);

        // And the allowance is useless unless it is read before the rule that drops everything.
        $this->assertLessThan(
            strpos($script, 'platform-api-drop'),
            strpos($script, 'platform-api-allow-local'),
        );
    }

    #[Test]
    public function a_malformed_local_subnet_is_refused_rather_than_patched_up(): void
    {
        // It is written straight into a firewall rule. A typo quietly becoming 0.0.0.0/0 would
        // open the API to everyone on the strength of a misplaced character.
        foreach (['0.0.0.0/0; /user add name=x', 'not-an-address', '192.168.0.0/99', '192.168.0.0/24 or 1=1'] as $bad) {
            config(['wireguard.local_api_subnet' => $bad]);

            $script = $this->script();

            $this->assertStringNotContainsString(
                'action=accept comment="platform-api-allow-local"',
                $script,
                sprintf('[%s] was let through.', $bad),
            );
            $this->assertStringContainsString('set api address=10.8.0.0/24 port=8728', $script);
        }
    }

    #[Test]
    public function generating_a_script_points_the_router_at_its_tunnel(): void
    {
        config(['wireguard.manage_over_tunnel' => true]);

        $this->script();

        $this->assertSame('10.8.0.2', $this->router->fresh()->host);
    }

    #[Test]
    public function a_machine_with_no_tunnel_leaves_the_router_where_it_can_be_reached(): void
    {
        // The rewrite runs on every render of the setup page, not on a button. Without this
        // guard a developer who merely looked at the script found their bench router had
        // stopped answering, timing out against a 10.8.0.x address they never typed.
        config(['wireguard.manage_over_tunnel' => false]);

        $this->router->forceFill(['host' => '192.168.0.102', 'api_port' => 8728])->save();

        $this->script();

        $this->assertSame('192.168.0.102', $this->router->fresh()->host);
    }

    protected function script(): string
    {
        return app(RouterSetupScript::class)->for($this->router, ['lan_interface' => 'ether3']);
    }
}
