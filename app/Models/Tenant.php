<?php

namespace App\Models;

use App\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An ISP account. Every tenant-owned record in the platform ultimately hangs off one of these.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $address
 * @property string $timezone
 * @property string $currency
 * @property string|null $payment_provider
 * @property array<string, mixed>|null $settings
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $suspended_at
 */
#[Fillable([
    'name',
    'slug',
    'status',
    'contact_name',
    'contact_email',
    'contact_phone',
    'address',
    'timezone',
    'currency',
    'payment_provider',
    'settings',
])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $tenant): void {
            $tenant->uuid ??= (string) Str::uuid7();
            $tenant->slug ??= Str::slug($tenant->name);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * Back-office operators belonging to this ISP.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Roles defined by this ISP itself, on top of the platform-wide roles.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * What this ISP pays the platform.
     *
     * @return HasOne<TenantSubscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }

    /**
     * @return HasMany<TenantInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(TenantInvoice::class);
    }

    /**
     * The subscription, created on the standard terms if this ISP has none.
     *
     * An ISP without one would be an ISP nobody is billing, so it is reached through here
     * rather than through the relation.
     */
    public function billing(): TenantSubscription
    {
        return TenantSubscription::forTenant($this);
    }

    public function isOperational(): bool
    {
        return $this->status->isOperational();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
