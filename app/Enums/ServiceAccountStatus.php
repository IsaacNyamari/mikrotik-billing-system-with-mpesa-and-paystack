<?php

namespace App\Enums;

enum ServiceAccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending activation',
            self::Active => 'Active',
            self::Suspended => 'Suspended',
            self::Terminated => 'Terminated',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Suspended => 'orange',
            self::Terminated => 'red',
        };
    }

    /**
     * Whether RADIUS may authorise this account at all.
     *
     * This is the account-level gate only. Whether a session is actually allowed also depends
     * on the subscription being live, which Phase 4 layers on top.
     */
    public function allowsAuthentication(): bool
    {
        return $this === self::Active;
    }

    /**
     * A terminated account is final: it stays for history but never comes back.
     */
    public function isFinal(): bool
    {
        return $this === self::Terminated;
    }
}
