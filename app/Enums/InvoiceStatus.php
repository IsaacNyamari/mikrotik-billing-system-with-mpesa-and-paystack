<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    /** Past its due date. Still inside grace until the grace period runs out too. */
    case Overdue = 'overdue';

    /** Written off or raised in error. Never counts against an ISP's access. */
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Due',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Void => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Paid => 'green',
            self::Overdue => 'red',
            self::Void => 'zinc',
        };
    }

    public function isOutstanding(): bool
    {
        return $this === self::Pending || $this === self::Overdue;
    }
}
