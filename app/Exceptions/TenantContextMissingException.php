<?php

namespace App\Exceptions;

use RuntimeException;

class TenantContextMissingException extends RuntimeException
{
    public static function forModel(string $model, string $operation): self
    {
        return new self(sprintf(
            'Cannot %s [%s] without a resolved tenant. Wrap the call in Tenancy::forTenant() '.
            'if it belongs to one ISP, or Tenancy::withoutScope() if it is deliberately '.
            'platform-wide.',
            $operation,
            $model,
        ));
    }
}
