<?php

namespace App\Enums;

enum CustomerType: string
{
    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Company => 'Company',
        };
    }
}
