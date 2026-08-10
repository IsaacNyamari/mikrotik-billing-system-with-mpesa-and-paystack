<?php

namespace App\Services\RouterOs;

use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\Exceptions\RouterOsConnectionException;
use RouterOS\Client;
use RouterOS\Exceptions\BadCredentialsException;
use Throwable;

class LiveRouterOsConnector implements RouterOsConnector
{
    public function connect(Router $router): RouterOsConnection
    {
        try {
            return new LiveRouterOsConnection(new Client([
                'host' => $router->host,
                'user' => $router->api_username,
                'pass' => $router->api_password,
                'port' => $router->api_port,
                'ssl' => $router->uses_tls,

                // Post-6.43 login. RouterOS older than that needs the challenge-response
                // scheme, which we would rather surface as a clear error than support
                // silently — those releases are years past end of support.
                'legacy' => false,

                // An unreachable router must fail fast: an operator is watching a screen, and
                // provisioning runs inline with their click.
                'timeout' => (int) config('routeros.connect_timeout'),
                'socket_timeout' => (int) config('routeros.read_timeout'),
                'attempts' => (int) config('routeros.attempts'),
                'delay' => 1,
            ]));
        } catch (BadCredentialsException) {
            throw RouterOsConnectionException::badCredentials($router->host);
        } catch (Throwable $e) {
            throw RouterOsConnectionException::for($router->host, $router->api_port, $e);
        }
    }
}
