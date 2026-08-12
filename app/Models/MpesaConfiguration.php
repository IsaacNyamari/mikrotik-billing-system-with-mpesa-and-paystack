<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One ISP's Lipa na M-PESA credentials.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $environment
 * @property string $short_code
 * @property string|null $party_b
 * @property string $transaction_type
 * @property string $consumer_key
 * @property string $consumer_secret
 * @property string $passkey
 * @property string $callback_token
 * @property string $account_reference
 * @property bool $is_active
 */
#[Fillable([
    'environment',
    'short_code',
    'party_b',
    'transaction_type',
    'consumer_key',
    'consumer_secret',
    'passkey',
    'account_reference',
    'is_active',
])]
#[Hidden(['consumer_key', 'consumer_secret', 'passkey', 'callback_token'])]
class MpesaConfiguration extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'environment' => 'sandbox',
        'transaction_type' => 'CustomerPayBillOnline',
        'account_reference' => 'INTERNET',
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $configuration): void {
            $configuration->callback_token ??= Str::random(48);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Daraja needs these in cleartext to sign a request, so they are encrypted rather
            // than hashed.
            'consumer_key' => 'encrypted',
            'consumer_secret' => 'encrypted',
            'passkey' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The till or paybill an ISP's customers actually pay into.
     *
     * `party_b` when it is set, because an ISP signing with the platform's Daraja app records
     * their own number there while the shortcode belongs to the platform. Otherwise the
     * shortcode is their own and is the same thing.
     */
    public function collectionNumber(): string
    {
        return (string) ($this->party_b ?: $this->short_code);
    }

    /**
     * Whether this ISP is a till (Buy Goods) rather than a paybill.
     */
    public function isTill(): bool
    {
        return $this->transaction_type === 'CustomerBuyGoodsOnline';
    }

    public function isLive(): bool
    {
        return $this->environment === 'live';
    }

    public function baseUrl(): string
    {
        return $this->isLive()
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Who the money is paid to. For a paybill this differs from the short code that
     * identifies the request.
     */
    public function partyB(): string
    {
        return $this->party_b ?: $this->short_code;
    }

    /**
     * The Lipa na M-PESA password: short code + passkey + timestamp, base64 encoded.
     */
    public function password(string $timestamp): string
    {
        return base64_encode($this->short_code.$this->passkey.$timestamp);
    }

    /**
     * Where Safaricom should post the result. The token in the path is the only thing
     * distinguishing a genuine callback from anyone who guesses the URL.
     */
    public function callbackUrl(): string
    {
        return route('webhooks.mpesa', ['token' => $this->callback_token]);
    }
}
