<?php

namespace Tests\Feature\Frontend;

use App\Models\ContactEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public site: the pages anyone can reach, and the one form on them.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_public_page_renders_to_a_stranger(): void
    {
        foreach (['home', 'about', 'services', 'contact'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    #[Test]
    public function the_pricing_a_prospect_is_quoted_is_the_pricing_the_system_bills(): void
    {
        // Read from config rather than typed into the page, so a rate change cannot leave the
        // marketing site quoting a number nobody is charged.
        config(['platform.pricing.onboarding_fee' => 2000, 'platform.pricing.pppoe_monthly_fee' => 15]);

        $this->get(route('services'))
            ->assertOk()
            ->assertSee('2,000')
            ->assertSee('3–5', escape: false)
            ->assertSee('15');
    }

    #[Test]
    public function the_home_page_says_what_it_costs_without_being_asked(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('KSh 2,000')
            ->assertSee('Per PPPoE subscriber a month');
    }

    #[Test]
    public function an_enquiry_is_kept_rather_than_only_emailed(): void
    {
        Livewire::test('pages::frontend.contact')
            ->set('name', 'Jane Wanjiru')
            ->set('email', 'jane@kawangware-net.co.ke')
            ->set('phone', '0712345678')
            ->set('company', 'Kawangware Net')
            ->set('routerCount', '2-5')
            ->set('message', 'We run a HotSpot and about 30 PPPoE homes. What would setup cost?')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Thanks');

        $enquiry = ContactEnquiry::query()->sole();

        // Stored, because mail is the part of any deployment most likely to be quietly broken
        // and an enquiry lost to a bad relay is a customer lost.
        $this->assertSame('jane@kawangware-net.co.ke', $enquiry->email);
        $this->assertSame('2-5', $enquiry->router_count);
        $this->assertNull($enquiry->handled_at);
    }

    #[Test]
    public function an_enquiry_with_nothing_in_it_is_refused(): void
    {
        Livewire::test('pages::frontend.contact')
            ->set('name', '')
            ->set('email', 'not-an-email')
            ->set('message', 'hi')
            ->call('submit')
            ->assertHasErrors(['name', 'email', 'message']);

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    #[Test]
    public function a_bot_filling_the_hidden_field_is_thanked_and_ignored(): void
    {
        // Telling it that it was caught only teaches whoever wrote it to try something else.
        Livewire::test('pages::frontend.contact')
            ->set('website', 'http://spam.example')
            ->set('name', 'Bot')
            ->set('email', 'bot@example.com')
            ->set('message', 'Buy cheap backlinks from us today please')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee('Thanks');

        $this->assertSame(0, ContactEnquiry::query()->count());
    }

    #[Test]
    public function the_public_site_never_asks_a_visitor_to_sign_in(): void
    {
        // The captive portal and these pages are the two the platform shows people who have
        // no account, and neither may redirect to a login.
        $this->get(route('home'))->assertOk()->assertDontSee('Dashboard');
    }
}
