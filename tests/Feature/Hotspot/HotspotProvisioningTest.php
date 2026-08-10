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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class HotspotProvisioningTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Router $router;

    protected Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::set(Tenant::factory()->create());

        $this->fakeRouters();
        $this->router = Router::factory()->online()->create();
        $this->plan = Plan::factory()->create([
            'code' => 'hotspot-1hr',
            'price' => 20,
            'validity_amount' => 1,
            'validity_unit' => 'hours',
            'download_kbps' => 5000,
            'upload_kbps' => 2000,
        ]);
    }

    #[Test]
    public function buying_a_package_puts_the_customer_on_the_router(): void
    {
        $user = app(HotspotUserProvisioner::class)
            ->issue($this->plan, $this->router, 'GDGDAHXS', ['phone' => '254700111222']);

        $this->assertSame(HotspotUserStatus::Active, $user->status);

        $row = $this->routers->for($this->router)->rows('/ip/hotspot/user')[0];

        // The M-PESA code is both the username and the password, so the customer has one
        // thing to remember and the portal can log them in with it.
        $this->assertSame('GDGDAHXS', $row['name']);
        $this->assertSame('GDGDAHXS', $row['password']);
        $this->assertSame('hotspot-1hr', $row['profile']);
        $this->assertSame('3600s', $row['limit-uptime']);
    }

    #[Test]
    public function the_plan_profile_is_created_on_the_device_before_the_user_needs_it(): void
    {
        app(HotspotUserProvisioner::class)->issue($this->plan, $this->router, 'ABCDEFGH');

        $profiles = $this->routers->for($this->router)->rows('/ip/hotspot/user/profile');

        $this->assertCount(1, $profiles);
        $this->assertSame('2000k/5000k', $profiles[0]['rate-limit']);
    }

    #[Test]
    public function a_data_capped_plan_limits_the_users_bytes(): void
    {
        $plan = Plan::factory()->withDataCap(500)->create(['code' => 'capped']);

        app(HotspotUserProvisioner::class)->issue($plan, $this->router, 'CAPPED123');

        $row = $this->routers->for($this->router)->rows('/ip/hotspot/user')[0];

        $this->assertSame((string) (500 * 1024 * 1024), $row['limit-bytes-total']);
    }

    #[Test]
    public function the_expiry_is_set_from_the_plans_validity(): void
    {
        $user = app(HotspotUserProvisioner::class)->issue($this->plan, $this->router, 'TIMED123');

        $this->assertEqualsWithDelta(3600, now()->diffInSeconds($user->expires_at), 5);
    }

    #[Test]
    public function a_repeated_payment_callback_does_not_create_a_second_login(): void
    {
        $provisioner = app(HotspotUserProvisioner::class);

        $provisioner->issue($this->plan, $this->router, 'GDGDAHXS');
        $user = HotspotUser::query()->sole();

        // M-PESA delivers callbacks more than once; provisioning again must be harmless.
        $provisioner->provision($user);

        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function a_pppoe_plan_cannot_be_sold_as_a_hotspot_login(): void
    {
        $plan = Plan::factory()->pppoe()->create(['code' => 'home-fibre']);

        $this->expectException(InvalidArgumentException::class);

        app(HotspotUserProvisioner::class)->issue($plan, $this->router, 'WRONGTYPE');
    }

    #[Test]
    public function a_customer_who_paid_while_the_router_was_down_keeps_their_record(): void
    {
        $this->routers->makeUnreachable($this->router, 'Connection timed out');

        $user = app(HotspotUserProvisioner::class)->issue($this->plan, $this->router, 'PAIDBUTDOWN');

        // The money is taken, so losing the record is not an option.
        $this->assertTrue($user->exists);
        $this->assertSame(HotspotUserStatus::Failed, $user->status);
        $this->assertStringContainsString('timed out', (string) $user->last_error);
    }

    #[Test]
    public function a_failed_package_can_be_provisioned_once_the_router_returns(): void
    {
        $this->routers->makeUnreachable($this->router);

        $provisioner = app(HotspotUserProvisioner::class);
        $user = $provisioner->issue($this->plan, $this->router, 'RETRYME');

        $this->routers->makeReachable($this->router);
        $provisioner->provision($user);

        $this->assertSame(HotspotUserStatus::Active, $user->status);
        $this->assertNull($user->last_error);
        $this->assertCount(1, $this->routers->for($this->router)->rows('/ip/hotspot/user'));
    }

    #[Test]
    public function removing_a_package_takes_the_login_off_the_device_but_keeps_the_record(): void
    {
        $provisioner = app(HotspotUserProvisioner::class);
        $user = $provisioner->issue($this->plan, $this->router, 'SPENTCODE');

        $provisioner->remove($user);

        $this->assertSame([], $this->routers->for($this->router)->rows('/ip/hotspot/user'));

        // The earnings record has to outlive the login.
        $this->assertTrue($user->exists);
        $this->assertSame(HotspotUserStatus::Expired, $user->status);
        $this->assertNotNull($user->removed_at);
        $this->assertSame('20.00', $user->amount);
    }

    #[Test]
    public function usage_is_read_off_the_device_before_the_login_is_deleted(): void
    {
        $provisioner = app(HotspotUserProvisioner::class);
        $user = $provisioner->issue($this->plan, $this->router, 'USEDCODE');

        // Stand in for the customer having actually used the package.
        $device = $this->routers->for($this->router);
        $row = $device->rows('/ip/hotspot/user')[0];
        $device->update('/ip/hotspot/user', $row['.id'], [
            'uptime' => '45m30s',
            'bytes-in' => '1048576',
            'bytes-out' => '2097152',
        ]);

        $provisioner->remove($user);

        $this->assertSame(2730, $user->used_uptime_seconds);
        $this->assertSame(3145728, $user->used_bytes);
    }

    #[Test]
    public function revoking_knocks_a_logged_in_customer_off(): void
    {
        $provisioner = app(HotspotUserProvisioner::class);
        $user = $provisioner->issue($this->plan, $this->router, 'KICKME');

        $device = $this->routers->for($this->router);
        $device->add('/ip/hotspot/active', ['user' => 'KICKME', 'address' => '10.10.10.50']);

        $provisioner->remove($user, HotspotUserStatus::Revoked);

        // Deleting the login alone would leave the session running until it timed out.
        $this->assertSame([], $device->rows('/ip/hotspot/active'));
        $this->assertSame(HotspotUserStatus::Revoked, $user->status);
    }

    #[Test]
    public function generated_codes_avoid_characters_that_are_misread(): void
    {
        $code = HotspotUserProvisioner::generateReference();

        $this->assertSame(10, strlen($code));
        $this->assertDoesNotMatchRegularExpression('/[IO01]/', $code);
    }

    #[Test]
    public function two_isps_can_each_sell_a_package_on_their_own_router(): void
    {
        $ourUser = app(HotspotUserProvisioner::class)->issue($this->plan, $this->router, 'SHAREDREF');

        $other = Tenant::factory()->create();

        $theirUser = Tenancy::forTenant($other, function (): HotspotUser {
            $router = Router::factory()->online()->create();
            $plan = Plan::factory()->create(['code' => 'their-plan']);

            return app(HotspotUserProvisioner::class)->issue($plan, $router, 'SHAREDREF');
        });

        $this->assertSame(HotspotUserStatus::Active, $ourUser->status);
        $this->assertSame(HotspotUserStatus::Active, $theirUser->status);
        $this->assertNotSame($ourUser->router_id, $theirUser->router_id);
    }
}
