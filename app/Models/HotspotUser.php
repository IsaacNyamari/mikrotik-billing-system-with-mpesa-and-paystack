<?php

namespace App\Models;

use App\Enums\HotspotUserStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\HotspotUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One purchased HotSpot package.
 *
 * The customer buys a plan, gets a username and password — both their M-PESA transaction code —
 * and a matching row is written to the router. Once the package is spent that row is deleted
 * from the device, but this record stays: it is what earnings reporting is built from.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property int $router_id
 * @property int|null $customer_id
 * @property string $username
 * @property string $password
 * @property string $source
 * @property string|null $reference
 * @property string|null $phone
 * @property string $amount
 * @property HotspotUserStatus $status
 * @property int|null $limit_uptime_seconds
 * @property int|null $limit_bytes
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $provisioned_at
 * @property CarbonImmutable|null $first_seen_at
 * @property CarbonImmutable|null $removed_at
 * @property int|null $used_uptime_seconds
 * @property int|null $used_bytes
 * @property string|null $routeros_id
 * @property string|null $last_error
 */
#[Fillable([
    'plan_id',
    'router_id',
    'customer_id',
    'username',
    'password',
    'source',
    'reference',
    'phone',
    'amount',
    'limit_uptime_seconds',
    'limit_bytes',
    'expires_at',
])]
#[Hidden(['password'])]
class HotspotUser extends Model
{
    /** @use HasFactory<HotspotUserFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'source' => 'purchase',
        'amount' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => HotspotUserStatus::class,
            // The customer may need this read back to them over the phone, and the portal
            // logs them in with it, so it is encrypted rather than hashed.
            'password' => 'encrypted',
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Router, $this>
     */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Whether the package has run out of wall-clock time.
     */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * How long is left, for showing the customer.
     */
    public function remainingForHumans(): ?string
    {
        if ($this->expires_at === null || $this->hasExpired()) {
            return null;
        }

        // Absolute rather than relative: "58 minutes", not "58 minutes from now".
        return $this->expires_at->diffForHumans(syntax: CarbonImmutable::DIFF_ABSOLUTE);
    }

    /**
     * Packages the sweeper should take off the router.
     *
     * @param  Builder<HotspotUser>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->where('status', HotspotUserStatus::Active)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * @param  Builder<HotspotUser>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', HotspotUserStatus::Active);
    }
}
