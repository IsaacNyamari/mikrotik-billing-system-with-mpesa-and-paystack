<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ISP paying the platform.
 *
 * The mirror image of `Payment`, which is an ISP's customer paying the ISP. They are kept
 * apart because every earnings figure the platform shows an ISP is built from that table, and
 * the platform's own fees have no business appearing in an ISP's revenue.
 *
 * The status vocabulary is shared, though: it is the same Daraja conversation, so a customer
 * cancelling a prompt and an ISP cancelling one mean the same thing.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $tenant_invoice_id
 * @property string $provider
 * @property PaymentStatus $status
 * @property string $phone
 * @property string $amount
 * @property string|null $checkout_request_id
 * @property string|null $merchant_request_id
 * @property string|null $receipt
 * @property int|null $result_code
 * @property string|null $result_description
 * @property array<string, mixed>|null $request_payload
 * @property array<string, mixed>|null $callback_payload
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $failed_at
 */
#[Fillable([
    'tenant_id',
    'tenant_invoice_id',
    'provider',
    'phone',
    'amount',
    'checkout_request_id',
    'merchant_request_id',
    'receipt',
    'result_code',
    'result_description',
    'request_payload',
    'callback_payload',
])]
class TenantPayment extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'provider' => 'mpesa',
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'request_payload' => 'array',
            'callback_payload' => 'array',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<TenantInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TenantInvoice::class, 'tenant_invoice_id');
    }
}
