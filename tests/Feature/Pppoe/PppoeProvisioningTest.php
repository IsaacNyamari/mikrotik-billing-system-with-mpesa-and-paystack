<?php

namespace Tests\Feature\Pppoe;

use App\Actions\ServiceAccounts\ChangeServiceAccountStatus;
use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Router;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Services\RouterOs\PppoeSecretProvisioner;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class PppoeProvisioningTest extends TestCase
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
        $this->plan = Plan::factory()->pppoe()->create([
            'code' => 'home-10mbps',
            'download_kbps' => 10_000,
            'upload_kbps' => 5000,
        ]);
    }

    #[Test]
    public function activating_a_line_writes_the_secret_to_the_router(): void
    {
        $account = $this->account();

        app(PppoeSecretProvisioner::class)->push($account);

        $secret = $this->routers->for($this->router)->rows('/ppp/secret')[0];

        $this->assertSame($account->username, $secret['name']);
        $this->assertSame('home-10mbps', $secret['profile']);
        $this->assertSame('pppoe', $secret['service']);
        $this->assertSame('no', $secret['disabled']);
    }

    #[Test]
    public function the_plan_profile_is_prepared_on_the_device_first(): void
    {
        app(PppoeSecretProvisioner::class)->push($this->account());

        $profile = $this->routers->for($this->router)->rows('/ppp/profile')[0];

        $this->assertSame('home-10mbps', $profile['name']);
        $this->assertSame('5000k/10000k', $profile['rate-limit']);
    }

    #[Test]
    public function suspending_a_line_disables_the_secret_rather_than_deleting_it(): void
    {
        $account = $this->account();
        app(PppoeSecretProvisioner::class)->push($account);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Suspended);

        $secrets = $this->routers->for($this->router)->rows('/ppp/secret');

        // A suspended line normally comes back, so the credentials stay.
        $this->assertCount(1, $secrets);
        $this->assertSame('yes', $secrets[0]['disabled']);
    }

    #[Test]
    public function suspending_a_line_knocks_the_customer_off(): void
    {
        $account = $this->account();
        app(PppoeSecretProvisioner::class)->push($account);

        $device = $this->routers->for($this->router);
        $device->add('/ppp/active', ['name' => $account->username, 'address' => '10.0.0.5']);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Suspended);

        // Disabling a secret does not end a session already running.
        $this->assertSame([], $device->rows('/ppp/active'));
    }

    #[Test]
    public function terminating_a_line_removes_the_secret_entirely(): void
    {
        $account = $this->account();
        app(PppoeSecretProvisioner::class)->push($account);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Terminated);

        $this->assertSame([], $this->routers->for($this->router)->rows('/ppp/secret'));
        $this->assertNull($account->fresh()->routeros_id);
    }

    #[Test]
    public function reactivating_a_suspended_line_enables_it_again(): void
    {
        $account = $this->account();
        $changer = app(ChangeServiceAccountStatus::class);

        app(PppoeSecretProvisioner::class)->push($account);
        $changer->handle($account, ServiceAccountStatus::Suspended);
        $changer->handle($account, ServiceAccountStatus::Active);

        $secret = $this->routers->for($this->router)->rows('/ppp/secret')[0];

        $this->assertSame('no', $secret['disabled']);
    }

    #[Test]
    public function a_blocked_customer_takes_their_line_down_too(): void
    {
        $customer = Customer::factory()->blocked()->create();
        $account = $this->account(['customer_id' => $customer->id]);

        app(PppoeSecretProvisioner::class)->push($account);

        $secret = $this->routers->for($this->router)->rows('/ppp/secret')[0];

        // The account is active, but the customer is blocked, so the line must not work.
        $this->assertSame('yes', $secret['disabled']);
    }

    #[Test]
    public function pushing_twice_updates_the_secret_rather_than_duplicating_it(): void
    {
        $account = $this->account();
        $provisioner = app(PppoeSecretProvisioner::class);

        $provisioner->push($account);
        $provisioner->push($account);

        $this->assertCount(1, $this->routers->for($this->router)->rows('/ppp/secret'));
    }

    #[Test]
    public function an_unreachable_router_records_the_failure_on_the_account(): void
    {
        $this->routers->makeUnreachable($this->router, 'Connection timed out');

        $account = app(PppoeSecretProvisioner::class)->push($this->account());

        $this->assertFalse($account->isProvisioned());
        $this->assertStringContainsString('timed out', (string) $account->provision_error);
    }

    #[Test]
    public function a_hotspot_account_cannot_be_pushed_as_a_pppoe_secret(): void
    {
        $account = $this->account(['type' => ServiceType::Hotspot]);

        $this->expectException(InvalidArgumentException::class);

        app(PppoeSecretProvisioner::class)->push($account);
    }

    #[Test]
    public function an_account_with_no_router_cannot_be_pushed(): void
    {
        $account = $this->account(['router_id' => null]);

        $this->expectException(InvalidArgumentException::class);

        app(PppoeSecretProvisioner::class)->push($account);
    }

    #[Test]
    public function a_status_change_on_an_account_with_no_router_is_still_recorded(): void
    {
        $account = $this->account(['router_id' => null]);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Suspended);

        $this->assertSame(ServiceAccountStatus::Suspended, $account->fresh()->status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function account(array $overrides = []): ServiceAccount
    {
        return ServiceAccount::factory()
            ->for(Customer::factory())
            ->create(array_merge([
                'type' => ServiceType::Pppoe,
                'status' => ServiceAccountStatus::Active,
                'username' => 'jane-home',
                'secret' => 'line-secret',
                'plan_id' => $this->plan->id,
                'router_id' => $this->router->id,
            ], $overrides));
    }
}
