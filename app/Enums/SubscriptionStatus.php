<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    /** Signed up, onboarding fee not yet settled. Cannot use the platform. */
    case Pending = 'pending';

    /** Paid up, or inside the grace period on an unpaid invoice. */
    case Active = 'active';

    /** An invoice went past its grace period. Kept apart from cancelled: the data is intact
     *  and paying restores access the moment the callback lands. */
    case Suspended = 'suspended';

    /** The ISP has left. */
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting onboarding payment',
            self::Active => 'Active',
            self::Suspended => 'Suspended for non-payment',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Suspended => 'red',
            self::Cancelled => 'zinc',
        };
    }

    /**
     * Whether this state, on its own, lets the ISP into the console.
     *
     * Only ever half the answer: an active subscription with an invoice past its grace period
     * still ends up locked out. Ask the subscription, not the enum.
     */
    public function permitsAccess(): bool
    {
        return $this === self::Active;
    }
}
