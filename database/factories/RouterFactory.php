<?php

namespace Database\Factories;

use App\Enums\RouterStatus;
use App\Models\Router;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Router>
 */
class RouterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->city().'-'.fake()->numerify('##');

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 99999),
            'host' => fake()->localIpv4(),
            'api_port' => 8728,
            'api_username' => 'isp-platform',
            'api_password' => fake()->password(12, 20),
            'uses_tls' => false,
            'status' => RouterStatus::Pending,
            'location' => fake()->streetAddress(),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes): array => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RouterStatus::Online,
            'last_seen_at' => now(),
            'identity' => 'MikroTik',
            'model' => 'RB750Gr3',
            'os_version' => '7.14.3',
            'uptime_seconds' => 531_240,
            'cpu_load' => 7,
            'free_memory_bytes' => 180_000_000,
            'total_memory_bytes' => 256_000_000,
        ]);
    }

    public function offline(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RouterStatus::Offline,
            'last_error' => 'Connection timed out',
        ]);
    }
}
