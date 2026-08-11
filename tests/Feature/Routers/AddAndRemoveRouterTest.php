<?php

namespace Tests\Feature\Routers;

use App\Enums\RouterStatus;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Provisioning\RouterSetupScript;
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
 * Adding a router either way, and removing one.
 *
 * The choice matters more than it looks: an operator whose router sits on somebody else's
 * connection cannot answer the direct questions at all, and a form that only asks those is a
 * form they cannot complete.
 */
class AddAndRemoveRouterTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        config([
            'wireguard.enabled' => true,
            'wireguard.public_key' => 'c2VydmVycHVibGlja2V5MDAwMDAwMDAwMDAwMDAwMDAwMD0=',
            'wireguard.endpoint' => 'isp.solforbs.com',
        ]);

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);

        $this->actingAs($this->owner);
    }

    #[Test]
    public function adding_by_script_asks_for_nothing_the_platform_can_issue_itself(): void
    {
        Livewire::test('pages::routers.form')
            ->set('connection', 'tunnel')
            ->set('name', 'Kawangware 01')
            ->set('lanInterface', 'ether3')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $router = Router::query()->sole();

        // Address, credentials and keys are all issued here. Asking an operator to invent
        // them would only be asking them to get them wrong.
        $this->assertTrue($router->hasTunnel());
        $this->assertTrue($router->reachedOverTunnel());
        $this->assertNotNull($router->wg_private_key);
        $this->assertSame('platform', $router->api_username);
    }

    #[Test]
    public function adding_by_script_lands_the_operator_on_the_script(): void
    {
        Livewire::test('pages::routers.form')
            ->set('connection', 'tunnel')
            ->set('name', 'Kawangware 01')
            ->set('lanInterface', 'ether3')
            ->call('save')
            ->assertRedirect(route('routers.setup', Router::query()->sole()));
    }

    #[Test]
    public function a_script_built_router_is_not_reported_as_unreachable_before_it_is_set_up(): void
    {
        Livewire::test('pages::routers.form')
            ->set('connection', 'tunnel')
            ->set('name', 'Kawangware 01')
            ->set('lanInterface', 'ether3')
            ->call('save');

        // Telling an operator their brand-new router is unreachable, seconds before handing
        // them the script that makes it reachable, reads as a failure when it is a step. It
        // stays pending until the script has actually been pasted.
        $this->assertSame(RouterStatus::Pending, Router::query()->sole()->status);
    }

    #[Test]
    public function adding_by_address_still_asks_for_the_address(): void
    {
        Livewire::test('pages::routers.form')
            ->set('connection', 'direct')
            ->set('name', 'Head office')
            ->set('host', '')
            ->set('api_username', '')
            ->set('api_password', '')
            ->call('save')
            ->assertHasErrors(['host', 'api_username', 'api_password']);
    }

    #[Test]
    public function adding_by_address_connects_immediately(): void
    {
        Livewire::test('pages::routers.form')
            ->set('connection', 'direct')
            ->set('name', 'Head office')
            ->set('host', '192.168.88.1')
            ->set('api_username', 'platform')
            ->set('api_password', 'secret-one')
            ->call('save')
            ->assertHasNoErrors();

        $router = Router::query()->sole();

        // An operator who has just typed credentials wants to know there and then whether
        // they work.
        $this->assertSame('192.168.88.1', $router->host);
        $this->assertFalse($router->hasTunnel());
    }

    #[Test]
    public function the_script_option_asks_which_interface_customers_use(): void
    {
        // Putting the uplink into the bridge is how a router loses its own internet, so this
        // is the one thing the operator has to answer.
        Livewire::test('pages::routers.form')
            ->set('connection', 'tunnel')
            ->set('name', 'Kawangware 01')
            ->set('lanInterface', '')
            ->call('save')
            ->assertHasErrors(['lanInterface']);

        Livewire::test('pages::routers.form')
            ->set('connection', 'tunnel')
            ->set('name', 'Kawangware 02')
            ->set('lanInterface', 'ether3"; /user add name=x')
            ->call('save')
            ->assertHasErrors(['lanInterface']);
    }

    #[Test]
    public function the_generated_script_names_the_interface_that_was_chosen(): void
    {
        Livewire::test('pages::routers.form')
            ->set('connection', 'tunnel')
            ->set('name', 'Kawangware 01')
            ->set('lanInterface', 'wlan1')
            ->call('save');

        // The script is rendered inside a <pre>, so its quotes arrive HTML-escaped — the
        // name itself is what to look for.
        $this->get(route('routers.setup', Router::query()->sole()))
            ->assertOk()
            ->assertSee('wlan1');

        $this->assertStringContainsString(
            'interface="wlan1"',
            app(RouterSetupScript::class)->for(Router::query()->sole(), ['lan_interface' => 'wlan1']),
        );
    }

    #[Test]
    public function a_router_can_be_removed(): void
    {
        $router = Router::factory()->online()->create();

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('destroy')
            ->assertRedirect(route('routers.index'));

        // Soft deleted: the payments taken through it are the ISP's business record, and
        // cascading them away because a device was decommissioned would rewrite their
        // earnings history.
        $this->assertSoftDeleted('routers', ['id' => $router->id]);
    }

    #[Test]
    public function removing_a_router_does_not_hang_on_a_device_that_has_gone(): void
    {
        $router = Router::factory()->online()->create();

        // A router is usually removed *because* it has gone — stolen, burnt out, taken back
        // by a landlord. A delete that waits thirty seconds for a dead box before failing is
        // one that does not work when it is most needed.
        $this->routers->makeUnreachable($router, 'Connection timed out');

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('destroy')
            ->assertHasNoErrors();

        $this->assertSoftDeleted('routers', ['id' => $router->id]);
    }

    #[Test]
    public function an_operator_without_the_right_cannot_remove_a_router(): void
    {
        $router = Router::factory()->create();

        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        Livewire::actingAs($agent)
            ->test('pages::routers.show', ['router' => $router])
            ->call('destroy')
            ->assertForbidden();

        $this->assertNotSoftDeleted('routers', ['id' => $router->id]);
    }

    #[Test]
    public function removing_a_router_is_written_to_the_audit_trail(): void
    {
        $router = Router::factory()->create(['name' => 'Kawangware 01']);

        Livewire::test('pages::routers.show', ['router' => $router])->call('destroy');

        $this->assertDatabaseHas('audit_logs', ['event' => 'router.deleted']);
    }
}
