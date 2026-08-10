<?php

namespace Tests\Feature\Routers;

use App\Enums\RouterStatus;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\Exceptions\RouterOsConnectionException;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class RouterScreensTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create();
        $this->engineer = User::factory()->forTenant($this->tenant)->create();
        $this->engineer->assignRole(Roles::NETWORK_ENGINEER);

        Tenancy::set($this->tenant);
    }

    #[Test]
    public function an_engineer_can_add_a_router_and_it_is_contacted_immediately(): void
    {
        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.form')
            ->set('name', 'Nairobi 01')
            ->set('host', '192.168.88.1')
            ->set('api_username', 'isp-platform')
            ->set('api_password', 'router-secret')
            ->call('save')
            ->assertHasNoErrors();

        $router = Router::query()->sole();

        $this->assertSame('Nairobi 01', $router->name);
        $this->assertSame('nairobi-01', $router->slug);
        $this->assertSame($this->tenant->id, $router->tenant_id);
        $this->assertSame('router-secret', $router->api_password);

        // The fake device answers, so the probe that runs on save marks it online.
        $this->assertSame(RouterStatus::Online, $router->status);
    }

    #[Test]
    public function saving_a_router_that_cannot_be_reached_still_stores_it(): void
    {
        $this->actingAs($this->engineer);

        // Every fake router is reachable by default, so make the next one refuse. The id is
        // not known until save, so trap by making all connections fail.
        $this->app->instance(
            RouterOsConnector::class,
            new class implements RouterOsConnector
            {
                public function connect(Router $router): RouterOsConnection
                {
                    throw new RouterOsConnectionException('Connection timed out');
                }
            },
        );

        Livewire::test('pages::routers.form')
            ->set('name', 'Unreachable')
            ->set('host', '10.99.99.99')
            ->set('api_username', 'isp-platform')
            ->set('api_password', 'secret')
            ->call('save')
            ->assertHasNoErrors();

        $router = Router::query()->sole();

        $this->assertSame(RouterStatus::Offline, $router->status);
        $this->assertStringContainsString('timed out', (string) $router->last_error);
    }

    #[Test]
    public function editing_a_router_without_retyping_the_password_keeps_the_stored_one(): void
    {
        $router = Router::factory()->create(['api_password' => 'original-secret']);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.form', ['router' => $router])
            ->set('name', 'Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Renamed', $router->fresh()->name);
        $this->assertSame('original-secret', $router->fresh()->api_password);
    }

    #[Test]
    public function a_second_router_with_the_same_name_gets_its_own_slug(): void
    {
        Router::factory()->create(['name' => 'Nairobi 01', 'slug' => 'nairobi-01']);

        $this->actingAs($this->engineer);

        Livewire::test('pages::routers.form')
            ->set('name', 'Nairobi 01')
            ->set('host', '192.168.88.2')
            ->set('api_username', 'isp-platform')
            ->set('api_password', 'secret')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('nairobi-01-2', Router::query()->latest('id')->first()->slug);
    }

    #[Test]
    public function the_router_list_shows_only_this_isps_devices(): void
    {
        Router::factory()->create(['name' => 'Ours']);

        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => Router::factory()->create(['name' => 'Theirs']));

        $this->actingAs($this->engineer)
            ->get(route('routers.index'))
            ->assertOk()
            ->assertSee('Ours')
            ->assertDontSee('Theirs');
    }

    #[Test]
    public function another_isps_router_is_not_reachable_by_url(): void
    {
        $other = Tenant::factory()->create();
        $foreign = Tenancy::forTenant($other, fn (): Router => Router::factory()->create());

        $this->actingAs($this->engineer)
            ->get(route('routers.show', $foreign))
            ->assertNotFound();
    }

    #[Test]
    public function the_api_password_never_reaches_the_browser(): void
    {
        $router = Router::factory()->online()->create(['api_password' => 'do-not-leak']);

        $this->actingAs($this->engineer)
            ->get(route('routers.edit', $router))
            ->assertOk()
            ->assertDontSee('do-not-leak');
    }

    #[Test]
    public function a_billing_officer_cannot_touch_routers(): void
    {
        $officer = User::factory()->forTenant($this->tenant)->create();
        $officer->assignRole(Roles::BILLING_OFFICER);

        $this->actingAs($officer)
            ->get(route('routers.index'))
            ->assertForbidden();
    }

    #[Test]
    public function a_support_agent_can_look_but_not_run_commands(): void
    {
        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        $router = Router::factory()->create();

        $this->actingAs($agent)
            ->get(route('routers.index'))
            ->assertOk();

        Livewire::test('pages::routers.show', ['router' => $router])
            ->call('probe')
            ->assertForbidden();
    }
}
