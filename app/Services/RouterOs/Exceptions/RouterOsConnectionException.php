<?php

namespace App\Services\RouterOs\Exceptions;

use RuntimeException;
use Throwable;

class RouterOsConnectionException extends RuntimeException
{
    public static function for(string $host, int $port, Throwable $previous): self
    {
        return new self(
            sprintf('Could not reach RouterOS at %s:%d — %s', $host, $port, $previous->getMessage()),
            previous: $previous,
        );
    }

    public static function badCredentials(string $host): self
    {
        return new self(sprintf('RouterOS at %s rejected the API credentials.', $host));
    }
}
