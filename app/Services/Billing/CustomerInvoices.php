<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ValidityUnit;
use App\Models\CustomerInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ServiceAccount;
use App\Support\Numbering\Sequence;
use App\Support\Numbering\SequenceGenerator;
use App\Support\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Raises, settles and cancels PPPoE subscribers' invoices.
 *
 * One invoice per line per due date (a unique index), so raising is safe to repeat: the
 * billing run asks for one every few minutes and gets the same row back. A payment settles the
 * oldest outstanding invoice on its line, or — paid before one was raised, or in cash — leaves
 * a paid invoice behind as the receipt. Either way every shilling has a document.
 */
class CustomerInvoices
{
    public function __construct(protected SequenceGenerator $sequence) {}

    /**
     * The invoice for the line's current due date, raised if it does not exist yet.
     */
    public function raiseFor(ServiceAccount $account): ?CustomerInvoice
    {
        $account->loadMissing('plan');

        if ($account->expires_at === null || $account->plan === null) {
            return null;
        }

        $due = CarbonImmutable::parse($account->expires_at);

        $existing = CustomerInvoice::query()
            ->where('service_account_id', $account->id)
            ->where('due_at', $due)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $end = $this->periodEnd($due, $account->plan);

        return $this->create($account, $account->plan, [
            'due_at' => $due,
            'period_start' => $due,
            'period_end' => $end,
        ], $due->isPast() ? InvoiceStatus::Overdue : InvoiceStatus::Pending);
    }

    /**
     * Mark the line's invoice paid by this payment, or leave a paid one behind as its receipt.
     *
     * Called inside the renewal's transaction, with the period the payment actually bought.
     */
    public function settle(Payment $payment, ServiceAccount $account, ?CarbonImmutable $previousExpiry, CarbonImmutable $paidUntil): CustomerInvoice
    {
        $plan = $account->plan;
        $start = $previousExpiry !== null && $previousExpiry->isFuture() ? $previousExpiry : CarbonImmutable::now();

        $invoice = CustomerInvoice::query()
            ->where('service_account_id', $account->id)
            ->outstanding()
            ->orderBy('due_at')
            ->lockForUpdate()
            ->first();

        $invoice ??= $plan !== null ? $this->create($account, $plan, [
            'due_at' => $previousExpiry ?? CarbonImmutable::now(),
            'period_start' => $start,
            'period_end' => $paidUntil,
        ], InvoiceStatus::Pending) : null;

        if ($invoice === null) {
            throw new \LogicException('A renewal was paid on a line with no package.');
        }

        $invoice->status = InvoiceStatus::Paid;
        $invoice->payment_id = $payment->id;
        $invoice->paid_at = $payment->paid_at ?? now();
        $invoice->receipt = $payment->receipt;
        $invoice->period_start = $start;
        $invoice->period_end = $paidUntil;
        // The record shows the money that moved, as everywhere else in this system.
        $invoice->amount = (string) $payment->amount;
        $invoice->description = $this->describe($plan, $start, $paidUntil);
        $invoice->save();

        return $invoice;
    }

    /**
     * Pending invoices whose date has passed.
     */
    public function markOverdue(): int
    {
        return CustomerInvoice::query()
            ->where('status', InvoiceStatus::Pending)
            ->where('due_at', '<', now())
            ->update(['status' => InvoiceStatus::Overdue, 'updated_at' => now()]);
    }

    /**
     * Cancel what a line owes up to a date an operator has moved its paid-until past.
     *
     * Moving the date by hand says the period is settled some other way; leaving the old
     * invoice standing would bill the subscriber for time they have been given.
     */
    public function voidOutstandingBefore(ServiceAccount $account, CarbonImmutable $paidUntil, string $reason): int
    {
        return CustomerInvoice::query()
            ->where('service_account_id', $account->id)
            ->outstanding()
            ->where('due_at', '<', $paidUntil)
            ->update(['status' => InvoiceStatus::Void, 'void_reason' => mb_substr($reason, 0, 250), 'updated_at' => now()]);
    }

    public function void(CustomerInvoice $invoice, string $reason): void
    {
        if (! $invoice->status->isOutstanding()) {
            return;
        }

        $invoice->status = InvoiceStatus::Void;
        $invoice->void_reason = mb_substr($reason, 0, 250);
        $invoice->save();
    }

    /**
     * @param  array{due_at: CarbonImmutable, period_start: CarbonImmutable, period_end: CarbonImmutable}  $dates
     */
    protected function create(ServiceAccount $account, Plan $plan, array $dates, InvoiceStatus $status): CustomerInvoice
    {
        try {
            return DB::transaction(function () use ($account, $plan, $dates, $status): CustomerInvoice {
                $invoice = new CustomerInvoice([
                    'customer_id' => $account->customer_id,
                    'service_account_id' => $account->id,
                    'plan_id' => $plan->id,
                    'description' => $this->describe($plan, $dates['period_start'], $dates['period_end']),
                    'amount' => $plan->price,
                    'currency' => (string) (Tenancy::tenant()->currency ?? 'KES'),
                    'due_at' => $dates['due_at'],
                    'period_start' => $dates['period_start'],
                    'period_end' => $dates['period_end'],
                ]);
                $invoice->number = $this->sequence->next(Sequence::CustomerInvoice);
                $invoice->status = $status;
                $invoice->save();

                return $invoice;
            });
        } catch (UniqueConstraintViolationException) {
            // Raised by another run in the same moment: theirs is the invoice.
            return CustomerInvoice::query()
                ->where('service_account_id', $account->id)
                ->where('due_at', $dates['due_at'])
                ->firstOrFail();
        }
    }

    protected function periodEnd(CarbonImmutable $from, Plan $plan): CarbonImmutable
    {
        return match ($plan->validity_unit) {
            ValidityUnit::Months => $from->addMonthsNoOverflow($plan->validity_amount)->endOfDay(),
            ValidityUnit::Days => $from->addDays($plan->validity_amount)->endOfDay(),
            default => $from->addSeconds($plan->validitySeconds()),
        };
    }

    protected function describe(?Plan $plan, CarbonImmutable $start, CarbonImmutable $end): string
    {
        return __(':plan internet, :from to :to', [
            'plan' => $plan->name ?? __('Internet'),
            'from' => $start->format('j M Y'),
            'to' => $end->format('j M Y'),
        ]);
    }
}
