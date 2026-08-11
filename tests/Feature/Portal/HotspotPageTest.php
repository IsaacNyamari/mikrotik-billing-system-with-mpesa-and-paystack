<?php

namespace Tests\Feature\Portal;

use App\Http\Controllers\HotspotPageController;
use App\Models\PortalSetting;
use App\Models\Router;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithRouters;
use Tests\TestCase;

class HotspotPageTest extends TestCase
{
    use InteractsWithRouters, RefreshDatabase;

    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        Tenancy::set(Tenant::factory()->create());
        $this->fakeRouters();
        $this->router = Router::factory()->online()->create();
    }

    #[Test]
    public function the_login_page_carries_the_routeros_placeholders(): void
    {
        $response = $this->get(route('portal.mikrotik', [
            'portalKey' => $this->router->portal_key,
            'page' => 'login.html',
        ]));

        $response->assertOk();

        // The router substitutes these before serving the page; without them the portal
        // cannot log the customer back in.
        foreach (['$(mac)', '$(ip)', '$(link-login-only)'] as $placeholder) {
            $response->assertSee($placeholder, escape: false);
        }

        $response->assertSee($this->router->portalUrl(), escape: false);
    }

    #[Test]
    public function the_post_login_page_sends_the_customer_on_their_way(): void
    {
        $this->get(route('portal.mikrotik', [
            'portalKey' => $this->router->portal_key,
            'page' => 'alogin.html',
        ]))
            ->assertOk()
            ->assertSee('$(link-redirect)', escape: false);
    }

    #[Test]
    public function an_unknown_portal_key_serves_nothing(): void
    {
        $this->get(route('portal.mikrotik', ['portalKey' => 'nonsense', 'page' => 'login.html']))
            ->assertNotFound();
    }

    #[Test]
    public function every_page_the_router_serves_comes_from_the_platform(): void
    {
        // A device missing any of these falls back to MikroTik's own, which asks a customer
        // for a username and password they have never been given.
        foreach (array_keys(HotspotPageController::PAGES) as $page) {
            $this->get('/p/'.$this->router->portal_key.'/mikrotik/'.$page)
                ->assertOk()
                ->assertHeader('content-type', 'text/html; charset=utf-8');
        }
    }

    #[Test]
    public function nothing_outside_that_set_is_served(): void
    {
        // The page name reaches the filesystem through a view lookup, so it is matched against
        // a list rather than trusted.
        $this->get('/p/'.$this->router->portal_key.'/mikrotik/passwd')->assertNotFound();
        $this->get('/p/'.$this->router->portal_key.'/mikrotik/login.php')->assertNotFound();
    }

    #[Test]
    public function the_pages_carry_the_isps_own_branding(): void
    {
        PortalSetting::query()->create([
            'brand_name' => 'Kawangware Wifi',
            'primary_color' => '#e11d48',
        ]);

        // Baked in when the router fetches them: these files live on the device, so an ISP who
        // rebrands re-runs the setup to see it here.
        $this->get('/p/'.$this->router->portal_key.'/mikrotik/status.html')
            ->assertOk()
            ->assertSee('Kawangware Wifi')
            ->assertSee('#e11d48', escape: false);
    }

    #[Test]
    public function every_router_gets_its_own_portal_key(): void
    {
        $second = Router::factory()->create();

        $this->assertNotSame($this->router->portal_key, $second->portal_key);
        $this->assertNotEmpty($second->portal_key);
    }

    #[Test]
    public function installing_the_portal_sets_the_router_up(): void
    {
        $device = $this->routers->for($this->router);
        $device->seed('/ip/hotspot', [['.id' => '*1', 'name' => 'billing-hotspot', 'profile' => 'billing-hotspot-profile']]);
        $device->add('/ip/hotspot/profile', ['name' => 'billing-hotspot-profile', 'login-by' => 'http-chap']);

        $this->artisan('hotspot:install-portal', ['--url' => 'http://192.168.0.100:8000'])
            ->assertSuccessful();

        // An unauthenticated phone must be able to reach the portal and Safaricom.
        $hosts = collect($device->rows('/ip/hotspot/walled-garden'))->pluck('dst-host');
        $this->assertContains('192.168.0.100', $hosts);
        $this->assertContains('*.safaricom.co.ke', $hosts);

        // And the profile has to allow the plain login the portal posts.
        $profile = $device->first('/ip/hotspot/profile', ['name' => 'billing-hotspot-profile']);
        $this->assertSame('hotspot', $profile['html-directory']);
        $this->assertStringContainsString('http-pap', $profile['login-by']);
    }

    #[Test]
    public function installing_twice_does_not_pile_up_walled_garden_entries(): void
    {
        $device = $this->routers->for($this->router);
        $device->seed('/ip/hotspot', [['.id' => '*1', 'name' => 'billing-hotspot', 'profile' => 'default']]);
        $device->add('/ip/hotspot/profile', ['name' => 'default']);

        $this->artisan('hotspot:install-portal', ['--url' => 'http://192.168.0.100:8000']);
        $this->artisan('hotspot:install-portal', ['--url' => 'http://192.168.0.100:8000']);

        // Counting rows would only pin down how many hosts we happen to allow today. What
        // matters is that running it again adds nothing.
        $hosts = collect($device->rows('/ip/hotspot/walled-garden'))->pluck('dst-host');

        $this->assertSame($hosts->count(), $hosts->unique()->count());
    }
}
