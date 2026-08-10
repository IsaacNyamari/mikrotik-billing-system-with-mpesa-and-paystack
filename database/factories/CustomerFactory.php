<?php

namespace Database\Factories;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Keeps generated numbers looking like the ones CreateCustomer allocates. The real
     * sequence is per tenant; this only has to avoid colliding.
     */
    protected static int $number = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_number' => 'CUS-'.str_pad((string) ++static::$number, 6, '0', STR_PAD_LEFT),
            'type' => CustomerType::Individual,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '2547'.fake()->numerify('########'),
            'national_id' => fake()->numerify('########'),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'status' => CustomerStatus::Active,
        ];
    }

    /**
     * Belonging to a specific ISP, for tests that set no tenant context.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function company(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CustomerType::Company,
            'company_name' => fake()->company(),
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CustomerStatus::Blocked,
        ]);
    }
}
