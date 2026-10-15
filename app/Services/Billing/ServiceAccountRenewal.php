<?php

namespace App\Services\Billing;

use App\Actions\ServiceAccounts\ChangeServiceAccountStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceAccountStatus;
use App\Enums\ValidityUnit;
use App\Jobs\SendCustomerInvoice;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ServiceAccount;
use App\Services\Sms\SmsSender;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns a paid renewal into time on a PPPoE line, and reconnects the line if it was cut.
 *
 * Reached from every path money can arrive by — the provider's callback, the pay page's poll,
 * the minute-by-minute reconciliation, an operator recording cash — so it is safe to run any
 * number of times against the same payment: `applied_at` records that it has been, and a
 * lock keeps two paths that land together in single file.
 */
class ServiceAccountRenewal
{
    public function __construct(
        protected ChangeServiceAccountStatus $status,
        protected SmsSender $sms,
        protected BillingMessages $messages,
        protected AuditLogger $audit,
        protected CustomerInvoices $invoices,
    ) {}

    public function renew(Payment $payment): Payment
    {
        if ($payment->service_account_id === null || $payment->applied_at !== null || $payment->status !== PaymentStatus::Paid) {
            return $payment;
        }

        $lock = Cache::lock('payments:renew:'.$payment->id, 30);

        if (! $lock->get()) {
            return $payment;
        }

        try {
            return $this->apply($payment);
        } finally {
            $lock->release();
        }
    }

    /**
     * Record money an operator took by hand — cash at the counter, a transfer — and renew.
     */
    public function recordManual(ServiceAccount $account, ?string $reference = null): Payment
    {
        $account->loadMissing(['plan', 'customer']);

        $payment = new Payment([
            'plan_id' => $account->plan_id,
            'router_id' => $account->router_id,
            'customer_id' => $account->customer_id,
            'service_account_id' => $account->id,
            'provider' => 'manual',
            'phone' => (string) ($account->customer->phone ?? ''),
            'amount' => (string) $account->plan?->price,
            'result_description' => $reference !== null && $reference !== '' ? $reference : __('Recorded by hand'),
        ]);
        $payment->status = PaymentStatus::Paid;
        $payment->paid_at = now();
        $payment->save();

        $this->audit->record(
            event: 'payment.recorded_manually',
            auditable: $payment,
            description: sprintf('Recorded %s by hand for %s', $payment->amount, $account->account_number),
        );

        return $this->renew($payment);
    }

    /**
     * Where a paid period ends: from the current expiry if it is still running, else from now.
     *
     * Whole days and months run to the end of the day, so a line due "on the 5th" works for
     * all of the 5th and the due-day reminder arrives before anything is cut.
     */
    public function nextExpiry(ServiceAccount $account, Plan $plan): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $from = $account->expires_at !== null && $account->expires_at->isFuture()
            ? CarbonImmutable::parse($account->expires_at)
            : $now;

        return match ($plan->validity_unit) {
            ValidityUnit::Months => $from->addMonthsNoOverflow($plan->validity_amount)->endOfDay(),
            ValidityUnit::Days => $from->addDays($plan->validity_amount)->endOfDay(),
            default => $from->addSeconds($plan->validitySeconds()),
        };
    }

    protected function apply(Payment $payment): Payment
    {
        $payment->refresh();

        if ($payment->applied_at !== null) {
            return $payment;
        }

        $invoice = null;

        /** @var ServiceAccount|null $account */
        $account = DB::transaction(function () use ($payment, &$invoice): ?ServiceAccount {
            $account = ServiceAccount::query()->lockForUpdate()->find($payment->service_account_id);
            $plan = $account?->plan_id !== null ? Plan::query()->find($account->plan_id) : null;

            if ($account === null || $plan === null) {
                return null;
            }

            $previous = $account->expires_at;
            $next = $this->nextExpiry($account, $plan);

            $account->expires_at = $next;
            $account->reminded_before_for = null;
            $account->reminded_due_for = null;
            $account->save();

            $payment->applied_at = now();
            $payment->save();

            // The invoice it pays, or a paid one left behind as the receipt.
            $account->setRelation('plan', $plan);
            $invoice = $this->invoices->settle(
                $payment,
                $account,
                $previous !== null ? CarbonImmutable::parse($previous) : null,
                $next,
            );

            $this->audit->record(
                event: 'service_account.renewed',
                auditable: $account,
                description: sprintf('Paid until %s after payment #%d', $next->toDateTimeString(), $payment->id),
                oldValues: ['expires_at' => $previous?->toDateTimeString()],
                newValues: ['expires_at' => $next->toDateTimeString()],
            );

            return $account;
        });

        if ($account === null) {
            return $payment;
        }

        // The receipt, by email, for a subscriber who gave an address.
        if ($invoice !== null) {
            SendCustomerInvoice::for($invoice);
        }

        // Back online: a line cut for not paying, or one waiting on its first payment. A line
        // the ISP suspended by hand is theirs to bring back.
        $reconnect = ($account->status === ServiceAccountStatus::Suspended && $account->suspended_for_nonpayment)
            || $account->status === ServiceAccountStatus::Pending;

        if ($reconnect) {
            $account->suspended_for_nonpayment = false;
            $account->save();

            $this->status->handle($account, ServiceAccountStatus::Active, sprintf(
                'Reconnected %s after payment #%d',
                $account->account_number,
                $payment->id,
            ));
        }

        $phone = (string) ($account->customer?->phone ?: $payment->phone);

        if ($phone !== '') {
            $this->sms->send($phone, $this->messages->renewed($account, (string) $payment->amount, $payment->receipt, $invoice?->publicUrl()), BillingMessages::RENEWED, $account);
        }

        return $payment->refresh();
    }
}
