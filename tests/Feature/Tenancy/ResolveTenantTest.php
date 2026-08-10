<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ResolveTenant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ResolveTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A probe route that simply reports which tenant the middleware resolved.
        Route::middleware('web')->get('/_tenancy-probe', fn (): array => [
            'tenant_id' => Tenancy::id(),
        ]);
    }

    #[Test]
    public function it_binds_the_tenant_of_the_signed_in_operator(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant)->create();

        $this->actingAs($user)
            ->get('/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    }

    #[Test]
    public function it_binds_nothing_for_a_guest(): void
    {
        $this->get('/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant_id' => null]);
    }

    #[Test]
    public function it_locks_an_operator_out_of_a_suspended_isp(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        $user = User::factory()->forTenant($tenant)->create();

        $this->actingAs($user)
            ->get('/_tenancy-probe')
            ->assertForbidden();
    }

    #[Test]
    public function a_super_admin_starts_unscoped(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get('/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant_id' => null]);
    }

    #[Test]
    public function a_super_admin_can_switch_into_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->withSession([ResolveTenant::IMPERSONATION_KEY => $tenant->id])
            ->get('/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    }

    #[Test]
    public function a_super_admin_may_still_enter_a_suspended_isp_to_fix_it(): void
    {
        $tenant = Tenant::factory()->suspended()->create();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->withSession([ResolveTenant::IMPERSONATION_KEY => $tenant->id])
            ->get('/_tenancy-probe')
            ->assertOk()
            ->assertJson(['tenant_id' => $tenant->id]);
    }
}
