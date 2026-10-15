<?php

namespace App\Services\RouterOs\Exceptions;

use RuntimeException;
use Throwable;

class RouterOsConnectionException extends RuntimeException
{
    /**
     * The router answered and refused the login, as opposed to not answering at all. The one
     * case a stored administrator login can repair.
     */
    public bool $rejectedCredentials = false;

    public static function for(string $host, int $port, Throwable $previous): self
    {
        return new self(
            sprintf('Could not reach RouterOS at %s:%d — %s', $host, $port, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function badCredentials(string $host): self
    {
        $exception = new self(sprintf('RouterOS at %s rejected the API credentials.', $host));
        $exception->rejectedCredentials = true;

        return $exception;
    }
}
