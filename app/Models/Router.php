<?php

namespace App\Models;

use App\Enums\RouterStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\RouterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A MikroTik device managed by one ISP.
 *
 * Hotspot users and PPPoE secrets are written directly onto the device over the RouterOS API,
 * so this record holds the credentials for that connection and the last known health of the box.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $host
 * @property int $api_port
 * @property string $api_username
 * @property string $api_password
 * @property bool $uses_tls
 * @property RouterStatus $status
 * @property string|null $last_error
 * @property CarbonImmutable|null $last_seen_at
 * @property string|null $identity
 * @property string|null $model
 * @property string|null $serial_number
 * @property string|null $os_version
 * @property int|null $uptime_seconds
 * @property int|null $cpu_load
 * @property int|null $free_memory_bytes
 * @property int|null $total_memory_bytes
 * @property string|null $location
 * @property string|null $notes
 */
#[Fillable([
    'name',
    'slug',
    'host',
    'api_port',
    'api_username',
    'api_password',
    'uses_tls',
    'location',
    'notes',
])]
#[Hidden(['api_password'])]
class Router extends Model
{
    /** @use HasFactory<RouterFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'api_port' => 8728,
        'uses_tls' => false,
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $router): void {
            $router->slug ??= Str::slug($router->name);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RouterStatus::class,
            // The API password is needed in cleartext to authenticate against RouterOS, so it
            // is encrypted at rest rather than hashed.
            'api_password' => 'encrypted',
            'uses_tls' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Human-readable uptime, e.g. "6d 4h 12m".
     */
    public function uptimeForHumans(): ?string
    {
        if ($this->uptime_seconds === null) {
            return null;
        }

        $seconds = $this->uptime_seconds;

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => sprintf('%dd %dh %dm', $days, $hours, $minutes),
            $hours > 0 => sprintf('%dh %dm', $hours, $minutes),
            default => sprintf('%dm', $minutes),
        };
    }

    /**
     * Percentage of RAM in use, or null when the router has not been probed.
     */
    public function memoryUsedPercent(): ?int
    {
        if ($this->total_memory_bytes === null || $this->free_memory_bytes === null || $this->total_memory_bytes === 0) {
            return null;
        }

        $used = $this->total_memory_bytes - $this->free_memory_bytes;

        return (int) round($used / $this->total_memory_bytes * 100);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
