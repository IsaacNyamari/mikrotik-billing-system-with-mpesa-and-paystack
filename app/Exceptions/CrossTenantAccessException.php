<?php

namespace App\Exceptions;

use RuntimeException;

class CrossTenantAccessException extends RuntimeException
{
    public static function for(string $model, ?int $modelTenantId, ?int $currentTenantId): self
    {
        return new self(sprintf(
            'Refusing cross-tenant access to [%s]: the record belongs to tenant [%s] but the '.
            'current tenant is [%s].',
            $model,
            $modelTenantId ?? 'null',
            $currentTenantId ?? 'null',
        ));
    }
}
