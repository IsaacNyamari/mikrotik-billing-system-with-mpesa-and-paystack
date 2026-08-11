<?php

namespace App\Enums;

enum PaymentProvider: string
{
    /** Safaricom Daraja, direct. Cheapest, but needs the ISP's own Daraja app. */
    case Mpesa = 'mpesa';

    /** Paystack, which fronts M-PESA for an ISP that has no Daraja credentials. */
    case Paystack = 'paystack';

    public function label(): string
    {
        return match ($this) {
            self::Mpesa => 'M-PESA (Daraja)',
            self::Paystack => 'Paystack',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Mpesa => 'Collect straight into your own till. Needs a Daraja app from Safaricom.',
            self::Paystack => 'Collect through Paystack with no Daraja app. Paystack takes a cut and settles to your bank.',
        };
    }
}
