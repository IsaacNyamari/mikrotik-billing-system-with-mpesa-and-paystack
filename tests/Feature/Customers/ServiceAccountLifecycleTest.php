<?php

namespace Tests\Feature\Customers;

use App\Actions\Customers\CreateCustomer;
use App\Actions\ServiceAccounts\ChangeServiceAccountStatus;
use App\Actions\ServiceAccounts\CreateServiceAccount;
use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceAccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        Tenancy::set($this->tenant);
    }

    #[Test]
    public function customer_numbers_run_in_sequence_within_an_isp(): void
    {
        $action = app(CreateCustomer::class);

        $first = $action->handle(['name' => 'First', 'phone' => '254700000001']);
        $second = $action->handle(['name' => 'Second', 'phone' => '254700000002']);

        $this->assertSame('CUS-000001', $first->customer_number);
        $this->assertSame('CUS-000002', $second->customer_number);
    }

    #[Test]
    public function each_isp_gets_its_own_numbering(): void
    {
        $action = app(CreateCustomer::class);

        $first = $action->handle(['name' => 'A', 'phone' => '254700000001']);

        $other = Tenant::factory()->create();
        $second = Tenancy::forTenant(
            $other,
            fn (): Customer => $action->handle(['name' => 'B', 'phone' => '254700000002']),
        );

        $this->assertSame('CUS-000001', $first->customer_number);
        $this->assertSame('CUS-000001', $second->customer_number);
    }

    #[Test]
    public function a_deleted_customer_does_not_free_up_its_number(): void
    {
        $action = app(CreateCustomer::class);

        $first = $action->handle(['name' => 'First', 'phone' => '254700000001']);
        $first->delete();

        $second = $action->handle(['name' => 'Second', 'phone' => '254700000002']);

        $this->assertSame('CUS-000002', $second->customer_number);
    }

    #[Test]
    public function creating_a_customer_writes_an_audit_entry(): void
    {
        $customer = app(CreateCustomer::class)->handle([
            'name' => 'Audited',
            'phone' => '254700000001',
        ]);

        $log = AuditLog::query()->where('event', 'customer.created')->sole();

        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertSame($customer->id, $log->auditable_id);
    }

    #[Test]
    public function a_pppoe_service_gets_a_generated_secret_when_none_is_given(): void
    {
        $customer = Customer::factory()->create();

        $account = app(CreateServiceAccount::class)->handle($customer, [
            'type' => ServiceType::Pppoe,
            'username' => 'john',
        ]);

        $this->assertNotNull($account->secret);
        $this->assertGreaterThanOrEqual(8, strlen((string) $account->secret));
    }

    #[Test]
    public function the_network_secret_is_encrypted_at_rest(): void
    {
        $customer = Customer::factory()->create();

        $account = app(CreateServiceAccount::class)->handle($customer, [
            'type' => ServiceType::Pppoe,
            'username' => 'john',
            'secret' => 'plaintext-secret',
        ]);

        $stored = DB::table('service_accounts')
            ->where('id', $account->id)
            ->value('secret');

        $this->assertNotSame('plaintext-secret', $stored);
        $this->assertSame('plaintext-secret', $account->fresh()->secret);
    }

    #[Test]
    public function a_static_ip_service_carries_no_secret(): void
    {
        $customer = Customer::factory()->create();

        $account = app(CreateServiceAccount::class)->handle($customer, [
            'type' => ServiceType::Static,
            'username' => 'shop-link',
            'secret' => 'should-be-discarded',
            'static_ip' => '10.20.30.40',
        ]);

        $this->assertNull($account->secret);
    }

    #[Test]
    public function activating_a_service_stamps_the_activation_time(): void
    {
        $account = $this->makeAccount(ServiceAccountStatus::Pending);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Active);

        $this->assertSame(ServiceAccountStatus::Active, $account->status);
        $this->assertNotNull($account->activated_at);
    }

    #[Test]
    public function a_terminated_service_cannot_come_back(): void
    {
        $account = $this->makeAccount(ServiceAccountStatus::Active);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Terminated);

        $this->expectException(LogicException::class);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Active);
    }

    #[Test]
    public function a_status_change_is_audited(): void
    {
        $account = $this->makeAccount(ServiceAccountStatus::Active);

        app(ChangeServiceAccountStatus::class)->handle($account, ServiceAccountStatus::Suspended);

        $log = AuditLog::query()->where('event', 'service_account.status_changed')->sole();

        $this->assertSame(['status' => 'active'], $log->old_values);
        $this->assertSame(['status' => 'suspended'], $log->new_values);
    }

    #[Test]
    public function a_blocked_customer_takes_all_of_their_services_offline(): void
    {
        $customer = Customer::factory()->blocked()->create();
        $account = ServiceAccount::factory()->for($customer)->create([
            'status' => ServiceAccountStatus::Active,
        ]);

        $this->assertTrue($account->status->allowsAuthentication());
        $this->assertFalse($account->allowsAuthentication());
    }

    protected function makeAccount(ServiceAccountStatus $status): ServiceAccount
    {
        return ServiceAccount::factory()
            ->for(Customer::factory())
            ->create(['status' => $status]);
    }
}
