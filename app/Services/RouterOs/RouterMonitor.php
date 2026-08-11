<?php

namespace App\Services\RouterOs;

use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use Throwable;

/**
 * Reads a live picture of what a router is doing right now.
 *
 * Everything here is read-only and best-effort: a monitor screen that throws because one menu
 * is unavailable is worse than one that shows the rest and says which part it could not read.
 */
class RouterMonitor
{
    public function __construct(protected RouterOsConnector $connector) {}

    /**
     * Everything the monitor screen shows, in one connection.
     *
     * Traffic is reported as cumulative byte counters rather than a rate: the caller compares
     * two snapshots to get bits per second, which needs no special RouterOS command and so
     * works the same on every version.
     *
     * @return array{
     *     online: bool,
     *     error: string|null,
     *     resource: array<string, string>,
     *     interfaces: array<int, array<string, string>>,
     *     hotspotActive: array<int, array<string, string>>,
     *     hotspotHosts: int,
     *     pppActive: array<int, array<string, string>>,
     *     wireless: array<int, array<string, string>>,
     *     leases: int,
     *     sampledAt: float,
     * }
     */
    public function snapshot(Router $router): array
    {
        $blank = [
            'online' => false,
            'error' => null,
            'resource' => [],
            'interfaces' => [],
            'hotspotActive' => [],
            'hotspotHosts' => 0,
            'pppActive' => [],
            'wireless' => [],
            'leases' => 0,
            // Taken here rather than by the caller, so the rate is measured against when the
            // counters were actually read.
            'sampledAt' => microtime(true),
        ];

        try {
            $connection = $this->connector->connect($router);
        } catch (Throwable $e) {
            return [...$blank, 'error' => $e->getMessage()];
        }

        $snapshot = [
            ...$blank,
            'online' => true,
            'resource' => $this->read($connection, fn () => $connection->first('/system/resource') ?? []),
            'interfaces' => $this->read($connection, fn () => $this->runningInterfaces($connection)),
            'hotspotActive' => $this->read($connection, fn () => $connection->get('/ip/hotspot/active')),
            'hotspotHosts' => count($this->read($connection, fn () => $connection->get('/ip/hotspot/host'))),
            'pppActive' => $this->read($connection, fn () => $connection->get('/ppp/active')),
            'wireless' => $this->read($connection, fn () => $connection->get('/interface/wireless/registration-table')),
            'leases' => count($this->read($connection, fn () => $connection->get('/ip/dhcp-server/lease'))),
            'sampledAt' => microtime(true),
        ];

        $connection->disconnect();

        return $snapshot;
    }

    /**
     * Interfaces worth charting: those actually passing traffic.
     *
     * @return array<int, array<string, string>>
     */
    protected function runningInterfaces(Contracts\RouterOsConnection $connection): array
    {
        $interfaces = $connection->get('/interface');

        return array_values(array_filter(
            $interfaces,
            fn (array $row): bool => ($row['disabled'] ?? 'false') !== 'true'
                // A loopback carries nothing anyone wants to watch.
                && ($row['type'] ?? '') !== 'loopback',
        ));
    }

    /**
     * Run one reading, treating a failure as "not available" rather than fatal.
     *
     * A monitor is most useful precisely when something is wrong, so losing the whole screen
     * because one menu is missing — PPP on a device with no PPP, say — defeats the point.
     *
     * @template TValue
     *
     * @param  callable(): TValue  $reader
     * @return TValue|array<never, never>
     */
    protected function read(Contracts\RouterOsConnection $connection, callable $reader): mixed
    {
        try {
            return $reader();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Bits per second between two byte counters.
     *
     * Counters wrap and reset when an interface restarts, so a negative delta is reported as
     * zero rather than as a spike that would wreck the chart's scale.
     */
    public static function rate(int $previousBytes, int $currentBytes, float $seconds): float
    {
        if ($seconds <= 0 || $currentBytes < $previousBytes) {
            return 0.0;
        }

        return ($currentBytes - $previousBytes) * 8 / $seconds;
    }

    /**
     * Bits per second, written the way a network engineer reads it.
     */
    public static function formatBits(float $bitsPerSecond): string
    {
        return match (true) {
            $bitsPerSecond >= 1_000_000_000 => round($bitsPerSecond / 1_000_000_000, 2).' Gbps',
            $bitsPerSecond >= 1_000_000 => round($bitsPerSecond / 1_000_000, 2).' Mbps',
            $bitsPerSecond >= 1_000 => round($bitsPerSecond / 1_000, 1).' kbps',
            default => round($bitsPerSecond).' bps',
        };
    }

    /**
     * Byte counts, written the way a person reads them.
     */
    public static function formatBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => round($bytes / 1_073_741_824, 2).' GB',
            $bytes >= 1_048_576 => round($bytes / 1_048_576, 1).' MB',
            $bytes >= 1024 => round($bytes / 1024).' KB',
            default => $bytes.' B',
        };
    }
}
