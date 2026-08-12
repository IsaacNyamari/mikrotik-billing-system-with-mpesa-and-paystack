<?php

namespace App\Services\RouterOs;

use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;

/**
 * Opens one connection per router and hands the same one back for the rest of the request.
 *
 * Every RouterOS conversation begins with a TCP handshake and a login, and both of those are
 * round trips down a WireGuard tunnel to a device on somebody else's broadband. Issuing a
 * HotSpot login used to pay for two of them — the plan profile is pushed by one service and the
 * user row written by another, each opening its own socket — while a customer who has just paid
 * watches a spinner.
 *
 * Nothing about the callers changes: they still ask for a connection and still call
 * `disconnect()` when they are finished. What changes is that the second ask costs nothing.
 *
 * The pool is deliberately request-scoped rather than persistent. A socket held between requests
 * is one nobody is watching: the router reboots, the tunnel re-handshakes, the connection dies
 * quietly, and the next customer inherits the corpse.
 */
class PooledRouterOsConnector implements RouterOsConnector
{
    /**
     * @var array<int, PooledRouterOsConnection>
     */
    protected array $connections = [];

    public function __construct(protected RouterOsConnector $connector) {}

    public function connect(Router $router): RouterOsConnection
    {
        $key = (int) $router->id;

        // A router with no id has not been saved, so there is nothing stable to pool it under.
        if ($key === 0) {
            return $this->connector->connect($router);
        }

        $pooled = $this->connections[$key] ?? null;

        // A connection that failed at the transport level is not reused. The device may have
        // rebooted or the tunnel re-handshaked underneath it, and a dead socket handed to the
        // next caller fails in a way that looks like the router being down.
        if ($pooled !== null && $pooled->isUsable()) {
            return $pooled;
        }

        return $this->connections[$key] = new PooledRouterOsConnection($this->connector->connect($router));
    }

    /**
     * Drop every pooled connection.
     *
     * Called when a router's credentials or address change, so the next command is not sent
     * down a socket authenticated against what the router used to be.
     */
    public function flush(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }

        $this->connections = [];
    }

    /**
     * Drop the pooled connection to one router, leaving the others alone.
     */
    public function forget(Router $router): void
    {
        $key = (int) $router->id;

        if (isset($this->connections[$key])) {
            $this->connections[$key]->close();

            unset($this->connections[$key]);
        }
    }
}
