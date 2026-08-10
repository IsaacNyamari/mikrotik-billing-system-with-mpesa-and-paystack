<?php

namespace Tests\Feature\Hotspot;

use App\Enums\HotspotUserStatus;
use App\Models\HotspotUser;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\HotspotUserProvisioner;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class HotspotSweepTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);

        $this->fakeRouters();
        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create(['code' => 'hourly']);
    }

    #[Test]
    public function it_removes_a_package_whose_time_is_up(): void
    {
        $user = $this->issue('EXPIREME');
        $user->update(['expires_at' => now()->subMinute()]);

        $this->artisan('hotspot:sweep')->assertSuccessful();

        $this->assertSame(HotspotUserStatus::Expired, $user->fresh()->status);
        $this->assertSame([], $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function it_leaves_a_package_that_still_has_time_alone(): void
    {
        $user = $this->issue('STILLGOOD');

        $this->artisan('hotspot:sweep')->assertSuccessful();

        $this->assertSame(HotspotUserStatus::Active, $user->fresh()->status);
        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function the_earnings_record_survives_the_sweep(): void
    {
        $user = $this->issue('KEEPRECORD');
        $user->update(['expires_at' => now()->subMinute()]);

        $this->artisan('hotspot:sweep');

        $record = HotspotUser::query()->find($user->id);

        $this->assertNotNull($record);
        $this->assertSame('20.00', $record->amount);
        $this->assertSame('KEEPRECORD', $record->reference);
    }

    #[Test]
    public function an_unreachable_router_leaves_the_package_to_be_swept_next_time(): void
    {
        $user = $this->issue('LATER');
        $user->update(['expires_at' => now()->subMinute()]);

        $this->routers->makeUnreachable($this->router);

        $this->artisan('hotspot:sweep')->assertSuccessful();

        // Still active, so the next sweep tries again rather than losing track of it.
        $this->assertSame(HotspotUserStatus::Active, $user->fresh()->status);

        $this->routers->makeReachable($this->router);
        $this->artisan('hotspot:sweep');

        $this->assertSame(HotspotUserStatus::Expired, $user->fresh()->status);
    }

    #[Test]
    public function retry_provisions_a_package_paid_for_while_the_router_was_down(): void
    {
        $this->routers->makeUnreachable($this->router);

        $user = app(HotspotUserProvisioner::class)->issue($this->plan, $this->router, 'OWEDACCESS');
        $this->assertSame(HotspotUserStatus::Failed, $user->status);

        $this->routers->makeReachable($this->router);

        $this->artisan('hotspot:sweep --retry')->assertSuccessful();

        $this->assertSame(HotspotUserStatus::Active, $user->fresh()->status);
        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function retry_does_not_resurrect_a_package_that_expired_while_it_was_stuck(): void
    {
        $this->routers->makeUnreachable($this->router);

        $user = app(HotspotUserProvisioner::class)->issue($this->plan, $this->router, 'TOOLATE');
        $user->update(['expires_at' => now()->subMinute()]);

        $this->routers->makeReachable($this->router);

        $this->artisan('hotspot:sweep --retry')->assertSuccessful();

        $this->assertSame(HotspotUserStatus::Failed, $user->fresh()->status);
        $this->assertSame([], $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function it_sweeps_every_isp_not_just_one(): void
    {
        $ours = $this->issue('OURSEXPIRED');
        $ours->update(['expires_at' => now()->subMinute()]);

        $other = Tenant::factory()->create();

        $theirs = Tenancy::forTenant($other, function (): HotspotUser {
            $router = Router::factory()->online()->create();
            $plan = Plan::factory()->create(['code' => 'their-hourly']);

            $user = app(HotspotUserProvisioner::class)->issue($plan, $router, 'THEIRSEXPIRED');
            $user->update(['expires_at' => now()->subMinute()]);

            return $user;
        });

        $this->artisan('hotspot:sweep')->assertSuccessful();

        $this->assertSame(HotspotUserStatus::Expired, $ours->fresh()->status);

        Tenancy::forTenant($other, function () use ($theirs): void {
            $this->assertSame(HotspotUserStatus::Expired, $theirs->fresh()->status);
        });
    }

    #[Test]
    public function limiting_to_one_isp_leaves_the_others_alone(): void
    {
        $other = Tenant::factory()->create();

        $theirs = Tenancy::forTenant($other, function (): HotspotUser {
            $router = Router::factory()->online()->create();
            $plan = Plan::factory()->create(['code' => 'their-hourly']);

            $user = app(HotspotUserProvisioner::class)->issue($plan, $router, 'UNTOUCHED');
            $user->update(['expires_at' => now()->subMinute()]);

            return $user;
        });

        $this->artisan('hotspot:sweep --tenant='.$this->tenant->slug)->assertSuccessful();

        Tenancy::forTenant($other, function () use ($theirs): void {
            $this->assertSame(HotspotUserStatus::Active, $theirs->fresh()->status);
        });
    }

    protected function issue(string $reference): HotspotUser
    {
        return app(HotspotUserProvisioner::class)->issue($this->plan, $this->router, $reference, ['amount' => 20]);
    }
}
