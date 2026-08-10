<?php

namespace App\Enums;

enum TenantStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending approval',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the ISP may use the platform at all. A suspended or cancelled ISP keeps its data
     * but cannot operate, and its routers must stop authorising new sessions.
     */
    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
