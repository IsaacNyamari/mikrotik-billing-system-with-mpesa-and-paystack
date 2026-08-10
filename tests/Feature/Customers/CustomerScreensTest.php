<?php

namespace Tests\Feature\Customers;

use App\Enums\ServiceAccountStatus;
use App\Models\Customer;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerScreensTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->tenant = Tenant::factory()->create();
        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);

        // Livewire::test() invokes a component directly and does not run the web middleware,
        // so ResolveTenant never fires. Real requests — full page loads and Livewire's own
        // update endpoint — do go through it, which the HTTP tests below cover.
        Tenancy::set($this->tenant);
    }

    #[Test]
    public function the_customer_list_shows_only_this_isps_subscribers(): void
    {
        $other = Tenant::factory()->create();

        Tenancy::forTenant($this->tenant, fn () => Customer::factory()->create(['name' => 'Our Person']));
        Tenancy::forTenant($other, fn () => Customer::factory()->create(['name' => 'Their Person']));

        $this->actingAs($this->owner)
            ->get(route('customers.index'))
            ->assertOk()
            ->assertSee('Our Person')
            ->assertDontSee('Their Person');
    }

    #[Test]
    public function a_customer_detail_page_loads_through_route_model_binding(): void
    {
        $customer = Tenancy::forTenant(
            $this->tenant,
            fn (): Customer => Customer::factory()->create(['name' => 'Jane Njeri']),
        );

        $this->actingAs($this->owner)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Jane Njeri')
            ->assertSee($customer->customer_number);
    }

    #[Test]
    public function another_isps_customer_is_not_reachable_by_guessing_the_url(): void
    {
        $other = Tenant::factory()->create();

        $foreign = Tenancy::forTenant(
            $other,
            fn (): Customer => Customer::factory()->create(),
        );

        $this->actingAs($this->owner)
            ->get(route('customers.show', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function an_operator_can_create_a_customer(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::customers.form')
            ->set('name', 'New Subscriber')
            ->set('phone', '254711222333')
            ->set('email', 'new@example.test')
            ->call('save')
            ->assertHasNoErrors();

        Tenancy::set($this->tenant);

        $customer = Customer::query()->sole();

        $this->assertSame('New Subscriber', $customer->name);
        $this->assertSame('CUS-000001', $customer->customer_number);
        $this->assertSame($this->tenant->id, $customer->tenant_id);
    }

    #[Test]
    public function a_company_customer_must_supply_a_company_name(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::customers.form')
            ->set('type', 'company')
            ->set('name', 'Contact Person')
            ->set('phone', '254711222333')
            ->call('save')
            ->assertHasErrors(['company_name']);
    }

    #[Test]
    public function an_operator_can_add_a_service_account_to_a_customer(): void
    {
        $customer = Tenancy::forTenant(
            $this->tenant,
            fn (): Customer => Customer::factory()->create(),
        );

        $this->actingAs($this->owner);

        Livewire::test('pages::customers.show', ['customer' => $customer])
            ->set('serviceType', 'pppoe')
            ->set('username', 'jane-home')
            ->call('addServiceAccount')
            ->assertHasNoErrors();

        Tenancy::set($this->tenant);

        $account = ServiceAccount::query()->sole();

        $this->assertSame('jane-home', $account->username);
        $this->assertSame('SVC-000001', $account->account_number);
        $this->assertNotNull($account->secret);
    }

    #[Test]
    public function a_network_login_already_used_in_this_isp_is_rejected(): void
    {
        $customer = Tenancy::forTenant($this->tenant, function (): Customer {
            $customer = Customer::factory()->create();
            ServiceAccount::factory()->for($customer)->create(['username' => 'taken']);

            return $customer;
        });

        $this->actingAs($this->owner);

        Livewire::test('pages::customers.show', ['customer' => $customer])
            ->set('username', 'taken')
            ->call('addServiceAccount')
            ->assertHasErrors(['username']);
    }

    #[Test]
    public function a_login_used_by_another_isp_is_still_available_here(): void
    {
        $other = Tenant::factory()->create();

        Tenancy::forTenant($other, function (): void {
            $customer = Customer::factory()->create();
            ServiceAccount::factory()->for($customer)->create(['username' => 'shared-name']);
        });

        $customer = Tenancy::forTenant(
            $this->tenant,
            fn (): Customer => Customer::factory()->create(),
        );

        $this->actingAs($this->owner);

        Livewire::test('pages::customers.show', ['customer' => $customer])
            ->set('username', 'shared-name')
            ->call('addServiceAccount')
            ->assertHasNoErrors();
    }

    #[Test]
    public function an_operator_can_suspend_a_service(): void
    {
        [$customer, $account] = Tenancy::forTenant($this->tenant, function (): array {
            $customer = Customer::factory()->create();

            return [$customer, ServiceAccount::factory()->for($customer)->create([
                'status' => ServiceAccountStatus::Active,
            ])];
        });

        $this->actingAs($this->owner);

        Livewire::test('pages::customers.show', ['customer' => $customer])
            ->call('changeStatus', $account->id, 'suspended');

        Tenancy::set($this->tenant);

        $this->assertSame(ServiceAccountStatus::Suspended, $account->fresh()->status);
    }

    #[Test]
    public function a_read_only_operator_cannot_reach_the_create_screen(): void
    {
        $reader = User::factory()->forTenant($this->tenant)->create();
        $reader->assignRole(Roles::READ_ONLY);

        $this->actingAs($reader)
            ->get(route('customers.create'))
            ->assertForbidden();
    }

    #[Test]
    public function a_read_only_operator_can_still_browse_the_list(): void
    {
        $reader = User::factory()->forTenant($this->tenant)->create();
        $reader->assignRole(Roles::READ_ONLY);

        $this->actingAs($reader)
            ->get(route('customers.index'))
            ->assertOk();
    }

    #[Test]
    public function a_support_agent_cannot_cut_someone_off(): void
    {
        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        [$customer, $account] = Tenancy::forTenant($this->tenant, function (): array {
            $customer = Customer::factory()->create();

            return [$customer, ServiceAccount::factory()->for($customer)->create([
                'status' => ServiceAccountStatus::Active,
            ])];
        });

        $this->actingAs($agent);

        Livewire::test('pages::customers.show', ['customer' => $customer])
            ->call('changeStatus', $account->id, 'suspended')
            ->assertForbidden();
    }
}
