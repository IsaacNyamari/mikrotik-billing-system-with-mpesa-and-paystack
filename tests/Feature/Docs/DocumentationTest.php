<?php

namespace Tests\Feature\Docs;

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

class DocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $tenant = Tenant::factory()->create();
        Tenancy::set($tenant);

        $this->owner = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->owner->assignRole(Roles::ISP_OWNER);
    }

    #[Test]
    public function the_manual_is_reachable_from_the_console(): void
    {
        $this->actingAs($this->owner)
            ->get(route('docs.index'))
            ->assertOk()
            ->assertSee('Documentation');
    }

    #[Test]
    public function every_chapter_renders(): void
    {
        $component = Livewire::actingAs($this->owner)->test('pages::docs.index');

        foreach ($component->instance()->chapters() as $chapter) {
            $component->call('show', $chapter['id'])
                ->assertOk()
                ->assertSee($chapter['title']);
        }
    }

    #[Test]
    public function a_chapter_name_invented_in_the_url_falls_back_rather_than_reaching_for_a_file(): void
    {
        // The chapter id is interpolated into a view name, and it arrives from the query
        // string. Anything not in the catalogue has to resolve to the first chapter, or the
        // reader chooses which file the application includes.
        Livewire::actingAs($this->owner)
            ->test('pages::docs.index', ['section' => '../../../../etc/passwd'])
            ->assertOk()
            ->assertSee('Getting started');
    }

    #[Test]
    public function the_manual_stays_open_to_an_isp_whose_bill_has_lapsed(): void
    {
        // Half of what it explains is read by somebody trying to put an account back in order,
        // so it sits outside the subscription gate exactly as the billing page does.
        $this->assertNotContains(
            'subscribed',
            app('router')->getRoutes()->getByName('docs.index')->gatherMiddleware(),
        );
    }

    #[Test]
    public function searching_narrows_the_contents(): void
    {
        Livewire::actingAs($this->owner)
            ->test('pages::docs.index')
            ->set('search', 'tunnel')
            ->assertSee('The management tunnel')
            ->assertDontSee('What you pay us');
    }
}
