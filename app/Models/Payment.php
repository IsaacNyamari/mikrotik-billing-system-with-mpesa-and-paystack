<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attempt to collect money, and the record of whether it worked.
 *
 * Payments outlive the access they buy: the HotSpot login is deleted from the router when the
 * package is spent, but this row stays and is what the ISP's earnings reporting reads.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $plan_id
 * @property int|null $router_id
 * @property int|null $customer_id
 * @property int|null $hotspot_user_id
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
 * @property string|null $client_mac
 * @property string|null $client_ip
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $failed_at
 */
#[Fillable([
    'plan_id',
    'router_id',
    'customer_id',
    'hotspot_user_id',
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
    'client_mac',
    'client_ip',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToTenant, HasFactory;

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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Router, $this>
     */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The access this payment bought, once it has been provisioned.
     *
     * @return BelongsTo<HotspotUser, $this>
     */
    public function hotspotUser(): BelongsTo
    {
        return $this->belongsTo(HotspotUser::class);
    }

    /**
     * What to tell the customer standing at the portal.
     *
     * The provider's own wording is repeated only when it describes what happened on the
     * customer's own phone — "insufficient funds" tells them exactly what to do next. Anything
     * that went wrong before the prompt was ever sent is not about them: an API fault, an
     * outage, a half-finished setup. Those read as gibberish to a walk-in, and the setup ones
     * would tell a stranger about the ISP's own configuration, so they get a plain instruction
     * instead and the provider's exact words are kept on the record for the ISP.
     */
    public function customerMessage(): string
    {
        return match ($this->status) {
            PaymentStatus::Cancelled => __('You cancelled the request on your phone. Nothing was charged.'),
            PaymentStatus::Abandoned => __('No response came back from your phone. Nothing was charged.'),
            PaymentStatus::Failed => $this->reachedTheCustomer()
                ? ($this->result_description ?? __('The payment did not go through.'))
                : __('We could not start the payment just now. Please try again in a moment.'),
            default => $this->result_description ?? '',
        };
    }

    /**
     * Whether the provider ever got as far as prompting the phone.
     *
     * The handle it gives back is only issued once it has accepted the charge, so its absence
     * is the plainest evidence that nothing ever reached the customer.
     */
    public function reachedTheCustomer(): bool
    {
        return $this->checkout_request_id !== null;
    }

    /**
     * Columns are qualified because reporting joins payments to plans and routers, and both
     * of those carry a "status" of their own.
     *
     * @param  Builder<Payment>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), PaymentStatus::Paid);
    }

    /**
     * Requests still waiting on the customer, old enough to have been given up on.
     *
     * @param  Builder<Payment>  $query
     */
    public function scopeStale(Builder $query, int $minutes = 5): void
    {
        $query->where($this->qualifyColumn('status'), PaymentStatus::Pending)
            ->where($this->qualifyColumn('created_at'), '<=', now()->subMinutes($minutes));
    }
}
