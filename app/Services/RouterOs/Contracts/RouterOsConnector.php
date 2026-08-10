<?php

namespace App\Services\RouterOs\Contracts;

use App\Models\Router;
use App\Services\RouterOs\Exceptions\RouterOsConnectionException;

/**
 * Opens connections to routers.
 *
 * Bound to the live implementation in production and swapped for a fake in tests, so nothing
 * in the application ever constructs a socket itself.
 */
interface RouterOsConnector
{
    /**
     * @throws RouterOsConnectionException
     */
    public function connect(Router $router): RouterOsConnection;
}
