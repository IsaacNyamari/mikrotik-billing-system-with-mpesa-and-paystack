<?php

namespace App\Models;

use App\Enums\RouterStatus;
use App\Jobs\SyncWireGuardPeers;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\RouterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property string $portal_key
 * @property string $host
 * @property int $api_port
 * @property string $api_username
 * @property string $api_password
 * @property string|null $admin_username
 * @property string|null $admin_password
 * @property string|null $wan_pppoe_password
 * @property bool $uses_tls
 * @property string|null $wg_private_key
 * @property string|null $wg_public_key
 * @property string|null $wg_address
 * @property CarbonImmutable|null $wg_configured_at
 * @property array<string, mixed>|null $setup_options
 * @property string|null $provision_token
 * @property CarbonImmutable|null $provision_token_expires_at
 * @property CarbonImmutable|null $provision_fetched_at
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
// The tunnel's private key is as sensitive as the API password: it is the router's identity
// on the management network.
// The administrator password and the upstream PPPoE password are written into a rebuilt
// router and are each a way into somebody's network.
#[Hidden(['api_password', 'wg_private_key', 'admin_password', 'wan_pppoe_password'])]
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
            // Identifies this device in public captive-portal URLs. Unguessable, so the
            // portal address gives away neither the ISP nor a sequential id.
            $router->portal_key ??= Str::lower(Str::random(24));
        });

        // The server's half of the tunnel, without anyone opening a terminal. Whenever a
        // router is given keys or an address — first setup, a rotation — this server learns
        // them too. After the commit, so the job never reads a key that was rolled back.
        static::saved(function (self $router): void {
            if ($router->wasChanged(['wg_public_key', 'wg_address']) || ($router->wasRecentlyCreated && $router->wg_public_key !== null)) {
                SyncWireGuardPeers::dispatch()->afterCommit();
            }
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
            // The router's identity on the management network, and needed in cleartext to
            // write into the setup script.
            'wg_private_key' => 'encrypted',
            // Written into the configuration a wiped router rebuilds itself from, so both are
            // needed in cleartext — and neither may sit in the database as one.
            'admin_password' => 'encrypted',
            'wan_pppoe_password' => 'encrypted',
            'uses_tls' => 'boolean',
            'last_seen_at' => 'datetime',
            'wg_configured_at' => 'datetime',
            'setup_options' => 'array',
            'provision_token_expires_at' => 'datetime',
            'provision_fetched_at' => 'datetime',
        ];
    }

    /**
     * Whether the one-time link the router fetches its configuration from is still good.
     */
    public function provisionTokenIsUsable(): bool
    {
        return $this->provision_token !== null
            && $this->provision_fetched_at === null
            && $this->provision_token_expires_at !== null
            && $this->provision_token_expires_at->isFuture();
    }

    /**
     * What the operator chose the last time a setup script was built for this router.
     *
     * Falls back to the platform defaults, so a router added before this was remembered still
     * produces a sensible script.
     *
     * @return array<string, mixed>
     */
    public function setupOptions(): array
    {
        return array_merge((array) config('wireguard.defaults'), $this->setup_options ?? []);
    }

    /**
     * Whether this router has been given a place on the management network.
     */
    public function hasTunnel(): bool
    {
        return $this->wg_address !== null && $this->wg_public_key !== null;
    }

    /**
     * Whether the platform reaches this router over the tunnel rather than over the internet.
     *
     * The specification is emphatic that the API must never be exposed publicly when a
     * management tunnel is available, so this is what tells the two situations apart.
     */
    public function reachedOverTunnel(): bool
    {
        return $this->hasTunnel() && $this->host === $this->wg_address;
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

    /**
     * Where this router's captive portal lives. This is the address the HotSpot login page
     * redirects customers to.
     */
    public function portalUrl(): string
    {
        return route('portal', ['portalKey' => $this->portal_key]);
    }

    /**
     * @return HasMany<RouterOutage, $this>
     */
    public function outages(): HasMany
    {
        return $this->hasMany(RouterOutage::class);
    }

    /**
     * The outage still going on, if there is one.
     *
     * @return HasOne<RouterOutage, $this>
     */
    public function openOutage(): HasOne
    {
        return $this->hasOne(RouterOutage::class)->whereNull('ended_at')->latestOfMany('started_at');
    }

    /**
     * @return HasMany<HotspotUser, $this>
     */
    public function hotspotUsers(): HasMany
    {
        return $this->hasMany(HotspotUser::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
