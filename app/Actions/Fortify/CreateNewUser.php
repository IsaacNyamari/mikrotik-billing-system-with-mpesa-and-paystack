<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\Authorization\Roles;
use App\Support\PhoneNumber;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Registration creates an ISP, not just a login.
 *
 * The platform exists to run ISPs, so whoever signs up is one. There is no application to
 * approve and no queue to sit in: an operator who has to wait for a human before they can even
 * look at the product is an operator who goes back to selling paper vouchers.
 *
 * What still gates them is money, not permission. The account opens immediately, the setup fee
 * is owed immediately, and `EnsureSubscriptionIsActive` keeps them out of the console until it
 * is settled — which is a decision they can act on themselves, at any hour, without us.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            // Optional: someone signing up from a phone should not be stopped by a field they
            // have not decided on yet. Their own name stands in until they change it.
            'business_name' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:32'],
        ])->validate();

        // One transaction: a user with no ISP cannot use the platform, and an ISP with no
        // owner cannot be reached by anybody. Half of this is worse than none of it.
        return DB::transaction(function () use ($input): User {
            $tenant = $this->tenant($input);

            $user = new User([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $user->tenant_id = $tenant->id;
            $user->save();

            // The role catalogue is seeded, so this is a lookup rather than a creation. An
            // owner holds every capability their own ISP has.
            $user->assignRole(Roles::ISP_OWNER);

            // Created here rather than lazily, so the setup fee is owed from the first second
            // and nothing has to remember to start billing them.
            TenantSubscription::forTenant($tenant);

            return $user;
        });
    }

    /**
     * The ISP this registration opens.
     *
     * @param  array<string, string>  $input
     */
    protected function tenant(array $input): Tenant
    {
        $name = trim($input['business_name'] ?? '');

        if ($name === '') {
            $name = trim($input['name']).__('\'s Network');
        }

        $phone = trim($input['phone'] ?? '');

        // Creating a tenant is the one write that cannot be scoped to a tenant, since it is
        // the tenant. Said explicitly rather than relying on nobody being signed in.
        return Tenancy::withoutScope(function () use ($input, $name, $phone): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $name,
                'slug' => $this->slug($name),
                // Active on sight. Whether they can use it is a question of payment, answered
                // by the subscription, not of approval.
                'status' => TenantStatus::Active,
                'contact_name' => $input['name'],
                'contact_email' => $input['email'],
                'contact_phone' => $phone === '' ? null : PhoneNumber::normalise($phone),
                'timezone' => config('app.timezone'),
                'currency' => config('platform.pricing.currency'),
            ]);

            // Not mass-assignable: when an ISP went live is a fact about the account rather
            // than something a form may state.
            $tenant->activated_at = now();
            $tenant->save();

            return $tenant;
        });
    }

    /**
     * A slug nobody else holds.
     *
     * Two ISPs may genuinely share a name — there is more than one "Kawangware Wifi" — so a
     * collision is resolved rather than refused.
     */
    protected function slug(string $name): string
    {
        $base = Str::slug($name) ?: 'isp';
        $slug = $base;

        $exists = fn (string $candidate): bool => Tenancy::withoutScope(
            fn (): bool => Tenant::query()->withTrashed()->where('slug', $candidate)->exists(),
        );

        for ($suffix = 2; $exists($slug); $suffix++) {
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }
}
