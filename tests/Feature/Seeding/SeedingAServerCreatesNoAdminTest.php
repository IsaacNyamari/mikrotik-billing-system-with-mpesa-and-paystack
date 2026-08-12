<?php

namespace Tests\Feature\Seeding;

use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Authorization\Roles;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Seeding a server must not hand anybody an administrator.
 *
 * `DatabaseSeeder` used to create admin@isp.solforbs.com with the password "password" and
 * `is_super_admin = true` *before* its own environment guard, so `migrate --seed` produced a
 * platform-wide administrator in any environment. Production escaped it only because
 * deploy/release.sh runs `migrate --force` with no `--seed` — a convention, not a safeguard, and
 * one command away from failing.
 */
class SeedingAServerCreatesNoAdminTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function seeding_outside_local_creates_no_super_admin(): void
    {
        // The tests already run as "testing" rather than "local", which is the whole point:
        // anything that is not a developer's own machine gets nothing.
        $this->assertFalse($this->app->isLocal());

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::query()->count(), 'Seeding a server created a user account.');
        $this->assertNull(User::query()->where('email', 'admin@isp.solforbs.com')->first());
    }

    #[Test]
    public function seeding_outside_local_still_builds_the_role_catalogue(): void
    {
        // This half must survive everywhere. Registration assigns a role, so an unseeded
        // catalogue makes signing up fail outright — which is why the guard sits between the
        // catalogue and the demo data rather than in front of both.
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => Roles::ISP_OWNER]);
        $this->assertDatabaseHas('permissions', ['name' => Permissions::CUSTOMERS_VIEW]);
    }

    #[Test]
    public function no_account_anywhere_is_left_holding_the_word_password(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Written as a filter rather than a loop of assertions so it still asserts something
        // when there are no users at all — which is the expected state today, and exactly when
        // a loop would quietly pass by never running.
        $guessable = User::query()->get()
            ->filter(fn (User $user): bool => Hash::check('password', $user->password))
            ->pluck('email')
            ->all();

        $this->assertSame([], $guessable, 'Seeding left an account with a guessable password.');
    }
}
