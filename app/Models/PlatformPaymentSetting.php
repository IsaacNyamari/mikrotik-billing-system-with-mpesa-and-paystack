<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * How the platform collects its own fees from ISPs. A single row; see the migration.
 *
 * Not tenant-owned and never shown to an ISP. Only a platform super admin reads or writes it.
 *
 * @property int $id
 * @property string|null $preferred_provider
 * @property bool $mpesa_enabled
 * @property string $mpesa_environment
 * @property string|null $mpesa_short_code
 * @property string|null $mpesa_party_b
 * @property string $mpesa_transaction_type
 * @property string|null $mpesa_consumer_key
 * @property string|null $mpesa_consumer_secret
 * @property string|null $mpesa_passkey
 * @property string|null $mpesa_account_reference
 * @property string|null $mpesa_callback_token
 * @property bool $paystack_enabled
 * @property string|null $paystack_secret_key
 * @property string|null $paystack_public_key
 * @property string $paystack_currency
 * @property string|null $paystack_billing_email
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'preferred_provider',
    'mpesa_enabled',
    'mpesa_environment',
    'mpesa_short_code',
    'mpesa_party_b',
    'mpesa_transaction_type',
    'mpesa_account_reference',
    'paystack_enabled',
    'paystack_public_key',
    'paystack_currency',
    'paystack_billing_email',
])]
#[Hidden(['mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_passkey', 'mpesa_callback_token', 'paystack_secret_key'])]
class PlatformPaymentSetting extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'mpesa_enabled' => false,
        'mpesa_environment' => 'production',
        'mpesa_transaction_type' => 'CustomerPayBillOnline',
        'paystack_enabled' => false,
        'paystack_currency' => 'KES',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mpesa_enabled' => 'boolean',
            'paystack_enabled' => 'boolean',
            // Secrets, needed in cleartext to sign requests — so encrypted, not hashed.
            'mpesa_consumer_key' => 'encrypted',
            'mpesa_consumer_secret' => 'encrypted',
            'mpesa_passkey' => 'encrypted',
            'paystack_secret_key' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        // Safaricom does not sign callbacks; this token in the URL is the only thing that
        // authenticates one. Issued once and kept, so a callback already in flight still lands.
        static::saving(function (self $settings): void {
            $settings->mpesa_callback_token ??= Str::random(48);
        });
    }

    /**
     * The one row, or an unsaved blank one when nothing has been set yet.
     */
    public static function current(): self
    {
        return self::query()->first() ?? new self;
    }

    public function mpesaIsComplete(): bool
    {
        return $this->mpesa_enabled
            && filled($this->mpesa_short_code)
            && filled($this->mpesa_consumer_key)
            && filled($this->mpesa_consumer_secret)
            && filled($this->mpesa_passkey);
    }

    public function paystackIsComplete(): bool
    {
        return $this->paystack_enabled && filled($this->paystack_secret_key);
    }
}
