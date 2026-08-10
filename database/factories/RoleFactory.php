<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->jobTitle();

        return [
            'tenant_id' => null,
            'name' => Str::snake($label).'_'.fake()->unique()->numberBetween(1, 99999),
            'label' => $label,
            'description' => fake()->sentence(),
            'is_system' => false,
        ];
    }

    /**
     * A role defined by one ISP for its own operators.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->id,
        ]);
    }
}
