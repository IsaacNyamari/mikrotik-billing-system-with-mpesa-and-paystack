<?php

namespace App\Services\RouterOs\Exceptions;

use RuntimeException;

/**
 * RouterOS accepted the connection but refused the command — a "!trap" response.
 */
class RouterOsCommandException extends RuntimeException
{
    public static function trap(string $command, string $message): self
    {
        return new self(sprintf('RouterOS refused [%s]: %s', $command, $message));
    }
}
