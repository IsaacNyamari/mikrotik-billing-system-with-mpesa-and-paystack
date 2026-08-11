<?php

namespace Tests\Feature\Portal;

use App\Models\Plan;
use App\Models\PortalSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class PortalBrandingTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Tenant $tenant;

    protected Router $router;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->fakeRouters();

        $this->tenant = Tenant::factory()->create(['name' => 'Solforbs Networks']);
        Tenancy::set($this->tenant);

        $this->router = Router::factory()->online()->create();
        $this->owner = User::factory()->forTenant($this->tenant)->create();
        $this->owner->assignRole(Roles::ISP_OWNER);
    }

    #[Test]
    public function a_portal_renders_for_an_isp_that_has_customised_nothing(): void
    {
        // No portal_settings row exists at all. The page must still work.
        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('Solforbs Networks')
            ->assertSee('Choose a package to get online')
            ->assertSee(PortalSetting::DEFAULT_PRIMARY, escape: false);
    }

    #[Test]
    public function the_isps_own_branding_reaches_the_portal(): void
    {
        PortalSetting::query()->create([
            'brand_name' => 'Kilimani WiFi',
            'headline' => 'Fast internet, pay as you go',
            'intro_text' => 'Ask at the desk for help',
            'footer_text' => 'Thanks for choosing us',
            'primary_color' => '#e11d48',
        ]);

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('Kilimani WiFi')
            ->assertSee('Fast internet, pay as you go')
            ->assertSee('Ask at the desk for help')
            ->assertSee('Thanks for choosing us')
            ->assertSee('--brand: #e11d48', escape: false)
            // The ISP name is replaced, not shown alongside.
            ->assertDontSee('Solforbs Networks');
    }

    #[Test]
    public function one_isps_branding_never_leaks_into_anothers_portal(): void
    {
        PortalSetting::query()->create(['brand_name' => 'Ours', 'primary_color' => '#e11d48']);

        $other = Tenant::factory()->create(['name' => 'Rival Networks']);

        $theirRouter = Tenancy::forTenant($other, function (): Router {
            PortalSetting::query()->create(['brand_name' => 'Theirs', 'primary_color' => '#16a34a']);

            return Router::factory()->online()->create();
        });

        $this->get($theirRouter->portalUrl())
            ->assertOk()
            ->assertSee('Theirs')
            ->assertSee('--brand: #16a34a', escape: false)
            ->assertDontSee('Ours')
            ->assertDontSee('#e11d48');
    }

    #[Test]
    public function a_colour_that_is_not_a_colour_never_reaches_the_stylesheet(): void
    {
        // The column is only wide enough for #rrggbb, which stops a long injection string at
        // the database. This covers the layer above it: anything that fits but is not a hex
        // colour is still refused rather than written into a style element.
        $setting = PortalSetting::query()->create(['primary_color' => 'red']);

        $this->assertSame(PortalSetting::DEFAULT_PRIMARY, $setting->primary());

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('--brand: '.PortalSetting::DEFAULT_PRIMARY, escape: false)
            ->assertDontSee('--brand: red', escape: false);
    }

    #[Test]
    public function the_colour_column_is_too_narrow_to_hold_an_injection(): void
    {
        // A second line of defence under the model's own check.
        $this->expectException(QueryException::class);

        PortalSetting::query()->create(['primary_color' => 'red; } body { display:none }']);
    }

    #[Test]
    public function custom_css_cannot_close_the_style_element_and_start_a_script(): void
    {
        PortalSetting::query()->create([
            'custom_css' => '.portal { color: red; } </style><script>alert(1)</script>',
        ]);

        $response = $this->get($this->router->portalUrl());

        $response->assertOk()
            ->assertSee('.portal { color: red; }', escape: false)
            // The angle brackets are gone, so nothing can escape the element.
            ->assertDontSee('<script>alert(1)</script>', escape: false)
            ->assertDontSee('</style><script>', escape: false);
    }

    #[Test]
    public function button_text_stays_readable_on_a_pale_brand_colour(): void
    {
        $pale = new PortalSetting(['primary_color' => '#fde047']);
        $dark = new PortalSetting(['primary_color' => '#1e3a8a']);

        // White on pale yellow is unreadable, so it flips to near-black.
        $this->assertSame('#111827', $pale->onPrimary());
        $this->assertSame('#ffffff', $dark->onPrimary());
    }

    #[Test]
    public function a_fixed_colour_scheme_does_not_wrap_its_rules_in_a_media_query(): void
    {
        PortalSetting::query()->create(['color_scheme' => 'dark']);

        $this->get($this->router->portalUrl())
            ->assertOk()
            // Rules nested inside a media query that never matches would leave the portal
            // stuck in light mode.
            ->assertDontSee('prefers-color-scheme: dark', escape: false)
            ->assertSee('background: #09090b', escape: false);
    }

    #[Test]
    public function a_light_only_portal_carries_no_dark_rules_at_all(): void
    {
        PortalSetting::query()->create(['color_scheme' => 'light']);

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertDontSee('prefers-color-scheme: dark', escape: false)
            ->assertDontSee('background: #09090b', escape: false);
    }

    #[Test]
    public function hiding_speeds_takes_them_off_the_portal(): void
    {
        Plan::factory()->create([
            'name' => 'Hourly',
            'code' => 'hourly',
            'download_kbps' => 5000,
            'upload_kbps' => 2000,
        ]);

        PortalSetting::query()->create(['show_speed' => false]);

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee('Hourly')
            ->assertDontSee('5 Mbps down');
    }

    #[Test]
    public function an_operator_can_customise_the_portal(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::settings.portal')
            ->set('brand_name', 'Kilimani WiFi')
            ->set('primary_color', '#E11D48')
            ->set('color_scheme', 'dark')
            ->call('save')
            ->assertHasNoErrors();

        $setting = PortalSetting::query()->sole();

        $this->assertSame('Kilimani WiFi', $setting->brand_name);
        // Stored lowercase so the same colour is always the same string.
        $this->assertSame('#e11d48', $setting->primary_color);
        $this->assertSame('dark', $setting->color_scheme);
    }

    #[Test]
    public function a_colour_that_is_not_a_hex_value_is_refused(): void
    {
        $this->actingAs($this->owner);

        Livewire::test('pages::settings.portal')
            ->set('primary_color', 'rebeccapurple')
            ->call('save')
            ->assertHasErrors(['primary_color']);
    }

    #[Test]
    public function a_logo_is_stored_and_shown_on_the_portal(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner);

        Livewire::test('pages::settings.portal')
            ->set('logo', UploadedFile::fake()->image('logo.png', 200, 80))
            ->call('save')
            ->assertHasNoErrors();

        $setting = PortalSetting::query()->sole();

        $this->assertNotNull($setting->logo_path);
        Storage::disk('public')->assertExists($setting->logo_path);

        $this->get($this->router->portalUrl())
            ->assertOk()
            ->assertSee($setting->logoUrl(), escape: false);
    }

    #[Test]
    public function replacing_a_logo_removes_the_old_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner);

        $component = Livewire::test('pages::settings.portal')
            ->set('logo', UploadedFile::fake()->image('first.png'))
            ->call('save');

        $first = PortalSetting::query()->sole()->logo_path;

        $component->set('logo', UploadedFile::fake()->image('second.png'))->call('save');

        $second = PortalSetting::query()->sole()->logo_path;

        $this->assertNotSame($first, $second);
        // Otherwise every change quietly fills the disk.
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    #[Test]
    public function an_oversized_logo_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs($this->owner);

        Livewire::test('pages::settings.portal')
            // It loads on a phone that has no internet yet.
            ->set('logo', UploadedFile::fake()->image('huge.png')->size(2048))
            ->call('save')
            ->assertHasErrors(['logo']);
    }

    #[Test]
    public function a_support_agent_cannot_rebrand_the_portal(): void
    {
        $agent = User::factory()->forTenant($this->tenant)->create();
        $agent->assignRole(Roles::SUPPORT_AGENT);

        $this->actingAs($agent)
            ->get(route('settings.portal'))
            ->assertForbidden();
    }

    #[Test]
    public function an_operator_only_ever_edits_their_own_isps_portal(): void
    {
        $other = Tenant::factory()->create();
        Tenancy::forTenant($other, fn () => PortalSetting::query()->create(['brand_name' => 'Not Yours']));

        $this->actingAs($this->owner);

        Livewire::test('pages::settings.portal')
            ->assertSet('brand_name', '')
            ->set('brand_name', 'Mine')
            ->call('save');

        $this->assertSame('Mine', PortalSetting::query()->sole()->brand_name);

        Tenancy::forTenant($other, function (): void {
            $this->assertSame('Not Yours', PortalSetting::query()->sole()->brand_name);
        });
    }
}
