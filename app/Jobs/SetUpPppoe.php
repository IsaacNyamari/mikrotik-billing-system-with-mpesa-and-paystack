<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\HotspotInstaller;
use App\Services\RouterOs\PppoeServerInstaller;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Builds a PPPoE server on a router, off the request, as SetUpHotspot does for the HotSpot.
 * The page polls the cached report.
 */
class SetUpPppoe implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public int $uniqueFor = 300;

    /**
     * @param  array{interface: string, address: string, pool: string, dns_servers: string, service_name: string, masquerade: bool}  $setup
     */
    public function __construct(
        public int $tenantId,
        public int $routerId,
        public array $setup,
        public ?int $userId = null,
    ) {}

    public static function cacheKey(int $routerId): string
    {
        return 'pppoe-setup:'.$routerId;
    }

    public function uniqueId(): string
    {
        return (string) $this->routerId;
    }

    public function handle(PppoeServerInstaller $installer): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

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
                $report = [['step' => __('Set up PPPoE'), 'status' => HotspotInstaller::STEP_FAILED, 'detail' => $e->getMessage()]];
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
            'report' => [['step' => __('Set up PPPoE'), 'status' => HotspotInstaller::STEP_FAILED, 'detail' => $e?->getMessage() ?? __('The job failed.')]],
            'finished_at' => now()->toIso8601String(),
        ], now()->addHour());
    }
}
