<?php

namespace Tests\Feature\Monitor;

use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterOs\RouterMonitor;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class MikrotikMonitorTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->router = Router::factory()->online()->create(['name' => 'Nairobi 01']);
        $this->engineer = User::factory()->forTenant($this->tenant)->create();
        $this->engineer->assignRole(Roles::NETWORK_ENGINEER);

        $this->seedDevice();
    }

    protected function seedDevice(int $rxBytes = 1_000_000, int $txBytes = 500_000): void
    {
        $device = $this->routers->for($this->router);

        $device->seed('/system/resource', [$this->systemResource()]);
        $device->seed('/interface', [[
            '.id' => '*1', 'name' => 'bridge-hotspot', 'type' => 'bridge', 'disabled' => 'false',
            'rx-byte' => (string) $rxBytes, 'tx-byte' => (string) $txBytes,
        ]]);
        $device->seed('/ip/hotspot/active', [[
            '.id' => '*1', 'user' => 'GDGDAHXS', 'address' => '10.10.10.50',
            'mac-address' => 'AE:47:AA:0B:3B:E1', 'uptime' => '12m30s',
            'bytes-in' => '2048000', 'bytes-out' => '1024000',
        ]]);
        $device->seed('/ip/hotspot/host', [['.id' => '*1'], ['.id' => '*2']]);
        $device->seed('/ip/dhcp-server/lease', [['.id' => '*1']]);
        $device->seed('/interface/wireless/registration-table', [['.id' => '*1', 'mac-address' => 'AA:BB']]);
    }

    #[Test]
    public function the_monitor_shows_what_the_device_is_doing(): void
    {
        $this->actingAs($this->engineer)
            ->get(route('monitor.index'))
            ->assertOk()
            ->assertSee('Nairobi 01')
            // The live hotspot session, straight off the device.
            ->assertSee('GDGDAHXS')
            ->assertSee('10.10.10.50');
    }

    #[Test]
    public function traffic_is_worked_out_from_two_counter_readings(): void
    {
        $this->actingAs($this->engineer);

        $component = Livewire::test('pages::monitor.index');

        // The first reading establishes a baseline and cannot produce a rate on its own.
        $this->assertCount(0, $component->get('history'));

        // A megabyte more received since the last poll.
        $this->seedDevice(rxBytes: 2_000_000, txBytes: 500_000);
        $component->call('refresh');

        $history = $component->get('history');

        $this->assertCount(1, $history);
        $this->assertGreaterThan(0, $history[0]['rx']);
        // Nothing was sent, so the upload series stays flat.
        $this->assertSame(0.0, $history[0]['tx']);
    }

    #[Test]
    public function a_counter_reset_does_not_draw_a_spike(): void
    {
        // Interfaces restart and counters go back to zero. Treating that as a huge delta
        // would wreck the chart's scale for the rest of the session.
        $this->assertSame(0.0, RouterMonitor::rate(5_000_000, 1_000, 5.0));
        $this->assertSame(0.0, RouterMonitor::rate(0, 1_000, 0.0));
    }

    #[Test]
    public function the_rate_maths_is_bits_per_second(): void
    {
        // 1000 bytes over 2 seconds is 4000 bits per second.
        $this->assertSame(4000.0, RouterMonitor::rate(0, 1000, 2.0));
    }

    #[Test]
    public function an_unreachable_router_says_so_instead_of_breaking_the_page(): void
    {
        $this->routers->makeUnreachable($this->router, 'Connection timed out');

        $this->actingAs($this->engineer)
            ->get(route('monitor.index'))
            ->assertOk()
            ->assertSee('Cannot reach')
            ->assertSee('timed out');
    }

    #[Test]
    public function a_menu_the_device_does_not_have_does_not_lose_the_whole_screen(): void
    {
        // A device with no PPP refuses that menu. The monitor is most useful when something
        // is wrong, so it has to survive the parts it cannot read.
        $this->routers->for($this->router)->trap('/ppp/active', 'no such command prefix');

        $this->actingAs($this->engineer)
            ->get(route('monitor.index'))
            ->assertOk()
            ->assertSee('GDGDAHXS');
    }

    #[Test]
    public function pausing_stops_the_readings(): void
    {
        $this->actingAs($this->engineer);

        $component = Livewire::test('pages::monitor.index')
            ->call('togglePause')
            ->assertSet('paused', true);

        $before = $component->get('previous');

        $this->seedDevice(rxBytes: 9_000_000);
        $component->call('refresh');

        // Paused means paused: no new sample was taken.
        $this->assertSame($before, $component->get('previous'));
    }

    #[Test]
    public function switching_router_throws_away_the_old_readings(): void
    {
        $second = Router::factory()->online()->create(['name' => 'Mombasa 02']);
        $this->routers->for($second)->seed('/system/resource', [$this->systemResource()]);

        $this->actingAs($this->engineer);

        // Named explicitly: the page defaults to the first router by name, and "Mombasa 02"
        // sorts ahead of "Nairobi 01".
        $component = Livewire::test('pages::monitor.index')->set('routerSlug', $this->router->slug);

        $this->seedDevice(rxBytes: 3_000_000);
        $component->call('refresh');

        $this->assertNotEmpty($component->get('history'));

        // Rates from one device describe nothing about another.
        $component->set('routerSlug', $second->slug);

        $this->assertSame([], $component->get('history'));
        $this->assertNull($component->get('previous'));
    }

    #[Test]
    public function history_does_not_grow_without_bound(): void
    {
        $this->actingAs($this->engineer);

        $component = Livewire::test('pages::monitor.index');

        // Far more polls than the window keeps.
        for ($i = 1; $i <= 70; $i++) {
            $this->seedDevice(rxBytes: 1_000_000 + ($i * 100_000));
            $component->call('refresh');
        }

        $this->assertLessThanOrEqual(60, count($component->get('history')));
    }

    #[Test]
    public function a_billing_officer_cannot_watch_the_network(): void
    {
        $officer = User::factory()->forTenant($this->tenant)->create();
        $officer->assignRole(Roles::BILLING_OFFICER);

        $this->actingAs($officer)
            ->get(route('monitor.index'))
            ->assertForbidden();
    }

    #[Test]
    public function the_monitor_only_offers_this_isps_routers(): void
    {
        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => Router::factory()->online()->create(['name' => 'Rival Router']));

        $this->actingAs($this->engineer)
            ->get(route('monitor.index'))
            ->assertOk()
            ->assertDontSee('Rival Router');
    }

    #[Test]
    public function rates_and_sizes_are_written_for_people(): void
    {
        $this->assertSame('1.5 Mbps', RouterMonitor::formatBits(1_500_000));
        $this->assertSame('2.4 kbps', RouterMonitor::formatBits(2_400));
        $this->assertSame('1.5 GB', RouterMonitor::formatBytes(1_610_612_736));
    }
}
