<?php

namespace App\Services\RouterOs;

use App\Enums\RouterStatus;
use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\Exceptions\RouterOsConnectionException;
use App\Support\Audit\AuditLogger;
use Throwable;

/**
 * Contacts a router, records what it says about itself, and updates its status.
 *
 * This doubles as the connection test an operator runs after adding a device: if the probe
 * succeeds, the credentials and API access are good enough to provision with.
 */
class RouterHealthProbe
{
    public function __construct(
        protected RouterOsConnector $connector,
        protected AuditLogger $audit,
    ) {}

    /**
     * Probe the router and persist the result. Never throws — an unreachable router is an
     * expected outcome that is recorded, not an error that aborts the caller.
     */
    public function probe(Router $router): Router
    {
        try {
            $connection = $this->connector->connect($router);

            $resource = $connection->first('/system/resource') ?? [];
            $identity = $connection->first('/system/identity') ?? [];
            $routerboard = $this->routerboard($connection);

            $connection->disconnect();

            // Assigned rather than filled: status and health are reported by the device, so
            // they are deliberately not mass-assignable and fill() would drop them.
            $router->status = RouterStatus::Online;
            $router->last_error = null;
            $router->last_seen_at = now();
            $router->identity = $identity['name'] ?? null;
            $router->os_version = $resource['version'] ?? null;
            $router->uptime_seconds = $this->parseUptime($resource['uptime'] ?? null);
            $router->cpu_load = isset($resource['cpu-load']) ? (int) $resource['cpu-load'] : null;
            $router->free_memory_bytes = isset($resource['free-memory']) ? (int) $resource['free-memory'] : null;
            $router->total_memory_bytes = isset($resource['total-memory']) ? (int) $resource['total-memory'] : null;

            // "board-name" from /system/resource is the friendly model on most devices;
            // /system/routerboard carries the exact model and serial on hardware boards, and
            // is simply absent on a CHR.
            $router->model = $routerboard['model'] ?? $resource['board-name'] ?? null;
            $router->serial_number = $routerboard['serial-number'] ?? null;

            $router->save();

            return $router;
        } catch (RouterOsConnectionException $e) {
            return $this->recordFailure($router, RouterStatus::Offline, $e->getMessage());
        } catch (Throwable $e) {
            // The router answered but something about the exchange was wrong — bad
            // permissions on the API user, an unexpected RouterOS version. Worth
            // distinguishing from simply being unreachable.
            return $this->recordFailure($router, RouterStatus::Error, $e->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    protected function routerboard(Contracts\RouterOsConnection $connection): array
    {
        try {
            return $connection->first('/system/routerboard') ?? [];
        } catch (Throwable) {
            // Cloud Hosted Router has no routerboard menu at all.
            return [];
        }
    }

    protected function recordFailure(Router $router, RouterStatus $status, string $message): Router
    {
        $wasOnline = $router->status === RouterStatus::Online;

        $router->status = $status;
        $router->last_error = $message;
        $router->save();

        // Only worth an audit entry when the router actually changed state; a device that has
        // been down for a week should not fill the trail on every scheduled probe.
        if ($wasOnline) {
            $this->audit->record(
                event: 'router.unreachable',
                auditable: $router,
                description: sprintf('Router %s became %s: %s', $router->name, $status->value, $message),
            );
        }

        return $router;
    }

    /**
     * RouterOS reports uptime as "6w2d3h4m5s" — weeks through seconds, each part optional.
     */
    protected function parseUptime(?string $uptime): ?int
    {
        if ($uptime === null || $uptime === '') {
            return null;
        }

        if (preg_match_all('/(\d+)([wdhms])/', $uptime, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $seconds = 0;

        foreach ($matches as [, $value, $unit]) {
            $seconds += (int) $value * match ($unit) {
                'w' => 604800,
                'd' => 86400,
                'h' => 3600,
                'm' => 60,
                's' => 1,
            };
        }

        return $seconds;
    }
}
