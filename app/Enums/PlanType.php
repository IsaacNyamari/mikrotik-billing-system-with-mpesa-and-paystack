<?php

namespace App\Enums;

enum PlanType: string
{
    case Hotspot = 'hotspot';
    case Pppoe = 'pppoe';

    public function label(): string
    {
        return match ($this) {
            self::Hotspot => 'HotSpot',
            self::Pppoe => 'PPPoE',
        };
    }

    /**
     * The RouterOS menu holding the profiles for this kind of plan.
     */
    public function profileMenu(): string
    {
        return match ($this) {
            self::Hotspot => '/ip/hotspot/user/profile',
            self::Pppoe => '/ppp/profile',
        };
    }

    /**
     * The RouterOS menu holding the users for this kind of plan.
     */
    public function userMenu(): string
    {
        return match ($this) {
            self::Hotspot => '/ip/hotspot/user',
            self::Pppoe => '/ppp/secret',
        };
    }

    /**
     * HotSpot packages are bought by whoever walks in; PPPoE lines are handed out by the ISP
     * to a named customer.
     */
    public function isSelfService(): bool
    {
        return $this === self::Hotspot;
    }
}
