<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The copy of a plan that lives on one router, as a RouterOS profile.
 *
 * Tracked separately from the plan because the two drift: a push can fail, a router can be
 * offline, and an operator can edit the device by hand. This row records what the platform
 * last managed to put there.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $plan_id
 * @property int $router_id
 * @property string $profile_name
 * @property string|null $routeros_id
 * @property CarbonImmutable|null $synced_at
 * @property string|null $sync_error
 */
#[Fillable(['plan_id', 'router_id', 'profile_name', 'routeros_id', 'synced_at', 'sync_error'])]
class PlanRouterProfile extends Model
{
    use BelongsToTenant;

    protected $table = 'plan_router';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
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

    public function isSynced(): bool
    {
        return $this->synced_at !== null && $this->sync_error === null;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->sync_error !== null => __('Failed'),
            $this->synced_at !== null => __('On device'),
            default => __('Not pushed'),
        };
    }

    public function statusColor(): string
    {
        return match (true) {
            $this->sync_error !== null => 'red',
            $this->synced_at !== null => 'green',
            default => 'zinc',
        };
    }
}
