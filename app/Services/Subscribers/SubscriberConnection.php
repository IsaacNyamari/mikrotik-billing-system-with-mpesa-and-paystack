<?php

namespace App\Services\Subscribers;

use App\Models\RouterOutage;
use App\Models\ServiceAccount;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Whether a subscriber's PPPoE line is connected right now, read off their router.
 *
 * Cached for twenty seconds per line: a subscriber refreshing their page should not become
 * a stream of API logins on a router that is busy carrying everybody's traffic.
 */
class SubscriberConnection
{
    protected const CACHE_SECONDS = 20;

    public function __construct(protected RouterOsConnector $connector) {}

    /**
     * @return array{state: string, uptime: ?string, address: ?string, device: ?string, downloaded: ?int, uploaded: ?int, outage_since: ?string, checked_at: string}
     */
    public function status(ServiceAccount $account, bool $fresh = false): array
    {
        $key = 'subscriber-connection:'.$account->id;

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, fn (): array => $this->read($account));
    }

    /**
     * @return array{state: string, uptime: ?string, address: ?string, device: ?string, downloaded: ?int, uploaded: ?int, outage_since: ?string, checked_at: string}
     */
    protected function read(ServiceAccount $account): array
    {
        $result = [
            'state' => 'unknown',
            'uptime' => null,
            'address' => null,
            'device' => null,
            'downloaded' => null,
            'uploaded' => null,
            'outage_since' => null,
            'checked_at' => now()->toIso8601String(),
        ];

        $router = $account->router;

        if ($router === null) {
            return $result;
        }

        // A known outage answers the question without waiting on a router that is not there.
        $outage = RouterOutage::query()->where('router_id', $router->id)->open()->latest('started_at')->first();

        if ($outage !== null) {
            $result['state'] = 'outage';
            $result['outage_since'] = $outage->started_at->toIso8601String();

            return $result;
        }

        try {
            $connection = $this->connector->connect($router);

            try {
                $session = $connection->first('/ppp/active', ['name' => $account->username]);

                if ($session === null) {
                    $result['state'] = 'offline';

                    return $result;
                }

                $result['state'] = 'online';
                $result['uptime'] = $session['uptime'] ?? null;
                $result['address'] = $session['address'] ?? null;
                $result['device'] = $session['caller-id'] ?? null;

                // The session's own interface counts the bytes. From the router's side, what it
                // received is what the subscriber uploaded.
                $interface = $connection->first('/interface', ['name' => '<pppoe-'.$account->username.'>']);

                if ($interface !== null) {
                    $result['uploaded'] = isset($interface['rx-byte']) ? (int) $interface['rx-byte'] : null;
                    $result['downloaded'] = isset($interface['tx-byte']) ? (int) $interface['tx-byte'] : null;
                }
            } finally {
                $connection->disconnect();
            }
        } catch (Throwable) {
            $result['state'] = 'unknown';
        }

        return $result;
    }
}
