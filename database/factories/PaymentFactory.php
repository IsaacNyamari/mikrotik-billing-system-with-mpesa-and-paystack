<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'mpesa',
            'status' => PaymentStatus::Pending,
            'phone' => '2547'.fake()->numerify('########'),
            'amount' => 20,
            'checkout_request_id' => 'ws_CO_'.Str::upper(Str::random(20)),
            'merchant_request_id' => Str::upper(Str::random(12)),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Paid,
            'receipt' => Str::upper(Str::random(10)),
            'result_code' => 0,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PaymentStatus::Failed,
            'result_code' => 1032,
            'result_description' => 'Request cancelled by user',
            'failed_at' => now(),
        ]);
    }
}
