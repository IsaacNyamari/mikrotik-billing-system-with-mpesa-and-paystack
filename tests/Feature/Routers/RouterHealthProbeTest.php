<?php

namespace Tests\Feature\Routers;

use App\Enums\RouterStatus;
use App\Models\AuditLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\RouterHealthProbe;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class RouterHealthProbeTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::set(Tenant::factory()->create());

        $this->fakeRouters();
        $this->router = Router::factory()->create(['name' => 'Nairobi 01']);
    }

    #[Test]
    public function it_records_what_the_router_reports_about_itself(): void
    {
        $device = $this->routers->for($this->router);
        $device->seed('/system/resource', [$this->systemResource()]);
        $device->seed('/system/identity', [['name' => 'nairobi-core']]);
        $device->seed('/system/routerboard', [['model' => 'RB750Gr3', 'serial-number' => 'HEX123456']]);

        app(RouterHealthProbe::class)->probe($this->router);

        $this->assertSame(RouterStatus::Online, $this->router->status);
        $this->assertSame('nairobi-core', $this->router->identity);
        $this->assertSame('7.14.3', $this->router->os_version);
        $this->assertSame('RB750Gr3', $this->router->model);
        $this->assertSame('HEX123456', $this->router->serial_number);
        $this->assertSame(7, $this->router->cpu_load);
        $this->assertNotNull($this->router->last_seen_at);
        $this->assertNull($this->router->last_error);
    }

    #[Test]
    public function it_converts_routeros_uptime_into_seconds(): void
    {
        $device = $this->routers->for($this->router);
        $device->seed('/system/resource', [$this->systemResource(['uptime' => '6w2d3h4m5s'])]);

        app(RouterHealthProbe::class)->probe($this->router);

        // 6w + 2d + 3h + 4m + 5s = 3628800 + 172800 + 10800 + 240 + 5
        $this->assertSame(3_812_645, $this->router->uptime_seconds);
    }

    #[Test]
    public function it_handles_a_cloud_hosted_router_with_no_routerboard(): void
    {
        $device = $this->routers->for($this->router);
        $device->seed('/system/resource', [$this->systemResource(['board-name' => 'CHR'])]);
        $device->trap('/system/routerboard', 'no such command prefix');

        app(RouterHealthProbe::class)->probe($this->router);

        $this->assertSame(RouterStatus::Online, $this->router->status);
        $this->assertSame('CHR', $this->router->model);
        $this->assertNull($this->router->serial_number);
    }

    #[Test]
    public function an_unreachable_router_is_marked_offline_rather_than_throwing(): void
    {
        $this->routers->makeUnreachable($this->router, 'Connection timed out');

        app(RouterHealthProbe::class)->probe($this->router);

        $this->assertSame(RouterStatus::Offline, $this->router->status);
        $this->assertStringContainsString('timed out', (string) $this->router->last_error);
    }

    #[Test]
    public function it_reports_the_memory_and_uptime_an_operator_reads(): void
    {
        $device = $this->routers->for($this->router);
        $device->seed('/system/resource', [$this->systemResource([
            'uptime' => '3d4h5m6s',
            'free-memory' => '64000000',
            'total-memory' => '256000000',
        ])]);

        app(RouterHealthProbe::class)->probe($this->router);

        $this->assertSame('3d 4h 5m', $this->router->uptimeForHumans());
        $this->assertSame(75, $this->router->memoryUsedPercent());
    }

    #[Test]
    public function going_offline_is_audited_once_not_on_every_probe(): void
    {
        $device = $this->routers->for($this->router);
        $device->seed('/system/resource', [$this->systemResource()]);

        $probe = app(RouterHealthProbe::class);
        $probe->probe($this->router);

        $this->routers->makeUnreachable($this->router);

        $probe->probe($this->router);
        $probe->probe($this->router);

        $this->assertSame(1, AuditLog::query()->where('event', 'router.unreachable')->count());
    }

    #[Test]
    public function a_router_that_answers_but_misbehaves_is_flagged_as_an_error(): void
    {
        $this->routers->for($this->router)->trap('/system/resource', 'not enough permissions');

        app(RouterHealthProbe::class)->probe($this->router);

        $this->assertSame(RouterStatus::Error, $this->router->status);
        $this->assertStringContainsString('permissions', (string) $this->router->last_error);
    }

    #[Test]
    public function the_api_password_is_encrypted_at_rest(): void
    {
        $router = Router::factory()->create(['api_password' => 'super-secret']);

        $stored = DB::table('routers')->where('id', $router->id)->value('api_password');

        $this->assertNotSame('super-secret', $stored);
        $this->assertSame('super-secret', $router->fresh()->api_password);
    }
}
