<?php

namespace App\Models;

use App\Enums\PlanType;
use App\Enums\ValidityUnit;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An internet package an ISP sells.
 *
 * A plan is a business object. Its network settings are copied onto each router as a RouterOS
 * profile, which is what actually shapes traffic — see PlanRouterProfile for that copy.
 *
 * @property int $id
 * @property int $tenant_id
 * @property PlanType $type
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string $price
 * @property int $validity_amount
 * @property ValidityUnit $validity_unit
 * @property int|null $download_kbps
 * @property int|null $upload_kbps
 * @property int|null $data_cap_mb
 * @property int $shared_users
 * @property int|null $idle_timeout_minutes
 * @property bool $is_active
 * @property bool $is_public
 * @property int $sort_order
 */
#[Fillable([
    'type',
    'name',
    'code',
    'description',
    'price',
    'validity_amount',
    'validity_unit',
    'download_kbps',
    'upload_kbps',
    'data_cap_mb',
    'shared_users',
    'idle_timeout_minutes',
    'is_active',
    'is_public',
    'sort_order',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'shared_users' => 1,
        'is_active' => true,
        'is_public' => true,
        'sort_order' => 0,
        'price' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PlanType::class,
            'validity_unit' => ValidityUnit::class,
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * The copy of this plan living on each router.
     *
     * @return HasMany<PlanRouterProfile, $this>
     */
    public function routerProfiles(): HasMany
    {
        return $this->hasMany(PlanRouterProfile::class);
    }

    /**
     * @return BelongsToMany<Router, $this>
     */
    public function routers(): BelongsToMany
    {
        return $this->belongsToMany(Router::class, 'plan_router')
            ->withPivot(['profile_name', 'routeros_id', 'synced_at', 'sync_error'])
            ->withTimestamps();
    }

    /**
     * How long the package runs for, in seconds. This is what RouterOS is given as an
     * uptime limit.
     */
    public function validitySeconds(): int
    {
        return $this->validity_amount * $this->validity_unit->seconds();
    }

    /**
     * Validity written the way RouterOS wants it. Seconds are unambiguous across versions,
     * where "1d" style values are parsed differently by different menus.
     */
    public function routerOsUptimeLimit(): string
    {
        return $this->validitySeconds().'s';
    }

    /**
     * RouterOS rate-limit, written "upload/download" from the client's point of view.
     * Null when the plan is uncapped, which leaves the profile unshaped.
     */
    public function routerOsRateLimit(): ?string
    {
        if ($this->download_kbps === null && $this->upload_kbps === null) {
            return null;
        }

        // An unset side means "not limited in that direction", but RouterOS needs both halves,
        // so mirror whichever one was given.
        $upload = $this->upload_kbps ?? $this->download_kbps;
        $download = $this->download_kbps ?? $this->upload_kbps;

        return sprintf('%dk/%dk', $upload, $download);
    }

    /**
     * Data allowance in bytes, for RouterOS limit-bytes-total.
     */
    public function dataCapBytes(): ?int
    {
        return $this->data_cap_mb === null ? null : $this->data_cap_mb * 1024 * 1024;
    }

    /**
     * Speed written for a human, e.g. "5 Mbps down / 2 Mbps up".
     */
    public function speedForHumans(): string
    {
        if ($this->download_kbps === null && $this->upload_kbps === null) {
            return __('Unshaped');
        }

        $format = fn (?int $kbps): string => $kbps === null
            ? '—'
            : ($kbps >= 1000 ? round($kbps / 1000, 1).' Mbps' : $kbps.' kbps');

        return sprintf('%s down / %s up', $format($this->download_kbps), $format($this->upload_kbps));
    }

    public function validityForHumans(): string
    {
        return trans_choice(
            ':count '.rtrim($this->validity_unit->value, 's').'|:count '.$this->validity_unit->value,
            $this->validity_amount,
            ['count' => $this->validity_amount],
        );
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeOfType(Builder $query, PlanType $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Plans a customer may buy from the captive portal.
     *
     * @param  Builder<Plan>  $query
     */
    public function scopeSellable(Builder $query): void
    {
        $query->where('is_active', true)->where('is_public', true);
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
