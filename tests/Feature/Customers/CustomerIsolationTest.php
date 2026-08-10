<?php

namespace Tests\Feature\Customers;

use App\Exceptions\CrossTenantAccessException;
use App\Models\Customer;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create();
        $this->tenantB = Tenant::factory()->create();
    }

    #[Test]
    public function customers_are_scoped_to_their_isp(): void
    {
        Tenancy::forTenant($this->tenantA, fn () => Customer::factory()->create(['name' => 'Alice']));
        Tenancy::forTenant($this->tenantB, fn () => Customer::factory()->create(['name' => 'Bob']));

        Tenancy::set($this->tenantA);

        $this->assertSame(['Alice'], Customer::query()->pluck('name')->all());
    }

    #[Test]
    public function service_accounts_are_scoped_to_their_isp(): void
    {
        Tenancy::forTenant($this->tenantB, function (): void {
            $customer = Customer::factory()->create();
            ServiceAccount::factory()->for($customer)->create(['username' => 'b-line']);
        });

        Tenancy::set($this->tenantA);

        $this->assertSame(0, ServiceAccount::query()->count());
    }

    #[Test]
    public function two_isps_may_each_have_a_subscriber_with_the_same_network_login(): void
    {
        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            Tenancy::forTenant($tenant, function (): void {
                $customer = Customer::factory()->create();
                ServiceAccount::factory()->for($customer)->create(['username' => 'john']);
            });
        }

        $this->assertSame(2, Tenancy::withoutScope(
            fn (): int => ServiceAccount::query()->where('username', 'john')->count(),
        ));
    }

    #[Test]
    public function a_network_login_cannot_repeat_inside_one_isp(): void
    {
        Tenancy::set($this->tenantA);

        $customer = Customer::factory()->create();
        ServiceAccount::factory()->for($customer)->create(['username' => 'john']);

        $this->expectException(QueryException::class);

        ServiceAccount::factory()->for($customer)->create(['username' => 'john']);
    }

    #[Test]
    public function a_service_account_cannot_be_attached_across_isps(): void
    {
        $foreignCustomer = Tenancy::forTenant(
            $this->tenantB,
            fn (): Customer => Customer::factory()->create(),
        );

        Tenancy::set($this->tenantA);

        $this->expectException(CrossTenantAccessException::class);

        ServiceAccount::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'customer_id' => $foreignCustomer->id,
        ]);
    }

    #[Test]
    public function searching_never_reaches_another_isp(): void
    {
        Tenancy::forTenant($this->tenantB, function (): void {
            $customer = Customer::factory()->create(['name' => 'Findable Person']);
            ServiceAccount::factory()->for($customer)->create(['username' => 'findable']);
        });

        Tenancy::set($this->tenantA);

        $this->assertSame(0, Customer::query()->search('Findable')->count());
        $this->assertSame(0, Customer::query()->search('findable')->count());
    }
}
