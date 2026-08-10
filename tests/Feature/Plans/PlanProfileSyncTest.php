<?php

namespace Tests\Feature\Plans;

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanRouterProfile;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\PlanProfileSynchroniser;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class PlanProfileSyncTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::set(Tenant::factory()->create());

        $this->fakeRouters();
        $this->router = Router::factory()->online()->create();
    }

    #[Test]
    public function it_creates_a_hotspot_user_profile_on_the_device(): void
    {
        $plan = Plan::factory()->create([
            'code' => 'hourly',
            'download_kbps' => 5000,
            'upload_kbps' => 2000,
            'shared_users' => 2,
        ]);

        app(PlanProfileSynchroniser::class)->sync($plan, $this->router);

        $profile = $this->routers->for($this->router)->rows('/ip/hotspot/user/profile')[0];

        $this->assertSame('hourly', $profile['name']);
        $this->assertSame('2000k/5000k', $profile['rate-limit']);
        $this->assertSame('2', $profile['shared-users']);
    }

    #[Test]
    public function the_rate_limit_puts_upload_first_the_way_routeros_reads_it(): void
    {
        $plan = Plan::factory()->create(['download_kbps' => 10_000, 'upload_kbps' => 1000]);

        app(PlanProfileSynchroniser::class)->sync($plan, $this->router);

        // RouterOS rate-limit is rx/tx from the router's point of view, which is
        // upload/download from the customer's. Getting this backwards would sell a 10 Mbps
        // package that uploads at 10 and downloads at 1.
        $this->assertSame('1000k/10000k', $this->profile()['rate-limit']);
    }

    #[Test]
    public function an_uncapped_plan_leaves_the_profile_unshaped(): void
    {
        $plan = Plan::factory()->uncapped()->create();

        app(PlanProfileSynchroniser::class)->sync($plan, $this->router);

        $this->assertArrayNotHasKey('rate-limit', $this->profile());
    }

    #[Test]
    public function a_pppoe_plan_writes_a_ppp_profile_instead(): void
    {
        $plan = Plan::factory()->pppoe()->create(['code' => 'home-10mbps']);

        app(PlanProfileSynchroniser::class)->sync($plan, $this->router);

        $device = $this->routers->for($this->router);

        $this->assertSame([], $device->rows('/ip/hotspot/user/profile'));

        $profile = $device->rows('/ppp/profile')[0];
        $this->assertSame('home-10mbps', $profile['name']);
        $this->assertSame('yes', $profile['only-one']);
    }

    #[Test]
    public function pushing_twice_updates_the_profile_rather_than_duplicating_it(): void
    {
        $plan = Plan::factory()->create(['code' => 'hourly', 'download_kbps' => 5000]);

        $sync = app(PlanProfileSynchroniser::class);
        $sync->sync($plan, $this->router);

        $plan->update(['download_kbps' => 8000]);
        $sync->sync($plan, $this->router);

        $profiles = $this->routers->for($this->router)->rows('/ip/hotspot/user/profile');

        $this->assertCount(1, $profiles);
        $this->assertSame('2000k/8000k', $profiles[0]['rate-limit']);
    }

    #[Test]
    public function it_adopts_a_profile_the_operator_already_made_by_hand(): void
    {
        // The live router already carries a hand-built "billing-plan-1" profile. Pushing a
        // plan of the same name must take it over, not sit alongside it.
        $device = $this->routers->for($this->router);
        $existingId = $device->add('/ip/hotspot/user/profile', [
            'name' => 'billing-plan-1',
            'session-timeout' => '3h',
            'shared-users' => '1',
        ]);

        $plan = Plan::factory()->create(['code' => 'billing-plan-1', 'download_kbps' => 4000]);

        $pairing = app(PlanProfileSynchroniser::class)->sync($plan, $this->router);

        $profiles = $device->rows('/ip/hotspot/user/profile');

        $this->assertCount(1, $profiles);
        $this->assertSame($existingId, $pairing->routeros_id);
        $this->assertSame('2000k/4000k', $profiles[0]['rate-limit']);
        // Settings we do not manage are left alone.
        $this->assertSame('3h', $profiles[0]['session-timeout']);
    }

    #[Test]
    public function an_unreachable_router_records_the_failure_instead_of_throwing(): void
    {
        $this->routers->makeUnreachable($this->router, 'Connection timed out');

        $plan = Plan::factory()->create();

        $pairing = app(PlanProfileSynchroniser::class)->sync($plan, $this->router);

        $this->assertFalse($pairing->isSynced());
        $this->assertStringContainsString('timed out', (string) $pairing->sync_error);
        $this->assertNull($pairing->synced_at);
    }

    #[Test]
    public function a_failed_push_clears_once_it_succeeds(): void
    {
        $plan = Plan::factory()->create();
        $sync = app(PlanProfileSynchroniser::class);

        $this->routers->makeUnreachable($this->router);
        $sync->sync($plan, $this->router);

        $this->routers->makeReachable($this->router);
        $pairing = $sync->sync($plan, $this->router);

        $this->assertTrue($pairing->isSynced());
        $this->assertNull($pairing->sync_error);
    }

    #[Test]
    public function pushing_to_all_routers_covers_every_device_of_this_isp_only(): void
    {
        $second = Router::factory()->online()->create();

        $other = Tenant::factory()->create();
        $foreign = Tenancy::forTenant($other, fn (): Router => Router::factory()->create());

        $plan = Plan::factory()->create();

        app(PlanProfileSynchroniser::class)->syncToAllRouters($plan);

        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user/profile'));
        $this->assertCount(1, $this->routers->for($second)->rows('/ip/hotspot/user/profile'));
        $this->assertSame([], $this->routers->for($foreign)->rows('/ip/hotspot/user/profile'));
    }

    #[Test]
    public function removing_a_plan_takes_its_profile_off_the_device(): void
    {
        $plan = Plan::factory()->create(['code' => 'hourly']);
        $sync = app(PlanProfileSynchroniser::class);

        $sync->sync($plan, $this->router);
        $sync->remove($plan, $this->router);

        $this->assertSame([], $this->routers->for($this->router)->rows('/ip/hotspot/user/profile'));
        $this->assertSame(0, PlanRouterProfile::query()->count());
    }

    #[Test]
    public function a_push_is_audited(): void
    {
        $plan = Plan::factory()->create();

        app(PlanProfileSynchroniser::class)->sync($plan, $this->router);

        $this->assertSame(1, AuditLog::query()->where('event', 'plan.pushed_to_router')->count());
    }

    #[Test]
    public function validity_is_converted_to_seconds_for_routeros(): void
    {
        $plan = Plan::factory()->create(['validity_amount' => 3, 'validity_unit' => 'hours']);

        $this->assertSame(10800, $plan->validitySeconds());
        $this->assertSame('10800s', $plan->routerOsUptimeLimit());
    }

    #[Test]
    public function a_data_cap_is_expressed_in_bytes(): void
    {
        $plan = Plan::factory()->withDataCap(500)->create();

        $this->assertSame(524_288_000, $plan->dataCapBytes());
    }

    /**
     * @return array<string, string>
     */
    protected function profile(): array
    {
        return $this->routers->for($this->router)->rows('/ip/hotspot/user/profile')[0];
    }
}
