<?php

namespace App\Support;

/**
 * Kenyan mobile numbers, in the one form the rest of the platform stores and sends.
 *
 * Customers type 0712…, +254712…, 254712… and 712… interchangeably, and M-PESA only accepts
 * 2547XXXXXXXX. Normalising in one place keeps the stored number comparable — two payments
 * from the same phone should not look like two different customers.
 */
class PhoneNumber
{
    /**
     * Convert any of the forms a customer might type into 2547XXXXXXXX.
     */
    public static function normalise(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return match (true) {
            str_starts_with($digits, '254') => $digits,
            str_starts_with($digits, '0') => '254'.substr($digits, 1),
            strlen($digits) === 9 => '254'.$digits,
            default => $digits,
        };
    }

    /**
     * The same number written the local way, 07XXXXXXXX.
     */
    public static function local(string $phone): string
    {
        $normalised = self::normalise($phone);

        return str_starts_with($normalised, '254')
            ? '0'.substr($normalised, 3)
            : $normalised;
    }

    /**
     * E.164, with the leading plus: +2547XXXXXXXX.
     *
     * Providers disagree about this and are not forgiving. Daraja wants 2547XXXXXXXX with no
     * plus; Paystack rejects both that and the local 07XXXXXXXX as an "invalid phone number
     * format" and accepts only this. Verified against Paystack's own API rather than inferred.
     * Storage stays on normalise(), so the same phone is one customer whoever collected.
     */
    public static function e164(string $phone): string
    {
        return '+'.self::normalise($phone);
    }
}
