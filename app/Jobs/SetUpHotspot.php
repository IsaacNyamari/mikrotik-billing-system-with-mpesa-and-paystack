<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\HotspotInstaller;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Builds a HotSpot on a router, off the request.
 *
 * Provisioning goes through the queue. A dozen commands and eight page downloads down a tunnel
 * can take half a minute, and a web request held that long is one a proxy times out — leaving
 * the operator unsure whether anything happened. The page polls the result instead.
 */
class SetUpHotspot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public int $uniqueFor = 300;

    /**
     * @param  array{interface: string, address: string, pool: string, dns_servers: string, dns_name: string, masquerade: bool, name: string}  $setup
     */
    public function __construct(
        public int $tenantId,
        public int $routerId,
        public array $setup,
        public ?int $userId = null,
    ) {}

    public static function cacheKey(int $routerId): string
    {
        return 'hotspot-setup:'.$routerId;
    }

    public function uniqueId(): string
    {
        return (string) $this->routerId;
    }

    public function handle(HotspotInstaller $installer): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        // So the audit trail names the operator who pressed the button, not "system".
        if ($this->userId !== null) {
            Auth::onceUsingId($this->userId);
        }

        Tenancy::forTenant($tenant, function () use ($installer): void {
            $router = Router::query()->find($this->routerId);

            if ($router === null) {
                return;
            }

            try {
                $report = $installer->install($router, $this->setup);
            } catch (Throwable $e) {
                $report = [['step' => __('Set up the HotSpot'), 'status' => HotspotInstaller::STEP_FAILED, 'detail' => $e->getMessage()]];
            }

            Cache::put(self::cacheKey($this->routerId), [
                'status' => 'done',
                'report' => $report,
                'finished_at' => now()->toIso8601String(),
            ], now()->addHour());
        });
    }

    public function failed(?Throwable $e): void
    {
        Cache::put(self::cacheKey($this->routerId), [
            'status' => 'done',
            'report' => [['step' => __('Set up the HotSpot'), 'status' => HotspotInstaller::STEP_FAILED, 'detail' => $e?->getMessage() ?? __('The job failed.')]],
            'finished_at' => now()->toIso8601String(),
        ], now()->addHour());
    }
}
