<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The time one customer was given back for one outage, and whether they were logged back in.
 *
 * One row per customer per outage, enforced by a unique index: the watcher sees a recovery
 * more than once if a run is retried, and time must be credited exactly once.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $router_outage_id
 * @property int $hotspot_user_id
 * @property int $credited_seconds
 * @property bool $was_online
 * @property string|null $relogin_status
 * @property int $relogin_attempts
 * @property CarbonImmutable|null $relogged_in_at
 * @property string|null $relogin_error
 */
#[Fillable(['router_outage_id', 'hotspot_user_id', 'credited_seconds'])]
class OutageCompensation extends Model
{
    use BelongsToTenant;

    public const RELOGIN_PENDING = 'pending';

    public const RELOGIN_DONE = 'done';

    /** Their device never came back to the wifi in time; they can still sign in themselves. */
    public const RELOGIN_GAVE_UP = 'gave_up';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'was_online' => false,
        'relogin_attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'was_online' => 'boolean',
            'relogged_in_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RouterOutage, $this>
     */
    public function outage(): BelongsTo
    {
        return $this->belongsTo(RouterOutage::class, 'router_outage_id');
    }

    /**
     * @return BelongsTo<HotspotUser, $this>
     */
    public function hotspotUser(): BelongsTo
    {
        return $this->belongsTo(HotspotUser::class);
    }
}
