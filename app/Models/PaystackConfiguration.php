<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\PhoneNumber;
use App\Support\Tenancy\Facades\Tenancy;
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
 * @property string|null $billing_email
 * @property bool $is_active
 */
#[Fillable(['secret_key', 'public_key', 'currency', 'billing_email', 'is_active'])]
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
     * The email Paystack is given for a charge, or null when there is no usable one.
     *
     * Paystack insists on an email and a walk-in has given only a phone number, so one has to
     * come from somewhere. Both answers an ISP might reasonably give are accepted:
     *
     * - a full address, used as it stands for every charge;
     * - a bare domain, which derives a per-customer address so one buyer's receipts stay
     *   together.
     *
     * Nothing unvalidated is ever sent. Paystack rejects a malformed address with a flat
     * "invalid email", which tells the operator nothing about which field is at fault.
     */
    public function emailFor(string $phone): ?string
    {
        $configured = trim((string) $this->billing_email);

        if (filter_var($configured, FILTER_VALIDATE_EMAIL) !== false) {
            return $configured;
        }

        // A domain has a dot and no "@" — anything else was meant to be an address and is
        // malformed, so it is not patched up into something that looks deliberate.
        if ($configured !== '' && ! str_contains($configured, '@') && str_contains($configured, '.')) {
            $derived = PhoneNumber::normalise($phone).'@'.$configured;

            return filter_var($derived, FILTER_VALIDATE_EMAIL) !== false ? $derived : null;
        }

        // Falling back to the ISP's own address beats failing the sale outright. Read from
        // the bound tenant rather than the relation, which the request already knows.
        $contact = trim((string) Tenancy::tenant()?->contact_email);

        return filter_var($contact, FILTER_VALIDATE_EMAIL) !== false ? $contact : null;
    }
}
