<?php

namespace Tests\Feature\Platform;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Signing up makes you an ISP.
 *
 * The platform exists to run ISPs, so there is no application to approve. What gates a new
 * account is the setup fee, which they can settle themselves at any hour.
 */
class SelfServiceSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
    }

    #[Test]
    public function registering_opens_an_isp_there_and_then(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Jane Wanjiru',
            'business_name' => 'Kawangware Wifi',
            'phone' => '0712345678',
            'email' => 'jane@example.co.ke',
            'password' => 'correct-horse-battery-1',
            'password_confirmation' => 'correct-horse-battery-1',
        ]);

        $tenant = Tenancy::withoutScope(fn (): ?Tenant => Tenant::query()->where('slug', 'kawangware-wifi')->first());

        $this->assertNotNull($tenant);
        // No queue, no approval: they are live the moment they sign up.
        $this->assertSame(TenantStatus::Active, $tenant->status);
        $this->assertSame('254712345678', $tenant->contact_phone);

        $user = User::query()->where('email', 'jane@example.co.ke')->sole();

        $this->assertSame($tenant->id, $user->tenant_id);
        $this->assertFalse($user->is_super_admin);
    }

    #[Test]
    public function the_person_who_signs_up_owns_the_isp(): void
    {
        $this->register();

        $user = User::query()->sole();

        $this->assertTrue($user->hasRole(Roles::ISP_OWNER));

        // An owner holds every capability their own ISP has, so they can set up routers and
        // packages without anyone granting it to them.
        Tenancy::set($user->tenant);
        $this->assertTrue($user->can(Permissions::ROUTERS_CREATE));
        $this->assertTrue($user->can(Permissions::PLANS_CREATE));
    }

    #[Test]
    public function they_owe_the_setup_fee_from_the_first_second(): void
    {
        $this->register();

        $tenant = Tenancy::withoutScope(fn (): Tenant => Tenant::query()->sole());
        $subscription = $tenant->billing();

        $this->assertSame(SubscriptionStatus::Pending, $subscription->status);
        $this->assertSame('2000.00', $subscription->onboarding_fee);
        $this->assertFalse($subscription->permitsAccess());
    }

    #[Test]
    public function a_new_isp_is_sent_to_pay_rather_than_to_a_dead_end(): void
    {
        $this->register();

        $user = User::query()->sole();

        // Signing up and landing on a screen that says "no" would be worse than not opening
        // the account at all.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('billing.index'));

        $this->actingAs($user)
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('setup fee');
    }

    #[Test]
    public function signing_up_without_a_business_name_still_works(): void
    {
        // Somebody registering from a phone should not be stopped by a decision they have not
        // made yet.
        $this->post(route('register.store'), [
            'name' => 'Peter Otieno',
            'email' => 'peter@example.co.ke',
            'password' => 'correct-horse-battery-1',
            'password_confirmation' => 'correct-horse-battery-1',
        ]);

        $tenant = Tenancy::withoutScope(fn (): Tenant => Tenant::query()->sole());

        $this->assertStringContainsString('Peter Otieno', $tenant->name);
        $this->assertSame(TenantStatus::Active, $tenant->status);
    }

    #[Test]
    public function two_isps_may_share_a_name_without_colliding(): void
    {
        // There is more than one "Kawangware Wifi".
        $this->register('first@example.co.ke');
        $this->register('second@example.co.ke');

        $slugs = Tenancy::withoutScope(fn () => Tenant::query()->pluck('slug'));

        $this->assertCount(2, $slugs);
        $this->assertCount(2, $slugs->unique());
    }

    #[Test]
    public function one_new_isp_cannot_see_anothers_anything(): void
    {
        $this->register('first@example.co.ke');
        $this->register('second@example.co.ke');

        [$first, $second] = User::query()->orderBy('id')->get()->all();

        $this->assertNotSame($first->tenant_id, $second->tenant_id);

        // The wall between them is the same one every other ISP sits behind.
        Tenancy::set($first->tenant);
        $this->assertSame($first->tenant_id, Tenancy::id());
    }

    #[Test]
    public function a_failed_registration_leaves_no_half_built_isp(): void
    {
        User::factory()->create(['email' => 'taken@example.co.ke']);

        $before = Tenancy::withoutScope(fn (): int => Tenant::query()->count());

        $this->post(route('register.store'), [
            'name' => 'Jane Wanjiru',
            'business_name' => 'Kawangware Wifi',
            'email' => 'taken@example.co.ke',
            'password' => 'correct-horse-battery-1',
            'password_confirmation' => 'correct-horse-battery-1',
        ])->assertSessionHasErrors('email');

        // An ISP with no owner is unreachable by anybody.
        $this->assertSame($before, Tenancy::withoutScope(fn (): int => Tenant::query()->count()));
    }

    protected function register(string $email = 'jane@example.co.ke'): void
    {
        $this->post(route('register.store'), [
            'name' => 'Jane Wanjiru',
            'business_name' => 'Kawangware Wifi',
            'email' => $email,
            'password' => 'correct-horse-battery-1',
            'password_confirmation' => 'correct-horse-battery-1',
        ]);

        auth()->logout();
    }
}
