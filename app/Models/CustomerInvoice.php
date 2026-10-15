<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bill from an ISP to one of its PPPoE subscribers, for one period of one line.
 *
 * Subscribers never sign in to see these: each is reached through its line's payment link,
 * the same unguessable token their reminders carry. A paid invoice doubles as the receipt.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $customer_id
 * @property int $service_account_id
 * @property int|null $plan_id
 * @property int|null $payment_id
 * @property string $number
 * @property string $description
 * @property string $amount
 * @property string $currency
 * @property InvoiceStatus $status
 * @property CarbonImmutable $due_at
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $paid_at
 * @property string|null $receipt
 * @property string|null $void_reason
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['customer_id', 'service_account_id', 'plan_id', 'description', 'amount', 'currency', 'due_at', 'period_start', 'period_end'])]
class CustomerInvoice extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'currency' => 'KES',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'amount' => 'decimal:2',
            'due_at' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<ServiceAccount, $this>
     */
    public function serviceAccount(): BelongsTo
    {
        return $this->belongsTo(ServiceAccount::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Where the subscriber reads and prints it, with no account of their own.
     */
    public function publicUrl(): string
    {
        $this->loadMissing('serviceAccount');

        return route('pay.invoice', ['token' => $this->serviceAccount->payToken(), 'number' => $this->number]);
    }

    /**
     * @param  Builder<CustomerInvoice>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::Overdue]);
    }
}
