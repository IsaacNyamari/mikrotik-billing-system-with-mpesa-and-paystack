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
}
