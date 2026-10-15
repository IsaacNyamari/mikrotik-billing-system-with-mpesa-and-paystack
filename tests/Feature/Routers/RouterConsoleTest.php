<?php

namespace Tests\Feature\Routers;

use App\Enums\RouterStatus;
use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterOs\Testing\FakeRouterOsConnection;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class RouterConsoleTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected User $engineer;

    protected Router $router;

    protected FakeRouterOsConnection $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create();
        $this->engineer = User::factory()->forTenant($this->tenant)->create();
        $this->engineer->assignRole(Roles::NETWORK_ENGINEER);

        Tenancy::set($this->tenant);

        $this->router = Router::factory()->create(['status' => RouterStatus::Online]);
        $this->device = $this->routers->for($this->router);
    }

    #[Test]
    public function it_shows_what_the_device_holds(): void
    {
        $this->device->add('/interface', ['name' => 'ether2', 'type' => 'ether', 'running' => 'true', 'disabled' => 'false']);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'interfaces'])
            ->assertOk()
            ->assertSee('ether2')
            ->assertSee('/interface');
    }

    #[Test]
    public function the_full_page_is_reachable_over_http(): void
    {
        $this->actingAs($this->engineer)
            ->get(route('routers.console', ['router' => $this->router, 'menu' => 'log']))
            ->assertOk();
    }

    #[Test]
    public function disabling_an_interface_reaches_the_device_and_is_audited(): void
    {
        $id = $this->device->add('/interface', ['name' => 'ether2', 'type' => 'ether', 'disabled' => 'false']);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'interfaces'])
            ->call('toggle', $id, true);

        $this->assertSame('yes', $this->device->first('/interface', ['.id' => $id])['disabled']);

        $log = AuditLog::query()->sole();
        $this->assertSame('router.console.disabled', $log->event);
        $this->assertSame($this->router->id, $log->auditable_id);
        $this->assertStringContainsString('ether2', (string) $log->description);
    }

    #[Test]
    public function the_tunnel_and_the_uplink_cannot_be_disabled_from_the_dashboard(): void
    {
        $tunnel = $this->device->add('/interface', ['name' => 'wg-platform', 'type' => 'wg', 'disabled' => 'false']);
        $uplink = $this->device->add('/interface', ['name' => 'ether1', 'type' => 'ether', 'disabled' => 'false']);

        $this->actingAs($this->engineer);

        $page = Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'interfaces']);
        $page->call('toggle', $tunnel, true);
        $page->call('toggle', $uplink, true);

        $this->assertSame('false', $this->device->first('/interface', ['.id' => $tunnel])['disabled']);
        $this->assertSame('false', $this->device->first('/interface', ['.id' => $uplink])['disabled']);
        $this->assertSame(0, AuditLog::query()->count());
    }

    #[Test]
    public function rows_the_setup_script_wrote_are_left_alone(): void
    {
        $id = $this->device->add('/queue/simple', ['name' => 'mgmt', 'target' => '10.8.0.0/24', 'comment' => 'platform-management']);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'queues'])
            ->call('remove', $id);

        $this->assertCount(1, $this->device->rows('/queue/simple'));
    }

    #[Test]
    public function an_ordinary_row_cannot_be_given_a_platform_comment_and_so_locked_away(): void
    {
        $id = $this->device->add('/queue/simple', ['name' => 'office', 'target' => '10.10.10.20/32', 'max-limit' => '1M/2M']);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'queues'])
            ->call('edit', $id)
            ->set('form.comment', 'platform-api-allow')
            ->call('save');

        $this->assertArrayNotHasKey('comment', $this->device->first('/queue/simple', ['.id' => $id]));
    }

    #[Test]
    public function a_user_who_may_only_look_cannot_change_anything(): void
    {
        $id = $this->device->add('/interface', ['name' => 'ether2', 'type' => 'ether', 'disabled' => 'false']);

        $viewer = User::factory()->forTenant($this->tenant)->create();
        $viewer->assignRole(Roles::READ_ONLY);

        $this->actingAs($viewer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'interfaces'])
            ->assertOk()
            ->assertSee('ether2')
            ->call('toggle', $id, true)
            ->assertForbidden();

        $this->assertSame('false', $this->device->first('/interface', ['.id' => $id])['disabled']);
    }

    #[Test]
    public function another_isps_router_is_not_reachable(): void
    {
        $other = Tenant::factory()->create();
        $theirs = Tenancy::forTenant($other, fn () => Router::factory()->create());

        $this->actingAs($this->engineer)
            ->get(route('routers.console', ['router' => $theirs->slug]))
            ->assertNotFound();
    }

    #[Test]
    public function an_unknown_menu_is_not_found(): void
    {
        $this->actingAs($this->engineer)
            ->get(route('routers.console', ['router' => $this->router, 'menu' => 'firewall-everything']))
            ->assertNotFound();
    }

    #[Test]
    public function a_static_lease_can_be_added_and_a_malformed_mac_is_refused(): void
    {
        $this->actingAs($this->engineer);

        $page = Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'dhcp-leases'])
            ->call('create')
            ->set('form.address', '10.10.10.20')
            ->set('form.mac_address', 'not-a-mac')
            ->call('save')
            ->assertHasErrors(['form.mac_address']);

        $this->assertSame([], $this->device->rows('/ip/dhcp-server/lease'));

        $page->set('form.mac_address', 'AA:BB:CC:DD:EE:FF')
            ->set('form.comment', 'Till')
            ->call('save')
            ->assertHasNoErrors();

        $lease = $this->device->rows('/ip/dhcp-server/lease')[0];
        $this->assertSame('10.10.10.20', $lease['address']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $lease['mac-address']);
        $this->assertSame('Till', $lease['comment']);
        // Left empty, so not written as an empty server name the device would reject.
        $this->assertArrayNotHasKey('server', $lease);
    }

    #[Test]
    public function only_properties_the_menu_offers_reach_the_device(): void
    {
        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'queues'])
            ->call('create')
            ->set('form.name', 'office')
            ->set('form.target', '10.10.10.0/24')
            ->set('form.max_limit', '5M/10M')
            ->set('form.parent', 'global')
            ->call('save')
            ->assertHasNoErrors();

        $queue = $this->device->rows('/queue/simple')[0];
        $this->assertSame('5M/10M', $queue['max-limit']);
        $this->assertArrayNotHasKey('parent', $queue);
    }

    #[Test]
    public function make_static_runs_only_on_a_dynamic_lease(): void
    {
        $dynamic = $this->device->add('/ip/dhcp-server/lease', ['address' => '10.10.10.50', 'dynamic' => 'true']);
        $static = $this->device->add('/ip/dhcp-server/lease', ['address' => '10.10.10.20', 'dynamic' => 'false']);
        $this->device->log = [];

        $this->actingAs($this->engineer);

        $page = Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'dhcp-leases']);
        $page->call('run', $dynamic, 'make-static');
        $page->call('run', $static, 'make-static');
        $page->call('run', $dynamic, 'reboot');

        $executed = array_values(array_filter($this->device->log, fn (array $entry): bool => $entry['operation'] === 'execute'));

        $this->assertCount(1, $executed);
        $this->assertSame('/ip/dhcp-server/lease/make-static', $executed[0]['path']);
        $this->assertSame(['.id' => $dynamic], $executed[0]['payload']);
    }

    #[Test]
    public function queues_the_hotspot_made_for_a_customer_are_read_only(): void
    {
        $id = $this->device->add('/queue/simple', ['name' => '<hotspot-GDGDAHXS>', 'dynamic' => 'true']);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'queues'])
            ->call('remove', $id);

        $this->assertCount(1, $this->device->rows('/queue/simple'));
    }

    #[Test]
    public function the_log_reads_newest_first(): void
    {
        $this->device->seed('/log', [
            ['.id' => '*1', 'time' => '10:00:00', 'topics' => 'system,info', 'message' => 'first entry'],
            ['.id' => '*2', 'time' => '10:05:00', 'topics' => 'hotspot,info', 'message' => 'latest entry'],
        ]);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'log'])
            ->assertSeeInOrder(['latest entry', 'first entry']);
    }

    #[Test]
    public function an_unreachable_router_says_so_rather_than_showing_an_empty_table(): void
    {
        $this->routers->makeUnreachable($this->router, 'Connection timed out');

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.console', ['router' => $this->router, 'menu' => 'interfaces'])
            ->assertOk()
            ->assertSee('Could not reach the router')
            ->assertSee('Connection timed out');
    }
}
