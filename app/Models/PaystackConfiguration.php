<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One ISP's Paystack credentials.
 *
 * The alternative for an ISP that has no Daraja app of its own — Paystack fronts M-PESA and
 * settles to their bank, at the cost of a cut.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $secret_key
 * @property string|null $public_key
 * @property string $currency
 * @property string|null $receipt_domain
 * @property bool $is_active
 */
#[Fillable(['secret_key', 'public_key', 'currency', 'receipt_domain', 'is_active'])]
#[Hidden(['secret_key'])]
class PaystackConfiguration extends Model
{
    use BelongsToTenant;

    public const BASE_URL = 'https://api.paystack.co';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'KES',
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Needed in cleartext to sign requests and to verify webhook signatures.
            'secret_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether these are live keys rather than test ones.
     *
     * Paystack encodes the mode in the key itself, so an ISP cannot be in any doubt about
     * which one they have pasted in.
     */
    public function isLive(): bool
    {
        return str_starts_with($this->secret_key, 'sk_live_');
    }

    /**
     * Paystack insists on an email for every charge, and a walk-in has given only a phone
     * number. Deriving one keeps their receipts together without inventing a person.
     */
    public function emailFor(string $phone): string
    {
        $domain = $this->receipt_domain ?: 'hotspot.invalid';

        return PhoneNumber::normalise($phone).'@'.ltrim($domain, '@');
    }
}
