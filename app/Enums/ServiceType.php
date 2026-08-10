<?php

namespace App\Enums;

enum ServiceType: string
{
    case Pppoe = 'pppoe';
    case Hotspot = 'hotspot';
    case Static = 'static';

    public function label(): string
    {
        return match ($this) {
            self::Pppoe => 'PPPoE',
            self::Hotspot => 'HotSpot',
            self::Static => 'Static IP',
        };
    }

    /**
     * Whether this service authenticates against RADIUS with a username and secret.
     * A static-IP service is authorised by address instead, so it carries no secret.
     */
    public function usesCredentials(): bool
    {
        return $this !== self::Static;
    }

    public function requiresStaticIp(): bool
    {
        return $this === self::Static;
    }
}
