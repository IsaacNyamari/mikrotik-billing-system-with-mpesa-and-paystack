<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One bill from the platform to one ISP.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $number
 * @property InvoiceStatus $status
 * @property string $kind
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property string $onboarding_amount
 * @property string $hotspot_amount
 * @property string $pppoe_amount
 * @property string $total
 * @property array<string, mixed>|null $breakdown
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $paid_at
 */
#[Fillable([
    'tenant_id',
    'kind',
    'period_start',
    'period_end',
    'onboarding_amount',
    'hotspot_amount',
    'pppoe_amount',
    'total',
    'breakdown',
    'due_at',
])]
class TenantInvoice extends Model
{
    /** The one-off fee that lets an ISP in at all. */
    public const KIND_ONBOARDING = 'onboarding';

    /** A month of HotSpot commission and PPPoE lines. */
    public const KIND_USAGE = 'usage';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'kind' => 'usage',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            // Random rather than sequential: an ISP quotes this number when paying, and a
            // running sequence would tell every one of them how many others there are.
            $invoice->number ??= 'INV-'.now()->format('ym').'-'.Str::upper(Str::random(6));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'onboarding_amount' => 'decimal:2',
            'hotspot_amount' => 'decimal:2',
            'pppoe_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'breakdown' => 'array',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<TenantPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(TenantPayment::class);
    }

    /**
     * @param  Builder<TenantInvoice>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn($this->qualifyColumn('status'), [InvoiceStatus::Pending, InvoiceStatus::Overdue]);
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }

    /**
     * Days until it is due, negative once it is late.
     */
    public function daysUntilDue(): ?int
    {
        return $this->due_at === null ? null : (int) now()->startOfDay()->diffInDays($this->due_at->startOfDay(), false);
    }

    /**
     * How long is left before this bill costs them the console.
     */
    public function daysOfGraceLeft(): ?int
    {
        if ($this->due_at === null || ! $this->isOutstanding()) {
            return null;
        }

        $lapsesOn = $this->due_at->addDays((int) config('platform.terms.grace_days'));

        return (int) max(0, now()->startOfDay()->diffInDays($lapsesOn->startOfDay(), false));
    }

    public function describePeriod(): string
    {
        if ($this->kind === self::KIND_ONBOARDING) {
            return __('One-off setup fee');
        }

        return $this->period_start === null
            ? __('Usage')
            : $this->period_start->format('F Y');
    }
}
