<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Inactive => 'Inactive',
            self::Blocked => 'Blocked',
        };
    }

    /**
     * Colour used by Flux badges across the customer screens.
     */
    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'zinc',
            self::Blocked => 'red',
        };
    }

    /**
     * Whether the customer's services may carry traffic. A blocked customer keeps their record
     * and history, but nothing of theirs should authenticate.
     */
    public function allowsService(): bool
    {
        return $this === self::Active;
    }
}
