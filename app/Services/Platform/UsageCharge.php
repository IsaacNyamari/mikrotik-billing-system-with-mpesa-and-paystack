<?php

namespace App\Services\Platform;

use Carbon\CarbonImmutable;

/**
 * What one ISP owes for one month, and the arithmetic that got there.
 *
 * The workings are carried alongside the answer on purpose: an ISP querying a bill is owed the
 * numbers it was built from — what they collected, at what rate, over how many lines — not a
 * single figure they have to take on trust.
 */
final class UsageCharge
{
    public function __construct(
        public readonly CarbonImmutable $periodStart,
        public readonly CarbonImmutable $periodEnd,
        public readonly float $hotspotCollected,
        public readonly float $hotspotRate,
        public readonly float $hotspotAmount,
        public readonly int $pppoeLines,
        public readonly float $pppoeUnitFee,
        public readonly float $pppoeAmount,
    ) {}

    public function total(): float
    {
        return round($this->hotspotAmount + $this->pppoeAmount, 2);
    }

    public function isZero(): bool
    {
        return $this->total() <= 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function breakdown(): array
    {
        return [
            'period' => [
                'start' => $this->periodStart->toDateString(),
                'end' => $this->periodEnd->toDateString(),
            ],
            'hotspot' => [
                'collected' => round($this->hotspotCollected, 2),
                'rate_percent' => $this->hotspotRate,
                'amount' => $this->hotspotAmount,
            ],
            'pppoe' => [
                'lines' => $this->pppoeLines,
                'fee_per_line' => $this->pppoeUnitFee,
                'amount' => $this->pppoeAmount,
            ],
            'total' => $this->total(),
        ];
    }
}
