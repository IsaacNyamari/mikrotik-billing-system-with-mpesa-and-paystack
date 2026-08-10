<?php

namespace App\Enums;

enum PaymentStatus: string
{
    /** STK push sent; the customer is looking at their phone. */
    case Pending = 'pending';

    /** Confirmed by a callback. Money has moved. */
    case Paid = 'paid';

    /** The customer declined, timed out, or had insufficient funds. */
    case Failed = 'failed';

    /** Safaricom never came back and we stopped waiting. */
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Failed => 'Failed',
            self::Abandoned => 'No response',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Paid => 'green',
            self::Failed => 'red',
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
