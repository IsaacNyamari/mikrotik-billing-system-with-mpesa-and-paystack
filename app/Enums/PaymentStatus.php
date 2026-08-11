<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /** STK push sent; the customer is looking at their phone. */
    case Pending = 'pending';

    /** Confirmed by a callback. Money has moved. */
    case Paid = 'paid';

    /** The prompt failed on its own — insufficient funds, a timeout, a wrong PIN. */
    case Failed = 'failed';

    /**
     * The customer said no.
     *
     * Kept apart from a failure because it is the one outcome the customer already knows about:
     * they pressed cancel a second ago, and the portal saying "cancelled" rather than "failed"
     * is the difference between a page that agrees with them and one that seems broken.
     */
    case Cancelled = 'cancelled';

    /** The provider never came back and we stopped waiting. */
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Abandoned => 'No response',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Paid => 'green',
            self::Failed => 'red',
            self::Cancelled => 'zinc',
            self::Abandoned => 'zinc',
        };
    }

    /**
     * Whether this payment is finished with, either way.
     */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether it should buy the customer access.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Paid;
    }
}
