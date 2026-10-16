<?php

namespace App\Models;

use App\Enums\OutageCause;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stretch of time a router could not be reached, and what was done about it.
 *
 * Opened by the watcher the first minute a router stops answering, closed the first minute it
 * answers again. Nothing here is mass-assignable: every field is something the watcher
 * observed, not something an operator supplies.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $router_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $device_booted_at
 * @property OutageCause|null $cause
 * @property string|null $last_error
 * @property int $affected_users
 * @property int $credited_seconds
 * @property int $relogged_users
 * @property CarbonImmutable|null $compensated_at
 * @property CarbonImmutable|null $alerted_at
 */
class RouterOutage extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'affected_users' => 0,
        'credited_seconds' => 0,
        'relogged_users' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'detected_at' => 'datetime',
            'ended_at' => 'datetime',
            'device_booted_at' => 'datetime',
            'compensated_at' => 'datetime',
            'alerted_at' => 'datetime',
            'cause' => OutageCause::class,
        ];
    }

    /**
     * @return BelongsTo<Router, $this>
     */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * @return HasMany<OutageCompensation, $this>
     */
    public function compensations(): HasMany
    {
        return $this->hasMany(OutageCompensation::class);
    }

    /**
     * @param  Builder<RouterOutage>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * How long customers were without service, so far if it is still going.
     */
    public function durationSeconds(): int
    {
        $end = $this->ended_at ?? now();

        return max(0, (int) $this->started_at->diffInSeconds($end, absolute: true));
    }

    public function durationForHumans(): string
    {
        return self::humanDuration($this->durationSeconds());
    }

    /**
     * "1 hour 5 minutes", never "1.08 hours": this is read by customers as well as engineers.
     */
    public static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return trans_choice(':count second|:count seconds', $seconds, ['count' => $seconds]);
        }

        return CarbonImmutable::now()
            ->subSeconds($seconds)
            ->diffForHumans(syntax: CarbonImmutable::DIFF_ABSOLUTE, parts: 2);
    }
}
