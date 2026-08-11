<?php

namespace App\Services\Provisioning;

use App\Models\Router;
use App\Support\Tenancy\Facades\Tenancy;
use RuntimeException;

/**
 * Hands a router its identity on the management network.
 *
 * Addresses span every ISP, so allocation deliberately looks across all of them: the tunnel
 * is one network, and two routers on the same address would point provisioning at whichever
 * answered first. That is the one place in this system where crossing tenants is correct, and
 * it says so rather than relying on being called from an unscoped context.
 */
class TunnelAllocator
{
    /**
     * Give this router a keypair and an address, keeping any it already holds.
     *
     * Re-runnable: an operator who regenerates a script must get the same tunnel back, or
     * every router they have already deployed would need re-pasting.
     */
    public function ensure(Router $router): Router
    {
        if ($router->hasTunnel() && $router->wg_private_key !== null) {
            return $router;
        }

        if ($router->wg_private_key === null) {
            $keypair = WireGuardKeypair::generate();

            $router->wg_private_key = $keypair->privateKey;
            $router->wg_public_key = $keypair->publicKey;
        } elseif ($router->wg_public_key === null) {
            // A private key with no public half — recoverable, since one derives from the other.
            $router->wg_public_key = WireGuardKeypair::publicKeyFor($router->wg_private_key);
        }

        $router->wg_address ??= $this->nextAddress();
        $router->save();

        return $router;
    }

    /**
     * Throw the keypair away and issue a new one, keeping the address.
     *
     * For a script that leaked. The address stays because it is what provisioning talks to,
     * and moving it would mean re-pointing everything that already refers to this router.
     */
    public function rotate(Router $router): Router
    {
        $keypair = WireGuardKeypair::generate();

        $router->wg_private_key = $keypair->privateKey;
        $router->wg_public_key = $keypair->publicKey;
        $router->wg_address ??= $this->nextAddress();
        // The device is no longer configured the way we think it is until the new script runs.
        $router->wg_configured_at = null;
        $router->save();

        return $router;
    }

    /**
     * The lowest free address in the management subnet.
     */
    public function nextAddress(): string
    {
        $prefix = $this->prefix();
        $first = (int) config('wireguard.first_host');
        $last = (int) config('wireguard.last_host');

        // Soft-deleted routers count: their address is still written into a device somewhere
        // until somebody goes and removes it.
        $taken = Tenancy::withoutScope(fn () => Router::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->whereNotNull('wg_address')
            ->pluck('wg_address')
            ->flip());

        for ($host = $first; $host <= $last; $host++) {
            $candidate = $prefix.'.'.$host;

            if (! $taken->has($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('The management subnet is full. Widen wireguard.subnet.');
    }

    /**
     * The first three octets of the management subnet, e.g. "10.8.0".
     */
    protected function prefix(): string
    {
        $subnet = (string) config('wireguard.subnet');
        $network = explode('/', $subnet)[0];
        $octets = explode('.', $network);

        if (count($octets) !== 4) {
            throw new RuntimeException("wireguard.subnet [{$subnet}] is not an IPv4 network.");
        }

        return implode('.', array_slice($octets, 0, 3));
    }
}
