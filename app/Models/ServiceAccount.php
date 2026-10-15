<?php

namespace App\Models;

use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One network service belonging to a customer: a PPPoE line, a HotSpot login, a static-IP link.
 *
 * This is the identity the network actually authenticates. Plans, subscriptions, RADIUS
 * identities and sessions all attach here rather than to the customer.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int|null $plan_id
 * @property int|null $router_id
 * @property string $account_number
 * @property ServiceType $type
 * @property string|null $label
 * @property string $username
 * @property string|null $secret
 * @property ServiceAccountStatus $status
 * @property string|null $static_ip
 * @property string|null $mac_address
 * @property string|null $installation_address
 * @property string|null $routeros_id
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $provisioned_at
 * @property string|null $provision_error
 * @property CarbonImmutable|null $suspended_at
 * @property CarbonImmutable|null $terminated_at
 * @property CarbonImmutable|null $expires_at
 * @property string|null $pay_token
 * @property CarbonImmutable|null $reminded_before_for
 * @property CarbonImmutable|null $reminded_due_for
 * @property bool $suspended_for_nonpayment
 * @property string|null $notes
 */
#[Fillable([
    'customer_id',
    'plan_id',
    'router_id',
    'account_number',
    'type',
    'label',
    'username',
    'secret',
    'status',
    'static_ip',
    'mac_address',
    'installation_address',
    'notes',
])]
#[Hidden(['secret'])]
class ServiceAccount extends Model
{
    /** @use HasFactory<ServiceAccountFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

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
            'type' => ServiceType::class,
            'status' => ServiceAccountStatus::class,
            // The network password is readable by the platform because RADIUS provisioning
            // needs the cleartext, so it is encrypted at rest rather than hashed.
            'secret' => 'encrypted',
            'activated_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'expires_at' => 'datetime',
            'reminded_before_for' => 'date',
            'reminded_due_for' => 'date',
            'suspended_for_nonpayment' => 'boolean',
        ];
    }

    /**
     * Renewals paid against this line.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Texts sent about this line.
     *
     * @return HasMany<SmsMessage, $this>
     */
    public function smsMessages(): HasMany
    {
        return $this->hasMany(SmsMessage::class);
    }

    /**
     * Whether this line is billed by the system: a PPPoE line with a package and a router.
     */
    public function isBilled(): bool
    {
        return $this->type === ServiceType::Pppoe && $this->plan_id !== null && $this->router_id !== null;
    }

    /**
     * The link a subscriber is texted, to pay from their phone without an account of their own.
     *
     * The token is made on first use and kept, so a link sent last month still works.
     */
    public function payUrl(): string
    {
        if ($this->pay_token === null) {
            $this->pay_token = Str::lower(Str::random(24));
            $this->saveQuietly();
        }

        return route('pay', ['token' => $this->pay_token]);
    }

    /**
     * Whole days until the paid period ends: negative once it has passed, null when never set.
     */
    public function daysLeft(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * The device this account's credentials live on.
     *
     * @return BelongsTo<Router, $this>
     */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Whether the router currently holds this account's credentials.
     */
    public function isProvisioned(): bool
    {
        return $this->provisioned_at !== null && $this->provision_error === null;
    }

    /**
     * Whether the network should let this account on right now.
     *
     * Both gates have to pass: a blocked customer takes all of their services down, however
     * healthy the individual account looks.
     */
    public function allowsAuthentication(): bool
    {
        return $this->status->allowsAuthentication()
            && $this->customer->status->allowsService();
    }

    /**
     * @param  Builder<ServiceAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ServiceAccountStatus::Active);
    }

    /**
     * @param  Builder<ServiceAccount>  $query
     */
    public function scopeOfType(Builder $query, ServiceType $type): void
    {
        $query->where('type', $type);
    }
}
