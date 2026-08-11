<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the platform super admin, who is the only way into a freshly deployed platform.
 *
 * Deliberately interactive and deliberately not a seeder: a seeded admin means a password
 * that is written down somewhere, and the first account on an internet-facing installation is
 * the last one that should have a known password. Nothing here is passed as an argument
 * either, so the credential never reaches shell history.
 */
class PlatformAdminCommand extends Command
{
    protected $signature = 'platform:admin';

    protected $description = 'Create a platform super admin';

    public function handle(): int
    {
        // A super admin belongs to no ISP, and creating them must not be scoped to whichever
        // tenant happens to be resolved.
        return Tenancy::withoutScope(function (): int {
            $email = text(
                label: 'Email',
                required: true,
                validate: fn (string $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL) === false
                    ? 'That is not an email address.'
                    : null,
            );

            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null && ! confirm('A user with that email exists. Make them a super admin and reset their password?', default: false)) {
                $this->components->warn('Nothing changed.');

                return self::SUCCESS;
            }

            $name = text(label: 'Name', default: $existing->name ?? 'Platform Super Admin', required: true);

            $secret = password(
                label: 'Password',
                required: true,
                validate: function (string $value): ?string {
                    $validator = validator(['password' => $value], [
                        'password' => ['required', Password::min(12)->letters()->numbers()->uncompromised()],
                    ]);

                    return $validator->fails() ? (string) $validator->errors()->first('password') : null;
                },
            );

            if ($secret !== password(label: 'Confirm password', required: true)) {
                $this->components->error('The passwords did not match.');

                return self::FAILURE;
            }

            $user = $existing ?? new User;

            $user->fill([
                'name' => $name,
                'email' => $email,
                'password' => $secret,
            ]);

            // No ISP, and verified on the spot: there is nobody to send a verification email
            // to yet, and an admin locked out of the platform they just deployed helps no one.
            $user->tenant_id = null;
            $user->is_super_admin = true;
            $user->email_verified_at ??= now();
            $user->save();

            $user->assignRole(Roles::PLATFORM_SUPER_ADMIN);

            $this->components->info(sprintf('%s can now sign in at %s/login', $email, rtrim((string) config('app.url'), '/')));

            return self::SUCCESS;
        });
    }
}
