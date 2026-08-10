<?php

namespace Database\Factories;

use App\Enums\PlanType;
use App\Enums\ValidityUnit;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = ++static::$sequence;

        return [
            'type' => PlanType::Hotspot,
            'name' => sprintf('%d Hour Package', $number),
            'code' => 'plan-'.$number,
            'price' => fake()->randomElement([20, 50, 100, 300]),
            'validity_amount' => 1,
            'validity_unit' => ValidityUnit::Hours,
            'download_kbps' => 5000,
            'upload_kbps' => 2000,
            'shared_users' => 1,
            'is_active' => true,
            'is_public' => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function pppoe(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => PlanType::Pppoe,
            'validity_amount' => 1,
            'validity_unit' => ValidityUnit::Months,
        ]);
    }

    public function uncapped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'download_kbps' => null,
            'upload_kbps' => null,
        ]);
    }

    public function withDataCap(int $megabytes): static
    {
        return $this->state(fn (array $attributes): array => [
            'data_cap_mb' => $megabytes,
        ]);
    }
}
