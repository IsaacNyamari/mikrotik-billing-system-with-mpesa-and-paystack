<?php

namespace Database\Factories;

use App\Enums\HotspotUserStatus;
use App\Models\HotspotUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HotspotUser>
 */
class HotspotUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $reference = strtoupper(Str::random(10));

        return [
            'username' => $reference,
            'password' => $reference,
            'reference' => $reference,
            'source' => 'purchase',
            'phone' => '2547'.fake()->numerify('########'),
            'amount' => 50,
            'status' => HotspotUserStatus::Active,
            'limit_uptime_seconds' => 3600,
            'expires_at' => now()->addHour(),
            'provisioned_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HotspotUserStatus::Pending,
            'provisioned_at' => null,
        ]);
    }

    /**
     * Sold and provisioned, but the package's time has run out.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HotspotUserStatus::Active,
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => HotspotUserStatus::Expired,
            'expires_at' => now()->subHour(),
            'removed_at' => now(),
        ]);
    }
}
