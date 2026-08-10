<?php

namespace App\Enums;

enum ValidityUnit: string
{
    case Minutes = 'minutes';
    case Hours = 'hours';
    case Days = 'days';
    case Months = 'months';

    public function label(): string
    {
        return match ($this) {
            self::Minutes => 'Minutes',
            self::Hours => 'Hours',
            self::Days => 'Days',
            self::Months => 'Months',
        };
    }

    /**
     * Seconds in one of these units.
     *
     * A month is taken as 30 days. Calendar months are the right unit for a subscription
     * renewal date, but this value exists to hand RouterOS an uptime limit, and RouterOS has
     * no notion of a calendar.
     */
    public function seconds(): int
    {
        return match ($this) {
            self::Minutes => 60,
            self::Hours => 3600,
            self::Days => 86400,
            self::Months => 2_592_000,
        };
    }
}
