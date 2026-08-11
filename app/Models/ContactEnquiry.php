<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An enquiry from the public site.
 *
 * Deliberately not tenant-owned: it arrives before there is any ISP for it to belong to.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $company
 * @property string|null $subject
 * @property string $message
 * @property string|null $router_count
 * @property CarbonImmutable|null $handled_at
 * @property string|null $ip_address
 */
#[Fillable([
    'name',
    'email',
    'phone',
    'company',
    'subject',
    'message',
    'router_count',
    'ip_address',
])]
class ContactEnquiry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'handled_at' => 'datetime',
        ];
    }
}
