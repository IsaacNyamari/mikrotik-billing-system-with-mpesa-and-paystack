<?php

namespace Database\Factories;

use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceAccount>
 */
class ServiceAccountFactory extends Factory
{
    /**
     * @see CustomerFactory::$number
     */
    protected static int $number = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_number' => 'SVC-'.str_pad((string) ++static::$number, 6, '0', STR_PAD_LEFT),
            'type' => ServiceType::Pppoe,
            'username' => fake()->unique()->userName(),
            'secret' => fake()->password(10, 14),
            'status' => ServiceAccountStatus::Active,
            'installation_address' => fake()->streetAddress(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function hotspot(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Hotspot,
        ]);
    }

    public function staticIp(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ServiceType::Static,
            'secret' => null,
            'static_ip' => fake()->ipv4(),
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ServiceAccountStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
