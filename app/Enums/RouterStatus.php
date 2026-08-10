<?php

namespace App\Enums;

enum RouterStatus: string
{
    case Pending = 'pending';
    case Online = 'online';
    case Offline = 'offline';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Never contacted',
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Error => 'Error',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Online => 'green',
            self::Offline => 'red',
            self::Error => 'orange',
        };
    }

    /**
     * Whether the platform believes it can push configuration to this router right now.
     *
     * Provisioning still attempts the connection regardless — this only drives what the
     * operator is shown, since a router can go offline between the probe and the push.
     */
    public function isReachable(): bool
    {
        return $this === self::Online;
    }
}
