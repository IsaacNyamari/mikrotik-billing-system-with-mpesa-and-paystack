<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Networks';

        return [
            'uuid' => (string) Str::uuid7(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'status' => TenantStatus::Active,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->companyEmail(),
            'contact_phone' => '2547'.fake()->numerify('########'),
            'address' => fake()->address(),
            'timezone' => 'Africa/Nairobi',
            'currency' => 'KES',
            'activated_at' => now(),
        ];
    }

    /**
     * A factory tenant is an ISP already paying for the platform.
     *
     * The opposite of production, where a new ISP starts owing the setup fee and cannot get
     * in — and deliberately so. A test about routers or customers is not a test about billing,
     * and making every one of them settle an invoice first would bury what they are actually
     * asserting. Tests that care about the unpaid state ask for it with `unpaid()`.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            $subscription = TenantSubscription::forTenant($tenant);
            $subscription->status = SubscriptionStatus::Active;
            $subscription->onboarding_paid_at = now();
            $subscription->period_started_on = now();
            $subscription->save();
        });
    }

    /**
     * An ISP that has not paid the setup fee, as they arrive in production.
     */
    public function unpaid(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            $subscription = TenantSubscription::forTenant($tenant);
            $subscription->status = SubscriptionStatus::Pending;
            $subscription->onboarding_paid_at = null;
            $subscription->save();
        });
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantStatus::Pending,
            'activated_at' => null,
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
