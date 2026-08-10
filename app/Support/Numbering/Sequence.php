<?php

namespace App\Support\Numbering;

/**
 * The running numbers the platform allocates per ISP.
 *
 * Identifiers are enumerated here rather than passed in as strings, so no caller can reach
 * SequenceGenerator's raw ordering clause with a table or column name of their choosing.
 */
enum Sequence
{
    case Customer;
    case ServiceAccount;

    public function table(): string
    {
        return match ($this) {
            self::Customer => 'customers',
            self::ServiceAccount => 'service_accounts',
        };
    }

    public function column(): string
    {
        return match ($this) {
            self::Customer => 'customer_number',
            self::ServiceAccount => 'account_number',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Customer => 'CUS',
            self::ServiceAccount => 'SVC',
        };
    }

    /**
     * Longest-then-highest, so the sequence keeps counting correctly once it outgrows its
     * padding and "CUS-1000000" appears alongside "CUS-999999".
     *
     * @return literal-string ordering clause, safe to interpolate into SQL
     */
    public function highestFirst(): string
    {
        return match ($this) {
            self::Customer => 'LENGTH(customer_number) DESC, customer_number DESC',
            self::ServiceAccount => 'LENGTH(account_number) DESC, account_number DESC',
        };
    }
}
