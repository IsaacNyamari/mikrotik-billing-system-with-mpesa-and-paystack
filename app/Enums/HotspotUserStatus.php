<?php

namespace App\Enums;

enum HotspotUserStatus: string
{
    /** Paid for, but not yet written to the router. */
    case Pending = 'pending';

    /** Live on the router; the customer can log in. */
    case Active = 'active';

    /** Time or data spent, and the row has been taken off the router. */
    case Expired = 'expired';

    /** Withdrawn by an operator before it was spent. */
    case Revoked = 'revoked';

    /** Could not be written to the router. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting provisioning',
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Revoked => 'Revoked',
            self::Failed => 'Failed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Active => 'green',
            self::Expired => 'zinc',
            self::Revoked => 'orange',
            self::Failed => 'red',
        };
    }

    /**
     * Whether a row for this package should exist on the router right now.
     */
    public function belongsOnRouter(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the package is finished with, one way or another.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Expired, self::Revoked], true);
    }
}
