<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one ISP pays the platform, and whether they are entitled to use it.
 *
 * Deliberately **not** a `BelongsToTenant` model. An ISP locked out for non-payment still has
 * to be able to read its own bill and pay it, and the platform has to see every ISP's position
 * at once — both of which a global tenant scope would fight. Ownership is carried by the
 * column and enforced by the screens that read it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property SubscriptionStatus $status
 * @property string $onboarding_fee
 * @property CarbonImmutable|null $onboarding_paid_at
 * @property string $hotspot_commission_rate
 * @property string $pppoe_monthly_fee
 * @property CarbonImmutable|null $period_started_on
 * @property CarbonImmutable|null $last_invoiced_on
 * @property CarbonImmutable|null $access_granted_until
 * @property string|null $notes
 */
#[Fillable([
    'tenant_id',
    'onboarding_fee',
    'hotspot_commission_rate',
    'pppoe_monthly_fee',
    'notes',
])]
class TenantSubscription extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'onboarding_fee' => 'decimal:2',
            'hotspot_commission_rate' => 'decimal:2',
            'pppoe_monthly_fee' => 'decimal:2',
            'onboarding_paid_at' => 'datetime',
            'period_started_on' => 'date',
            'last_invoiced_on' => 'date',
            'access_granted_until' => 'datetime',
        ];
    }

    /**
     * This ISP's subscription, created on the agreed terms if they have none yet.
     *
     * Every ISP has one from the moment they exist: a tenant with no subscription would be a
     * tenant nobody is billing, which is exactly the state this feature exists to prevent.
     */
    public static function forTenant(Tenant $tenant): self
    {
        return static::query()->firstOrCreate(
            ['tenant_id' => $tenant->id],
            [
                // Copied, not referenced. An ISP is billed the terms they signed up under,
                // whatever the price list says later.
                'onboarding_fee' => (float) config('platform.pricing.onboarding_fee'),
                'hotspot_commission_rate' => (float) config('platform.pricing.hotspot_commission_rate'),
                'pppoe_monthly_fee' => (float) config('platform.pricing.pppoe_monthly_fee'),
            ],
        );
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return HasMany<TenantInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(TenantInvoice::class, 'tenant_id', 'tenant_id');
    }

    public function onboardingIsPaid(): bool
    {
        return $this->onboarding_paid_at !== null;
    }

    /**
     * Whether this ISP may use the console right now.
     *
     * Three things have to hold, and the order is the order an ISP meets them: the onboarding
     * fee is settled, nothing has gone unpaid past its grace period, and the subscription has
     * not been cancelled outright. An explicit grant overrides all of it, which is how a trial
     * or an offline arrangement is expressed without special-casing the rest of the system.
     */
    public function permitsAccess(): bool
    {
        if ($this->access_granted_until !== null && $this->access_granted_until->isFuture()) {
            return true;
        }

        if ($this->status === SubscriptionStatus::Cancelled) {
            return false;
        }

        if (! $this->onboardingIsPaid()) {
            return false;
        }

        return ! $this->hasLapsedInvoice();
    }

    /**
     * Why they cannot get in, in words an operator can act on.
     */
    public function accessBlockedReason(): ?string
    {
        if ($this->permitsAccess()) {
            return null;
        }

        return match (true) {
            $this->status === SubscriptionStatus::Cancelled => __('This account has been cancelled. Talk to us to reopen it.'),
            ! $this->onboardingIsPaid() => __('Your one-off setup fee has not been received yet.'),
            default => __('An invoice has gone unpaid past its grace period.'),
        };
    }

    /**
     * An invoice old enough to cost them the console.
     *
     * The grace period runs from the due date, not from the invoice date: an ISP is warned
     * throughout it, and losing the console the morning a payment is late would cost their
     * subscribers service over a matter of hours.
     */
    public function hasLapsedInvoice(): bool
    {
        $grace = (int) config('platform.terms.grace_days');

        return $this->invoices()
            ->outstanding()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->subDays($grace))
            ->exists();
    }

    /**
     * What they owe across every unpaid invoice.
     */
    public function outstandingTotal(): float
    {
        return (float) $this->invoices()->outstanding()->sum('total');
    }

    /**
     * The bounds the rate is held within, whoever is setting it.
     *
     * @return array{float, float}
     */
    public static function commissionBounds(): array
    {
        return [
            (float) config('platform.pricing.hotspot_commission_min'),
            (float) config('platform.pricing.hotspot_commission_max'),
        ];
    }
}
