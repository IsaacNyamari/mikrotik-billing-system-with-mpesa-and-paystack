<?php

namespace App\Services\RouterOs\Testing;

use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\Exceptions\RouterOsConnectionException;

/**
 * Hands out in-memory routers, one per Router record, so a test can inspect what a given
 * device ended up holding.
 */
class FakeRouterOsConnector implements RouterOsConnector
{
    /**
     * @var array<int, FakeRouterOsConnection>
     */
    protected array $connections = [];

    /**
     * Router ids configured to be unreachable.
     *
     * @var array<int, string>
     */
    protected array $unreachable = [];

    public function connect(Router $router): RouterOsConnection
    {
        if (isset($this->unreachable[$router->id])) {
            throw new RouterOsConnectionException($this->unreachable[$router->id]);
        }

        return $this->for($router);
    }

    /**
     * The in-memory device standing in for this router, created on first use.
     */
    public function for(Router $router): FakeRouterOsConnection
    {
        return $this->connections[$router->id] ??= new FakeRouterOsConnection;
    }

    /**
     * Make this router refuse connections, as an offline device would.
     */
    public function makeUnreachable(Router $router, string $message = 'Connection timed out'): void
    {
        $this->unreachable[$router->id] = $message;
    }

    public function makeReachable(Router $router): void
    {
        unset($this->unreachable[$router->id]);
    }
}
